<?php

declare(strict_types = 1);

namespace Tests\Unit\Queue;

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobQueued;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Queue\ValkeyGlideQueue;
use Tests\Fakes\Jobs\BulkJobFake;
use Tests\Fakes\RedisFactoryFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Bulk pushes of Laravel's Redis queue on a GLIDE connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideQueue::class)]
final class BulkValkeyGlideQueueTest extends TestCase
{
    /**
     * Verify each job of a bulk is queued under its own id, whether it is
     * gathered for the bulk's script or pushed on its own once its database
     * transaction commits.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bulkJobsAreQueuedUnderTheirOwnIds(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 2);

        $container    = new Container;
        $events       = new Dispatcher($container);
        $transactions = new DatabaseTransactionsManager;
        $queued       = [];

        $container->instance('events', $events);
        $container->instance('db.transactions', $transactions);
        $events->listen(JobQueued::class, static function (JobQueued $event) use (&$queued): void {
            $queued[] = [$event->id, self::payloadId($event->payload)];
        });

        $queue = new ValkeyGlideQueue(new RedisFactoryFake(new ValkeyGlideConnection($client)));

        $queue->setContainer($container);
        $queue->bulk(['job-a', new BulkJobFake('later', 60)]);

        $transactions->begin('default', 1);
        $queue->bulk([new BulkJobFake('ready', null, true), new BulkJobFake('later', 60, true)]);
        $transactions->commit('default', 1, 0);

        self::assertCount(4, $queued);

        foreach ($queued as [$id, $payloadId]) {
            self::assertIsString($id);
            self::assertSame($payloadId, $id);
        }

        self::assertCount(4, array_unique(array_column($queued, 0)));
    }

    /**
     * Read the id of a job from its payload.
     *
     * @param  string  $payload
     * @return mixed
     */
    private static function payloadId(string $payload): mixed
    {
        $decoded = json_decode($payload, true);

        self::assertIsArray($decoded);

        return $decoded['id'] ?? null;
    }
}
