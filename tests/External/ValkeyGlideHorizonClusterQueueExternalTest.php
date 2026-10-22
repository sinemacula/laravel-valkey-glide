<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Foundation\Application;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\QueueManager;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonServiceProvider;
use Laravel\Horizon\RedisQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Queue\ValkeyGlideHorizonQueue;
use SineMacula\Valkey\ValkeyGlideServiceProvider;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\TestCase;

/**
 * External checks that Horizon's Redis queue runs on a valkey-glide cluster
 * connection with a queue name that carries no hash tag, on every supported
 * Laravel version.
 *
 * Horizon's listeners keep its records on the standalone server, under a unique
 * prefix.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideHorizonQueue::class)]
#[Group('external')]
final class ValkeyGlideHorizonClusterQueueExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string Redis connection configured on the standalone server, for Horizon's records. */
    private const string HORIZON_REDIS_CONNECTION = 'qht-horizon-standalone';

    /** @var string Redis connection configured on the cluster. */
    private const string CLUSTER_REDIS_CONNECTION = 'qht-horizon-cluster';

    /** @var string Queue connection configured on the cluster. */
    private const string CLUSTER_QUEUE_CONNECTION = 'qht-horizon-redis-cluster';

    /** @var string|null The prefix of Horizon's records, once Horizon is pointed at the server. */
    private ?string $horizonPrefix = null;

    /**
     * Ensure extension preconditions are met and the cluster is configured, and
     * point Horizon's records at the standalone server.
     *
     * @return void
     *
     * @throws \Exception
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertExtensionIsLoaded();
        $this->skipUnlessTopologyIsConfigured(self::CLUSTER);

        $this->horizonPrefix = 'horizon:' . $this->uniqueName('qht-horizon') . ':';

        config(['horizon.prefix' => $this->horizonPrefix]);

        Horizon::use(self::HORIZON_REDIS_CONNECTION);

        // The Redis manager copies its configuration when it is built, so it
        // is rebuilt to see the horizon connection Horizon::use() just added.
        $this->application()->forgetInstance('redis');
    }

    /**
     * Delete Horizon's records under the test's prefix.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->horizonPrefix !== null) {
            $physical = $this->connectTo(self::STANDALONE);
            $keys     = $physical->executeRaw(['KEYS', $this->horizonPrefix . '*']);

            foreach (is_array($keys) ? $keys : [] as $key) {
                $physical->executeRaw(['DEL', $key]);
            }
        }

        parent::tearDown();
    }

    /**
     * Verify a job is pushed, popped and deleted through Horizon's queue under
     * the hash-tagged queue keys.
     *
     * @return void
     */
    #[Test]
    public function horizonJobRoundtripsUnderTheHashTaggedQueueKeys(): void
    {
        $name  = $this->uniqueName('qht-horizon');
        $queue = $this->queue();

        try {
            self::assertIsString($queue->push('App\Jobs\Horizon', '', $name));
            self::assertSame(1, $queue->readyNow($name));
            self::assertSame(1, $this->connectTo(self::CLUSTER)->command('llen', ['queues:{' . $name . '}']));

            $job = $queue->pop($name);

            self::assertInstanceOf(RedisJob::class, $job);
            self::assertSame('App\Jobs\Horizon', $job->payload()['job']);

            $job->delete();

            self::assertSame(0, $queue->size($name));
        } finally {
            $queue->clear($name);
        }
    }

    /**
     * Register Horizon before the package, as package discovery orders them.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            HorizonServiceProvider::class,
            ValkeyGlideServiceProvider::class,
        ];
    }

    /**
     * Configure the standalone and cluster Redis connections, and the cluster
     * queue connection.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.options', []);
        $app['config']->set('database.redis.' . self::HORIZON_REDIS_CONNECTION, $this->standaloneConfig());

        if (!$this->isClusterConfigured()) {
            return;
        }

        $app['config']->set('database.redis.clusters.' . self::CLUSTER_REDIS_CONNECTION, [$this->clusterSeed()]);
        $app['config']->set('queue.connections.' . self::CLUSTER_QUEUE_CONNECTION, [
            'driver'       => 'redis',
            'connection'   => self::CLUSTER_REDIS_CONNECTION,
            'queue'        => 'default',
            'retry_after'  => 90,
            'block_for'    => null,
            'after_commit' => false,
        ]);
    }

    /**
     * Resolve the cluster queue connection, which Horizon's queue serves.
     *
     * @return \Laravel\Horizon\RedisQueue
     *
     * @throws \UnexpectedValueException
     */
    private function queue(): RedisQueue
    {
        $queue = $this->application()->make(QueueManager::class)->connection(self::CLUSTER_QUEUE_CONNECTION);

        if ($queue instanceof RedisQueue) {
            return $queue;
        }

        throw new \UnexpectedValueException(sprintf('Expected a Horizon redis queue, received [%s].', get_debug_type($queue)));
    }

    /**
     * Resolve the application.
     *
     * @return \Illuminate\Foundation\Application
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
}
