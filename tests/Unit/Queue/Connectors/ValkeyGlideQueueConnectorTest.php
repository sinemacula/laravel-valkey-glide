<?php

declare(strict_types = 1);

namespace Tests\Unit\Queue\Connectors;

use Illuminate\Queue\RedisQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Queue\Connectors\ValkeyGlideQueueConnector;
use SineMacula\Valkey\Queue\ValkeyGlideHorizonQueue;
use SineMacula\Valkey\Queue\ValkeyGlideQueue;
use Tests\Fakes\RedisFactoryFake;
use Tests\Fakes\ValkeyGlideFake;
use Tests\TestCase;

/**
 * GLIDE-aware queue connector test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideQueueConnector::class)]
final class ValkeyGlideQueueConnectorTest extends TestCase
{
    /**
     * Provide whether the connector connects Horizon's queue, the options, and
     * the queue and options it connects, as Laravel's and Horizon's connectors
     * do; Horizon's keeps a null retry after and takes no migration batch size.
     *
     * @return iterable<string, array{0: bool, 1: array<string, mixed>, 2: class-string<\Illuminate\Queue\RedisQueue>, 3: list<mixed>}>
     */
    public static function connectorProvider(): iterable
    {
        $options = [
            'queue'                => 'emails',
            'connection'           => 'queues',
            'retry_after'          => 90,
            'block_for'            => 5,
            'after_commit'         => true,
            'migration_batch_size' => 100,
        ];

        yield 'laravel' => [false, $options, ValkeyGlideQueue::class, ['emails', 'queues', 90, 5, true, 100]];
        yield 'laravel defaults' => [false, ['queue' => 'default'], ValkeyGlideQueue::class, ['default', null, 60, null, null, -1]];
        yield 'horizon' => [true, $options, ValkeyGlideHorizonQueue::class, ['emails', 'queues', 90, 5, true, -1]];
        yield 'horizon defaults' => [true, ['queue' => 'default'], ValkeyGlideHorizonQueue::class, ['default', null, 60, null, null, -1]];
        yield 'laravel null retry after' => [false, ['queue' => 'default', 'retry_after' => null], ValkeyGlideQueue::class, ['default', null, 60, null, null, -1]];
        yield 'horizon null retry after' => [true, ['queue' => 'default', 'retry_after' => null], ValkeyGlideHorizonQueue::class, ['default', null, null, null, null, -1]];
    }

    /**
     * Verify the connector builds its queue from the options, as Laravel's or
     * Horizon's connector does.
     *
     * @param  bool  $horizon
     * @param  array<string, mixed>  $config
     * @param  class-string<\Illuminate\Queue\RedisQueue>  $class
     * @param  list<mixed>  $options
     * @return void
     */
    #[DataProvider('connectorProvider')]
    #[Test]
    public function connectorBuildsTheQueueFromTheOptions(bool $horizon, array $config, string $class, array $options): void
    {
        $redis = new RedisFactoryFake(new ValkeyGlideConnection(new ValkeyGlideFake));
        $queue = (new ValkeyGlideQueueConnector($redis, $horizon))->connect($config);

        self::assertInstanceOf($class, $queue);
        self::assertSame($redis, $queue->getRedis());
        self::assertSame($options, $this->optionsOf($queue));
    }

    /**
     * Read the options a Redis queue was built with.
     *
     * @param  \Illuminate\Queue\RedisQueue  $queue
     * @return list<mixed>
     */
    private function optionsOf(RedisQueue $queue): array
    {
        return array_map(
            static fn (string $property): mixed => (new \ReflectionProperty(RedisQueue::class, $property))->getValue($queue),
            ['default', 'connection', 'retryAfter', 'blockFor', 'dispatchAfterCommit', 'migrationBatchSize'],
        );
    }
}
