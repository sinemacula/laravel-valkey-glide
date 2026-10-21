<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Valkey GLIDE batch test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideBatch::class)]
final class ValkeyGlideBatchTest extends TestCase
{
    /** @var string Message for a batch used after it ended. */
    private const string INACTIVE_MESSAGE = 'This pipeline or transaction has already been executed or discarded.';

    /**
     * Verify a command called on the batch is prefixed, buffered, and returns
     * the batch.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandIsBufferedAndReturnsTheBatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);
        $batch      = $this->newBatch($connection);

        self::assertSame($batch, $batch->set('key', 'value'));
        self::assertSame([['app:key', 'value', null]], $client->callsFor('set'));
        self::assertTrue($client->isBatching());
        self::assertSame([], $client->callsFor('exec'));
    }

    /**
     * Verify exec runs the batch and returns its results in call order.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function execReturnsTheResultsInCallOrder(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $batch = $this->newBatch(new ValkeyGlideConnection($client));

        self::assertSame([true, 'value'], $batch->set('key', 'value')->get('key')->exec());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify exec on a transaction that a WATCH aborted returns false.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function execReturnsFalseWhenAWatchedTransactionIsAborted(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        $connection = new ValkeyGlideConnection($client);
        $connection->command('watch', ['key']);

        $batch = $connection->transaction();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);
        self::assertFalse($batch->set('key', 'value')->exec());
    }

    /**
     * Verify exec that closes a joined multi() returns the batch.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function execClosingAJoinedMultiReturnsTheBatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $batch      = $this->newBatch($connection);

        $batch->multi();

        self::assertSame($batch, $batch->set('key', 'value')->exec());
        self::assertSame([true], $batch->exec());
    }

    /**
     * Verify discard drops the buffered commands without sending them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function discardDropsTheBufferedCommands(): void
    {
        $client = new ValkeyGlideFake;
        $batch  = $this->newBatch(new ValkeyGlideConnection($client));

        $batch->set('key', 'value');

        self::assertTrue($batch->discard());
        self::assertSame([], $client->callsFor('exec'));
        self::assertCount(1, $client->callsFor('discard'));
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a command on a batch that was executed is refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandOnAnExecutedBatchIsRefused(): void
    {
        $client = new ValkeyGlideFake;
        $batch  = $this->newBatch(new ValkeyGlideConnection($client));

        $batch->set('key', 'value')->exec();

        try {
            $batch->set('key', 'other');
            self::fail('Expected a command on an executed batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::INACTIVE_MESSAGE, $exception->getMessage());
        }

        self::assertCount(1, $client->callsFor('set'));
    }

    /**
     * Verify zrevrange on the batch is buffered as ZRANGE ... REV for the
     * prefixed key, as GLIDE has no ZREVRANGE, in a pipeline and a transaction.
     *
     * @param  bool  $atomic
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function zrevrangeIsBufferedAsAReverseZrange(bool $atomic): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zrange', ['job-c' => 3.0, 'job-b' => 2.0]);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);
        $batch      = $atomic ? $connection->multi() : $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);
        self::assertSame($batch, $batch->zrevrange('tag', 0, 1, ['withscores' => true]));
        self::assertSame([], $client->callsFor('zrevrange'));
        self::assertSame([['app:tag', 0, 1, ['rev' => true, 'withscores' => true]]], $client->callsFor('zrange'));
        self::assertSame([['job-c' => 3.0, 'job-b' => 2.0]], $batch->exec());
    }

    /**
     * Verify zrevrange on a batch that was executed is refused rather than run
     * outside it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zrevrangeOnAnExecutedBatchIsRefused(): void
    {
        $client = new ValkeyGlideFake;
        $batch  = $this->newBatch(new ValkeyGlideConnection($client));

        $batch->set('key', 'value')->exec();

        try {
            $batch->zrevrange('tag', 0, 1);
            self::fail('Expected zrevrange on an executed batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::INACTIVE_MESSAGE, $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('zrange'));
    }

    /**
     * Verify exec on a batch that was discarded is refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function execOnADiscardedBatchIsRefused(): void
    {
        $batch = $this->newBatch(new ValkeyGlideConnection(new ValkeyGlideFake));

        $batch->discard();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(self::INACTIVE_MESSAGE);

        $batch->exec();
    }

    /**
     * Verify discard on a batch that was executed is refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function discardOnAnExecutedBatchIsRefused(): void
    {
        $batch = $this->newBatch(new ValkeyGlideConnection(new ValkeyGlideFake));

        $batch->exec();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(self::INACTIVE_MESSAGE);

        $batch->discard();
    }

    /**
     * Verify a pipeline callback passed to the batch runs against it, so its
     * commands join the batch's results.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineCallbackOnTheBatchJoinsIt(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            self::assertSame($pipe, $pipe->set('a', '1')->pipeline(static fn (ValkeyGlideBatch $inner): ValkeyGlideBatch => $inner->set('b', '2')));
            $pipe->get('a');
        });

        self::assertSame([true, true, 'value'], $results);
        self::assertSame([['app:a', '1', null], ['app:b', '2', null]], $client->callsFor('set'));
        self::assertSame(['pipeline', 'set', 'set', 'get', 'exec'], $client->callOrder());
    }

    /**
     * Verify a transaction callback passed to a pipeline's batch joins the
     * pipeline, as a transaction() on the connection does.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function transactionCallbackOnTheBatchJoinsIt(): void
    {
        $client = new ValkeyGlideFake;
        $batch  = $this->newBatch(new ValkeyGlideConnection($client));

        self::assertSame($batch, $batch->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')));
        self::assertSame([true], $batch->exec());
        self::assertSame(['pipeline', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify pipeline() and transaction() on the batch without a callback nest
     * as they do on the connection.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchMethodsWithoutACallbackNestAsOnTheConnection(): void
    {
        $client = new ValkeyGlideFake;
        $batch  = $this->newBatch(new ValkeyGlideConnection($client));

        self::assertSame($batch, $batch->pipeline());
        self::assertSame($batch, $batch->transaction());
        self::assertSame($batch, $batch->set('a', '1')->exec());
        self::assertSame([true], $batch->exec());
    }

    /**
     * Verify executeRaw() on the batch runs the raw command in call order, as
     * it does on the connection.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function executeRawOnTheBatchRunsInCallOrder(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 'PONG');
        $client->willReturn('get', 'value');

        $batch = $this->newBatch(new ValkeyGlideConnection($client));

        self::assertSame($batch, $batch->set('a', '1')->executeRaw(['PING']));
        self::assertSame([true, 'PONG', 'value'], $batch->get('a')->exec());
        self::assertSame([['PING']], $client->callsFor('rawcommand'));
    }

    /**
     * Verify executeRaw() on a cluster batch leads the raw command with the
     * route the connection resolves for it, as on the connection.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function executeRawOnAClusterBatchLeadsWithTheResolvedRoute(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 'value');

        $batch = $this->newBatch(new ValkeyGlideConnection($client));

        self::assertSame($batch, $batch->set('a', '1')->executeRaw(['GET', 'k']));
        self::assertSame([true, 'value'], $batch->exec());
        self::assertSame([[['type' => 'primarySlotKey', 'key' => 'k'], 'GET', 'k']], $client->callsFor('rawcommand'));
        self::assertSame(['pipeline', 'set', 'exec', 'rawcommand'], $client->callOrder());
    }

    /**
     * Provide the batch methods that are refused once the batch has ended.
     *
     * @return iterable<string, array{\Closure(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed}>
     */
    public static function endedBatchMethods(): iterable
    {
        yield 'pipeline' => [static fn (ValkeyGlideBatch $batch): mixed => $batch->pipeline()];
        yield 'transaction' => [static fn (ValkeyGlideBatch $batch): mixed => $batch->transaction()];
        yield 'executeRaw' => [static fn (ValkeyGlideBatch $batch): mixed => $batch->executeRaw(['PING'])];
    }

    /**
     * Verify the batch's own methods are refused once the batch has ended.
     *
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed  $call
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('endedBatchMethods')]
    #[Test]
    public function batchMethodsOnAnEndedBatchAreRefused(\Closure $call): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $batch      = $this->newBatch($connection);

        $batch->discard();

        try {
            $call($batch);
            self::fail('Expected a method on an ended batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::INACTIVE_MESSAGE, $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertSame([], $client->callsFor('rawcommand'));
    }

    /**
     * Start a pipeline on the connection and return its batch.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return \SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function newBatch(ValkeyGlideConnection $connection): ValkeyGlideBatch
    {
        $batch = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        return $batch;
    }
}
