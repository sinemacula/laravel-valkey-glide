<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\Jobs\InspectedJob;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ClusterRouter;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\TestCase;

/**
 * External Laravel queue integration checks for the valkey-glide Redis client.
 *
 * The cluster cases run when VALKEY_GLIDE_TEST_CLUSTER_HOST and
 * VALKEY_GLIDE_TEST_CLUSTER_PORT are set, and are skipped otherwise.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideLaravelQueueExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string Prefix configured on the Redis queue connection. */
    private const string REDIS_CONNECTION_PREFIX = 'lvkglide:queue:conn:';

    /** @var string Prefix configured on the cluster's Redis queue connection. */
    private const string CLUSTER_CONNECTION_PREFIX = 'lvkglide:queue:cluster:';

    /** @var string Default queue name used for Redis queue configuration. */
    private const string DEFAULT_QUEUE_NAME = 'default';

    /** @var string Redis connection configured on the cluster, when one is set. */
    private const string CLUSTER_REDIS_CONNECTION = 'queue-cluster';

    /** @var string Queue connection configured on the cluster, when one is set. */
    private const string CLUSTER_QUEUE_CONNECTION = 'redis-cluster';

    /** @var array<string, string> Queue connection for each topology. */
    private const array QUEUE_CONNECTIONS = [
        self::STANDALONE => 'redis',
        self::CLUSTER    => self::CLUSTER_QUEUE_CONNECTION,
    ];

    /** @var string Queue connection whose pop blocks for a job, as a worker's does with block_for set. */
    private const string BLOCKING_QUEUE_CONNECTION = 'redis-blocking';

    /** @var int Seconds the blocking queue connection's pop waits for a job. */
    private const int BLOCK_FOR = 1;

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
     * Verify Laravel's Redis queue driver resolves the valkey-glide connection.
     *
     * @return void
     */
    #[Test]
    public function queueDriverUsesValkeyGlideRedisConnection(): void
    {
        self::assertInstanceOf(
            ValkeyGlideConnection::class,
            $this->redisQueueConnection()->getConnection(),
        );
    }

    /**
     * Verify queue push/pop roundtrip succeeds through Laravel's Redis queue.
     *
     * @return void
     */
    #[Test]
    public function queuePushPopRoundtripSucceeds(): void
    {
        $queueName = $this->uniqueQueueName('roundtrip');
        $queue     = $this->redisQueueConnection();
        $payload   = $this->queuePayload();

        $queuedId = $queue->pushRaw($payload, $queueName);

        self::assertIsString($queuedId);
        self::assertSame(1, $queue->size($queueName));

        $job = $queue->pop($queueName);

        self::assertInstanceOf(RedisJob::class, $job);
        self::assertSame($payload, $job->getRawBody());

        $job->delete();

        self::assertSame(0, $queue->size($queueName));
        $queue->clear($queueName);
    }

    /**
     * Verify queue writes are stored under the expected prefixed Redis key.
     *
     * @return void
     */
    #[Test]
    public function queuePushWritesExpectedPhysicalRedisKey(): void
    {
        $queueName   = $this->uniqueQueueName('physical-key');
        $queue       = $this->redisQueueConnection();
        $connection  = $this->redisConnection();
        $physicalKey = self::REDIS_CONNECTION_PREFIX . 'queues:' . $queueName;
        $logicalKey  = 'queues:' . $queueName;

        $queue->pushRaw($this->queuePayload(), $queueName);

        self::assertGreaterThan(
            0,
            $this->normalizeIntegerResponse(
                $connection->client()->rawcommand('LLEN', $physicalKey),
            ),
        );

        self::assertSame(
            0,
            $this->normalizeIntegerResponse(
                $connection->client()->rawcommand('LLEN', $logicalKey),
            ),
        );

        $queue->clear($queueName);
    }

    /**
     * Verify a blocking pop on a prefixed connection waits on the prefixed
     * notify key, which Laravel's push script adds to, so a push wakes it.
     *
     * With the queue empty, Laravel's pop waits in
     * `blpop([$queue . ':notify'], $blockFor)`; with the prefixed notify key
     * seeded, that BLPOP takes the entry at once.
     *
     * @return void
     */
    #[Test]
    public function blockingPopWaitsOnThePrefixedNotifyKey(): void
    {
        $queueName = $this->uniqueQueueName('blocking');
        $queue     = $this->redisQueueConnection(self::BLOCKING_QUEUE_CONNECTION);
        $client    = $this->redisConnection()->client();
        $notifyKey = self::REDIS_CONNECTION_PREFIX . $queue->getQueue($queueName) . ':notify';

        $client->rawcommand('RPUSH', $notifyKey, '1');

        self::assertNull($queue->pop($queueName));

        $remaining = $this->normalizeIntegerResponse($client->rawcommand('LLEN', $notifyKey));
        $client->rawcommand('DEL', $notifyKey);

        self::assertSame(0, $remaining);
    }

    /**
     * Verify a queue whose name has no hash tag works on a cluster connection.
     *
     * The queue's scripts touch several keys at once, so they only run on a
     * cluster when Laravel hash-tags the queue name into one slot, which it
     * does from 13.5 for connections whose isCluster() returns true.
     *
     * @return void
     */
    #[Test]
    public function clusterQueuePushPopRoundtripSucceedsWithoutAHashTag(): void
    {
        $this->skipUnlessTopologyIsConfigured(self::CLUSTER);

        if (!(new \ReflectionClass(Connection::class))->hasMethod('isCluster')) {
            self::markTestSkipped('Laravel hash-tags queue keys on cluster connections from 13.5.');
        }

        $queueName = $this->uniqueQueueName('cluster');
        $queue     = $this->redisQueueConnection(self::CLUSTER_QUEUE_CONNECTION);
        $payload   = $this->queuePayload();

        $queue->pushRaw($payload, $queueName);

        self::assertSame(1, $queue->size($queueName));

        $job = $queue->pop($queueName);

        self::assertInstanceOf(RedisJob::class, $job);
        self::assertSame($payload, $job->getRawBody());

        $job->delete();

        self::assertSame(0, $queue->size($queueName));
        $queue->clear($queueName);
    }

    /**
     * Verify a funnel whose name has no hash tag acquires a slot on a cluster
     * connection.
     *
     * The limiter's lock script reads every slot key at once, so it only runs
     * on a cluster when Laravel hash-tags the funnel name into one slot, which
     * it does from 13.5 for connections whose isCluster() returns true.
     *
     * @return void
     *
     * @throws \Illuminate\Contracts\Redis\LimiterTimeoutException
     */
    #[Test]
    public function clusterFunnelAcquiresASlotWithoutAHashTag(): void
    {
        $this->skipUnlessTopologyIsConfigured(self::CLUSTER);

        if (!(new \ReflectionClass(Connection::class))->hasMethod('isCluster')) {
            self::markTestSkipped('Laravel hash-tags funnel keys on cluster connections from 13.5.');
        }

        $result = $this->redisConnection(self::CLUSTER_REDIS_CONNECTION)
            ->funnel($this->uniqueQueueName('funnel'))
            ->limit(2)
            ->releaseAfter(5)
            ->block(0)
            ->then(static fn (): string => 'acquired');

        self::assertSame('acquired', $result);
    }

    /**
     * Verify Laravel's queue inspection, which lists the queue names with KEYS
     * on a connection that is not a phpredis cluster connection, finds the
     * pending and delayed jobs of every queue under the connection prefix.
     *
     * On the cluster there is a queue on every primary, as each primary holds
     * only its own slots' keys.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function queueInspectionFindsTheJobsOfEveryQueue(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        if (!(new \ReflectionClass(RedisQueue::class))->hasMethod('allPendingJobs')) {
            self::markTestSkipped('Laravel added queue inspection to its Redis queue in 13.');
        }

        $queue  = $this->redisQueueConnection(self::QUEUE_CONNECTIONS[$topology]);
        $names  = $this->queueNamesOnEveryPrimary($topology);
        $listed = array_map(static fn (string $name): string => trim($name, '{}'), $names);

        try {
            foreach ($names as $name) {
                $queue->pushRaw($this->queuePayload('job-' . trim($name, '{}')), $name);
            }

            $queue->later(600, CallQueuedClosure::create(static fn () => null), '', $names[0]);

            $pending = $queue->allPendingJobs()->filter(static fn (InspectedJob $job): bool => in_array($job->queue, $listed, true));
            $delayed = $queue->allDelayedJobs()->filter(static fn (InspectedJob $job): bool => in_array($job->queue, $listed, true));

            self::assertEqualsCanonicalizing(
                array_map(static fn (string $name): string => 'job-' . $name, $listed),
                $pending->map(static fn (InspectedJob $job): mixed => $job->payload['job'] ?? null)->values()->all(),
            );
            self::assertSame([$listed[0]], $delayed->map(static fn (InspectedJob $job): ?string => $job->queue)->values()->all());
            self::assertGreaterThanOrEqual(count($names) + 1, $queue->totalSize());
        } finally {
            foreach ($names as $name) {
                $queue->clear($name);
            }
        }
    }

    /**
     * Verify clearing a queue, as `queue:clear` does, removes its pending and
     * delayed jobs with one script over the queue's keys, and reports how many
     * it removed.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function queueClearRemovesEveryJobOfTheQueue(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $queue = $this->redisQueueConnection(self::QUEUE_CONNECTIONS[$topology]);
        $name  = $this->topologyQueueName($topology, 'clear');

        $key = $queue->getQueue($name);

        try {
            $queue->pushRaw($this->queuePayload(), $name);
            $queue->pushRaw($this->queuePayload(), $name);
            $queue->later(600, CallQueuedClosure::create(static fn () => null), '', $name);

            self::assertSame(3, $queue->size($name));
            self::assertSame(3, $queue->clear($name));
            self::assertSame(0, $queue->size($name));
            self::assertNull($queue->pop($name));
        } finally {
            $queue->getConnection()->command('del', [$key, $key . ':delayed', $key . ':reserved', $key . ':notify']);
        }
    }

    /**
     * Configure Redis and queue defaults used by queue integration tests.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.default', $this->standaloneConfig() + ['prefix' => self::REDIS_CONNECTION_PREFIX]);
        $app['config']->set('database.redis.options', []);
        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', [
            'driver'       => 'redis',
            'connection'   => 'default',
            'queue'        => self::DEFAULT_QUEUE_NAME,
            'retry_after'  => 90,
            'block_for'    => null,
            'after_commit' => false,
        ]);

        $app['config']->set('queue.connections.' . self::BLOCKING_QUEUE_CONNECTION, [
            'driver'       => 'redis',
            'connection'   => 'default',
            'queue'        => self::DEFAULT_QUEUE_NAME,
            'retry_after'  => 90,
            'block_for'    => self::BLOCK_FOR,
            'after_commit' => false,
        ]);

        if (!$this->isClusterConfigured()) {
            return;
        }

        $app['config']->set('database.redis.clusters.' . self::CLUSTER_REDIS_CONNECTION, [
            $this->clusterSeed(),
            'options' => ['prefix' => self::CLUSTER_CONNECTION_PREFIX],
        ]);
        $app['config']->set('queue.connections.' . self::CLUSTER_QUEUE_CONNECTION, [
            'driver'       => 'redis',
            'connection'   => self::CLUSTER_REDIS_CONNECTION,
            'queue'        => self::DEFAULT_QUEUE_NAME,
            'retry_after'  => 90,
            'block_for'    => null,
            'after_commit' => false,
        ]);
    }

    /**
     * Resolve one of the Redis manager's connections as the valkey-glide
     * wrapper.
     *
     * @param  string  $name
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \UnexpectedValueException
     */
    private function redisConnection(string $name = 'default'): ValkeyGlideConnection
    {
        if (!$this->app instanceof Application) {
            throw new \UnexpectedValueException('Application container is unavailable.');
        }

        $redis      = $this->app->make(RedisManager::class);
        $connection = $redis->connection($name);

        if ($connection instanceof ValkeyGlideConnection) {
            return $connection;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis [%s] connection to be [%s], received [%s].', $name, ValkeyGlideConnection::class, get_debug_type($connection)));
    }

    /**
     * Resolve one of Laravel's Redis queue connections.
     *
     * @param  string  $name
     * @return \Illuminate\Queue\RedisQueue
     *
     * @throws \UnexpectedValueException
     */
    private function redisQueueConnection(string $name = 'redis'): RedisQueue
    {
        if (!$this->app instanceof Application) {
            throw new \UnexpectedValueException('Application container is unavailable.');
        }

        $manager = $this->app->make(QueueManager::class);
        $queue   = $manager->connection($name);

        if ($queue instanceof RedisQueue) {
            return $queue;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis queue connection to be [%s], received [%s].', RedisQueue::class, get_debug_type($queue)));
    }

    /**
     * Build a JSON payload accepted by Laravel's Redis queue driver.
     *
     * @param  string  $job
     * @return string
     */
    private function queuePayload(string $job = 'tests.stub'): string
    {
        $id = str_replace('.', '', uniqid('job', true));

        return json_encode(
            [
                'id'       => $id,
                'job'      => $job,
                'data'     => ['message' => 'payload'],
                'attempts' => 0,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Build a unique queue name for external queue test isolation.
     *
     * @param  string  $suffix
     * @return string
     */
    private function uniqueQueueName(string $suffix): string
    {
        $entropy = str_replace('.', '', uniqid('', true));

        return sprintf('lvkglide:queue:%s:%s', $suffix, $entropy);
    }

    /**
     * Build a unique queue name for the topology; on the cluster it is
     * hash-tagged, so the queue's keys share one slot whichever Laravel version
     * runs.
     *
     * @param  string  $topology
     * @param  string  $suffix
     * @return string
     */
    private function topologyQueueName(string $topology, string $suffix): string
    {
        $name = $this->uniqueQueueName($suffix);

        return $topology === self::CLUSTER ? '{' . $name . '}' : $name;
    }

    /**
     * Build unique queue names, one on each primary of the cluster, or two on
     * the standalone server.
     *
     * Each primary's slot ranges are read from CLUSTER SLOTS, and names are
     * drawn until one falls in the ranges of every primary.
     *
     * @param  string  $topology
     * @return non-empty-list<string>
     *
     * @throws \UnexpectedValueException
     */
    private function queueNamesOnEveryPrimary(string $topology): array
    {
        if ($topology !== self::CLUSTER) {
            return [$this->topologyQueueName($topology, 'inspect'), $this->topologyQueueName($topology, 'inspect')];
        }

        $ranges    = $this->clusterSlotRanges();
        $primaries = count(array_unique(array_column($ranges, 2)));
        $names     = [];
        $router    = new ClusterRouter;

        while (count($names) < $primaries) {
            $name = $this->topologyQueueName($topology, 'inspect');
            $slot = $router->slot($name);

            foreach ($ranges as [$start, $end, $primary]) {
                if ($slot < $start || $slot > $end) {
                    continue;
                }

                $names[$primary] ??= $name;
            }
        }

        return array_values($names);
    }

    /**
     * Read the cluster's slot ranges, each with the address of the primary that
     * owns it.
     *
     * @return non-empty-list<array{int, int, string}>
     *
     * @throws \UnexpectedValueException
     */
    private function clusterSlotRanges(): array
    {
        $reply = $this->redisConnection(self::CLUSTER_REDIS_CONNECTION)->executeRaw(['CLUSTER', 'SLOTS']);

        if (!is_array($reply) || $reply === []) {
            throw new \UnexpectedValueException(sprintf('Expected the cluster slots, received [%s].', get_debug_type($reply)));
        }

        return array_values(array_map(self::slotRange(...), $reply));
    }

    /**
     * Read a slot range of a CLUSTER SLOTS reply as its first and last slots
     * and the address of its primary.
     *
     * @param  mixed  $range
     * @return array{int, int, string}
     *
     * @throws \UnexpectedValueException
     */
    private static function slotRange(mixed $range): array
    {
        $primary = is_array($range) ? ($range[2] ?? null) : null;

        if (!is_array($range) || !is_int($range[0] ?? null) || !is_int($range[1] ?? null) || !is_array($primary)) {
            throw new \UnexpectedValueException('Unexpected CLUSTER SLOTS reply.');
        }

        if (!is_string($primary[0] ?? null) || !is_int($primary[1] ?? null)) {
            throw new \UnexpectedValueException('Unexpected CLUSTER SLOTS primary.');
        }

        return [$range[0], $range[1], $primary[0] . ':' . $primary[1]];
    }

    /**
     * Normalize mixed integer-like command responses.
     *
     * @param  mixed  $response
     * @return int
     *
     * @throws \UnexpectedValueException
     */
    private function normalizeIntegerResponse(mixed $response): int
    {
        if (is_int($response)) {
            return $response;
        }

        if (is_string($response) && is_numeric($response)) {
            return (int) $response;
        }

        throw new \UnexpectedValueException(sprintf('Expected integer response, received [%s].', get_debug_type($response)));
    }
}
