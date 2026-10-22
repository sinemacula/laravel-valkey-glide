<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\LuaScripts;
use Illuminate\Queue\Queue;
use Laravel\Horizon\Events\JobPending;
use Laravel\Horizon\Events\JobPushed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;
use SineMacula\Valkey\Queue\ValkeyGlideHorizonQueue;
use Tests\Fakes\Jobs\BulkJobFake;
use Tests\Fakes\RedisFactoryFake;
use Tests\Fakes\ValkeyGlideFake;
use Tests\TestCase;

/**
 * Bulk pushes of Horizon's Redis queue on a GLIDE connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideHorizonQueue::class)]
final class BulkValkeyGlideHorizonQueueTest extends TestCase
{
    /** @var list<array{string, mixed, int}> Job events heard: the event, its job id and the client calls made before it. */
    private array $heard = [];

    /** @var \Tests\Fakes\ValkeyGlideFake The client the queue's connection runs on. */
    private ValkeyGlideFake $client;

    /**
     * Listen for Horizon's job events.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ValkeyGlideFake;
        $this->heard  = [];

        $this->application()->make(Dispatcher::class)->listen([JobPending::class, JobPushed::class], function (JobPending|JobPushed $event): void {
            self::assertSame('redis', $event->connectionName);
            self::assertSame('mail', $event->queue);

            $this->heard[] = [$event::class, $event->payload->id(), count($this->client->callOrder())];
        });
    }

    /**
     * Forget the payload hooks the tests add.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Queue::createPayloadUsing(null);

        parent::tearDown();
    }

    /**
     * Verify a bulk prepares each job as Horizon does, fires `JobPending`, then
     * Laravel's `JobQueued`, for each before the one script pushes them all,
     * and `JobPushed` for each after it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bulkFiresHorizonsEventsAroundOnePush(): void
    {
        $this->client->willReturn('rawcommand', 2);

        $this->application()->make(Dispatcher::class)->listen(JobQueued::class, function (JobQueued $event): void {
            $this->heard[] = [JobQueued::class, $event->id, count($this->client->callOrder())];
        });

        $this->queue()->bulk(['job-a', new BulkJobFake('later', 60)], '', 'mail');

        $call = $this->client->callsFor('rawcommand')[0];
        $ids  = [self::payloadOf($call[7])['uuid'], self::payloadOf($call[9])['uuid']];

        self::assertSame(['rawcommand'], $this->client->callOrder());
        self::assertSame(1, $call[6]);
        self::assertSame(BulkJobFake::class, self::payloadOf($call[9])['displayName']);
        self::assertSame(['job', 'job'], [self::payloadOf($call[7])['type'], self::payloadOf($call[9])['type']]);
        self::assertSame([
            [JobPending::class, $ids[0], 0],
            [JobQueued::class, $ids[0], 0],
            [JobPending::class, $ids[1], 0],
            [JobQueued::class, $ids[1], 0],
            [JobPushed::class, $ids[0], 1],
            [JobPushed::class, $ids[1], 1],
        ], $this->heard);
    }

    /**
     * Verify the payload hooks are given the queue as Horizon's push and later
     * give it: the queue's key for a job pushed onto the queue, and the queue
     * as it was named for a delayed job.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function payloadHooksAreGivenTheQueueAsHorizonGivesIt(): void
    {
        $this->client->willReturn('rawcommand', 2);

        $queues = [];

        Queue::createPayloadUsing(static function (mixed $connection, mixed $queue) use (&$queues): array {
            $queues[] = $queue;

            return [];
        });

        $this->queue()->bulk(['job-a', new BulkJobFake('later', 60)], '', 'mail');

        self::assertSame(['queues:mail', 'mail'], $queues);
    }

    /**
     * Verify a bulk whose push fails fires no `JobPushed`.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedBulkFiresNoPushedEvent(): void
    {
        $this->client->willReturn('rawcommand', false);

        try {
            $this->queue()->bulk(['job-a'], '', 'mail');
            self::fail('Expected the failed push to throw.');
        } catch (ConnectionException) {
            // The push failed.
        }

        self::assertSame([JobPending::class], array_column($this->heard, 0));
    }

    /**
     * Verify a job of the bulk that waits for its database transaction is
     * pushed on its own once it commits, as Horizon's push and later push it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function afterCommitJobsArePushedAsHorizonPushesThem(): void
    {
        $this->client->willReturn('rawcommand', 1);

        $transactions = new DatabaseTransactionsManager;

        $this->application()->instance('db.transactions', $transactions);

        $transactions->begin('default', 1);
        $this->queue()->bulk([new BulkJobFake('ready', null, true), new BulkJobFake('later', 60, true)], '', 'mail');

        self::assertSame([], $this->client->callOrder());

        $transactions->commit('default', 1, 0);

        $calls = $this->client->callsFor('rawcommand');

        self::assertSame([LuaScripts::push(), LuaScripts::later()], array_column($calls, 1));
        self::assertSame(['job', 'job'], [self::payloadOf($calls[0][5])['type'], self::payloadOf($calls[1][5])['type']]);
        self::assertSame([
            [JobPending::class, 0],
            [JobPushed::class, 1],
            [JobPending::class, 1],
            [JobPushed::class, 2],
        ], array_map(static fn (array $heard): array => [$heard[0], $heard[2]], $this->heard));
    }

    /**
     * Build a Horizon queue over a GLIDE connection.
     *
     * @return \SineMacula\Valkey\Queue\ValkeyGlideHorizonQueue
     */
    private function queue(): ValkeyGlideHorizonQueue
    {
        $queue = new ValkeyGlideHorizonQueue(new RedisFactoryFake(new ValkeyGlideConnection($this->client)));

        $queue->setContainer($this->application());
        $queue->setConnectionName('redis');

        return $queue;
    }

    /**
     * Resolve the application.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    private function application(): Application
    {
        self::assertNotNull($this->app);

        return $this->app;
    }

    /**
     * Decode a job payload.
     *
     * @param  mixed  $payload
     * @return array<array-key, mixed>
     */
    private static function payloadOf(mixed $payload): array
    {
        self::assertIsString($payload);

        $decoded = json_decode($payload, true);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
