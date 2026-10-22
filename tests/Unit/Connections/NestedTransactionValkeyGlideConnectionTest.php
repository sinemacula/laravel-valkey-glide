<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Transactions nested in a pipeline on the Valkey GLIDE connection.
 *
 * GLIDE cannot nest a MULTI inside a pipeline, so the connection sends what the
 * pipeline buffered, runs the transaction on its own when it closes, and gives
 * its results one place in the pipeline's results.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
final class NestedTransactionValkeyGlideConnectionTest extends TestCase
{
    /** @var string Message for a pipeline callback that returns with a multi() it opened still open. */
    private const string UNCLOSED_TRANSACTION_MESSAGE = 'A multi() opened inside the pipeline was not closed with exec() before the callback returned,'
        . ' so the transaction and the rest of the pipeline were discarded.';

    /** @var string Message for a command GLIDE cannot buffer inside a transaction. */
    private const string IMMEDIATE_IN_TRANSACTION_MESSAGE = '[%s] cannot run inside a transaction, as GLIDE sends it at once, outside the MULTI block,'
        . ' so the transaction would not be atomic; use pipeline() instead.';

    /** @var string Message for a batch that ended inside its callback. */
    private const string ENDED_IN_CALLBACK_MESSAGE = 'The batch ended inside its callback, so it was not executed; any of its commands not yet sent were discarded.';

    /** @var string Message for a connection call made inside a callback whose batch has ended. */
    private const string ENDED_BATCH_MESSAGE = '[%s()] cannot be called once the pipeline or transaction has ended inside its callback; return or throw from the callback.';

    /** @var string Message for a pipeline whose exec() failed. */
    private const string PIPELINE_FAILED_MESSAGE = 'The pipeline failed; some of its commands may have been applied.';

    /** @var string Message for a batch a listener left open while a batch was set aside. */
    private const string LEFT_OPEN_BY_LISTENER_MESSAGE = 'A listener left a pipeline or transaction open while [%s] ran in a pipeline, so both were ended.';

    /** @var string Message for a batch used after it was executed or discarded. */
    private const string INACTIVE_MESSAGE = 'This pipeline or transaction has already been executed or discarded.';

    /**
     * Verify a transaction callback nested in a pipeline runs as its own
     * MULTI/EXEC after what the pipeline buffered, and its results take one
     * place in the pipeline's results.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function nestedTransactionRunsOnItsOwn(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incr', 1);
        $client->willReturn('get', '1');

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);
        $dispatcher = new Dispatcher;
        $events     = [];

        $connection->setEventDispatcher($dispatcher);
        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = [$event->command, $event->parameters];
        });

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection): void {
            $pipe->set('a', '1');

            self::assertSame($pipe, $connection->transaction(static function (ValkeyGlideBatch $tx) use ($pipe): void {
                self::assertNotSame($pipe, $tx);

                $tx->incr('n')->get('n');
            }));

            $pipe->set('c', '3');
        });

        self::assertSame([true, [1, '1'], true], $results);
        self::assertSame(['pipeline', 'set', 'exec', 'multi', 'incr', 'get', 'exec', 'pipeline', 'set', 'exec'], $client->callOrder());
        self::assertSame([['app:n'], ['app:n']], [...$client->callsFor('incr'), ...$client->callsFor('get')]);
        self::assertSame([['pipeline', ['set']], ['transaction', ['incr', 'get']], ['pipeline', ['set']]], $events);
        self::assertFalse($connection->hasOpenBatch());
    }

    /**
     * Provide the ways a transaction is opened without a callback inside a
     * pipeline.
     *
     * @return iterable<string, array{\Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection, \SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed}>
     */
    public static function nestedTransactionOpenings(): iterable
    {
        yield 'bare multi' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->command('multi')];
        yield 'fluent transaction' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->transaction()];
        yield 'batch multi' => [static fn (ValkeyGlideConnection $connection, ValkeyGlideBatch $pipe): mixed => $pipe->multi()];
    }

    /**
     * Verify a transaction opened without a callback inside a pipeline runs at
     * its exec(), which returns the pipeline, as phpredis does in pipeline
     * mode.
     *
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection, \SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed  $open
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('nestedTransactionOpenings')]
    #[Test]
    public function nestedTransactionRunsAtItsExec(\Closure $open): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incr', 2);
        $client->willReturn('get', '1');

        $connection = new ValkeyGlideConnection($client);
        $pipe       = $connection->pipeline();

        $pipe->set('a', '1');

        $open($connection, $pipe);

        self::assertSame($pipe, $pipe->incr('n')->exec());
        self::assertSame([true, [2], '1'], $pipe->get('a')->exec());
        self::assertSame(['pipeline', 'set', 'exec', 'multi', 'incr', 'exec', 'pipeline', 'get', 'exec'], $client->callOrder());
    }

    /**
     * Verify the batch object of a transaction opened inside a pipeline is
     * refused once its exec() has run it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function nestedTransactionBatchIsRefusedAfterItsExec(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);
        $pipe       = $connection->pipeline();
        $tx         = $connection->transaction();

        self::assertNotSame($pipe, $tx);
        self::assertSame($pipe, $tx->set('a', '1')->exec());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(self::INACTIVE_MESSAGE);

        $tx->set('b', '2');
    }

    /**
     * Verify a multi() opened inside a pipeline callback is closed by its own
     * exec() there, rather than refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function nestedMultiInsideACallbackRunsAtItsExec(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incr', 2);

        $connection = new ValkeyGlideConnection($client);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection): void {
            $connection->command('multi');
            $connection->command('incr', ['n']);

            self::assertSame($pipe, $connection->exec());
        });

        self::assertSame([[2]], $results);
        self::assertSame(['multi', 'incr', 'exec'], $client->callOrder());
    }

    /**
     * Verify a pipeline callback that returns with a multi() it opened still
     * open is refused, and both batches are discarded.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function multiLeftOpenByThePipelineCallbackIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
                $pipe->set('a', '1')->multi()->set('b', '2');
            });
            self::fail('Expected the open multi() to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::UNCLOSED_TRANSACTION_MESSAGE, $exception->getMessage());
        }

        self::assertSame(['pipeline', 'set', 'exec', 'multi', 'set', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
        self::assertFalse($connection->hasOpenBatch());
    }

    /**
     * Verify a discard() of a transaction opened inside a pipeline drops that
     * transaction alone, and the pipeline goes on.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function discardOfANestedTransactionKeepsThePipeline(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $pipe       = $connection->pipeline();

        $pipe->set('a', '1')->multi()->set('b', '2');

        self::assertTrue($pipe->discard());
        self::assertSame([true, true], $pipe->set('c', '3')->exec());
        self::assertSame(['pipeline', 'set', 'exec', 'multi', 'set', 'discard', 'pipeline', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify a nested transaction callback that throws discards the transaction
     * alone, so a pipeline callback that catches it goes on.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function throwingNestedTransactionDiscardsOnlyTheTransaction(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $failure    = new \DomainException('nested failure');
        $throwing   = static function (ValkeyGlideBatch $tx) use ($failure): never {
            $tx->set('b', '2');

            throw $failure;
        };

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection, $failure, $throwing): void {
            $pipe->set('a', '1');

            try {
                $connection->transaction($throwing);
            } catch (\DomainException $exception) {
                self::assertSame($failure, $exception);
            }

            $pipe->set('c', '3');
        });

        self::assertSame([true, true], $results);
        self::assertSame(['pipeline', 'set', 'exec', 'multi', 'set', 'discard', 'pipeline', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify a script in a watched transaction nested in a pipeline is refused,
     * as it would split the transaction the WATCH guards.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function scriptInAWatchedNestedTransactionIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection): void {
            try {
                $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')->eval('return 1', 0));
                self::fail('Expected the script to be refused.');
            } catch (\LogicException $exception) {
                self::assertSame(sprintf(self::IMMEDIATE_IN_TRANSACTION_MESSAGE, 'EVAL'), $exception->getMessage());
            }

            $pipe->set('b', '1');
        });

        self::assertSame([true], $results);
        self::assertSame([], $client->callsFor('rawcommand'));
        self::assertSame(['watch', 'multi', 'set', 'discard', 'unwatch', 'pipeline', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify a pipeline callback that throws while a multi() it opened is open
     * ends the transaction with the pipeline.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function throwingPipelineEndsTheTransactionOpenInIt(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe): never {
                $pipe->multi()->set('a', '1');

                throw new \DomainException('pipeline failure');
            });
            self::fail('Expected the pipeline callback exception to be rethrown.');
        } catch (\DomainException $exception) {
            self::assertSame('pipeline failure', $exception->getMessage());
        }

        self::assertSame(['multi', 'set', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
        self::assertFalse($connection->hasOpenBatch());
    }

    /**
     * Verify the listeners of a nested transaction's send find no batch open,
     * so what they run is sent at once, and one that leaves a batch open ends
     * the pipeline.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function nestedTransactionListenersRunOutsideThePipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'heard');

        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $heard      = [];

        $connection->setEventDispatcher($dispatcher);
        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($connection, &$heard): void {
            if ($event->command !== 'transaction') {
                return;
            }

            $heard[] = $connection->get('x');
        });

        self::assertSame([[true], true], $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1'));
            $pipe->set('b', '2');
        }));
        self::assertSame(['heard'], $heard);

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($connection): void {
            if ($event->command !== 'pipeline') {
                return;
            }

            $connection->pipeline()->set('left', '1');
        });

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
                $pipe->set('a', '1')->multi();
            });
            self::fail('Expected the batch left open by the listener to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::LEFT_OPEN_BY_LISTENER_MESSAGE, 'MULTI'), $exception->getMessage());
        }

        self::assertFalse($client->isBatching());
        self::assertFalse($connection->hasOpenBatch());
    }

    /**
     * Verify a nested transaction callback that swallows the failure which
     * ended its transaction is refused once it returns.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function nestedTransactionEndedInItsCallbackIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $swallow    = static function (ValkeyGlideBatch $tx): void {
            try {
                $tx->sinter('a');
            } catch (\TypeError) {
                // The failed command ended the transaction.
            }
        };

        try {
            $connection->pipeline(static fn (): mixed => $connection->transaction($swallow));
            self::fail('Expected the ended transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::ENDED_IN_CALLBACK_MESSAGE, $exception->getMessage());
        }

        self::assertSame(['multi', 'sinter', 'discard'], $client->callOrder());
        self::assertFalse($connection->hasOpenBatch());
    }

    /**
     * Verify a watched transaction nested in a pipeline is guarded by the
     * WATCH: an aborted one takes an empty list in the pipeline's results and
     * is unwatched, while the pipeline around it still runs.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function watchedNestedTransactionIsGuardedByTheWatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($client, $connection): void {
            $pipe->set('b', '1');

            $connection->transaction(static function (ValkeyGlideBatch $tx) use ($client): void {
                $client->willReturn('exec', false);

                $tx->set('a', '1');
            });

            $client->willReturn('exec', null);

            $pipe->set('c', '1');
        });

        self::assertSame([true, [], true], $results);
        self::assertSame(['watch', 'pipeline', 'set', 'exec', 'multi', 'set', 'exec', 'discard', 'unwatch', 'pipeline', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify a pipeline whose buffered commands fail to send as a transaction
     * opens in it is ended, so its callback's later calls are refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedSendAsATransactionOpensEndsThePipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        $connection = new ValkeyGlideConnection($client);
        $refusal    = null;

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection, &$refusal): void {
                $pipe->set('a', '1');

                try {
                    $connection->transaction();
                } catch (ConnectionException $exception) {
                    self::assertSame(self::PIPELINE_FAILED_MESSAGE, $exception->getMessage());
                }

                try {
                    $connection->command('get', ['a']);
                } catch (\LogicException $exception) {
                    $refusal = $exception->getMessage();
                }
            });
            self::fail('Expected the ended pipeline to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::ENDED_IN_CALLBACK_MESSAGE, $exception->getMessage());
        }

        self::assertSame(sprintf(self::ENDED_BATCH_MESSAGE, 'get'), $refusal);
        self::assertSame(['pipeline', 'set', 'exec', 'discard'], $client->callOrder());
    }

    /**
     * Provide the ways a connection is closed while a transaction nested in a
     * pipeline is open.
     *
     * @return iterable<string, array{\Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed}>
     */
    public static function disconnectsInANestedTransaction(): iterable
    {
        yield 'bare multi' => [static function (ValkeyGlideConnection $connection): void {
            $connection->command('multi');
            $connection->disconnect();
        }];
        yield 'transaction callback' => [static function (ValkeyGlideConnection $connection): void {
            try {
                $connection->transaction(static function () use ($connection): void {
                    $connection->disconnect();
                });
            } catch (\LogicException $exception) {
                self::assertSame(self::ENDED_IN_CALLBACK_MESSAGE, $exception->getMessage());
            }
        }];
    }

    /**
     * Verify disconnect() inside a transaction nested in a pipeline ends both,
     * leaving the connection free.
     *
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed  $disconnect
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('disconnectsInANestedTransaction')]
    #[Test]
    public function disconnectInANestedTransactionEndsThePipeline(\Closure $disconnect): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $pipe       = $connection->pipeline();

        $disconnect($connection);

        self::assertFalse($connection->hasOpenBatch());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(self::INACTIVE_MESSAGE);

        $pipe->exec();
    }
}
