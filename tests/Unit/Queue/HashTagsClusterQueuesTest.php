<?php

declare(strict_types = 1);

namespace Tests\Unit\Queue;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Queue\Concerns\HashTagsClusterQueues;
use SineMacula\Valkey\Queue\ValkeyGlideHorizonQueue;
use SineMacula\Valkey\Queue\ValkeyGlideQueue;
use Tests\Fakes\LaravelConnectionFake;
use Tests\Fakes\RedisFactoryFake;
use Tests\Fakes\RedisQueueBefore135Fake;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;
use Tests\TestCase;

/**
 * Cluster queue hash tag test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideQueue::class)]
#[CoversClass(ValkeyGlideHorizonQueue::class)]
#[CoversTrait(HashTagsClusterQueues::class)]
final class HashTagsClusterQueuesTest extends TestCase
{
    /**
     * Provide connection prefixes, queue names and the queue each gives on a
     * GLIDE cluster.
     *
     * @return iterable<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function clusterQueueProvider(): iterable
    {
        yield 'name' => ['', 'emails', 'queues:{emails}'];
        yield 'default queue' => ['', null, 'queues:{default}'];
        yield 'untagged prefix' => ['app:', 'emails', 'queues:{emails}'];
        yield 'hash-tagged name' => ['', 'app:{emails}', 'queues:app:{emails}'];
        yield 'hash-tagged prefix' => ['{app}:', 'emails', 'queues:emails'];
        yield 'hash tag across the prefix and name' => ['app{', 'emails}', 'queues:emails}'];
    }

    /**
     * Verify a queue on a GLIDE cluster is hash-tagged before Laravel 13.5,
     * unless its keys already carry a hash tag from the name or the
     * connection's prefix.
     *
     * @param  string  $prefix
     * @param  string|null  $queue
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('clusterQueueProvider')]
    #[Test]
    public function queueOnAGlideClusterIsHashTagged(string $prefix, ?string $queue, string $expected): void
    {
        self::assertSame($expected, $this->queueBefore135(new ValkeyGlideConnection(new ValkeyGlideClusterFake, config: ['prefix' => $prefix]))->getQueue($queue));
    }

    /**
     * Verify a queue on a standalone GLIDE server, or on another client's
     * connection, is left as it is.
     *
     * @return void
     */
    #[Test]
    public function queueOffAGlideClusterIsLeftAsItIs(): void
    {
        self::assertSame('queues:emails', $this->queueBefore135(new ValkeyGlideConnection(new ValkeyGlideFake))->getQueue('emails'));
        self::assertSame('queues:emails', $this->queueBefore135(new LaravelConnectionFake)->getQueue('emails'));
    }

    /**
     * Verify Laravel's and Horizon's queues are hash-tagged on a GLIDE cluster
     * before Laravel 13.5.
     *
     * @return void
     */
    #[Test]
    public function queuesAreHashTaggedBeforeLaravel135(): void
    {
        if ((new \ReflectionClass(RedisQueue::class))->hasMethod('getQueueRedisKey')) {
            self::markTestSkipped('Laravel hash-tags cluster queue keys itself from 13.5.');
        }

        $redis = $this->redis(new ValkeyGlideClusterFake);

        self::assertSame('queues:{emails}', (new ValkeyGlideQueue($redis))->getQueue('emails'));
        self::assertSame('queues:{emails}', (new ValkeyGlideHorizonQueue($redis))->getQueue('emails'));
    }

    /**
     * Verify Laravel's and Horizon's queues are left to Laravel on a GLIDE
     * cluster from 13.5, whose `getQueueRedisKey()` hash-tags the keys itself.
     *
     * @return void
     */
    #[Test]
    public function queuesAreLeftToLaravelFrom135(): void
    {
        if (!(new \ReflectionClass(RedisQueue::class))->hasMethod('getQueueRedisKey')) {
            self::markTestSkipped('The queue hash-tags cluster queue keys itself before Laravel 13.5.');
        }

        $redis = $this->redis(new ValkeyGlideClusterFake);

        self::assertSame('queues:emails', (new ValkeyGlideQueue($redis))->getQueue('emails'));
        self::assertSame('queues:emails', (new ValkeyGlideHorizonQueue($redis))->getQueue('emails'));
    }

    /**
     * Verify the queue's scripts reach a GLIDE cluster with every key in the
     * queue's slot, and a popped job keeps the queue's name.
     *
     * @return void
     */
    #[Test]
    public function scriptsKeyTheQueueInOneSlotOnAGlideCluster(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', ['{"id":"job","attempts":0}', '{"id":"job","attempts":1}']);

        $queue = new ValkeyGlideQueue($this->redis($client));
        $queue->setContainer($this->application());

        $queue->pushRaw('{"id":"job"}', 'emails');
        $queue->later(60, 'App\Jobs\Later', '', 'emails');

        $job = $queue->pop('emails');

        self::assertInstanceOf(RedisJob::class, $job);
        self::assertSame('emails', $job->getQueue());

        $keys = array_map(static function (array $call): array {
            $call = array_values(array_slice($call, 1));

            self::assertSame('EVAL', $call[0]);
            self::assertIsInt($call[2]);

            return array_slice($call, 3, $call[2]);
        }, $client->callsFor('rawcommand'));

        self::assertSame([
            ['queues:{emails}', 'queues:{emails}:notify'],
            ['queues:{emails}:delayed'],
            ['queues:{emails}:delayed', 'queues:{emails}', 'queues:{emails}:notify'],
            ['queues:{emails}:reserved', 'queues:{emails}', 'queues:{emails}:notify'],
            ['queues:{emails}', 'queues:{emails}:reserved', 'queues:{emails}:notify'],
        ], $keys);
    }

    /**
     * Build a Redis factory over a GLIDE connection to the given client.
     *
     * @param  \ValkeyGlide|\ValkeyGlideCluster  $client
     * @return \Tests\Fakes\RedisFactoryFake
     */
    private function redis(\ValkeyGlide|\ValkeyGlideCluster $client): RedisFactoryFake
    {
        return new RedisFactoryFake(new ValkeyGlideConnection($client));
    }

    /**
     * Build a queue over the given connection that hash-tags its keys as the
     * trait does over Laravel's Redis queue before 13.5.
     *
     * @param  \Illuminate\Redis\Connections\Connection  $connection
     * @return \Tests\Fakes\RedisQueueBefore135Fake
     */
    private function queueBefore135(Connection $connection): RedisQueueBefore135Fake
    {
        return new class ($connection) extends RedisQueueBefore135Fake {
            use HashTagsClusterQueues;
        };
    }

    /**
     * Resolve the application.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    private function application(): Application
    {
        $app = $this->app;

        self::assertNotNull($app);

        return $app;
    }
}
