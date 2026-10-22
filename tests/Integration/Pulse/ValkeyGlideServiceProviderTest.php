<?php

declare(strict_types = 1);

namespace Tests\Integration\Pulse;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\RedisManager;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Ingests\NullIngest;
use Laravel\Pulse\Ingests\RedisIngest;
use Laravel\Pulse\Ingests\StorageIngest;
use Laravel\Pulse\PulseServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Pulse\ValkeyGlideIngest;
use SineMacula\Valkey\ValkeyGlideServiceProvider;
use Tests\Fakes\PulseIngestFake;
use Tests\TestCase;

/**
 * Service provider test case for the Laravel Pulse ingest.
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
     * Verify Pulse's Redis ingest is wrapped in the GLIDE-aware ingest when
     * Pulse's provider registers before the package's.
     *
     * @return void
     */
    #[Test]
    public function pulseRedisIngestIsWrappedForGlideConnections(): void
    {
        config(['pulse.ingest.driver' => 'redis']);

        self::assertInstanceOf(ValkeyGlideIngest::class, app(Ingest::class));
    }

    /**
     * Verify Pulse's storage ingest is left as Pulse resolves it.
     *
     * @return void
     */
    #[Test]
    public function pulseStorageIngestIsLeftAsItIs(): void
    {
        config(['pulse.ingest.driver' => 'storage']);

        self::assertInstanceOf(StorageIngest::class, app(Ingest::class));
    }

    /**
     * Verify Pulse's null ingest is left as Pulse resolves it.
     *
     * @return void
     */
    #[Test]
    public function pulseNullIngestIsLeftAsItIs(): void
    {
        config(['pulse.ingest.driver' => 'null']);

        self::assertInstanceOf(NullIngest::class, app(Ingest::class));
    }

    /**
     * Verify an ingest the application binds itself is left as it is.
     *
     * @return void
     */
    #[Test]
    public function applicationIngestIsLeftAsItIs(): void
    {
        $ingest = new PulseIngestFake;

        app()->bind(Ingest::class, static fn (): Ingest => $ingest);

        self::assertSame($ingest, app(Ingest::class));
    }

    /**
     * Verify a subclass of Pulse's Redis ingest the application binds is left
     * as it is, as its own behaviour would otherwise be bypassed.
     *
     * @return void
     */
    #[Test]
    public function applicationRedisIngestSubclassIsLeftAsItIs(): void
    {
        $ingest = new class (app(RedisManager::class), app(Config::class)) extends RedisIngest {};

        app()->bind(Ingest::class, static fn (): Ingest => $ingest);

        self::assertSame($ingest, app(Ingest::class));
    }

    /**
     * Verify Pulse's Redis ingest is wrapped when the package's provider
     * registers first, as the extension outlives the binding Pulse adds later.
     *
     * @return void
     */
    #[Test]
    public function pulseRedisIngestIsWrappedWhenThePackageRegistersFirst(): void
    {
        $app    = new Container;
        $config = new Repository;
        $redis  = new RedisManager($app, 'valkey-glide', []);

        (new ValkeyGlideServiceProvider($app))->register();

        $app->instance(Config::class, $config);
        $app->instance(Factory::class, $redis);
        $app->bind(Ingest::class, static fn (): Ingest => new RedisIngest($redis, $config));

        self::assertInstanceOf(ValkeyGlideIngest::class, $app->make(Ingest::class));
    }

    /**
     * Register Pulse's provider before the package's.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            PulseServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }
}
