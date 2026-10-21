<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Cache;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Support\Cast;

/**
 * Laravel's Redis cache store, made safe for GLIDE connections.
 *
 * `RedisStore::putMany()` writes through MULTI/EXEC unless its connection is
 * one of Laravel's own cluster connections. A GLIDE cluster connection is not,
 * so keys hashing to different slots fail the transaction with `CROSSSLOT`
 * while `putMany()` still reports success. On a cluster each value is written
 * on its own instead, as Laravel does for its cluster connections.
 *
 * The store also makes cache tags work on GLIDE connections: its tagged cache
 * and tag set (see `ValkeyGlideTaggedCache`) keep the tags' sorted sets in the
 * shapes GLIDE takes, and `flushStaleTags()` finds those sets with a SCAN of
 * the server, or of every primary of a cluster.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValkeyGlideStore extends RedisStore
{
    /** @var int The default COUNT hint of each SCAN, as Laravel's. */
    private const int SCAN_COUNT = 1000;

    /** @var string The SCAN pattern of a tag's sorted set, after the prefixes. */
    private const string TAG_SET_PATTERN = 'tag:*:entries';

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param  array<array-key, mixed>  $values
     * @param  int  $seconds
     * @return bool
     */
    #[\Override]
    public function putMany(array $values, mixed $seconds)
    {
        if (!$this->connection()->client() instanceof \ValkeyGlideCluster) {
            return parent::putMany($values, $seconds);
        }

        $manyResult = null;

        foreach ($values as $key => $value) {
            $result = $this->put((string) $key, $value, $seconds);

            $manyResult = $manyResult === null ? $result : $result && $manyResult;
        }

        return $manyResult ?: false;
    }

    /**
     * Begin executing a new tags operation.
     *
     * The connection is not resolved here: each tag operation checks it when it
     * runs, and runs Laravel's own code unless it is a GLIDE connection.
     *
     * @param  mixed  $names
     * @return \SineMacula\Valkey\Cache\ValkeyGlideTaggedCache
     */
    #[\Override]
    public function tags(mixed $names): ValkeyGlideTaggedCache
    {
        return new ValkeyGlideTaggedCache(
            $this,
            new ValkeyGlideTagSet($this, is_array($names) ? $names : func_get_args()),
        );
    }

    /**
     * Get a collection of all of the cache tags currently being used.
     *
     * Over a GLIDE connection the tags' sorted sets are found with a SCAN for
     * sorted sets whose pattern carries the connection and cache prefixes, with
     * their glob characters escaped. On a cluster every primary is scanned with
     * a `ClusterScanCursor`.
     *
     * @param  mixed  $chunkSize
     * @return \Illuminate\Support\LazyCollection<int, string>
     */
    #[\Override]
    protected function currentTags(mixed $chunkSize = 1000): LazyCollection
    {
        $connection = $this->connection();

        if (!$connection instanceof ValkeyGlideConnection) {
            return parent::currentTags($chunkSize);
        }

        $prefix  = $connection->getPrefix() . $this->getPrefix();
        $pattern = self::escapeGlob($prefix) . self::TAG_SET_PATTERN;
        $count   = Cast::toNonNegativeInt($chunkSize) ?? self::SCAN_COUNT;

        return (new LazyCollection(fn (): \Generator => $this->scanTagSets($connection, $pattern, $count)))
            ->map(static fn (string $tagKey): string => Str::match('/^' . preg_quote($prefix, '/') . 'tag:(.*):entries$/', $tagKey));
    }

    /**
     * Escape the glob characters of a SCAN pattern literal.
     *
     * @param  string  $value
     * @return string
     */
    private static function escapeGlob(string $value): string
    {
        return addcslashes($value, '\*?[]');
    }

    /**
     * Scan the server, or every primary of a cluster, for the keys matching the
     * pattern.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $pattern
     * @param  int  $count
     * @return \Generator<int, string>
     *
     * @throws \UnexpectedValueException
     */
    private function scanTagSets(ValkeyGlideConnection $connection, string $pattern, int $count): \Generator
    {
        $client = $connection->client();

        if ($client instanceof \ValkeyGlideCluster) {
            return $this->scanCluster($client, $pattern, $count);
        }

        return $this->scanServer($connection, $pattern, $count);
    }

    /**
     * Scan every primary of a cluster for the keys matching the pattern.
     *
     * The cluster SCAN is sent to GLIDE directly: a `ClusterScanCursor` among
     * the parameters of a command event breaks listeners that turn parameters
     * into strings. Each call needs a new cursor built from the id the last one
     * was given, since passing the same cursor again rescans the first node,
     * and a new cursor reports itself finished before any scan, so the check
     * comes after it.
     *
     * @param  \ValkeyGlideCluster  $client
     * @param  string  $pattern
     * @param  int  $count
     * @return \Generator<int, string>
     *
     * @throws \UnexpectedValueException
     */
    private function scanCluster(\ValkeyGlideCluster $client, string $pattern, int $count): \Generator
    {
        $cursor = new \ClusterScanCursor;

        do {
            $keys = $client->scan($cursor, $pattern, $count, 'zset');

            if (!is_array($keys)) {
                throw new \UnexpectedValueException(sprintf('Expected array response from %s on [%s], received [%s].', 'scan', $pattern, get_debug_type($keys)));
            }

            foreach ($keys as $key) {
                yield $key;
            }

            $cursor = new \ClusterScanCursor($cursor->getNextCursor());
        } while (!$cursor->isFinished());
    }

    /**
     * Scan a standalone server for the keys matching the pattern, from cursor
     * '0' until GLIDE writes '0' back.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $pattern
     * @param  int  $count
     * @return \Generator<int, string>
     *
     * @throws \UnexpectedValueException
     */
    private function scanServer(ValkeyGlideConnection $connection, string $pattern, int $count): \Generator
    {
        $cursor = '0';

        do {
            [$cursor, $keys] = $this->scanPage($connection, $cursor, $pattern, $count);

            foreach ($keys as $key) {
                yield $key;
            }
        } while ($cursor !== '0');
    }

    /**
     * Read one page of a standalone SCAN, from the given cursor.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $cursor
     * @param  string  $pattern
     * @param  int  $count
     * @return array{string, array<int, string>}
     *
     * @throws \UnexpectedValueException
     */
    private function scanPage(ValkeyGlideConnection $connection, string $cursor, string $pattern, int $count): array
    {
        /** @var array<int, string>|false $keys */
        $keys = $connection->command('scan', [&$cursor, $pattern, $count, 'zset']);

        if (!is_array($keys)) {
            throw new \UnexpectedValueException(sprintf('Expected array response from %s on [%s], received [%s].', 'scan', $pattern, get_debug_type($keys)));
        }

        return [$cursor, $keys];
    }
}
