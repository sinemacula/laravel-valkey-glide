<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Cache\ValkeyGlideTaggedCache;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\Queue\ValkeyGlideQueue;
use SineMacula\Valkey\ValkeyGlideServiceProvider;
use Tests\TestCase;

/**
 * Service provider test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideServiceProvider::class)]
final class ValkeyGlideServiceProviderTest extends TestCase
{
    /**
     * Verify provider registration wires the valkey-glide connector creator.
     *
     * @return void
     */
    #[Test]
    public function registerAddsValkeyGlideConnectorCreatorToRedisManager(): void
    {
        $app = new Container;

        $provider = new ValkeyGlideServiceProvider($app);
        $provider->register();

        $app->singleton(RedisManager::class, fn (): RedisManager => new RedisManager(
            $app,
            'valkey-glide',
            [
                'default' => [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                ],
                'options' => [],
            ],
        ));

        $resolvedManager = $app->make(RedisManager::class);

        $resolver = \Closure::bind(
            fn (): mixed => $this->connector(),
            $resolvedManager,
            RedisManager::class,
        );

        self::assertInstanceOf(ValkeyGlideConnector::class, $resolver());
    }

    /**
     * Verify the connector creator is registered on a Redis manager resolved
     * before the provider registers.
     *
     * @return void
     */
    #[Test]
    public function registerAddsValkeyGlideConnectorCreatorToARedisManagerResolvedFirst(): void
    {
        $app = $this->app;

        self::assertNotNull($app);

        $redis = new RedisManager($app, 'valkey-glide', []);

        $app->instance('redis', $redis);

        (new ValkeyGlideServiceProvider($app))->register();

        $resolver = \Closure::bind(fn (): mixed => $this->connector(), $redis, RedisManager::class);

        self::assertInstanceOf(ValkeyGlideConnector::class, $resolver());
    }

    /**
     * Verify the redis cache driver builds the GLIDE-aware store from the
     * store's configuration, as Laravel's own driver does.
     *
     * @return void
     */
    #[Test]
    public function redisDriverBuildsTheGlideAwareStore(): void
    {
        config(['cache.stores.glide' => [
            'driver'          => 'redis',
            'connection'      => 'cache',
            'lock_connection' => 'locks',
            'prefix'          => 'store-prefix:',
        ]]);

        $repository = Cache::store('glide');
        $store      = $repository->getStore();

        self::assertInstanceOf(Repository::class, $repository);
        self::assertInstanceOf(ValkeyGlideStore::class, $store);
        self::assertSame('store-prefix:', $store->getPrefix());
        self::assertSame('cache', $this->property($store, 'connection'));
        self::assertSame('locks', $this->property($store, 'lockConnection'));
        self::assertNotNull($repository->getEventDispatcher());
    }

    /**
     * Verify the store falls back to the default connection, the connection for
     * locks, and the cache's own prefix, as Laravel's driver does.
     *
     * @return void
     */
    #[Test]
    public function redisDriverFallsBackAsLaravelsDriverDoes(): void
    {
        config([
            'cache.prefix'       => 'cache-prefix:',
            'cache.stores.glide' => ['driver' => 'redis'],
        ]);

        $store = Cache::store('glide')->getStore();

        self::assertInstanceOf(ValkeyGlideStore::class, $store);
        self::assertSame('cache-prefix:', $store->getPrefix());
        self::assertSame('default', $this->property($store, 'connection'));
        self::assertSame('default', $this->property($store, 'lockConnection'));
    }

    /**
     * Verify a store with events turned off dispatches none, as with Laravel's
     * driver.
     *
     * @return void
     */
    #[Test]
    public function redisDriverHonoursDisabledEvents(): void
    {
        config(['cache.stores.glide' => ['driver' => 'redis', 'events' => false]]);

        $repository = Cache::store('glide');

        self::assertInstanceOf(Repository::class, $repository);
        self::assertNull($repository->getEventDispatcher());
    }

    /**
     * Verify the configured serializable classes reach the store, on the
     * Laravel versions whose store takes them.
     *
     * @return void
     */
    #[Test]
    public function redisDriverPassesTheSerializableClasses(): void
    {
        if (!property_exists(RedisStore::class, 'serializableClasses')) {
            self::markTestSkipped('This Laravel version\'s Redis store takes no serializable classes.');
        }

        config([
            'cache.serializable_classes' => [\stdClass::class],
            'cache.stores.glide'         => ['driver' => 'redis'],
        ]);

        self::assertSame([\stdClass::class], $this->property(Cache::store('glide')->getStore(), 'serializableClasses'));
    }

    /**
     * Verify the driver is registered on a cache manager resolved before the
     * provider registers.
     *
     * @return void
     */
    #[Test]
    public function redisDriverReachesACacheManagerResolvedFirst(): void
    {
        $app = $this->app;

        self::assertNotNull($app);

        $cache = new CacheManager($app);

        $app->instance('cache', $cache);

        (new ValkeyGlideServiceProvider($app))->register();

        config(['cache.stores.glide' => ['driver' => 'redis']]);

        self::assertInstanceOf(ValkeyGlideStore::class, $cache->store('glide')->getStore());
    }

    /**
     * Verify the store does not depend on the Redis client configured when the
     * cache manager resolved, since it differs from Laravel's only over a GLIDE
     * cluster connection.
     *
     * @return void
     */
    #[Test]
    public function redisDriverServesWhicheverClientIsConfigured(): void
    {
        config([
            'database.redis.client' => 'phpredis',
            'cache.stores.glide'    => ['driver' => 'redis'],
        ]);

        self::assertInstanceOf(ValkeyGlideStore::class, Cache::store('glide')->getStore());
    }

    /**
     * Verify the redis cache store's tags use the GLIDE-aware tagged cache,
     * without connecting.
     *
     * @return void
     */
    #[Test]
    public function redisCacheStoreTagsUseTheGlideTaggedCache(): void
    {
        config(['cache.stores.redis' => ['driver' => 'redis']]);

        $repository = Cache::store('redis');

        self::assertInstanceOf(Repository::class, $repository);
        self::assertInstanceOf(ValkeyGlideTaggedCache::class, $repository->tags(['a']));
    }

    /**
     * Verify a redis queue connection connects the GLIDE-aware queue, on every
     * Laravel version.
     *
     * @return void
     */
    #[Test]
    public function redisQueueConnectsTheGlideQueue(): void
    {
        config(['queue.connections.glide' => ['driver' => 'redis', 'queue' => 'default']]);

        self::assertInstanceOf(ValkeyGlideQueue::class, Queue::connection('glide'));
    }

    /**
     * Verify the queue connector reaches a queue manager resolved before the
     * provider registers, as the provider registers, and again as it boots.
     *
     * @return void
     */
    #[Test]
    public function redisQueueConnectorReachesAQueueManagerResolvedFirst(): void
    {
        $app = $this->app;

        self::assertNotNull($app);

        config(['queue.connections.glide' => ['driver' => 'redis', 'queue' => 'default']]);

        $phases = [
            'register' => static fn (ValkeyGlideServiceProvider $provider) => $provider->register(),
            'boot'     => static fn (ValkeyGlideServiceProvider $provider) => $provider->boot(),
        ];

        foreach ($phases as $phase) {
            $queues = new QueueManager($app);

            $app->instance('queue', $queues);

            $phase(new ValkeyGlideServiceProvider($app));

            self::assertInstanceOf(ValkeyGlideQueue::class, $queues->connection('glide'));
        }
    }

    /**
     * Read a protected property of the given store.
     *
     * @param  object  $store
     * @param  string  $name
     * @return mixed
     */
    private function property(object $store, string $name): mixed
    {
        return (new \ReflectionProperty(RedisStore::class, $name))->getValue($store);
    }
}
