<?php

declare(strict_types = 1);

namespace SineMacula\Valkey;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;

final class RedisServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->resolving('redis', function (RedisManager $redis): void {
            $this->registerDriver($redis);
        });

        if (!$this->app->resolved('redis')) {
            return;
        }

        /** @var RedisManager $redis */
        $redis = $this->app->make('redis');
        $this->registerDriver($redis);
    }

    private function registerDriver(RedisManager $redis): void
    {
        $redis->extend(
            'valkey-glide',
            fn (): ValkeyGlideConnector => new ValkeyGlideConnector,
        );
    }
}
