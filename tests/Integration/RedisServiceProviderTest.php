<?php

declare(strict_types = 1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\RedisServiceProvider;
use Tests\Support\FakeApplication;
use Tests\Support\FakeRedisManager;

/**
 * @internal
 */
#[CoversClass(RedisServiceProvider::class)]
final class RedisServiceProviderTest extends TestCase
{
    /**
     * Verify the provider registers a Redis resolving callback.
     *
     * @return void
     */
    public function testRegisterAddsResolvingHookWhenRedisIsNotYetResolved(): void
    {
        $app      = new FakeApplication;
        $provider = new RedisServiceProvider($app);
        $provider->register();

        self::assertArrayHasKey('redis', $app->resolvingCallbacks);
        self::assertSame(0, $app->makeCalls);
    }

    /**
     * Verify the provider immediately registers the driver when resolved.
     *
     * @return void
     */
    public function testRegisterAddsDriverImmediatelyWhenRedisAlreadyResolved(): void
    {
        $app                     = new FakeApplication;
        $app->resolved['redis']  = true;
        $app->instances['redis'] = new FakeRedisManager;

        $provider = new RedisServiceProvider($app);
        $provider->register();

        /** @var \Tests\Support\FakeRedisManager $manager */
        $manager = $app->instances['redis'];

        self::assertArrayHasKey('redis', $app->resolvingCallbacks);
        self::assertArrayHasKey('valkey-glide', $manager->extensions);
        self::assertInstanceOf(ValkeyGlideConnector::class, ($manager->extensions['valkey-glide'])());
        self::assertSame(1, $app->makeCalls);
    }

    /**
     * Verify the resolving callback registers the custom driver.
     *
     * @return void
     */
    public function testResolvingCallbackRegistersDriverWhenRedisResolvesLater(): void
    {
        $app     = new FakeApplication;
        $manager = new FakeRedisManager;

        $provider = new RedisServiceProvider($app);
        $provider->register();

        $callback = $app->resolvingCallbacks['redis'];
        $callback($manager);

        self::assertArrayHasKey('valkey-glide', $manager->extensions);
        self::assertInstanceOf(ValkeyGlideConnector::class, ($manager->extensions['valkey-glide'])());
    }
}
