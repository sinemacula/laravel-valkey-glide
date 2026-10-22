<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Laravel\Horizon\HorizonServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Queue\ValkeyGlideHorizonQueue;
use SineMacula\Valkey\ValkeyGlideServiceProvider;
use Tests\TestCase;

/**
 * Redis queue connector registration test case, under Horizon.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideServiceProvider::class)]
final class HorizonQueueValkeyGlideServiceProviderTest extends TestCase
{
    /** @var bool Whether Horizon is registered after the package, rather than before it. */
    private bool $horizonLast = false;

    /**
     * Verify a redis queue connection connects the GLIDE-aware Horizon queue,
     * on every Laravel version, when Horizon is registered first, as package
     * discovery registers it.
     *
     * @return void
     */
    #[Test]
    public function redisQueueConnectsTheGlideHorizonQueue(): void
    {
        self::assertInstanceOf(ValkeyGlideHorizonQueue::class, $this->redisQueue());
    }

    /**
     * Verify a redis queue connection connects the GLIDE-aware Horizon queue
     * even when Horizon, which replaces the connector, registers after the
     * package.
     *
     * @return void
     */
    #[Test]
    public function redisQueueConnectsTheGlideHorizonQueueWhenHorizonRegistersLast(): void
    {
        $this->horizonLast = true;

        $this->refreshApplication();

        self::assertInstanceOf(ValkeyGlideHorizonQueue::class, $this->redisQueue());
    }

    /**
     * Register Horizon before the package, as package discovery orders them, or
     * after it.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        $providers = [HorizonServiceProvider::class, ValkeyGlideServiceProvider::class];

        return $this->horizonLast ? array_reverse($providers) : $providers;
    }

    /**
     * Resolve a redis queue connection.
     *
     * @return \Illuminate\Contracts\Queue\Queue
     */
    private function redisQueue(): Queue
    {
        $app = $this->app;

        self::assertInstanceOf(Application::class, $app);

        config(['queue.connections.glide' => ['driver' => 'redis', 'queue' => 'default']]);

        return $app->make(QueueManager::class)->connection('glide');
    }
}
