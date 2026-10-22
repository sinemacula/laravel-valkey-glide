<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Support\HorizonClusterConnection;
use SineMacula\Valkey\ValkeyGlideServiceProvider;
use Tests\TestCase;

/**
 * Horizon cluster service provider test case.
 *
 * Configures a valkey-glide cluster for Horizon before the application's
 * providers register, registers this package's provider in each test, and reads
 * the configuration the Redis manager is built with, without connecting.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideServiceProvider::class)]
#[CoversClass(HorizonClusterConnection::class)]
final class HorizonClusterValkeyGlideServiceProviderTest extends TestCase
{
    /** @var list<array{host: string, port: int}> The cluster Horizon uses. */
    private const array CLUSTER = [['host' => '10.0.0.1', 'port' => 7000], ['host' => '10.0.0.2', 'port' => 7000]];

    /** @var array<string, mixed> Horizon's first-node fallback for the cluster. */
    private const array FALLBACK = ['host' => '10.0.0.1', 'port' => 7000, 'options' => ['prefix' => 'app-horizon:']];

    /**
     * Verify the first-node fallback that Horizon writes before Laravel 13.5
     * reaches the Redis manager as Horizon's hash-tagged cluster connection.
     *
     * The fallback is written by Horizon's own method for it, so the test
     * covers it on every Laravel version.
     *
     * @return void
     */
    #[Test]
    public function horizonsFirstNodeFallbackReachesTheRedisManagerAsTheCluster(): void
    {
        $this->application()->register(ValkeyGlideServiceProvider::class);

        (new \ReflectionMethod(Horizon::class, 'configureStandaloneConnection'))->invoke(null, self::CLUSTER[0]);

        self::assertSame(self::FALLBACK, config('database.redis.horizon'));

        $this->assertHorizonUsesTheCluster();
    }

    /**
     * Verify Horizon's service provider, registered before this package's as
     * package discovery orders them, leaves Horizon on the hash-tagged cluster
     * connection.
     *
     * @return void
     */
    #[Test]
    public function horizonsServiceProviderGetsTheCluster(): void
    {
        $this->application()->register(HorizonServiceProvider::class);
        $this->application()->register(ValkeyGlideServiceProvider::class);

        $this->assertHorizonUsesTheCluster();
    }

    /**
     * Verify a manager built before this package's provider registers keeps the
     * configuration it was built with.
     *
     * @return void
     */
    #[Test]
    public function aManagerBuiltBeforeTheProviderRegistersKeepsItsConfiguration(): void
    {
        config()->set('database.redis.horizon', self::FALLBACK);

        $this->application()->make('redis');
        $this->application()->register(ValkeyGlideServiceProvider::class);
        $this->application()->make('redis');

        self::assertSame(self::FALLBACK, config('database.redis.horizon'));
        self::assertSame('app-horizon:', config('horizon.prefix'));
    }

    /**
     * Configure the cluster Horizon uses, and Horizon's connection and prefix,
     * before any provider registers.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.clusters.queues', self::CLUSTER);
        $app['config']->set('horizon.use', 'queues');
        $app['config']->set('horizon.prefix', 'app-horizon:');
    }

    /**
     * Register no package providers, so each test registers this package's
     * provider itself.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [];
    }

    /**
     * Assert the Redis manager, built as the application next asks for it,
     * holds Horizon's hash-tagged cluster connection and no standalone one.
     *
     * @return void
     */
    private function assertHorizonUsesTheCluster(): void
    {
        $configuration = (new \ReflectionProperty(RedisManager::class, 'config'))->getValue($this->application()->make(RedisManager::class));

        self::assertIsArray($configuration);
        self::assertArrayNotHasKey('horizon', $configuration);
        self::assertSame([...self::CLUSTER, 'options' => ['prefix' => '{app-horizon:}']], $configuration['clusters']['horizon'] ?? null);
        self::assertSame('{app-horizon:}', config('horizon.prefix'));
    }

    /**
     * Resolve the application container.
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
