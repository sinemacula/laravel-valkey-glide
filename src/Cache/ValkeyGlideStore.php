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
 * and a cluster transaction must keep every key in one hash slot, so the
 * connection refuses one whose keys hash to different slots. On a cluster each
 * value is written on its own instead, as Laravel does for its cluster
 * connections; elsewhere the transaction is kept.
 *
 * The store also makes cache tags work on GLIDE connections: its tagged cache
 * and tag set (see `ValkeyGlideTaggedCache`) keep the tags' sorted sets in the
 * shapes GLIDE takes, and `flushStaleTags()` finds those sets with a SCAN of
 * the server's primary, or of every primary of a cluster.
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
     * Off a cluster the items are written in one transaction, which throws a
     * `\SineMacula\Valkey\Exceptions\ConnectionException` when GLIDE fails it
     * as a whole, and a `\LogicException` when a transaction callback already
     * owns the connection's batch, as its `exec()` is then refused. On a
     * cluster a write GLIDE fails throws a
     * `\SineMacula\Valkey\Exceptions\CommandException`, and the items after it
     * are not written.
     *
     * @param  array<array-key, mixed>  $values
     * @param  int  $seconds
     * @return bool
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     * @throws \LogicException
     * @throws \SineMacula\Valkey\Exceptions\CommandException
     *
     * @phpstan-ignore throws.unusedType, throws.unusedType, throws.unusedType
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
     * their glob characters escaped. The SCAN reads the primaries, whatever the
     * connection's read_from: a standalone server's primary, or every primary
     * of a cluster in turn, as the connection's `scan()` does.
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
     * Scan the server's primary, or every primary of a cluster, for the sorted
     * sets matching the pattern, from cursor '0' until it is '0' again.
     *
     * The connection refuses each page while a pipeline or transaction is open
     * on it; the caller may open a batch between pages.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $pattern
     * @param  int  $count
     * @return \Generator<int, string>
     *
     * @throws \Throwable
     */
    private function scanTagSets(ValkeyGlideConnection $connection, string $pattern, int $count): \Generator
    {
        $cluster = $connection->client() instanceof \ValkeyGlideCluster;
        $cursor  = '0';

        do {
            [$cursor, $keys] = $cluster
                ? $connection->scan($cursor, ['match' => $pattern, 'count' => $count, 'type' => 'zset'])
                : $connection->scanOnPrimary($cursor, $pattern, $count, 'zset');

            foreach ($keys as $key) {
                yield $key;
            }
        } while ($cursor !== '0');
    }
}
