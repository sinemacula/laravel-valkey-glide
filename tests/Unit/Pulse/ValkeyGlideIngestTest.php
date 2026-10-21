<?php

declare(strict_types = 1);

namespace Tests\Unit\Pulse;

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Value;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Pulse\ValkeyGlideIngest;
use Tests\Fakes\LaravelConnectionFake;
use Tests\Fakes\PulseIngestFake;
use Tests\Fakes\PulseStorageFake;
use Tests\Fakes\RecordingRedisFactoryFake;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * GLIDE-aware Pulse ingest test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideIngest::class)]
final class ValkeyGlideIngestTest extends TestCase
{
    /** @var string The stream's key under the shared prefix the tests configure. */
    private const string STREAM = 'app:laravel:pulse:ingest';

    /** @var string The refusal of a connection with an open pipeline or transaction. */
    private const string OPEN_BATCH_MESSAGE = 'Pulse\'s ingest cannot run while a pipeline or transaction is open on its connection; give Pulse a connection of its own.';

    /** @var \Tests\Fakes\RecordingRedisFactoryFake|null The last Redis factory built. */
    private ?RecordingRedisFactoryFake $redis = null;

    /**
     * Restore the real clock.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Verify each item is added to the stream under the shared prefix, with
     * Pulse's serialized payload, in one pipeline on Pulse's connection.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function ingestAddsEachItemToTheStreamInOnePipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('xadd', '1-0');

        $entry = new Entry(1700000000, 'slow_request', 'GET /', 1200);
        $value = new Value(1700000000, 'system', 'server', 'up');
        $fake  = new PulseIngestFake;

        $this->ingest($client, $fake, ['connection' => 'pulse'])->ingest(new Collection([$entry, $value]));

        self::assertSame(['pipeline', 'xadd', 'xadd', 'exec'], $client->callOrder());
        self::assertEquals([
            [self::STREAM, '*', ['data' => serialize($entry)]],
            [self::STREAM, '*', ['data' => serialize($value)]],
        ], $client->callsFor('xadd'));
        self::assertSame(['pulse'], $this->connectionNames());
        self::assertSame([], $fake->calls);
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a cluster connection pipelines the items too, as every one goes to
     * the stream's single key.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function ingestPipelinesTheItemsOnACluster(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('xadd', '1-0');

        $this->ingest($client)->ingest(new Collection([$this->entry(), $this->entry()]));

        self::assertSame(['pipeline', 'xadd', 'xadd', 'exec'], $client->callOrder());
        self::assertSame([self::STREAM, self::STREAM], array_column($client->callsFor('xadd'), 0));
    }

    /**
     * Verify nothing is resolved or sent when there is nothing to ingest, as
     * Pulse's own ingest does.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function ingestOfNothingSendsNothing(): void
    {
        $client = new ValkeyGlideFake;
        $fake   = new PulseIngestFake;

        $this->ingest($client, $fake)->ingest(new Collection);

        self::assertSame([], $client->callOrder());
        self::assertSame([], $this->connectionNames());
        self::assertSame([], $fake->calls);
    }

    /**
     * Verify an XADD that GLIDE fails is reported rather than dropped.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function ingestThrowsWhenAnItemIsNotAdded(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', ['1-0', false]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Pulse could not run XADD on the [' . self::STREAM . '] stream.');

        $this->ingest($client)->ingest(new Collection([$this->entry(), $this->entry()]));
    }

    /**
     * Verify a pipeline that GLIDE fails is reported, and the client leaves
     * batch mode.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function ingestThrowsWhenThePipelineFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        try {
            $this->ingest($client)->ingest(new Collection([$this->entry()]));

            self::fail('A failed pipeline was not reported.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Pulse could not run XADD on the [' . self::STREAM . '] stream.', $exception->getMessage());
            self::assertSame(['pipeline', 'xadd', 'exec', 'discard'], $client->callOrder());
        }
    }

    /**
     * Provide each operation of the ingest, with a pipeline and a transaction
     * open on its connection.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function operationsInOpenBatches(): iterable
    {
        foreach (['ingest', 'digest', 'trim'] as $operation) {
            yield $operation . ' in a pipeline' => [$operation, false];

            yield $operation . ' in a transaction' => [$operation, true];
        }
    }

    /**
     * Verify Pulse's ingest refuses its connection while a pipeline or
     * transaction is open on it, before anything joins the batch, which then
     * runs on as it was.
     *
     * @param  string  $operation
     * @param  bool  $atomic
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('operationsInOpenBatches')]
    #[Test]
    public function operationsRefuseAConnectionWithAnOpenBatch(string $operation, bool $atomic): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $ingest     = new ValkeyGlideIngest(new PulseIngestFake, $this->factory($connection), $this->config(['keep' => 10]));
        $refusal    = null;

        $callback = function (ValkeyGlideBatch $batch) use ($ingest, $operation, &$refusal): void {
            $batch->set('key', 'value');

            $refusal = $this->refusal($ingest, $operation);
        };

        $results = $atomic ? $connection->transaction($callback) : $connection->pipeline($callback);

        self::assertSame(self::OPEN_BATCH_MESSAGE, $refusal);
        self::assertSame([true], $results);
        self::assertSame([$atomic ? 'multi' : 'pipeline', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify a connection that is not GLIDE's is left to Pulse's own ingest.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function ingestHandsOtherConnectionsToPulse(): void
    {
        $connection = new LaravelConnectionFake;
        $fake       = new PulseIngestFake;
        $items      = new Collection([$this->entry()]);

        (new ValkeyGlideIngest($fake, $this->factory($connection), $this->config()))->ingest($items);

        self::assertSame([['ingest', $items]], $fake->calls);
        self::assertSame([], $connection->commands);
    }

    /**
     * Verify the stream is read a chunk at a time, each chunk stored as Pulse's
     * items and then deleted, until a chunk comes back short.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function digestStoresAndDeletesEachChunk(): void
    {
        $first  = new Entry(1, 'slow_query', 'select 1', 10);
        $second = new Value(2, 'system', 'server', 'up');
        $third  = new Entry(3, 'exception', \Exception::class);

        $client = $this->streamClient([
            'xrange' => [
                ['1-0' => ['data' => serialize($first)], '2-0' => ['data' => serialize($second)]],
                ['3-0' => ['data' => serialize($third)]],
            ],
            'xdel' => [2, 1],
        ]);

        $storage = new PulseStorageFake;
        $fake    = new PulseIngestFake;

        self::assertSame(3, $this->ingest($client, $fake, ['chunk' => 2, 'connection' => 'pulse'])->digest($storage));
        self::assertEquals([[$first, $second], [$third]], $storage->stored);
        self::assertSame([
            ['xrange', [self::STREAM, '-', '+', 2]],
            ['xdel', [self::STREAM, ['1-0', '2-0']]],
            ['xrange', [self::STREAM, '-', '+', 2]],
            ['xdel', [self::STREAM, ['3-0']]],
        ], $client->calls);
        self::assertSame(['pulse'], $this->connectionNames());
        self::assertSame([], $fake->calls);
    }

    /**
     * Verify a stream holding exactly a chunk is read again, and the digest
     * ends once a read finds it empty.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function digestReadsAgainAfterAFullChunk(): void
    {
        $client = $this->streamClient([
            'xrange' => [['1-0' => ['data' => serialize($this->entry())]], []],
            'xdel'   => [1],
        ]);

        $storage = new PulseStorageFake;

        self::assertSame(1, $this->ingest($client, null, ['chunk' => 1])->digest($storage));
        self::assertCount(1, $storage->stored);
        self::assertSame(['xrange', 'xdel', 'xrange'], array_column($client->calls, 0));
    }

    /**
     * Verify a chunk size given as a numeric string, as from the environment,
     * is read as that many entries.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function digestReadsANumericStringChunkSize(): void
    {
        $client = $this->streamClient([
            'xrange' => [['1-0' => ['data' => serialize($this->entry())]]],
            'xdel'   => [1],
        ]);

        self::assertSame(1, $this->ingest($client, null, ['chunk' => '2'])->digest(new PulseStorageFake));
        self::assertSame([
            ['xrange', [self::STREAM, '-', '+', 2]],
            ['xdel', [self::STREAM, ['1-0']]],
        ], $client->calls);
    }

    /**
     * Verify an empty stream stores nothing and deletes nothing.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function digestOfAnEmptyStreamStoresNothing(): void
    {
        $client  = $this->streamClient(['xrange' => [[]]]);
        $storage = new PulseStorageFake;

        self::assertSame(0, $this->ingest($client)->digest($storage));
        self::assertSame([], $storage->stored);
        self::assertSame(['xrange'], array_column($client->calls, 0));
    }

    /**
     * Verify the whole stream is read at once when no chunk size is set, and
     * read again until it is empty, as Pulse does.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function digestWithoutAChunkSizeReadsTheWholeStream(): void
    {
        $client = $this->streamClient([
            'xrange' => [['1-0' => ['data' => serialize($this->entry())], '2-0' => ['data' => serialize($this->entry())]], []],
            'xdel'   => [2],
        ]);

        self::assertSame(2, $this->ingest($client, null, ['chunk' => null])->digest(new PulseStorageFake));
        self::assertSame([
            ['xrange', [self::STREAM, '-', '+', -1]],
            ['xdel', [self::STREAM, ['1-0', '2-0']]],
            ['xrange', [self::STREAM, '-', '+', -1]],
        ], $client->calls);
    }

    /**
     * Verify an XRANGE that GLIDE fails is reported rather than read as an
     * empty stream.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function digestThrowsWhenTheStreamCannotBeRead(): void
    {
        $client = $this->streamClient(['xrange' => [false]]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Pulse could not run XRANGE on the [' . self::STREAM . '] stream.');

        $this->ingest($client)->digest(new PulseStorageFake);
    }

    /**
     * Verify an XDEL that GLIDE fails is reported, as the stored chunk would
     * otherwise be stored again.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function digestThrowsWhenAChunkCannotBeDeleted(): void
    {
        $client = $this->streamClient([
            'xrange' => [['1-0' => ['data' => serialize($this->entry())]]],
            'xdel'   => [false],
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Pulse could not run XDEL on the [' . self::STREAM . '] stream.');

        $this->ingest($client, null, ['chunk' => 2])->digest(new PulseStorageFake);
    }

    /**
     * Verify an item that is not Pulse's is never instantiated, and is refused
     * with a TypeError, as Pulse refuses it, before its chunk is stored or
     * deleted from the stream.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function digestRefusesItemsThatAreNotPulses(): void
    {
        $client = $this->streamClient([
            'xrange' => [['1-0' => ['data' => serialize($this->entry())], '2-0' => ['data' => serialize(new \ArrayObject)]]],
            'xdel'   => [2],
        ]);

        $storage = new PulseStorageFake;

        try {
            $this->ingest($client, null, ['chunk' => 2])->digest($storage);

            self::fail('An item that is not Pulse\'s was digested.');
        } catch (\TypeError $exception) {
            self::assertStringContainsString('__PHP_Incomplete_Class returned', $exception->getMessage());
            self::assertSame([], $storage->stored);
            self::assertSame(['xrange'], array_column($client->calls, 0));
        }
    }

    /**
     * Verify a connection that is not GLIDE's is digested by Pulse's own
     * ingest.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function digestHandsOtherConnectionsToPulse(): void
    {
        $connection = new LaravelConnectionFake;
        $fake       = new PulseIngestFake;
        $storage    = new PulseStorageFake;

        self::assertSame(1, (new ValkeyGlideIngest($fake, $this->factory($connection), $this->config()))->digest($storage));
        self::assertSame([['digest', $storage]], $fake->calls);
        self::assertSame([], $connection->commands);
    }

    /**
     * Verify a configured length trims the stream approximately to that many
     * entries.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function trimKeepsTheConfiguredNumberOfEntries(): void
    {
        $client = $this->streamClient(['xtrim' => [0]]);
        $fake   = new PulseIngestFake;

        $this->ingest($client, $fake, ['keep' => 5000, 'connection' => 'pulse'])->trim();

        self::assertSame([['xtrim', [self::STREAM, '5000', true, false]]], $client->calls);
        self::assertSame(['pulse'], $this->connectionNames());
        self::assertSame([], $fake->calls);
    }

    /**
     * Verify a configured age trims the stream approximately to the entries
     * added within it, by their millisecond ids.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function trimKeepsTheEntriesWithinTheConfiguredAge(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampMs(1700000000500));

        $client = $this->streamClient(['xtrim' => [3]]);

        $this->ingest($client, null, ['keep' => '7 days'])->trim();

        self::assertSame([['xtrim', [self::STREAM, (string) (1700000000500 - 7 * 86400000), true, true]]], $client->calls);
    }

    /**
     * Verify an XTRIM that GLIDE fails is reported.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function trimThrowsWhenTheStreamCannotBeTrimmed(): void
    {
        $client = $this->streamClient(['xtrim' => [false]]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Pulse could not run XTRIM on the [' . self::STREAM . '] stream.');

        $this->ingest($client, null, ['keep' => 10])->trim();
    }

    /**
     * Verify a connection that is not GLIDE's is trimmed by Pulse's own ingest.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function trimHandsOtherConnectionsToPulse(): void
    {
        $connection = new LaravelConnectionFake;
        $fake       = new PulseIngestFake;

        (new ValkeyGlideIngest($fake, $this->factory($connection), $this->config()))->trim();

        self::assertSame([['trim', null]], $fake->calls);
        self::assertSame([], $connection->commands);
    }

    /**
     * Verify the stream's key is Pulse's own when no shared prefix is set, and
     * the default connection is used when Pulse names none.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function streamIsUnprefixedWithoutASharedPrefix(): void
    {
        $client = $this->streamClient(['xtrim' => [0]]);

        $config = $this->config(['keep' => 10]);
        $config->set('database.redis.options.prefix', null);
        $config->set('pulse.ingest.redis.connection', null);

        (new ValkeyGlideIngest(new PulseIngestFake, $this->factory(new ValkeyGlideConnection($client, null, ['prefix' => 'own:'])), $config))->trim();

        self::assertSame([['xtrim', ['laravel:pulse:ingest', '10', true, false]]], $client->calls);
        self::assertSame([null], $this->connectionNames());
    }

    /**
     * Provide prefixed connections: one with a prefix of its own, and one with
     * the shared prefix, as Laravel gives a connection without its own, each on
     * a standalone and a cluster client.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function prefixedConnections(): iterable
    {
        yield 'own prefix, standalone' => ['own:', false];

        yield 'own prefix, cluster' => ['own:', true];

        yield 'shared prefix, standalone' => ['app:', false];

        yield 'shared prefix, cluster' => ['app:', true];
    }

    /**
     * Verify ingest, digest and trim each send the stream's key as Pulse builds
     * it on a prefixed connection, without the connection's prefix, as Pulse's
     * raw commands are sent over phpredis, and digest the ingested entry.
     *
     * @param  string  $prefix
     * @param  bool  $cluster
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('prefixedConnections')]
    #[Test]
    public function everyOperationSendsTheStreamKeyAsPulseBuildsItOnAPrefixedConnection(string $prefix, bool $cluster): void
    {
        $entry  = $this->entry();
        $client = $cluster ? new ValkeyGlideClusterFake : new ValkeyGlideFake;

        $client->willReturn('xadd', '1-0');
        $client->willReturn('xrange', ['1-0' => ['data' => serialize($entry)]]);
        $client->willReturn('xdel', 1);
        $client->willReturn('xtrim', 0);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => $prefix]);
        $storage    = new PulseStorageFake;
        $ingest     = new ValkeyGlideIngest(new PulseIngestFake, $this->factory($connection), $this->config(['keep' => 10]));

        $ingest->ingest(new Collection([$entry]));

        self::assertSame(1, $ingest->digest($storage));

        $ingest->trim();

        self::assertEquals([[$entry]], $storage->stored);
        self::assertEquals([[self::STREAM, '*', ['data' => serialize($entry)]]], $client->callsFor('xadd'));
        self::assertSame([[self::STREAM, '-', '+', 1000]], $client->callsFor('xrange'));
        self::assertSame([[self::STREAM, ['1-0']]], $client->callsFor('xdel'));
        self::assertSame([[self::STREAM, '10', true, false]], $client->callsFor('xtrim'));
        self::assertSame($prefix, $connection->getPrefix());
    }

    /**
     * Build an ingest over a connection to the given client.
     *
     * @param  \ValkeyGlide|\ValkeyGlideCluster  $client
     * @param  \Tests\Fakes\PulseIngestFake|null  $fake
     * @param  array<string, mixed>  $settings
     * @return \SineMacula\Valkey\Pulse\ValkeyGlideIngest
     */
    private function ingest(\ValkeyGlide|\ValkeyGlideCluster $client, ?PulseIngestFake $fake = null, array $settings = []): ValkeyGlideIngest
    {
        return new ValkeyGlideIngest($fake ?? new PulseIngestFake, $this->factory(new ValkeyGlideConnection($client)), $this->config($settings));
    }

    /**
     * Build a Redis factory that hands out the connection, recording the names
     * it is asked for.
     *
     * @param  \Illuminate\Redis\Connections\Connection  $connection
     * @return \Tests\Fakes\RecordingRedisFactoryFake
     */
    private function factory(Connection $connection): RecordingRedisFactoryFake
    {
        return $this->redis = new RecordingRedisFactoryFake($connection);
    }

    /**
     * Return the connection names the last Redis factory was asked for.
     *
     * @return list<mixed>
     */
    private function connectionNames(): array
    {
        return $this->redis->names ?? [];
    }

    /**
     * Run the operation, and return the message of the LogicException it
     * throws.
     *
     * @param  \SineMacula\Valkey\Pulse\ValkeyGlideIngest  $ingest
     * @param  string  $operation
     * @return string|null
     *
     * @throws \Throwable
     */
    private function refusal(ValkeyGlideIngest $ingest, string $operation): ?string
    {
        try {
            match ($operation) {
                'ingest' => $ingest->ingest(new Collection([$this->entry()])),
                'digest' => $ingest->digest(new PulseStorageFake),
                default  => $ingest->trim(),
            };
        } catch (\LogicException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Build Pulse's configuration, under the shared prefix `app:`.
     *
     * @param  array<string, mixed>  $settings
     * @return \Illuminate\Config\Repository
     */
    private function config(array $settings = []): Repository
    {
        return new Repository([
            'database' => ['redis' => ['options' => ['prefix' => 'app:']]],
            'pulse'    => ['ingest' => [
                'redis' => ['connection' => $settings['connection'] ?? null, 'chunk' => array_key_exists('chunk', $settings) ? $settings['chunk'] : 1000],
                'trim'  => ['keep' => $settings['keep'] ?? '7 days'],
            ]],
        ]);
    }

    /**
     * Build a Pulse entry.
     *
     * @return \Laravel\Pulse\Entry
     */
    private function entry(): Entry
    {
        return new Entry(1700000000, 'slow_request', 'GET /', 1200);
    }

    /**
     * Build a client answering each stream command with its replies, in turn.
     *
     * @param  array<string, list<mixed>>  $replies
     * @return object{calls: list<array{string, array<int|string, mixed>}>}&\ValkeyGlide
     */
    private function streamClient(array $replies): \ValkeyGlide
    {
        $client = new class extends \ValkeyGlide {
            /** @var list<array{string, array<int|string, mixed>}> The commands received, in order. */
            public array $calls = [];

            /** @var array<string, list<mixed>> The replies to give each command, in turn. */
            public array $replies = [];

            /**
             * Record each command and answer it with its next reply.
             *
             * @param  string  $name
             * @param  array<int|string, mixed>  $arguments
             * @return mixed
             */
            #[\Override]
            public function __call(string $name, array $arguments): mixed
            {
                $this->calls[] = [$name, $arguments];

                return array_shift($this->replies[$name]);
            }
        };

        $client->replies = $replies;

        return $client;
    }
}
