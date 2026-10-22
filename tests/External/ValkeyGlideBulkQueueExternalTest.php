<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\Events\CommandExecuted;
use Laravel\Horizon\Events\JobPending;
use Laravel\Horizon\Events\JobPushed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;
use SineMacula\Valkey\Queue\BulkPush;
use SineMacula\Valkey\Queue\Connectors\ValkeyGlideQueueConnector;
use SineMacula\Valkey\Queue\ValkeyGlideHorizonQueue;
use SineMacula\Valkey\Queue\ValkeyGlideQueue;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\TestCase;

/**
 * External checks that `Queue::bulk()`, which `Bus::batch()` pushes its jobs
 * with, pushes every job with one atomic script on the valkey-glide connection,
 * and throws when the push fails.
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
#[CoversClass(BulkPush::class)]
#[CoversClass(ValkeyGlideQueue::class)]
#[CoversClass(ValkeyGlideHorizonQueue::class)]
#[Group('external')]
final class ValkeyGlideBulkQueueExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var array<string, string> Key prefix configured on each Redis connection. */
    private const array CONNECTION_PREFIXES = [
        self::STANDALONE => 'lvkglide:bulk:conn:',
        self::CLUSTER    => 'lvkglide:bulk:cluster:',
    ];

    /** @var list<array{\Illuminate\Queue\RedisQueue, string}> Queues to clear after the test. */
    private array $queues = [];

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
     * Clear the queues the test pushed to and close the physical connections.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->queues as [$queue, $name]) {
            $queue->clear($name);
        }

        foreach ($this->physicalConnections as $connection) {
            $connection->disconnect();
        }

        $this->queues              = [];
        $this->physicalConnections = [];

        parent::tearDown();
    }

    /**
     * Verify a bulk pushes every job, delayed jobs included, with one script,
     * which the server runs as one atomic step, and leaves the jobs for a
     * worker to pop in order.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function bulkPushesEveryJobWithOneScript(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $queue    = $this->redisQueue($topology);
        $name     = $this->uniqueQueueName($topology, true);
        $key      = self::CONNECTION_PREFIXES[$topology] . 'queues:' . $name;
        $physical = $this->physical($topology);
        $commands = $this->recordCommands($topology);

        $queue->bulk(['job-a', CallQueuedClosure::create(static fn () => null)->delay(600), 'job-b'], ['k' => 'v'], $name);

        self::assertSame(['eval'], $commands->getArrayCopy());
        self::assertSame([2, 2, 1], [$physical->command('llen', [$key]), $physical->command('llen', [$key . ':notify']), $physical->command('zcard', [$key . ':delayed'])]);

        foreach (['job-a', 'job-b'] as $expected) {
            $job = $queue->pop($name);

            self::assertInstanceOf(RedisJob::class, $job);
            self::assertSame($expected, $job->payload()['job']);
            self::assertSame(['k' => 'v'], $job->payload()['data']);

            $job->delete();
        }

        self::assertNull($queue->pop($name));
    }

    /**
     * Verify a bulk larger than the script's chunks of 100 and 200 arguments
     * still goes in one script, with every job and notification.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function largeBulkPushesEveryJobWithOneScript(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $queue    = $this->redisQueue($topology);
        $name     = $this->uniqueQueueName($topology, true);
        $key      = self::CONNECTION_PREFIXES[$topology] . 'queues:' . $name;
        $physical = $this->physical($topology);
        $commands = $this->recordCommands($topology);
        $jobs     = array_map(static fn (int $index): string => 'job-' . $index, range(1, 250));
        $delayed  = array_map(static fn (): CallQueuedClosure => CallQueuedClosure::create(static fn () => null)->delay(600), range(1, 150));

        $queue->bulk([...$jobs, ...$delayed], '', $name);

        self::assertSame(['eval'], $commands->getArrayCopy());
        self::assertSame([250, 250, 150], [$physical->command('llen', [$key]), $physical->command('llen', [$key . ':notify']), $physical->command('zcard', [$key . ':delayed'])]);

        $first = $physical->command('lindex', [$key, 0]);
        $last  = $physical->command('lindex', [$key, -1]);

        self::assertIsString($first);
        self::assertIsString($last);
        self::assertSame(['job-1', 'job-250'], [json_decode($first, true)['job'] ?? null, json_decode($last, true)['job'] ?? null]);
    }

    /**
     * Verify a cluster bulk to a queue named without a hash tag stays in one
     * slot, as the queue hash-tags its keys on a cluster before Laravel 13.5
     * and Laravel does from 13.5.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterBulkToAnUntaggedQueueStaysInOneSlot(): void
    {
        $this->skipUnlessTopologyIsConfigured(self::CLUSTER);

        $queue = $this->redisQueue(self::CLUSTER);
        $name  = $this->uniqueQueueName(self::CLUSTER, false);

        $queue->bulk(['job-a', 'job-b'], '', $name);

        self::assertSame(2, $queue->size($name));
        self::assertSame(2, $queue->getConnection()->command('llen', ['queues:{' . $name . '}']));
    }

    /**
     * Verify a bulk whose push fails throws rather than drop the jobs quietly,
     * and pushes none of them, as the script fails on its first write.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function failedBulkPushThrows(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $queue    = $this->redisQueue($topology);
        $name     = $this->uniqueQueueName($topology, true);
        $key      = self::CONNECTION_PREFIXES[$topology] . 'queues:' . $name;
        $physical = $this->physical($topology);

        $physical->command('set', [$key, 'not a list']);

        try {
            $queue->bulk(['job-a', CallQueuedClosure::create(static fn () => null)->delay(600)], '', $name);
            self::fail('Expected the failed bulk push to throw.');
        } catch (ConnectionException $exception) {
            self::assertStringStartsWith('The bulk push to [queues:' . $name . '] failed', $exception->getMessage());
        } finally {
            $physical->command('del', [$key]);
        }

        self::assertSame([0, 0], [$physical->command('llen', [$key . ':notify']), $physical->command('zcard', [$key . ':delayed'])]);
    }

    /**
     * Verify Horizon's queue pushes a bulk with one script, with the payloads
     * Horizon prepares, and fires Horizon's events for every job around it.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function horizonBulkPushesEveryJobWithOneScript(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $queue = (new ValkeyGlideQueueConnector($this->application()->make(RedisFactory::class), horizon: true))->connect(['queue' => 'default', 'connection' => $topology]);

        self::assertInstanceOf(ValkeyGlideHorizonQueue::class, $queue);

        $queue->setContainer($this->application());
        $queue->setConnectionName($topology);

        $name     = $this->uniqueQueueName($topology, true);
        $key      = self::CONNECTION_PREFIXES[$topology] . 'queues:' . $name;
        $physical = $this->physical($topology);
        $commands = $this->recordCommands($topology);
        $events   = [];

        $this->queues[] = [$queue, $name];

        $this->application()->make(Dispatcher::class)->listen([JobPending::class, JobPushed::class], static function (JobPending|JobPushed $event) use (&$events, $commands): void {
            $events[] = [$event::class, count($commands)];
        });

        $queue->bulk(['job-a', CallQueuedClosure::create(static fn () => null)->delay(600)], '', $name);

        $pushed = $physical->command('lindex', [$key, 0]);

        self::assertSame(['eval'], $commands->getArrayCopy());
        self::assertSame([[JobPending::class, 0], [JobPending::class, 0], [JobPushed::class, 1], [JobPushed::class, 1]], $events);
        self::assertIsString($pushed);
        self::assertSame('job', json_decode($pushed, true)['type'] ?? null);
        self::assertSame(1, $physical->command('zcard', [$key . ':delayed']));
    }

    /**
     * Configure standalone and cluster Redis connections and queues.
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
        }
    }

    /**
     * Record the commands the topology's application connection runs.
     *
     * @param  string  $topology
     * @return \ArrayObject<int, string>
     */
    private function recordCommands(string $topology): \ArrayObject
    {
        $connection = $this->application()->make(RedisFactory::class)->connection($topology);
        $commands   = new \ArrayObject;

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);

        $connection->setEventDispatcher(new \Illuminate\Events\Dispatcher);
        $connection->listen(static function (CommandExecuted $event) use ($commands): void {
            $commands->append(strtolower($event->command));
        });

        return $commands;
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
     * Resolve the topology's Redis queue, and clear its queues after the test.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Queue\ValkeyGlideQueue
     */
    private function redisQueue(string $topology): ValkeyGlideQueue
    {
        $queue = $this->application()->make(QueueManager::class)->connection($topology);

        self::assertInstanceOf(ValkeyGlideQueue::class, $queue);

        return $queue;
    }

    /**
     * Build a unique queue name, registered to be cleared after the test; on
     * the cluster it is hash-tagged unless asked not to be.
     *
     * @param  string  $topology
     * @param  bool  $hashTagged
     * @return string
     */
    private function uniqueQueueName(string $topology, bool $hashTagged): string
    {
        $name = 'bulk-' . str_replace('.', '', uniqid('', true));
        $name = $topology === self::CLUSTER && $hashTagged ? '{' . $name . '}' : $name;

        $this->queues[] = [$this->redisQueue($topology), $name];

        return $name;
    }

    /**
     * Resolve the application.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    private function application(): Application
    {
        self::assertNotNull($this->app);

        return $this->app;
    }
}
