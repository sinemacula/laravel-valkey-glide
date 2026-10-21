<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Cache\ValkeyGlideTaggedCache;
use SineMacula\Valkey\Cache\ValkeyGlideTagSet;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\TestCase;

/**
 * External checks that a tag flush deletes each chunk of entries, and removes
 * them from the tags' sorted sets, in one script on a standalone server, and
 * with no script on a cluster.
 *
 * The standalone cases run against the standalone server; the cluster cases are
 * skipped unless VALKEY_GLIDE_TEST_CLUSTER_HOST and
 * VALKEY_GLIDE_TEST_CLUSTER_PORT are set. Physical keys are read through an
 * unprefixed connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideTagSet::class)]
#[Group('external')]
final class ValkeyGlideAtomicTagFlushExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string The connection prefix, with Lua pattern characters in it. */
    private const string CONNECTION_PREFIX = 'lvkglide:atomic:%a.(b)-[c]^$*+?:';

    /** @var string The cache prefix, with Lua pattern characters in it. */
    private const string CACHE_PREFIX = 'cache-%d.';

    /** @var int The number of entries a chunk of a flush deletes. */
    private const int CHUNK_SIZE = 1000;

    /** @var int The number of entries the race rewrites. */
    private const int RACE_ENTRIES = 300;

    /** @var int The number of flushes raced against the rewrites. */
    private const int RACE_ROUNDS = 25;

    /** @var int The longest the rewriting process runs, in seconds. */
    private const int RACE_SECONDS = 120;

    /** @var int The longest wait for the rewriting process to connect, in microseconds. */
    private const int READY_TIMEOUT = 10000000;

    /** @var array<string, \SineMacula\Valkey\Connections\ValkeyGlideConnection> Unprefixed connections, by topology. */
    private array $physicalConnections = [];

    /** @var array<string, array<string, true>> Physical keys to delete after the test, by topology. */
    private array $trackedKeys = [];

    /** @var resource|null The rewriting process, while it runs. */
    private mixed $rewriter = null;

    /** @var array<int, resource> The rewriting process' output and error pipes, by descriptor. */
    private array $rewriterPipes = [];

    /**
     * Ensure extension preconditions are met before each external test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertExtensionIsLoaded();
    }

    /**
     * Stop the rewriting process, delete the physical keys the test wrote and
     * close the unprefixed connections.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (is_resource($this->rewriter)) {
            proc_terminate($this->rewriter);
            proc_close($this->rewriter);
        }

        foreach ($this->trackedKeys as $topology => $keys) {
            foreach (array_chunk(array_keys($keys), self::CHUNK_SIZE) as $chunk) {
                $this->physical($topology)->command('del', $chunk);
            }
        }

        foreach ($this->physicalConnections as $connection) {
            $connection->disconnect();
        }

        $this->rewriter            = null;
        $this->rewriterPipes       = [];
        $this->trackedKeys         = [];
        $this->physicalConnections = [];

        parent::tearDown();
    }

    /**
     * Provide each topology with the commands a chunk of a two-tag flush sends.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function chunkCommands(): iterable
    {
        yield 'standalone' => [self::STANDALONE, ['eval']];

        yield 'cluster' => [self::CLUSTER, ['del', 'zrem', 'zrem']];
    }

    /**
     * Verify a flush of two tags deletes their entries in chunks of a thousand,
     * each with one script on a standalone server and with a DEL and a ZREM per
     * tag on a cluster, under prefixes full of Lua pattern characters.
     *
     * @param  string  $topology
     * @param  list<string>  $perChunk
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('chunkCommands')]
    #[Test]
    public function flushDeletesEachChunkWithTheCommandsOfItsTopology(string $topology, array $perChunk): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        [$tagA, $tagB] = [$this->uniqueName('a'), $this->uniqueName('b')];

        $prefix    = self::CONNECTION_PREFIX . self::CACHE_PREFIX;
        $cache     = $this->tagged($topology, [$tagA, $tagB]);
        $setKeys   = $this->track($topology, $prefix . 'tag:' . $tagA . ':entries', $prefix . 'tag:' . $tagB . ':entries');
        $valueKeys = [];

        foreach (range(1, 1500) as $index) {
            self::assertTrue($cache->put('item-' . $index, 'v', 600));

            $valueKeys[] = $prefix . $cache->taggedItemKey('item-' . $index);
        }

        $this->track($topology, ...$valueKeys);

        $commands = $this->recordWrites($this->connectionOf($topology), fn (): bool => $this->tagged($topology, [$tagA, $tagB])->flush());

        self::assertSame([...$perChunk, ...$perChunk], $commands);
        self::assertSame(0, $this->countExisting($topology, $valueKeys));
        self::assertSame(0, $this->physical($topology)->command('exists', $setKeys));
    }

    /**
     * Provide each topology.
     *
     * @return iterable<string, array{string}>
     */
    public static function topologies(): iterable
    {
        yield 'standalone' => [self::STANDALONE];

        yield 'cluster' => [self::CLUSTER];
    }

    /**
     * Verify a flush whose entries' values are already gone, as when they
     * expired, deletes nothing yet succeeds and removes their tag records, so a
     * chunk that deletes no key is not taken for a failure.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function flushOfEntriesWhoseValuesAreGoneRemovesTheirRecords(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        [$tagA, $tagB] = [$this->uniqueName('a'), $this->uniqueName('b')];

        $prefix    = self::CONNECTION_PREFIX . self::CACHE_PREFIX;
        $cache     = $this->tagged($topology, [$tagA, $tagB]);
        $setKeys   = $this->track($topology, $prefix . 'tag:' . $tagA . ':entries', $prefix . 'tag:' . $tagB . ':entries');
        $valueKeys = [];

        foreach (['first', 'second'] as $key) {
            self::assertTrue($cache->put($key, 'v', 600));

            $valueKeys[] = $prefix . $cache->taggedItemKey($key);
        }

        $this->track($topology, ...$valueKeys);

        foreach ($valueKeys as $valueKey) {
            self::assertSame(1, $this->physical($topology)->command('del', [$valueKey]));
        }

        self::assertSame(2, $this->physical($topology)->command('exists', $setKeys));
        self::assertTrue($this->tagged($topology, [$tagA, $tagB])->flush());
        self::assertSame(0, $this->physical($topology)->command('exists', $setKeys));
    }

    /**
     * Verify a standalone flush keeps the tag record of every entry rewritten
     * while it runs, so the next flush still reaches it.
     *
     * A second process rewrites the entries, each value with its tag record in
     * one transaction, while the entries are flushed again and again. A flush
     * that deleted a value and removed its record in separate commands would
     * let a rewrite land between the two and leave a cached value without a
     * record; one script per chunk leaves no such gap.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function standaloneFlushKeepsTheRecordOfAnEntryRewrittenDuringIt(): void
    {
        $tag     = $this->uniqueName('race');
        $prefix  = self::CONNECTION_PREFIX . self::CACHE_PREFIX;
        $cache   = $this->tagged(self::STANDALONE, [$tag]);
        $keys    = array_map(static fn (int $index): string => 'item-' . $index, range(1, self::RACE_ENTRIES));
        $members = array_map($cache->taggedItemKey(...), $keys);
        $setKey  = $this->track(self::STANDALONE, $prefix . 'tag:' . $tag . ':entries')[0];
        $stopKey = $this->uniqueName('stop');

        $readyKey = $this->startRewriter($prefix, $setKey, $members, $stopKey);

        $this->track(self::STANDALONE, $stopKey, $readyKey, ...array_map(static fn (string $member): string => $prefix . $member, $members));

        $orphans = [];

        for ($round = 1; $round <= self::RACE_ROUNDS; $round++) {
            foreach ($keys as $key) {
                $cache->put($key, 'v', 600);
            }

            self::assertTrue($this->tagged(self::STANDALONE, [$tag])->flush());

            foreach ($this->untrackedMembers($prefix, $setKey, $members) as $member) {
                $orphans[] = sprintf('round %d: %s', $round, $member);
            }
        }

        $rewrites = $this->stopRewriter($stopKey);

        self::assertSame([], $orphans);
        self::assertGreaterThan(self::RACE_ENTRIES, $rewrites);
    }

    /**
     * Configure a standalone and a cluster connection and a redis cache store
     * over each, all with prefixes full of Lua pattern characters.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.options', []);
        $app['config']->set('database.redis.' . self::STANDALONE, $this->standaloneConfig() + ['prefix' => self::CONNECTION_PREFIX]);
        $app['config']->set('database.redis.clusters.' . self::CLUSTER, [$this->clusterSeed() + ['prefix' => self::CONNECTION_PREFIX]]);

        foreach ([self::STANDALONE, self::CLUSTER] as $topology) {
            $app['config']->set('cache.stores.' . $topology, [
                'driver'     => 'redis',
                'connection' => $topology,
                'prefix'     => self::CACHE_PREFIX,
            ]);
        }
    }

    /**
     * Start the process that rewrites the entries, wait until it is connected,
     * and return the key it sets once connected.
     *
     * @param  string  $prefix
     * @param  string  $setKey
     * @param  list<string>  $members
     * @param  string  $stopKey
     * @return string
     *
     * @throws \JsonException
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function startRewriter(string $prefix, string $setKey, array $members, string $stopKey): string
    {
        $readyKey = $this->uniqueName('ready');
        $process  = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/rewrite-tagged-entries.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        self::assertIsResource($process);

        $this->rewriter = $process;

        fwrite($pipes[0], json_encode([
            'config'      => $this->standaloneConfig(),
            'valuePrefix' => $prefix,
            'setKey'      => $setKey,
            'members'     => $members,
            'readyKey'    => $readyKey,
            'stopKey'     => $stopKey,
            'seconds'     => self::RACE_SECONDS,
        ], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);

        $this->rewriterPipes = [1 => $pipes[1], 2 => $pipes[2]];
        $waited              = 0;

        while ($this->physical(self::STANDALONE)->command('exists', [$readyKey]) === 0) {
            if ($waited >= self::READY_TIMEOUT) {
                proc_terminate($process);

                self::fail('The rewriting process did not connect: ' . stream_get_contents($pipes[2]));
            }

            usleep(10000);
            $waited += 10000;
        }

        return $readyKey;
    }

    /**
     * Stop the rewriting process and return how many rewrites it made.
     *
     * @param  string  $stopKey
     * @return int
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function stopRewriter(string $stopKey): int
    {
        $this->physical(self::STANDALONE)->command('set', [$stopKey, '1', 'EX', 60]);

        $output = (string) stream_get_contents($this->rewriterPipes[1]);
        $errors = (string) stream_get_contents($this->rewriterPipes[2]);
        $status = is_resource($this->rewriter) ? proc_close($this->rewriter) : -1;

        $this->rewriter      = null;
        $this->rewriterPipes = [];

        self::assertSame(0, $status, 'The rewriting process failed: ' . $errors);
        self::assertMatchesRegularExpression('/^\d+$/', $output, 'The rewriting process failed: ' . $errors);

        return (int) $output;
    }

    /**
     * Find the members whose value key exists without a record in the tag's
     * sorted set.
     *
     * Each value is read before its record. Every write of a value writes its
     * record with it, and only a flush removes a record, so with no flush
     * running a value found without its record was left untracked by one.
     *
     * @param  string  $prefix
     * @param  string  $setKey
     * @param  list<string>  $members
     * @return list<string>
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function untrackedMembers(string $prefix, string $setKey, array $members): array
    {
        $physical  = $this->physical(self::STANDALONE);
        $untracked = [];

        foreach ($members as $member) {
            if ($physical->command('exists', [$prefix . $member]) === 0 || $physical->command('zscore', [$setKey, $member]) !== false) {
                continue;
            }

            $untracked[] = $member;
        }

        return $untracked;
    }

    /**
     * Run the callback and return the write commands the connection sent, in
     * order.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  \Closure(): mixed  $callback
     * @return list<string>
     */
    private function recordWrites(ValkeyGlideConnection $connection, \Closure $callback): array
    {
        $commands   = [];
        $dispatcher = new Dispatcher;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$commands): void {
            if ($event->command === 'zscan') {
                return;
            }

            $commands[] = $event->command;
        });

        $connection->setEventDispatcher($dispatcher);

        try {
            $callback();
        } finally {
            $connection->unsetEventDispatcher();
        }

        return $commands;
    }

    /**
     * Get the unprefixed connection to the topology, to read and delete
     * physical keys.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function physical(string $topology): ValkeyGlideConnection
    {
        return $this->physicalConnections[$topology] ??= $this->connectTo($topology);
    }

    /**
     * Get the GLIDE connection of the topology's cache store.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     */
    private function connectionOf(string $topology): ValkeyGlideConnection
    {
        $store = $this->repository($topology)->getStore();

        self::assertInstanceOf(RedisStore::class, $store);

        $connection = $store->connection();

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);

        return $connection;
    }

    /**
     * Record physical keys to delete after the test, and return them.
     *
     * @param  string  $topology
     * @param  string  ...$keys
     * @return list<string>
     */
    private function track(string $topology, string ...$keys): array
    {
        foreach ($keys as $key) {
            $this->trackedKeys[$topology][$key] = true;
        }

        return array_values($keys);
    }

    /**
     * Count how many of the physical keys exist, a thousand at a time.
     *
     * @param  string  $topology
     * @param  list<string>  $keys
     * @return int
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function countExisting(string $topology, array $keys): int
    {
        $existing = 0;

        foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
            $count = $this->physical($topology)->command('exists', $chunk);

            self::assertIsInt($count);

            $existing += $count;
        }

        return $existing;
    }

    /**
     * Resolve the topology's cache store as the concrete cache repository.
     *
     * @param  string  $topology
     * @return \Illuminate\Cache\Repository
     *
     * @throws \UnexpectedValueException
     */
    private function repository(string $topology): Repository
    {
        $repository = Cache::store($topology);

        if ($repository instanceof Repository) {
            return $repository;
        }

        throw new \UnexpectedValueException(sprintf('Expected cache store [%s] to be [%s], received [%s].', $topology, Repository::class, get_debug_type($repository)));
    }

    /**
     * Resolve the GLIDE-aware tagged cache for the given tags of the topology's
     * store.
     *
     * @param  string  $topology
     * @param  array<int, string>  $tags
     * @return \SineMacula\Valkey\Cache\ValkeyGlideTaggedCache
     *
     * @throws \UnexpectedValueException
     */
    private function tagged(string $topology, array $tags): ValkeyGlideTaggedCache
    {
        $cache = $this->repository($topology)->tags($tags);

        if ($cache instanceof ValkeyGlideTaggedCache) {
            return $cache;
        }

        throw new \UnexpectedValueException(sprintf('Expected tagged cache to be [%s], received [%s].', ValkeyGlideTaggedCache::class, get_debug_type($cache)));
    }
}
