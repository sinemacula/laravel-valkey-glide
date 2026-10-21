<?php

declare(strict_types = 1);

namespace SineMacula\Valkey;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;

/**
 * Valkey GLIDE service provider.
 *
 * Registers the "valkey-glide" Redis client driver with Laravel's RedisManager,
 * and a "redis" cache driver whose store stays safe on GLIDE cluster
 * connections and otherwise behaves as Laravel's.
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
