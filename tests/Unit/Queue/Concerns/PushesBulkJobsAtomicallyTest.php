<?php

declare(strict_types = 1);

namespace Tests\Unit\Queue\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Attributes\Delay;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\LuaScripts;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;
use SineMacula\Valkey\Queue\Concerns\PushesBulkJobsAtomically;
use SineMacula\Valkey\Queue\ValkeyGlideQueue;
use Tests\Fakes\Jobs\BulkJobFake;
use Tests\Fakes\Jobs\DelayAttributeJobFake;
use Tests\Fakes\LaravelConnectionFake;
use Tests\Fakes\RedisFactoryFake;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Atomic bulk pushes of a Redis queue on a GLIDE connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideQueue::class)]
#[CoversTrait(PushesBulkJobsAtomically::class)]
final class PushesBulkJobsAtomicallyTest extends TestCase
{
    /** @var int The time the tests' clock is frozen at. */
    private const int NOW = 1700000000;

    /**
     * Freeze the clock, so delayed jobs have a known time.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
    }

    /**
     * Restore the real clock.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Verify a bulk on a GLIDE connection queues every job, with Laravel's
     * queueing events, and pushes them all, delayed jobs included, with one
     * script, without opening a pipeline or transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bulkPushesEveryJobWithOneScript(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 3);

        $container  = new Container;
        $dispatcher = new Dispatcher($container);
        $events     = [];

        $container->instance('events', $dispatcher);
        $dispatcher->listen([JobQueueing::class, JobQueued::class], static function (object $event) use (&$events): void {
            $events[] = $event::class;
        });

        $this->queue($client, $container)->bulk(['job-a', new BulkJobFake('job-b', 60), 'job-c'], ['k' => 'v'], 'mail');

        $calls = $client->callsFor('rawcommand');

        self::assertSame(['rawcommand'], $client->callOrder());
        self::assertSame([3, 'app:queues:mail', 'app:queues:mail:notify', 'app:queues:mail:delayed', 2], array_slice($calls[0], 2, 5));
        self::assertSame(['job-a', 'job-c'], array_map(self::jobName(...), array_slice($calls[0], 7, 2)));
        self::assertSame(self::NOW + 60, $calls[0][9]);
        self::assertSame(BulkJobFake::class, self::jobName($calls[0][10]));
        self::assertCount(11, $calls[0]);
        self::assertSame(array_merge(...array_fill(0, 3, [JobQueueing::class, JobQueued::class])), $events);
    }

    /**
     * Verify a bulk whose push fails throws, rather than drop the jobs quietly.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedBulkPushThrows(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', false);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('The bulk push to [queues:default] failed; some or all of its jobs may have been pushed.');

        $this->queue($client, $this->container(), '')->bulk(['job-a', 'job-b']);
    }

    /**
     * Verify a single job given in place of a list is pushed, as Laravel's bulk
     * takes it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bulkTakesASingleJob(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);

        $this->queue($client, $this->container(), '')->bulk('job-a');

        $call = $client->callsFor('rawcommand')[0];

        self::assertSame([1, 'job-a'], [$call[6], self::jobName($call[7])]);
    }

    /**
     * Verify a job that is neither an object nor a string is refused before
     * anything is queued.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bulkRefusesAJobThatIsNeitherAnObjectNorAString(): void
    {
        $client = new ValkeyGlideFake;

        try {
            $this->queue($client, $this->container())->bulk([42]);
            self::fail('Expected the job to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('A job pushed in bulk must be an object or a string, [int] given.', $exception->getMessage());
        }

        self::assertSame([], $client->callOrder());
    }

    /**
     * Verify a bulk on a GLIDE cluster connection pushes to the queue's
     * hash-tagged keys, as Laravel's push does from 13.5 and the queue's own
     * keys are before, so its script stays in one slot.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterBulkPushesToTheQueuesOwnKeys(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 1);

        $this->queue($client, $this->container(), '')->bulk(['job-a'], '', 'mail');

        self::assertSame(['queues:{mail}', 'queues:{mail}:notify', 'queues:{mail}:delayed'], array_slice($client->callsFor('rawcommand')[0], 4, 3));
    }

    /**
     * Verify a job delayed by Laravel 13's `#[Delay]` attribute joins the bulk
     * as a delayed job, as Laravel's bulk reads the attribute.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function delayAttributeDelaysAJobInTheBulk(): void
    {
        if (!class_exists(Delay::class)) {
            self::markTestSkipped('Laravel 12 has no #[Delay] attribute.');
        }

        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);

        $this->queue($client, $this->container(), '')->bulk([new DelayAttributeJobFake]);

        $call = $client->callsFor('rawcommand')[0];

        self::assertSame(0, $call[6]);
        self::assertSame(self::NOW + 30, $call[7]);
    }

    /**
     * Verify a job that waits for a database transaction joins the bulk when
     * none is open, and is pushed on its own, as Laravel pushes it, once the
     * transaction it waits for commits after the bulk was pushed.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function afterCommitJobIsPushedOnItsOwnOnceItsTransactionCommits(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 2);

        $container    = $this->container();
        $transactions = new DatabaseTransactionsManager;

        $container->instance('db.transactions', $transactions);

        $queue = $this->queue($client, $container, '');

        $queue->bulk([new BulkJobFake('first', null, true), 'job-b']);

        self::assertCount(1, $client->callsFor('rawcommand'));
        self::assertSame(2, $client->callsFor('rawcommand')[0][6]);

        $client->willReturn('rawcommand', 1);
        $transactions->begin('default', 1);
        $queue->bulk([new BulkJobFake('ready', null, true), new BulkJobFake('later', 60, true), 'job-c']);

        $calls = $client->callsFor('rawcommand');

        self::assertCount(2, $calls);
        self::assertSame([1, 'job-c'], [$calls[1][6], self::jobName($calls[1][7])]);

        $transactions->commit('default', 1, 0);

        $calls = $client->callsFor('rawcommand');

        self::assertCount(4, $calls);
        self::assertSame([LuaScripts::push(), 2, 'queues:default', 'queues:default:notify'], array_slice($calls[2], 1, 4));
        self::assertSame(BulkJobFake::class, self::jobName($calls[2][5]));
        self::assertSame([LuaScripts::later(), 1, 'queues:default:delayed', self::NOW + 60], array_slice($calls[3], 1, 4));
    }

    /**
     * Verify a bulk on any other connection is Laravel's.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bulkOnAnotherConnectionIsLaravels(): void
    {
        $other = new LaravelConnectionFake;
        $queue = new ValkeyGlideQueue(new RedisFactoryFake($other));

        $queue->setContainer($this->container());
        $queue->bulk(['job-a']);

        self::assertSame(['pipeline'], array_column($other->commands, 0));
    }

    /**
     * Verify a bulk on a GLIDE connection with a pipeline open is refused
     * before its script is sent, as the script would run outside the batch, and
     * the pipeline carries on.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bulkInsideAnOpenBatchIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $queue      = new ValkeyGlideQueue(new RedisFactoryFake($connection));
        $container  = new Container;
        $dispatcher = new Dispatcher($container);
        $queued     = 0;

        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $dispatcher->listen(JobQueueing::class, static function () use (&$queued): void {
            $queued++;
        });

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($queue): void {
            $pipe->set('a', '1');

            try {
                $queue->bulk(['job-a']);
                self::fail('Expected the bulk to be refused.');
            } catch (\LogicException $exception) {
                self::assertStringStartsWith('A bulk of jobs cannot be pushed to [queues:default] while a pipeline or transaction is open', $exception->getMessage());
            }
        });

        self::assertSame([true], $results);
        self::assertSame(1, $queued);
        self::assertSame([], $client->callsFor('rawcommand'));
    }

    /**
     * Verify a bulk that fails before it is pushed leaves a job waiting for its
     * database transaction to be pushed on its own once it commits, and pushes
     * none of the jobs it gathered.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedBulkLeavesWaitingJobsToBePushedOnTheirOwn(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);

        $container    = $this->container();
        $transactions = new DatabaseTransactionsManager;

        $container->instance('db.transactions', $transactions);

        $queue = $this->queue($client, $container, '');

        $transactions->begin('default', 1);

        try {
            $queue->bulk(['job-a', new BulkJobFake('waiting', null, true), 42]);
            self::fail('Expected the bulk to fail.');
        } catch (\InvalidArgumentException) {
            // The third job is refused.
        }

        self::assertSame([], $client->callOrder());

        $transactions->commit('default', 1, 0);

        $calls = $client->callsFor('rawcommand');

        self::assertCount(1, $calls);
        self::assertSame(LuaScripts::push(), $calls[0][1]);
        self::assertSame(BulkJobFake::class, self::jobName($calls[0][5]));
    }

    /**
     * Build a container that dispatches events.
     *
     * @return \Illuminate\Container\Container
     */
    private function container(): Container
    {
        $container = new Container;

        $container->instance('events', new Dispatcher($container));

        return $container;
    }

    /**
     * Build a queue over a GLIDE connection.
     *
     * @param  \ValkeyGlide|\ValkeyGlideCluster  $client
     * @param  \Illuminate\Container\Container  $container
     * @param  string  $prefix
     * @return \SineMacula\Valkey\Queue\ValkeyGlideQueue
     */
    private function queue(\ValkeyGlide|\ValkeyGlideCluster $client, Container $container, string $prefix = 'app:'): ValkeyGlideQueue
    {
        $queue = new ValkeyGlideQueue(new RedisFactoryFake(new ValkeyGlideConnection($client, null, ['prefix' => $prefix])));

        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        return $queue;
    }

    /**
     * Read the job name from a payload.
     *
     * @param  mixed  $payload
     * @return mixed
     */
    private static function jobName(mixed $payload): mixed
    {
        self::assertIsString($payload);

        $decoded = json_decode($payload, true);

        self::assertIsArray($decoded);

        return $decoded['displayName'] ?? null;
    }
}
