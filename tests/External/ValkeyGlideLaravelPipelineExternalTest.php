<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\RedisTaggedCache;
use Illuminate\Cache\RedisTagSet;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Connections\ClusterRouter;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\TestCase;

/**
 * External checks for the Laravel features that run Redis pipelines and
 * transactions: queue bulk pushes, the stale-entry flush of Laravel's own tag
 * set, cache putMany(), and the package's tagged cache inside a pipeline.
 *
 * Every test runs against the standalone server and, when
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT are set,
 * against the cluster; the cluster cases are skipped otherwise.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[CoversClass(ValkeyGlideStore::class)]
#[Group('external')]
final class ValkeyGlideLaravelPipelineExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var array<string, string> Key prefix configured on each Redis connection. */
    private const array CONNECTION_PREFIXES = [
        self::STANDALONE => 'lvkglide:batch:conn:',
        self::CLUSTER    => 'lvkglide:batch:cluster:',
    ];

    /** @var string Prefix configured on both Redis cache stores. */
    private const string CACHE_STORE_PREFIX = 'lvkglide:batch:cache:';

    /** @var list<\SineMacula\Valkey\Connections\ValkeyGlideConnection> Unprefixed connections to close after the test. */
    private array $physicalConnections = [];

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
     * Restore the real clock after tests that move it.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach ($this->physicalConnections as $connection) {
            $connection->disconnect();
        }

        $this->physicalConnections = [];

        parent::tearDown();
    }

    /**
     * Verify Queue::bulk(), which nests a transaction in a pipeline and pushes
     * every job with EVAL on the connection, queues every job, including a
     * delayed one, and leaves them for a worker to pop in order.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function queueBulkPushesEveryJobForWorkersToPop(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $queue    = $this->redisQueue($topology);
        $physical = $this->physical($topology);
        $name     = $this->uniqueQueueName($topology);
        $queueKey = self::CONNECTION_PREFIXES[$topology] . 'queues:' . $name;

        $queue->bulk(['job-a', 'job-b', CallQueuedClosure::create(static fn () => null)->delay(600)], ['k' => 'v'], $name);

        self::assertSame(3, $queue->size($name));
        self::assertSame(2, $physical->command('llen', [$queueKey]));
        self::assertSame(1, $physical->command('zcard', [$queueKey . ':delayed']));

        foreach (['job-a', 'job-b'] as $expected) {
            $job = $queue->pop($name);

            self::assertInstanceOf(RedisJob::class, $job);
            self::assertSame($expected, $job->payload()['job']);
            self::assertSame(['k' => 'v'], $job->payload()['data']);

            $job->delete();
        }

        self::assertNull($queue->pop($name));
        self::assertSame(1, $queue->size($name));

        $queue->clear($name);
    }

    /**
     * Verify the stale-entry flush of Laravel's own tag set, which pipelines
     * ZREMRANGEBYSCORE over the tags' prefixed entry sets, removes only the
     * expired entries.
     *
     * The package's `redis` cache store prunes without a pipeline, so this runs
     * Laravel's own Redis store and tag set over the GLIDE connection.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function cacheTagsFlushStaleRemovesExpiredEntries(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $cache    = $this->laravelCacheStore($topology);
        $physical = $this->physical($topology);
        $tags     = [$this->uniqueName('tag-a'), $this->uniqueName('tag-b')];
        $prefix   = self::CONNECTION_PREFIXES[$topology] . self::CACHE_STORE_PREFIX;

        self::assertNotInstanceOf(ValkeyGlideStore::class, $cache->getStore());
        self::assertTrue($cache->tags($tags)->put('stale', 'v', 60));
        self::assertTrue($cache->tags($tags)->put('fresh', 'v', 3600));

        foreach ($tags as $tag) {
            self::assertSame(2, $physical->command('zcard', [$prefix . 'tag:' . $tag . ':entries']));
        }

        Carbon::setTestNow(Carbon::now()->addSeconds(120));

        $tagged = $cache->tags($tags);

        self::assertInstanceOf(RedisTaggedCache::class, $tagged);
        self::assertSame(RedisTagSet::class, $tagged->getTags()::class);
        self::assertTrue($tagged->flushStale());

        foreach ($tags as $tag) {
            self::assertSame(1, $physical->command('zcard', [$prefix . 'tag:' . $tag . ':entries']));
            self::assertSame(0, $physical->command('exists', ['tag:' . $tag . ':entries']));
        }

        self::assertSame('v', $cache->tags($tags)->get('fresh'));
        self::assertTrue($cache->tags($tags)->forget('stale'));
        self::assertTrue($cache->tags($tags)->forget('fresh'));
        self::assertNull($cache->tags($tags)->get('fresh'));

        foreach ($tags as $tag) {
            $physical->command('del', [$prefix . 'tag:' . $tag . ':entries']);
        }
    }

    /**
     * Verify the package's tagged cache inside a pipeline on its own
     * connection: tagged writes join the batch and land when it is sent, while
     * a tag flush, which reads the tags' sorted sets with ZSCAN, and a prune of
     * the tags, which finds them with SCAN, are refused before anything is sent
     * and discard the batch, leaving the entries.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function cacheTagsInsideAPipelineWriteThroughTheBatchAndRefuseAFlush(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $cache    = $this->cacheStore($topology);
        $physical = $this->physical($topology);
        $tags     = [$this->uniqueName('pipe-tag-a'), $this->uniqueName('pipe-tag-b')];
        $prefix   = self::CONNECTION_PREFIXES[$topology] . self::CACHE_STORE_PREFIX;
        $store    = $cache->getStore();

        self::assertInstanceOf(ValkeyGlideStore::class, $store);

        $connection = $store->connection();

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);

        $results = $connection->pipeline(static function () use ($cache, $tags): void {
            self::assertTrue($cache->tags($tags)->put('first', 'one', 600));
            self::assertTrue($cache->tags($tags)->put('second', 'two', 600));
        });

        self::assertCount(6, $results);
        self::assertSame('one', $cache->tags($tags)->get('first'));
        self::assertSame('two', $cache->tags($tags)->get('second'));

        foreach ($tags as $tag) {
            self::assertSame(2, $physical->command('zcard', [$prefix . 'tag:' . $tag . ':entries']));
        }

        try {
            $connection->pipeline(static function () use ($cache, $tags): void {
                $cache->tags($tags)->put('third', 'three', 600);
                $cache->tags($tags)->flush();
            });

            self::fail('Expected the tag flush inside the pipeline to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('[ZSCAN] cannot be used inside a pipeline or transaction', $exception->getMessage());
        }

        try {
            $connection->pipeline(static function () use ($store): void {
                $store->flushStaleTags();
            });

            self::fail('Expected the tag prune inside the pipeline to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('[SCAN] cannot be used inside a pipeline or transaction', $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertSame('one', $cache->tags($tags)->get('first'));
        self::assertNull($cache->tags($tags)->get('third'));

        foreach ($tags as $tag) {
            self::assertSame(2, $physical->command('zcard', [$prefix . 'tag:' . $tag . ':entries']));
        }

        self::assertTrue($cache->tags($tags)->flush());
        self::assertNull($cache->tags($tags)->get('first'));
        self::assertNull($cache->tags($tags)->get('second'));

        foreach ($tags as $tag) {
            self::assertSame(0, $physical->command('exists', [$prefix . 'tag:' . $tag . ':entries']));
        }
    }

    /**
     * Verify Cache::putMany() writes every prefixed key through the package's
     * cache store, also when the keys hash to different cluster slots, and that
     * an empty putMany() leaves the client usable.
     *
     * On the standalone server the store keeps Laravel's MULTI/EXEC, so this
     * runs the connection's transaction, heard of as one `transaction`; on the
     * cluster it writes each key on its own, which a cross-slot transaction
     * could not, heard of as one `setex` each.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function cachePutManyWritesEveryKeyAndEmptyPutManyDoesNotWedge(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $cache    = $this->cacheStore($topology);
        $physical = $this->physical($topology);
        $keys     = [$this->uniqueName('put-many-a'), $this->uniqueName('put-many-b')];
        $prefix   = self::CONNECTION_PREFIXES[$topology] . self::CACHE_STORE_PREFIX;
        $router   = new ClusterRouter;

        if ($router->slot($prefix . $keys[0]) === $router->slot($prefix . $keys[1])) {
            $keys[1] .= ':other-slot';
        }

        self::assertNotSame($router->slot($prefix . $keys[0]), $router->slot($prefix . $keys[1]));

        $store      = $cache->getStore();
        $executed   = [];
        $dispatcher = new Dispatcher;

        self::assertInstanceOf(ValkeyGlideStore::class, $store);

        $connection = $store->connection();

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$executed): void {
            $executed[] = $event->command;
        });

        $previous = $connection->getEventDispatcher();

        $connection->setEventDispatcher($dispatcher);

        self::assertTrue($cache->putMany([$keys[0] => 'value-a', $keys[1] => 'value-b'], 600));
        self::assertSame($topology === self::CLUSTER ? ['setex', 'setex'] : ['transaction'], $executed);

        $connection->unsetEventDispatcher();

        if ($previous !== null) {
            $connection->setEventDispatcher($previous);
        }

        self::assertSame([$keys[0] => 'value-a', $keys[1] => 'value-b'], $cache->many($keys));
        self::assertSame(serialize('value-a'), $physical->command('get', [$prefix . $keys[0]]));
        self::assertSame(serialize('value-b'), $physical->command('get', [$prefix . $keys[1]]));
        self::assertGreaterThan(500, $physical->command('ttl', [$prefix . $keys[0]]));
        self::assertGreaterThan(500, $physical->command('ttl', [$prefix . $keys[1]]));

        // Repository::putMany([]) fails in Laravel's own WritingManyKeys event
        // when events are enabled, so the empty call goes to the store itself.
        self::assertFalse($store->putMany([], 600));
        self::assertFalse($store->putMany([], 600));

        // Only the standalone store enters batch mode for putMany(); on either
        // topology no batch is left behind, and GLIDE's client answers a direct
        // read rather than buffer it.
        self::assertFalse($connection->exec());
        self::assertSame(serialize('value-b'), $connection->client()->get($prefix . $keys[1]));

        self::assertTrue($cache->put($keys[0], 'after-empty', 600));
        self::assertSame('after-empty', $cache->get($keys[0]));

        $cache->forget($keys[0]);
        $cache->forget($keys[1]);
    }

    /**
     * Verify the bare multi(), setex() and exec() that Laravel's own
     * RedisStore::putMany() issues on a connection throw before anything is
     * sent when a cluster transaction's keys span hash slots, where GLIDE would
     * fail the whole MULTI and write nothing, and leave the connection usable.
     * The package's cache store avoids this by writing key by key on a cluster;
     * other code driving a bare multi() still meets it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterBareMultiAcrossSlotsThrowsBeforeSending(): void
    {
        $this->skipUnlessTopologyIsConfigured(self::CLUSTER);

        $connection = $this->physical(self::CLUSTER);
        $keys       = [$this->uniqueName('spread-a'), $this->uniqueName('spread-b')];
        $router     = new ClusterRouter;

        if ($router->slot($keys[0]) === $router->slot($keys[1])) {
            $keys[1] .= ':other-slot';
        }

        self::assertNotSame($router->slot($keys[0]), $router->slot($keys[1]));

        $connection->command('multi');
        $connection->command('setex', [$keys[0], 600, 'a']);

        try {
            $connection->command('setex', [$keys[1], 600, 'b']);

            self::fail('Expected the cross-slot transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('must keep every key in one hash slot', $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertFalse($connection->command('get', [$keys[0]]));
        self::assertFalse($connection->command('get', [$keys[1]]));
        self::assertTrue($connection->command('set', [$keys[0], 'usable']));
        self::assertSame('usable', $connection->get($keys[0]));

        $connection->command('del', [$keys[0]]);
    }

    /**
     * Configure standalone and cluster Redis connections, queues and cache
     * stores.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.options', []);
        $app['config']->set('database.redis.' . self::STANDALONE, $this->standaloneConfig() + ['prefix' => self::CONNECTION_PREFIXES[self::STANDALONE]]);
        $app['config']->set('database.redis.clusters.' . self::CLUSTER, [
            $this->clusterSeed(),
            'options' => ['prefix' => self::CONNECTION_PREFIXES[self::CLUSTER]],
        ]);

        foreach ([self::STANDALONE, self::CLUSTER] as $connection) {
            $app['config']->set('queue.connections.' . $connection, [
                'driver'      => 'redis',
                'connection'  => $connection,
                'queue'       => 'default',
                'retry_after' => 90,
                'block_for'   => null,
            ]);

            $app['config']->set('cache.stores.' . $connection, [
                'driver'     => 'redis',
                'connection' => $connection,
                'prefix'     => self::CACHE_STORE_PREFIX,
            ]);
        }
    }

    /**
     * Create an unprefixed connection to the topology, to read physical keys.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function physical(string $topology): ValkeyGlideConnection
    {
        $connection = $this->connectTo($topology);

        $this->physicalConnections[] = $connection;

        return $connection;
    }

    /**
     * Resolve a Laravel Redis queue connection.
     *
     * @param  string  $name
     * @return \Illuminate\Queue\RedisQueue
     *
     * @throws \UnexpectedValueException
     */
    private function redisQueue(string $name): RedisQueue
    {
        $queue = $this->application()->make(QueueManager::class)->connection($name);

        if ($queue instanceof RedisQueue) {
            return $queue;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis queue connection to be [%s], received [%s].', RedisQueue::class, get_debug_type($queue)));
    }

    /**
     * Resolve a Laravel Redis cache store.
     *
     * @param  string  $name
     * @return \Illuminate\Cache\Repository
     *
     * @throws \UnexpectedValueException
     */
    private function cacheStore(string $name): Repository
    {
        $store = Cache::store($name);

        if ($store instanceof Repository) {
            return $store;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis cache store to be [%s], received [%s].', Repository::class, get_debug_type($store)));
    }

    /**
     * Build a repository over Laravel's own Redis store, rather than the
     * package's, on the topology's connection.
     *
     * @param  string  $topology
     * @return \Illuminate\Cache\Repository
     *
     * @throws \UnexpectedValueException
     */
    private function laravelCacheStore(string $topology): Repository
    {
        $redis = $this->application()->make('redis');

        if (!$redis instanceof Factory) {
            throw new \UnexpectedValueException(sprintf('Expected the redis binding to be [%s], received [%s].', Factory::class, get_debug_type($redis)));
        }

        return new Repository(new RedisStore($redis, self::CACHE_STORE_PREFIX, $topology));
    }

    /**
     * Resolve the application container.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     *
     * @throws \UnexpectedValueException
     */
    private function application(): Application
    {
        if (!$this->app instanceof Application) {
            throw new \UnexpectedValueException('Application container is unavailable.');
        }

        return $this->app;
    }

    /**
     * Build a unique queue name; on the cluster it is hash-tagged, so the
     * queue's keys share one slot, as Laravel only adds the tag itself for
     * phpredis cluster connections.
     *
     * @param  string  $topology
     * @return string
     */
    private function uniqueQueueName(string $topology): string
    {
        $name = $this->uniqueName('bulk');

        return $topology === self::CLUSTER ? '{' . $name . '}' : $name;
    }
}
