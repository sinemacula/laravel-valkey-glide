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
     * An unset after commit is false, as the queue reads it, and the integers
     * and flags environment variables give are read as theirs.
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
        yield 'laravel defaults' => [false, ['queue' => 'default'], ValkeyGlideQueue::class, ['default', null, 60, null, false, -1]];
        yield 'horizon' => [true, $options, ValkeyGlideHorizonQueue::class, ['emails', 'queues', 90, 5, true, -1]];
        yield 'horizon defaults' => [true, ['queue' => 'default'], ValkeyGlideHorizonQueue::class, ['default', null, 60, null, false, -1]];
        yield 'laravel null retry after' => [false, ['queue' => 'default', 'retry_after' => null], ValkeyGlideQueue::class, ['default', null, 60, null, false, -1]];
        yield 'horizon null retry after' => [true, ['queue' => 'default', 'retry_after' => null], ValkeyGlideHorizonQueue::class, ['default', null, null, null, false, -1]];

        $environment = [
            'queue'                => 'emails',
            'retry_after'          => '90',
            'block_for'            => '5',
            'after_commit'         => 'false',
            'migration_batch_size' => '-1',
        ];

        yield 'laravel environment' => [false, $environment, ValkeyGlideQueue::class, ['emails', null, 90, 5, false, -1]];
        yield 'horizon environment' => [true, ['after_commit' => 1] + $environment, ValkeyGlideHorizonQueue::class, ['emails', null, 90, 5, true, -1]];
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
     * Provide whether the connector connects Horizon's queue, options of the
     * wrong type, and the message each is refused with.
     *
     * @return iterable<string, array{0: bool, 1: array<string, mixed>, 2: string}>
     */
    public static function invalidOptionProvider(): iterable
    {
        foreach ([false, true] as $horizon) {
            $name = $horizon ? 'horizon' : 'laravel';

            yield "{$name} missing queue" => [$horizon, [], 'The [queue] queue option must be a string, received [null].'];
            yield "{$name} queue" => [$horizon, ['queue' => 1], 'The [queue] queue option must be a string, received [int].'];
            yield "{$name} connection" => [$horizon, ['queue' => 'default', 'connection' => 1], 'The [connection] queue option must be a string, received [int].'];
            yield "{$name} retry after" => [$horizon, ['queue' => 'default', 'retry_after' => 'soon'], 'The [retry_after] queue option must be an integer, received [string].'];
            yield "{$name} block for" => [$horizon, ['queue' => 'default', 'block_for' => 1.5], 'The [block_for] queue option must be an integer, received [float].'];
            yield "{$name} after commit" => [$horizon, ['queue' => 'default', 'after_commit' => 'later'], 'The [after_commit] queue option must be a boolean, received [string].'];
            yield "{$name} after commit float" => [$horizon, ['queue' => 'default', 'after_commit' => 1.0], 'The [after_commit] queue option must be a boolean, received [float].'];
        }

        yield 'laravel migration batch size' => [
            false,
            ['queue' => 'default', 'migration_batch_size' => '1e3'],
            'The [migration_batch_size] queue option must be an integer, received [string].',
        ];
    }

    /**
     * Verify an option of the wrong type is refused, rather than handed to the
     * queue.
     *
     * @param  bool  $horizon
     * @param  array<string, mixed>  $config
     * @param  string  $message
     * @return void
     */
    #[DataProvider('invalidOptionProvider')]
    #[Test]
    public function connectorRefusesAnOptionOfTheWrongType(bool $horizon, array $config, string $message): void
    {
        $connector = new ValkeyGlideQueueConnector(new RedisFactoryFake(new ValkeyGlideConnection(new ValkeyGlideFake)), $horizon);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $connector->connect($config);
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
