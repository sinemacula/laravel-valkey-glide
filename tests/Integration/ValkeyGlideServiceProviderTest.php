<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\ValkeyGlideServiceProvider;

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

        $resolved_manager = $app->make(RedisManager::class);

        $resolver = \Closure::bind(
            fn (): mixed => $this->connector(),
            $resolved_manager,
            RedisManager::class,
        );

        self::assertInstanceOf(ValkeyGlideConnector::class, $resolver());
    }
}
