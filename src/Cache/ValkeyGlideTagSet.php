<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Cache;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\RedisTagSet;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Support\Cast;

/**
 * Laravel's Redis tag set, with its upkeep written for GLIDE connections.
 *
 * Laravel's tag set reads each tag's sorted set with a phpredis-style
 * `zscan()`, records increments with a string `NX` option and prunes through
 * `pipeline()` with a callback, whose replies it ignores; GLIDE takes the scan
 * cursor by reference and ignores a string option. Over a GLIDE connection each
 * of those paths is sent in the shape GLIDE takes, without opening a batch, and
 * outside a batch a `false` reply, which is how GLIDE reports every failure,
 * throws. Over any other connection Laravel's own code runs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValkeyGlideTagSet extends RedisTagSet
{
    /** @var int The COUNT hint of each ZSCAN, as Laravel's. */
    private const int SCAN_COUNT = 1000;

    /** @var int The number of entries each step of a flush deletes, as Laravel's. */
    private const int FLUSH_CHUNK_SIZE = 1000;

    /** @var string The script that deletes a chunk of entries and removes them from each tag's sorted set. */
    private const string FLUSH_SCRIPT = <<<'EOD'
        local entries = #ARGV
        local deleted = redis.call('DEL', unpack(KEYS, 1, entries))

        for index = entries + 1, #KEYS do
            redis.call('ZREM', KEYS[index], unpack(ARGV))
        end

        return deleted
        EOD;

    /**
     * Create a new tag set for the given store and tag names.
     *
     * @param  \Illuminate\Cache\RedisStore  $redisStore
     * @param  array<array-key, mixed>  $names
     * @return void
     */
    public function __construct(

        /** The Redis store the tag set records entries through. */
        private readonly RedisStore $redisStore,

        // The names of the tags in the set.
        array $names = [],
    ) {
        parent::__construct($redisStore, $names);
    }

    /**
     * Add a reference entry to the tag set's underlying sorted set.
     *
     * Over a GLIDE connection an update condition, such as the `NX` tagged
     * increments use, is sent as GLIDE's options array: GLIDE ignores the
     * string form and writes nothing.
     *
     * @param  string  $key
     * @param  int|null  $ttl
     * @param  mixed  $updateWhen
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    #[\Override]
    public function addEntry(string $key, ?int $ttl = null, mixed $updateWhen = null): void
    {
        $connection = $this->glideConnection();

        if ($connection === null) {
            parent::addEntry($key, $ttl, $updateWhen);

            return;
        }

        $score     = $ttl === null ? -1 : Carbon::now()->addSeconds($ttl)->getTimestamp();
        $condition = Cast::toNonEmptyString($updateWhen);

        foreach ($this->tagSetKeys() as $setKey) {
            $parameters = $condition !== null ? [$setKey, [$condition], $score, $key] : [$setKey, $score, $key];

            $this->run($connection, 'zadd', $parameters, $setKey);
        }
    }

    /**
     * Get all of the cache entry keys for the tag set.
     *
     * Over a GLIDE connection each tag's sorted set is read with ZSCAN from
     * cursor '0' until GLIDE writes '0' back, so no command runs until the
     * collection is iterated.
     *
     * @return \Illuminate\Support\LazyCollection<int, string>
     */
    #[\Override]
    public function entries(): LazyCollection
    {
        $connection = $this->glideConnection();

        if ($connection === null) {
            return parent::entries();
        }

        return new LazyCollection(fn (): \Generator => $this->scanEntries($connection));
    }

    /**
     * Remove the stale entries from the tag set.
     *
     * Over a GLIDE connection each tag's expired entries are removed with its
     * own ZREMRANGEBYSCORE, since each tag's sorted set may live in a different
     * hash slot. Entries stored forever, at score -1, are kept.
     *
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    #[\Override]
    public function flushStaleEntries(): void
    {
        $connection = $this->glideConnection();

        if ($connection === null) {
            parent::flushStaleEntries();

            return;
        }

        $now = (string) Carbon::now()->getTimestamp();

        foreach ($this->tagSetKeys() as $setKey) {
            $this->run($connection, 'zremrangebyscore', [$setKey, '0', $now], $setKey);
        }
    }

    /**
     * Reset all tags in the set.
     *
     * Once a tag set is reset its entries can no longer be reached. The tag ids
     * of a Redis tag set are fixed, so over a GLIDE connection the entries are
     * deleted instead, a thousand at a time, and removed from every tag's
     * sorted set. On a standalone server each chunk is deleted and removed in
     * one script, so no write lands between the two, as with Laravel's Lua
     * flush over phpredis and Predis. On a cluster, where the entries and tags
     * live in different hash slots and no script can reach them all, each chunk
     * is deleted with one DEL, which GLIDE splits across hash slots, then
     * removed from each tag's sorted set with ZREM. Either way the entries are
     * deleted first, so an interrupted reset leaves only records of deleted
     * keys, and running it again completes it. A sorted set left empty is
     * removed by the server.
     *
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    #[\Override]
    public function reset(): void
    {
        $connection = $this->glideConnection();

        if ($connection === null) {
            parent::reset();

            return;
        }

        $prefix  = $this->redisStore->getPrefix();
        $setKeys = $this->tagSetKeys();

        foreach ($this->entries()->chunk(self::FLUSH_CHUNK_SIZE) as $chunk) {
            $members = array_values($chunk->all());
            $keys    = array_map(static fn (string $member): string => $prefix . $member, $members);

            if ($connection->isCluster()) {
                $this->deleteAcrossSlots($connection, $keys, $members, $setKeys);
            } else {
                $this->deleteInScript($connection, $keys, $members, $setKeys);
            }
        }
    }

    /**
     * Delete a chunk of entries and remove them from each tag's sorted set in
     * one script, which the server runs without interleaving another command.
     *
     * The script's keys are the entries, then the tags' sorted sets, so it
     * names every key it touches, and its arguments are the entries' members,
     * so no prefix is stripped from a key with a Lua pattern. It returns the
     * number of entries deleted, never nil, as GLIDE's raw command reports a
     * nil reply as `false`, the reply of a failure.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  list<string>  $keys
     * @param  list<string>  $members
     * @param  array<array-key, string>  $setKeys
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private function deleteInScript(ValkeyGlideConnection $connection, array $keys, array $members, array $setKeys): void
    {
        $scriptKeys = [...$keys, ...array_values($setKeys)];

        $this->run($connection, 'eval', [self::FLUSH_SCRIPT, count($scriptKeys), ...$scriptKeys, ...$members], $keys[0], count($scriptKeys) - 1);
    }

    /**
     * Delete a chunk of entries with one DEL, then remove them from each tag's
     * sorted set with its own ZREM, so no command spans hash slots.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  list<string>  $keys
     * @param  list<string>  $members
     * @param  array<array-key, string>  $setKeys
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private function deleteAcrossSlots(ValkeyGlideConnection $connection, array $keys, array $members, array $setKeys): void
    {
        $this->run($connection, 'del', $keys, $keys[0], count($keys) - 1);

        foreach ($setKeys as $setKey) {
            $this->run($connection, 'zrem', [$setKey, ...$members], $setKey);
        }
    }

    /**
     * Get the store's connection when it is a GLIDE connection.
     *
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection|null
     */
    private function glideConnection(): ?ValkeyGlideConnection
    {
        $connection = $this->redisStore->connection();

        return $connection instanceof ValkeyGlideConnection ? $connection : null;
    }

    /**
     * Get the cache-prefixed key of each tag's sorted set.
     *
     * @return array<array-key, string>
     */
    private function tagSetKeys(): array
    {
        $prefix = $this->redisStore->getPrefix();

        /** @var array<array-key, string> $tagIds */
        $tagIds = $this->tagIds();

        return array_map(static fn (string $tagId): string => $prefix . $tagId, $tagIds);
    }

    /**
     * Scan every tag's sorted set for its members, each from cursor '0' until
     * GLIDE writes '0' back.
     *
     * Members PHP turned into integer array keys are yielded as strings.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return \Generator<int, string>
     *
     * @throws \UnexpectedValueException
     */
    private function scanEntries(ValkeyGlideConnection $connection): \Generator
    {
        foreach ($this->tagSetKeys() as $setKey) {
            $cursor = '0';

            do {
                [$cursor, $page] = $this->scanPage($connection, $setKey, $cursor);

                foreach (array_keys($page) as $member) {
                    yield (string) $member;
                }
            } while ($cursor !== '0');
        }
    }

    /**
     * Read one page of a tag's sorted set, from the given cursor.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $setKey
     * @param  string  $cursor
     * @return array{string, array<array-key, mixed>}
     *
     * @throws \UnexpectedValueException
     */
    private function scanPage(ValkeyGlideConnection $connection, string $setKey, string $cursor): array
    {
        $page = $connection->command('zscan', [$setKey, &$cursor, '*', self::SCAN_COUNT]);

        if (!is_array($page)) {
            throw new \UnexpectedValueException(sprintf('Expected array response from %s on [%s], received [%s].', 'zscan', $setKey, get_debug_type($page)));
        }

        return [$cursor, $page];
    }

    /**
     * Run a write, throwing when GLIDE reports it failed.
     *
     * GLIDE returns `false` for every failure, a server error and a transport
     * fault alike, so a `false` reply cannot be passed over. Inside a pipeline
     * or transaction on the connection the write joins the batch, so its reply,
     * a failure included, comes back in the batch's results instead.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @param  string  $key
     * @param  int  $moreKeys
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private function run(ValkeyGlideConnection $connection, string $command, array $parameters, string $key, int $moreKeys = 0): void
    {
        if ($connection->command($command, $parameters) !== false) {
            return;
        }

        $more = $moreKeys !== 0 ? sprintf(' and %d more', $moreKeys) : '';

        throw new \UnexpectedValueException(sprintf('Valkey GLIDE failed to run %s on [%s]%s.', strtoupper($command), $key, $more));
    }
}
