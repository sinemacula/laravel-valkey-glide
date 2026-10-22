<?php

declare(strict_types = 1);

namespace Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;
use SineMacula\Valkey\Queue\BulkPush;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Bulk push test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(BulkPush::class)]
final class BulkPushTest extends TestCase
{
    /**
     * Verify the gathered jobs are pushed by one script, which takes the
     * queue's keys, the number of jobs for the queue itself, their payloads,
     * and the time and payload of each delayed job, and that the callbacks
     * waiting for the push run in order once it answers with the number of
     * jobs.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function gatheredJobsArePushedByOneScript(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 4);

        $bulk = new BulkPush;
        $ran  = [];

        self::assertTrue($bulk->isOpen());

        $bulk->add('job-a');
        $bulk->addDelayed(100, 'job-b');
        $bulk->add('job-c');
        $bulk->addDelayed(200, 'job-d');
        $bulk->afterPush(static function () use (&$ran): void {
            $ran[] = 'first';
        });
        $bulk->afterPush(static function () use (&$ran): void {
            $ran[] = 'second';
        });

        $bulk->push(new ValkeyGlideConnection($client, null, ['prefix' => 'app:']), 'queues:default');

        $calls = $client->callsFor('rawcommand');

        self::assertFalse($bulk->isOpen());
        self::assertSame(['first', 'second'], $ran);
        self::assertCount(1, $calls);
        self::assertSame('EVAL', $calls[0][0]);
        self::assertStringContainsString('redis.call(\'rpush\', KEYS[1]', self::stringAt($calls[0], 1));
        self::assertStringContainsString('redis.call(\'zadd\', KEYS[3]', self::stringAt($calls[0], 1));
        self::assertSame(
            [3, 'app:queues:default', 'app:queues:default:notify', 'app:queues:default:delayed', 2, 'job-a', 'job-c', 100, 'job-b', 200, 'job-d'],
            array_slice($calls[0], 2),
        );
    }

    /**
     * Verify a cluster connection sends the script to the queue's own node.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterPushIsRoutedByTheQueueKey(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 1);

        (static function (BulkPush $bulk) use ($client): void {
            $bulk->add('job-a');
            $bulk->push(new ValkeyGlideConnection($client), 'queues:{default}');
        })(new BulkPush);

        $calls = $client->callsFor('rawcommand');

        self::assertCount(1, $calls);
        self::assertSame(['type' => 'primarySlotKey', 'key' => 'queues:{default}'], $calls[0][0]);
        self::assertSame('EVAL', $calls[0][1]);
    }

    /**
     * Verify a bulk with nothing gathered sends nothing, and closes.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function emptyBulkSendsNothing(): void
    {
        $client = new ValkeyGlideFake;
        $bulk   = new BulkPush;
        $ran    = false;

        $bulk->afterPush(static function () use (&$ran): void {
            $ran = true;
        });
        $bulk->push(new ValkeyGlideConnection($client), 'queues:default');

        self::assertFalse($bulk->isOpen());
        self::assertSame([], $client->callOrder());
        self::assertFalse($ran);
    }

    /**
     * Verify a push while a pipeline or transaction is open on the connection
     * is refused before anything is sent or flushed, and runs none of the
     * callbacks.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pushWithABatchOpenIsRefusedBeforeAnythingIsSent(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $bulk       = new BulkPush;
        $ran        = false;

        $connection->pipeline()->set('a', '1');

        $bulk->add('job-a');
        $bulk->afterPush(static function () use (&$ran): void {
            $ran = true;
        });

        try {
            $bulk->push($connection, 'queues:default');
            self::fail('Expected the push to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(
                'A bulk of jobs cannot be pushed to [queues:default] while a pipeline or transaction is open on its connection,'
                . ' as GLIDE would run its script outside the batch; nothing was pushed.',
                $exception->getMessage(),
            );
        }

        self::assertFalse($bulk->isOpen());
        self::assertFalse($ran);
        self::assertSame(['pipeline', 'set'], $client->callOrder());
        self::assertTrue($connection->hasOpenBatch());
    }

    /**
     * Verify a closed bulk stops gathering, and still pushes the jobs it
     * gathered.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function closedBulkStillPushesWhatItGathered(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);

        $bulk = new BulkPush;

        $bulk->add('job-a');
        $bulk->close();

        self::assertFalse($bulk->isOpen());

        $bulk->push(new ValkeyGlideConnection($client), 'queues:default');

        self::assertCount(1, $client->callsFor('rawcommand'));
    }

    /**
     * Verify a script that does not answer with the number of jobs, as GLIDE
     * returns false for every error, throws, and runs none of the callbacks.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedPushThrowsWithoutRunningTheCallbacks(): void
    {
        foreach ([false, 1] as $reply) {
            $client = new ValkeyGlideFake;
            $client->willReturn('rawcommand', $reply);

            $bulk = new BulkPush;
            $ran  = false;

            $bulk->add('job-a');
            $bulk->addDelayed(100, 'job-b');
            $bulk->afterPush(static function () use (&$ran): void {
                $ran = true;
            });

            try {
                $bulk->push(new ValkeyGlideConnection($client), 'queues:default');
                self::fail('Expected the failed push to throw.');
            } catch (ConnectionException $exception) {
                self::assertSame('The bulk push to [queues:default] failed; some or all of its jobs may have been pushed.', $exception->getMessage());
            }

            self::assertFalse($ran);
            self::assertFalse($bulk->isOpen());
        }
    }

    /**
     * Read a string argument of a recorded call.
     *
     * @param  array<array-key, mixed>  $call
     * @param  int  $index
     * @return string
     */
    private static function stringAt(array $call, int $index): string
    {
        $value = $call[$index] ?? null;

        self::assertIsString($value);

        return $value;
    }
}
