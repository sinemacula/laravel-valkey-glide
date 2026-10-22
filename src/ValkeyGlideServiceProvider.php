<?php

declare(strict_types = 1);

namespace SineMacula\Valkey;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Ingests\RedisIngest;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\Pulse\ValkeyGlideIngest;
use SineMacula\Valkey\Queue\Connectors\ValkeyGlideQueueConnector;

/**
 * Valkey GLIDE service provider.
 *
 * Registers the "valkey-glide" Redis client driver with Laravel's RedisManager,
 * a "redis" cache driver whose store stays safe on GLIDE cluster connections;
 * before Laravel 13.5, a "redis" queue connector whose queue hash-tags its keys
 * on GLIDE cluster connections, both otherwise behaving as Laravel's; and, when
 * Laravel Pulse is installed, a Redis ingest that runs on GLIDE connections.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValkeyGlideServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     *
     * @return void
     */
    #[\Override]
    public function register(): void
    {
        $this->callAfterResolving(RedisManager::class, function (RedisManager $redis): void {
            $redis->extend('valkey-glide', fn (): ValkeyGlideConnector => new ValkeyGlideConnector);
        });

        $this->callAfterResolving(CacheManager::class, function (CacheManager $cache): void {
            $cache->extend('redis', $this->redisCacheDriver($cache));
        });

        $this->app->extend(Ingest::class, $this->pulseIngest(...));

        // Laravel hash-tags a cluster queue's keys itself from 13.5, in
        // RedisQueue::getQueueRedisKey(); reflection reads the installed
        // framework rather than the one static analysis sees.
        if ((new \ReflectionClass(RedisQueue::class))->hasMethod('getQueueRedisKey')) {
            return;
        }

        // Only runs before Laravel 13.5, so only those versions' tests reach
        // it. Horizon replaces the connector as the queue manager resolves, so
        // this one, registered after Horizon's by package discovery, connects
        // Horizon's queue when Horizon is loaded.
        // @codeCoverageIgnoreStart
        $this->callAfterResolving(QueueManager::class, function (QueueManager $queue): void {
            $queue->addConnector('redis', fn (): ValkeyGlideQueueConnector => new ValkeyGlideQueueConnector(
                $this->app->make('redis'),
                horizon: $this->app->getProviders(HorizonServiceProvider::class) !== [],
            ));
        });
        // @codeCoverageIgnoreEnd
    }

    /**
     * Wrap Pulse's Redis ingest in one that also runs on GLIDE connections,
     * leaving any other ingest, including a subclass of Pulse's, as it is.
     *
     * Pulse binds its ingest by its configured driver, so the extension sees
     * whichever ingest that resolves; it is never called when Pulse is not
     * installed, as nothing then resolves the contract.
     *
     * @param  \Laravel\Pulse\Contracts\Ingest  $ingest
     * @param  \Illuminate\Contracts\Container\Container  $app
     * @return \Laravel\Pulse\Contracts\Ingest
     */
    private function pulseIngest(Ingest $ingest, Container $app): Ingest
    {
        if ($ingest::class !== RedisIngest::class) {
            return $ingest;
        }

        return new ValkeyGlideIngest($ingest, $app->make(RedisFactory::class), $app->make(Config::class));
    }

    /**
     * Build the "redis" cache driver as CacheManager::createRedisDriver() does,
     * over the GLIDE-aware store.
     *
     * The closure is not static: Laravel 12 binds every driver closure to the
     * manager, which a static closure refuses. Laravel before 12.53 takes no
     * serializable classes, and ignores the argument.
     *
     * @param  \Illuminate\Cache\CacheManager  $cache
     * @return \Closure(\Illuminate\Contracts\Container\Container, array<string, mixed>): \Illuminate\Cache\Repository
     */
    private function redisCacheDriver(CacheManager $cache): \Closure
    {
        return function (Container $app, array $config) use ($cache): Repository {
            $settings   = $app->make(Config::class);
            $connection = $config['connection'] ?? 'default';

            $store = new ValkeyGlideStore(
                $app->make('redis'),
                $config['prefix'] ?? $settings->get('cache.prefix'),
                $connection,
                $settings->get('cache.serializable_classes'),
            );

            return $cache->repository($store->setLockConnection($config['lock_connection'] ?? $connection), $config);
        };
    }
}
