<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;
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
    /**
     * Verify a redis queue connection connects the GLIDE-aware Horizon queue
     * before Laravel 13.5 when Horizon is registered first, as package
     * discovery registers it.
     *
     * @return void
     */
    #[Test]
    public function redisQueueConnectsTheGlideHorizonQueueBeforeLaravel135(): void
    {
        if ((new \ReflectionClass(RedisQueue::class))->hasMethod('getQueueRedisKey')) {
            self::markTestSkipped('Laravel hash-tags cluster queue keys itself from 13.5.');
        }

        config(['queue.connections.glide' => ['driver' => 'redis', 'queue' => 'default']]);

        self::assertInstanceOf(ValkeyGlideHorizonQueue::class, Queue::connection('glide'));
    }

    /**
     * Register Horizon before the package, as package discovery orders them.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            HorizonServiceProvider::class,
            ValkeyGlideServiceProvider::class,
        ];
    }
}
