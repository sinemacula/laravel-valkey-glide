<?php

declare(strict_types = 1);

namespace SineMacula\Valkey;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;

/**
 * Valkey GLIDE service provider.
 *
 * Registers the "valkey-glide" Redis client driver with Laravel's RedisManager.
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
        $this->app->afterResolving(RedisManager::class, function (RedisManager $redis): void {
            $redis->extend('valkey-glide', fn (): ValkeyGlideConnector => new ValkeyGlideConnector);
        });
    }
}
