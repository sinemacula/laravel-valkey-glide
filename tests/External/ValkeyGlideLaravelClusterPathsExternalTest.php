<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Broadcasting\Broadcasters\RedisBroadcaster;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ClusterRouter;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\TestCase;

/**
 * External checks for the Laravel features that take a different path on
 * Laravel's own cluster connections.
 *
 * Laravel's broadcaster and cache store branch on `PhpRedisClusterConnection`
 * and `PredisClusterConnection`, so on a GLIDE cluster they take the path they
 * take on a single server: one keyless script that publishes to every channel,
 * MGET across slots, and single-key scripts for locks and limiters. Each test
 * runs that path through Laravel's public API against the standalone server
 * and, when VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT
 * are set, against the cluster; the cluster cases are skipped otherwise. The
 * queue's KEYS-based inspection is checked in the queue external test.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideLaravelClusterPathsExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var array<string, string> Key prefix configured on each Redis connection. */
    private const array CONNECTION_PREFIXES = [
        self::STANDALONE => 'lvkglide:paths:conn:',
        self::CLUSTER    => 'lvkglide:paths:cluster:',
    ];

    /** @var string Prefix configured on both Redis cache stores. */
    private const string CACHE_STORE_PREFIX = 'lvkglide:paths:cache:';

    /** @var int Seconds the raw subscriber waits for each reply. */
    private const int SUBSCRIBER_TIMEOUT = 5;

    /** @var resource|null The raw subscriber connection, closed after the test. */
    private mixed $subscriber = null;

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
     * Close the subscriber connection the test opened.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (is_resource($this->subscriber)) {
            fclose($this->subscriber);
        }

        $this->subscriber = null;

        parent::tearDown();
    }

    /**
     * Verify Laravel's Redis broadcaster delivers an event to every channel it
     * names, through the keyless script it runs on a connection that is not a
     * phpredis cluster connection.
     *
     * On the cluster the script runs on a primary GLIDE picks, and the server
     * relays each publish to every node, so the subscriber on the seed node
     * hears it wherever it ran.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Illuminate\Broadcasting\BroadcastException
     * @throws \UnexpectedValueException
     */
    #[DataProvider('topologies')]
    #[Test]
    public function broadcastPublishesTheEventOnEveryChannel(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $channels = [$this->uniqueName('broadcast-a'), $this->uniqueName('broadcast-b'), $this->uniqueName('broadcast-c')];

        $this->subscribe($topology, $channels);

        (new RedisBroadcaster($this->redis(), $topology))->broadcast($channels, 'OrderShipped', ['order' => 42]);

        $payload = json_encode(['event' => 'OrderShipped', 'data' => ['order' => 42], 'socket' => null]);

        foreach ($channels as $channel) {
            self::assertSame(['message', $channel, $payload], $this->readReply());
        }
    }

    /**
     * Verify the cache store reads keys spread over several slots in one
     * many(), which Laravel sends as one MGET to a connection that is not a
     * Predis cluster connection, giving null for a missing key.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function cacheManyReadsKeysFromSeveralSlots(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $cache  = $this->cacheStore($topology);
        $keys   = [];
        $values = [];

        foreach (range(1, 6) as $index) {
            $key          = $this->uniqueName('many-' . $index);
            $keys[]       = $key;
            $values[$key] = 'value-' . $index;
        }

        $slots = array_map(static fn (string $key): int => (new ClusterRouter)->slot(self::CONNECTION_PREFIXES[$topology] . self::CACHE_STORE_PREFIX . $key), $keys);

        self::assertGreaterThan(1, count(array_unique($slots)));

        $missing = $this->uniqueName('many-missing');

        try {
            self::assertTrue($cache->putMany($values, 600));
            self::assertSame($values + [$missing => null], $cache->many([...$keys, $missing]));
        } finally {
            $cache->deleteMultiple($keys);
        }
    }

    /**
     * Verify a cache lock, taken and released through single-key commands and
     * scripts, is held by its first owner only, and is free again once that
     * owner, restored by its owner id, releases it.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function cacheLockIsHeldByItsOwnerOnly(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $cache = $this->lockProvider($topology);
        $name  = $this->uniqueName('lock');
        $first = $cache->lock($name, 60, 'owner-a');
        $other = $cache->lock($name, 60, 'owner-b');

        try {
            self::assertTrue($first->get());
            self::assertFalse($other->get());
            self::assertFalse($other->release());
            self::assertTrue($cache->restoreLock($name, 'owner-a')->release());
            self::assertTrue($other->get());
            self::assertFalse($first->release());
        } finally {
            $cache->lock($name)->forceRelease();
        }
    }

    /**
     * Verify Redis::throttle(), whose limiter script reads and writes one key,
     * allows its limit within the window and refuses the next call.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function throttleAllowsItsLimitAndRefusesTheNextCall(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $connection = $this->redisConnection($topology);
        $name       = $this->uniqueName('throttle');
        $throttle   = static fn (): mixed => $connection->throttle($name)->allow(2)->every(60)->block(0)->then(static fn (): string => 'allowed');

        try {
            self::assertSame('allowed', $throttle());
            self::assertSame('allowed', $throttle());

            $this->expectException(LimiterTimeoutException::class);

            $throttle();
        } finally {
            $connection->command('del', [$name]);
        }
    }

    /**
     * Verify Redis::funnel() with a hash-tagged name, whose lock script reads
     * every slot key at once, holds both of its slots while their callbacks
     * run, so a third is refused, and frees both once they return.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function funnelHoldsItsSlotsUntilTheirCallbacksReturn(string $topology): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $connection = $this->redisConnection($topology);
        $name       = '{' . $this->uniqueName('funnel') . '}';
        $funnel     = static fn (\Closure $callback): mixed => $connection->funnel($name)->limit(2)->releaseAfter(60)->block(0)->then($callback);
        $third      = static function () use ($funnel): mixed {
            try {
                return $funnel(static fn (): string => 'acquired a third slot');
            } catch (LimiterTimeoutException) {
                return 'third slot refused';
            }
        };

        // The second run takes both slots only if the first freed them.
        self::assertSame('third slot refused', $funnel(static fn (): mixed => $funnel($third)));
        self::assertSame('third slot refused', $funnel(static fn (): mixed => $funnel($third)));
    }

    /**
     * Configure standalone and cluster Redis connections and cache stores.
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
            $app['config']->set('cache.stores.' . $connection, [
                'driver'     => 'redis',
                'connection' => $connection,
                'prefix'     => self::CACHE_STORE_PREFIX,
            ]);
        }
    }

    /**
     * Subscribe a raw connection to the channels on the topology's server, or
     * on the cluster's seed node, and read each subscription's confirmation.
     *
     * A raw connection is used because a GLIDE subscription blocks its caller.
     *
     * @param  string  $topology
     * @param  list<string>  $channels
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private function subscribe(string $topology, array $channels): void
    {
        $config = $topology === self::CLUSTER ? $this->clusterSeed() : $this->standaloneConfig();

        if (($config['tls'] ?? false) === true) {
            self::markTestSkipped('The raw subscriber speaks plain TCP only.');
        }

        $socket = stream_socket_client(sprintf('tcp://%s:%d', $config['host'], $config['port']), $errorCode, $errorMessage, self::SUBSCRIBER_TIMEOUT);

        if ($socket === false) {
            throw new \UnexpectedValueException(sprintf('Unable to open the subscriber connection: [%d] %s', $errorCode, $errorMessage));
        }

        stream_set_timeout($socket, self::SUBSCRIBER_TIMEOUT);

        $this->subscriber = $socket;

        if (is_string($config['password'] ?? null)) {
            $this->sendCommand(['AUTH', ...(is_string($config['username'] ?? null) ? [$config['username']] : []), $config['password']]);

            self::assertSame('OK', $this->readReply());
        }

        $this->sendCommand(['SUBSCRIBE', ...$channels]);

        foreach ($channels as $index => $channel) {
            self::assertSame(['subscribe', $channel, $index + 1], $this->readReply());
        }
    }

    /**
     * Send a command to the subscriber connection in the Redis protocol.
     *
     * @param  list<string>  $arguments
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private function sendCommand(array $arguments): void
    {
        $command = '*' . count($arguments) . "\r\n";

        foreach ($arguments as $argument) {
            $command .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        if (!is_resource($this->subscriber) || fwrite($this->subscriber, $command) !== strlen($command)) {
            throw new \UnexpectedValueException('Unable to send a command to the subscriber connection.');
        }
    }

    /**
     * Read one reply from the subscriber connection in the Redis protocol.
     *
     * @return mixed
     *
     * @throws \UnexpectedValueException
     */
    private function readReply(): mixed
    {
        $line = is_resource($this->subscriber) ? fgets($this->subscriber) : false;

        if ($line === false) {
            throw new \UnexpectedValueException('The subscriber received no reply in time.');
        }

        $value = substr($line, 1, -2);

        return match ($line[0]) {
            '+'     => $value,
            ':'     => (int) $value,
            '$'     => (int) $value < 0 ? null : $this->readBulkString((int) $value),
            '*'     => array_map(fn (): mixed => $this->readReply(), array_fill(0, max(0, (int) $value), null)),
            default => throw new \UnexpectedValueException(sprintf('The subscriber received [%s].', trim($line))),
        };
    }

    /**
     * Read a bulk string of the given length, and its line ending, from the
     * subscriber connection.
     *
     * @param  int  $length
     * @return string
     *
     * @throws \UnexpectedValueException
     */
    private function readBulkString(int $length): string
    {
        $value = '';

        while (strlen($value) < $length + 2) {
            $chunk = is_resource($this->subscriber) ? fread($this->subscriber, $length + 2 - strlen($value)) : false;

            if ($chunk === false || $chunk === '') {
                throw new \UnexpectedValueException('The subscriber received a truncated reply.');
            }

            $value .= $chunk;
        }

        return substr($value, 0, $length);
    }

    /**
     * Resolve the Redis manager.
     *
     * @return \Illuminate\Contracts\Redis\Factory
     *
     * @throws \UnexpectedValueException
     */
    private function redis(): Factory
    {
        $redis = $this->application()->make('redis');

        if (!$redis instanceof Factory) {
            throw new \UnexpectedValueException(sprintf('Expected the redis binding to be [%s], received [%s].', Factory::class, get_debug_type($redis)));
        }

        return $redis;
    }

    /**
     * Resolve the topology's Redis connection as the valkey-glide wrapper.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \UnexpectedValueException
     */
    private function redisConnection(string $topology): ValkeyGlideConnection
    {
        $connection = $this->redis()->connection($topology);

        if ($connection instanceof ValkeyGlideConnection) {
            return $connection;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis [%s] connection to be [%s], got [%s].', $topology, ValkeyGlideConnection::class, get_debug_type($connection)));
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
     * Resolve the lock provider of a Laravel Redis cache store.
     *
     * @param  string  $name
     * @return \Illuminate\Contracts\Cache\LockProvider
     *
     * @throws \UnexpectedValueException
     */
    private function lockProvider(string $name): LockProvider
    {
        $store = $this->cacheStore($name)->getStore();

        if ($store instanceof LockProvider) {
            return $store;
        }

        throw new \UnexpectedValueException(sprintf('Expected the cache store to be a [%s], received [%s].', LockProvider::class, get_debug_type($store)));
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
}
