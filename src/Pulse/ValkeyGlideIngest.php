<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Pulse;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Collection;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Value;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Support\Cast;

/**
 * Laravel Pulse's Redis ingest, run on GLIDE connections.
 *
 * Pulse's own Redis ingest types its pipeline callback against the phpredis,
 * Predis and Relay clients and sends raw commands only through them, so it
 * cannot run on a GLIDE connection. This ingest keeps Pulse's stream, its key
 * and its entry format, and sends the same commands to the GLIDE client, so the
 * entries one process ingests are digested by `pulse:work` as Pulse's own are.
 * On any other connection it hands each call to Pulse's ingest.
 *
 * As in Pulse, the stream's key carries the shared prefix,
 * `database.redis.options.prefix`, and never the connection's own prefix: Pulse
 * sends its commands raw, and phpredis does not add OPT_PREFIX to a raw
 * command. The commands go to the client directly, as Pulse's go to phpredis,
 * so they reach the same physical key.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValkeyGlideIngest implements Ingest
{
    /** @var string The stream Pulse ingests into, before the shared prefix. */
    private const string STREAM = 'laravel:pulse:ingest';

    /**
     * Create a new GLIDE-aware ingest over Pulse's Redis ingest.
     *
     * @param  \Laravel\Pulse\Contracts\Ingest  $ingest
     * @param  \Illuminate\Contracts\Redis\Factory  $redis
     * @param  \Illuminate\Contracts\Config\Repository  $config
     * @return void
     */
    public function __construct(

        /** Pulse's Redis ingest, which runs on every other connection. */
        private Ingest $ingest,

        /** The Redis connections Pulse's ingest connection is resolved from. */
        private Factory $redis,

        /** The application configuration. */
        private Repository $config,
    ) {}

    /**
     * Ingest the items, adding each to the stream in one pipeline.
     *
     * @param  \Illuminate\Support\Collection<int, \Laravel\Pulse\Entry|\Laravel\Pulse\Value>  $items
     * @return void
     *
     * @throws \LogicException
     * @throws \UnexpectedValueException
     * @throws \Throwable
     */
    #[\Override]
    public function ingest(Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $connection = $this->glideConnection();

        if ($connection === null) {
            $this->ingest->ingest($items);

            return;
        }

        $stream = $this->stream();
        $client = $connection->client();

        $client->pipeline();

        foreach ($items as $item) {
            $client->xadd($stream, '*', ['data' => serialize($item)]);
        }

        $replies = $client->exec();

        if (!is_array($replies)) {
            $client->discard();

            $replies = [false];
        }

        foreach ($replies as $reply) {
            self::assertSucceeded('XADD', $stream, $reply);
        }
    }

    /**
     * Digest the ingested items into the storage, a chunk at a time, deleting
     * each chunk from the stream once it is stored.
     *
     * @param  \Laravel\Pulse\Contracts\Storage  $storage
     * @return int
     *
     * @throws \LogicException
     * @throws \UnexpectedValueException
     * @throws \Throwable
     */
    #[\Override]
    public function digest(Storage $storage): int
    {
        $connection = $this->glideConnection();

        if ($connection === null) {
            return $this->ingest->digest($storage);
        }

        $client = $connection->client();
        $stream = $this->stream();
        $chunk  = Cast::toNonNegativeInt($this->config->get('pulse.ingest.redis.chunk')) ?? -1;
        $total  = 0;

        while (true) {
            /** @var array<string, array{data: string}> $entries */
            $entries = self::assertSucceeded('XRANGE', $stream, $client->xrange($stream, '-', '+', $chunk));

            if ($entries === []) {
                return $total;
            }

            // As in Pulse, an item that is not an entry or value fails the
            // return type, before its chunk is stored or deleted.
            $storage->store(collect($entries)->map(
                // @phpstan-ignore return.type
                static fn (array $payload): Entry|Value => unserialize($payload['data'], ['allowed_classes' => [Entry::class, Value::class]]),
            )->values());

            self::assertSucceeded('XDEL', $stream, $client->xdel($stream, array_keys($entries)));

            $total += count($entries);

            if (count($entries) < $chunk) {
                return $total;
            }
        }
    }

    /**
     * Trim the stream, approximately, to Pulse's configured length, or to the
     * entries within its configured age.
     *
     * @return void
     *
     * @throws \LogicException
     * @throws \UnexpectedValueException
     * @throws \Throwable
     */
    #[\Override]
    public function trim(): void
    {
        $connection = $this->glideConnection();

        if ($connection === null) {
            $this->ingest->trim();

            return;
        }

        $stream = $this->stream();
        $keep   = $this->config->get('pulse.ingest.trim.keep');

        $threshold = is_int($keep)
            ? $keep
            : CarbonImmutable::now()->subMilliseconds(
                (int) CarbonInterval::fromString(Cast::toNonEmptyString($keep) ?? '')->totalMilliseconds,
            )->getTimestampMs();

        self::assertSucceeded('XTRIM', $stream, $connection->client()->xtrim($stream, (string) $threshold, true, !is_int($keep)));
    }

    /**
     * Resolve Pulse's ingest connection when it is a GLIDE connection, or null
     * when it is any other.
     *
     * A pipeline or transaction open on the connection is refused, as the
     * client would buffer the ingest's commands into it.
     *
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection|null
     *
     * @throws \LogicException
     */
    private function glideConnection(): ?ValkeyGlideConnection
    {
        $name       = $this->config->get('pulse.ingest.redis.connection');
        $connection = $this->redis->connection(is_string($name) ? $name : null);

        if (!$connection instanceof ValkeyGlideConnection) {
            return null;
        }

        if ($connection->hasOpenBatch()) {
            throw new \LogicException('Pulse\'s ingest cannot run while a pipeline or transaction is open on its connection; give Pulse a connection of its own.');
        }

        return $connection;
    }

    /**
     * Build the stream's physical key under the shared prefix, as Pulse does.
     *
     * @return string
     */
    private function stream(): string
    {
        return (Cast::toNonEmptyString($this->config->get('database.redis.options.prefix')) ?? '') . self::STREAM;
    }

    /**
     * Return a command's reply, or throw when GLIDE failed the command.
     *
     * @param  string  $command
     * @param  string  $stream
     * @param  mixed  $reply
     * @return mixed
     *
     * @throws \UnexpectedValueException
     */
    private static function assertSucceeded(string $command, string $stream, mixed $reply): mixed
    {
        if ($reply === false) {
            throw new \UnexpectedValueException(sprintf('Pulse could not run %s on the [%s] stream.', $command, $stream));
        }

        return $reply;
    }
}
