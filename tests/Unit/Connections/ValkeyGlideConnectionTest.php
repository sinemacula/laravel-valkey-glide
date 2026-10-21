<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Valkey GLIDE connection test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @SuppressWarnings("php:S1448")
 */
#[CoversClass(ValkeyGlideConnection::class)]
final class ValkeyGlideConnectionTest extends TestCase
{
    /** @var string Shared transient failure message for retry assertions. */
    private const string TRANSIENT_RESET_MESSAGE = 'connection reset by peer';

    /** @var string Shared pattern channel used for psubscribe tests. */
    private const string PSUBSCRIBE_PATTERN = 'pattern:*';

    /** @var string Shared Lua script used in EVAL prefix normalization tests. */
    private const string EVAL_TEST_SCRIPT = 'return 1';

    /** @var string Message for a key-list command given a key list in a form GLIDE does not take as keys. */
    private const string UNSUPPORTED_KEY_LIST_MESSAGE = '[%s] takes its keys as separate arguments or as a single list of keys, not a list beside other'
        . ' arguments or a list of lists, which GLIDE would send as the key "Array" or drop.';

    /** @var string Message for a cluster INFO called without a route. */
    private const string UNROUTED_CLUSTER_INFO_MESSAGE = '[INFO] on a cluster connection needs a route, as each node reports only itself;'
        . ' pass one first, such as info(\'randomNode\') or info(\'randomNode\', \'memory\').';

    /** @var string Message for a command GLIDE cannot buffer inside a transaction. */
    private const string IMMEDIATE_IN_TRANSACTION_MESSAGE = '[%s] cannot run inside a transaction, as GLIDE sends it at once, outside the MULTI block,'
        . ' so the transaction would not be atomic; use pipeline() instead.';

    /** @var string Message for a cluster transaction whose keys span hash slots. */
    private const string CROSS_SLOT_MESSAGE = 'A cluster transaction must keep every key in one hash slot, but [%s] key [%s] is in slot [%d], not slot [%d];'
        . ' use a hash tag, such as {user:1}, so they share one.';

    /** @var string Message for a watched transaction whose exec() failed. */
    private const string WATCHED_TRANSACTION_FAILED_MESSAGE = 'The watched transaction was aborted or its outcome is unknown; a watched key may have changed,'
        . ' or it may have timed out and still been committed.';

    /** @var string Message for a pipeline whose exec() failed. */
    private const string PIPELINE_FAILED_MESSAGE = 'The pipeline failed; some of its commands may have been applied.';

    /** @var string Message for a command a watched cluster transaction cannot check against the watched slot. */
    private const string UNGUARDED_WATCH_MESSAGE = 'A cluster transaction that watches keys cannot carry [%s], as its keys are unknown or absent,'
        . ' so GLIDE could send the transaction to a node where the WATCH does not guard it.';

    /** @var string Message for a transaction joining a pipeline while keys are watched. */
    private const string WATCHED_TRANSACTION_IN_PIPELINE_MESSAGE = 'A transaction cannot join a pipeline while keys are watched, as the pipeline flattens it'
        . ' and the WATCH could not guard it; run the transaction outside the pipeline.';

    /** @var string Message for a batch whose exec() results do not match what it buffered. */
    private const string MISALIGNED_BATCH_MESSAGE = 'The %s was sent and has most likely been applied, but its results do not match its commands,'
        . ' so they could not be returned in call order.';

    /** @var string Message for a cluster transaction key outside the slot of the watched keys. */
    private const string WATCHED_CROSS_SLOT_MESSAGE = 'A cluster transaction must keep every key in the hash slot of the keys it watches, but [%s] key [%s]'
        . ' is in slot [%d], not slot [%d] of the watched keys [%s]; watch only keys in that slot, or call unwatch() first if they are'
        . ' still watched after a failed unwatch().';

    /** @var string Message for a client-local method called inside a transaction. */
    private const string LOCAL_IN_TRANSACTION_MESSAGE = '[%s] cannot be called inside a transaction, as GLIDE runs it at once on the client'
        . ' instead of adding it to the MULTI block; call it before or after the transaction.';

    /** @var string Message for a batch that ended inside its callback. */
    private const string ENDED_IN_CALLBACK_MESSAGE = 'The batch ended inside its callback, so it was not executed; any of its commands not yet sent were discarded.';

    /** @var string Message for a connection call made inside a callback whose batch has ended. */
    private const string ENDED_BATCH_MESSAGE = '[%s()] cannot be called once the pipeline or transaction has ended inside its callback; return or throw from the callback.';

    /** @var string Message for a connection method refused while a batch is active. */
    private const string ACTIVE_BATCH_MESSAGE = '[%s()] cannot be called while a pipeline or transaction is active; %s.';

    /**
     * Verify client() returns the wrapped GLIDE client instance.
     *
     * @return void
     */
    #[Test]
    public function clientReturnsUnderlyingClientInstance(): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client);

        self::assertSame($client, $connection->client());
    }

    /**
     * Verify isCluster() reports whether the wrapped client is a cluster
     * client.
     *
     * @return void
     */
    #[Test]
    public function isClusterReportsWhetherTheClientIsACluster(): void
    {
        self::assertFalse((new ValkeyGlideConnection(new ValkeyGlideFake))->isCluster());
        self::assertTrue((new ValkeyGlideConnection(new ValkeyGlideClusterFake))->isCluster());
    }

    /**
     * Verify getPrefix() returns the configured key prefix.
     *
     * @return void
     */
    #[Test]
    public function getPrefixReturnsTheConfiguredPrefix(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        self::assertSame('app:', $connection->getPrefix());
    }

    /**
     * Provide connection configs with no prefix, or one that is not a string.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusablePrefixConfigs(): iterable
    {
        yield 'no prefix' => [[]];
        yield 'null prefix' => [['prefix' => null]];
        yield 'array prefix' => [['prefix' => ['app:']]];
    }

    /**
     * Verify getPrefix() is empty when no usable prefix is configured.
     *
     * @param  array<string, mixed>  $config
     * @return void
     */
    #[DataProvider('unusablePrefixConfigs')]
    #[Test]
    public function getPrefixIsEmptyWithoutAUsablePrefix(array $config): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, $config);

        self::assertSame('', $connection->getPrefix());
    }

    /**
     * Verify hasOpenBatch() reports a batch from pipeline(), transaction() or
     * multi() until it is sent or discarded, also inside its callback and once
     * an error has ended it there.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function hasOpenBatchReportsAnOpenBatchUntilItIsSentOrDiscarded(): void
    {
        $client = new ValkeyGlideFake;
        $client->willThrow('rawcommand', new \RuntimeException('script failed'));

        $connection = new ValkeyGlideConnection($client);

        /** @var \ArrayObject<int, bool> $seen */
        $seen = new \ArrayObject;

        $record = static function (ValkeyGlideBatch $batch) use ($connection, $seen): void {
            $batch->set('a', '1');

            $seen[] = $connection->hasOpenBatch();
        };
        $endThenRecord = static function (ValkeyGlideBatch $pipe) use ($connection, $seen): void {
            self::assertInstanceOf(\RuntimeException::class, self::exceptionOf(static fn (): ValkeyGlideBatch => $pipe->eval(self::EVAL_TEST_SCRIPT, 0)));

            $seen[] = $connection->hasOpenBatch();
        };

        self::assertFalse($connection->hasOpenBatch());

        $connection->pipeline($record);
        $connection->transaction($record);

        self::assertSame([true, true], $seen->getArrayCopy());
        self::assertFalse($connection->hasOpenBatch());

        $connection->multi();

        self::assertTrue($connection->hasOpenBatch());

        $connection->command('set', ['a', '1']);
        $connection->exec();

        self::assertFalse($connection->hasOpenBatch());

        $connection->multi();
        $connection->discard();

        self::assertFalse($connection->hasOpenBatch());

        try {
            $connection->pipeline($endThenRecord);
            self::fail('Expected the ended pipeline to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::ENDED_IN_CALLBACK_MESSAGE, $exception->getMessage());
        }

        self::assertSame([true, true, true], $seen->getArrayCopy());
        self::assertFalse($connection->hasOpenBatch());
    }

    /**
     * Verify get returns null when the underlying client returns false (cache
     * miss).
     *
     * @return void
     */
    #[Test]
    public function getReturnsFalseAsNull(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', false);

        $connection = new ValkeyGlideConnection($client);

        self::assertNull($connection->get('missing-key'));
        self::assertSame([['missing-key']], $client->callsFor('get'));
    }

    /**
     * Verify get returns the value when the underlying client returns a string.
     *
     * @return void
     */
    #[Test]
    public function getReturnsValueWhenPresent(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'cached-value');

        $connection = new ValkeyGlideConnection($client);

        self::assertSame('cached-value', $connection->get('existing-key'));
        self::assertSame([['existing-key']], $client->callsFor('get'));
    }

    /**
     * Verify mget converts false entries to null for missing keys.
     *
     * @return void
     */
    #[Test]
    public function mgetReturnsFalseAsNull(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('mget', ['value-a', false, 'value-b']);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['value-a', null, 'value-b'], $connection->mget(['key-a', 'key-b', 'key-c']));
        self::assertSame([[['key-a', 'key-b', 'key-c']]], $client->callsFor('mget'));
    }

    /**
     * Verify mget returns all values when all keys are present.
     *
     * @return void
     */
    #[Test]
    public function mgetReturnsAllValuesWhenPresent(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('mget', ['value-a', 'value-b', 'value-c']);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['value-a', 'value-b', 'value-c'], $connection->mget(['key-a', 'key-b', 'key-c']));
        self::assertSame([[['key-a', 'key-b', 'key-c']]], $client->callsFor('mget'));
    }

    /**
     * Verify mget applies the configured prefix to each key in the key list.
     *
     * @return void
     */
    #[Test]
    public function mgetPrefixesEveryKeyWhenPrefixConfigured(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('mget', ['value-a', 'value-b']);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(['value-a', 'value-b'], $connection->mget(['key-a', 'key-b']));
        self::assertSame([[['app:key-a', 'app:key-b']]], $client->callsFor('mget'));
    }

    /**
     * Verify mget throws when the client returns a non-array response payload.
     *
     * @return void
     */
    #[Test]
    public function mgetThrowsForUnexpectedTopLevelResponseType(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('mget', $client);

        $connection = new ValkeyGlideConnection($client);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf('Expected array response from mget, received [%s].', ValkeyGlideFake::class));

        $connection->mget(['key-a']);
    }

    /**
     * Verify command execution delegates to the underlying client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandDelegatesToUnderlyingClient(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);

        self::assertSame('value', $connection->command('get', ['cache-key']));
        self::assertSame([['cache-key']], $client->callsFor('get'));
    }

    /**
     * Verify single-key commands apply configured key prefix.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandPrefixesSingleKeyCommands(): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $connection->command('get', ['user:1']);

        self::assertSame([['app:user:1']], $client->callsFor('get'));
    }

    /**
     * Verify eval command arguments are executed through rawcommand.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRoutesEvalToRawcommand(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(
            1,
            $connection->command(
                'eval',
                [self::EVAL_TEST_SCRIPT, 1, 'queue-key', 'arg-value'],
            ),
        );

        self::assertSame(
            [
                ['EVAL', self::EVAL_TEST_SCRIPT, 1, 'app:queue-key', 'arg-value'],
            ],
            $client->callsFor('rawcommand'),
        );
        self::assertSame([], $client->callsFor('eval'));
    }

    /**
     * Verify phpredis-style set options are executed through rawcommand.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRoutesLegacySetOptionsToRawcommand(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 'OK');

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(
            'OK',
            $connection->command('set', ['lock-key', 'owner-id', 'EX', 10, 'NX']),
        );

        self::assertSame(
            [
                ['SET', 'app:lock-key', 'owner-id', 'EX', 10, 'NX'],
            ],
            $client->callsFor('rawcommand'),
        );
        self::assertSame([], $client->callsFor('set'));
    }

    /**
     * Verify native set argument shapes continue to use the set method
     * directly.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandUsesSetMethodForNativeSetArgumentShape(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('set', true);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertTrue($connection->command('set', ['cache-key', 'value', ['nx']]));
        self::assertSame(
            [
                ['app:cache-key', 'value', ['nx']],
            ],
            $client->callsFor('set'),
        );
        self::assertSame([], $client->callsFor('rawcommand'));
    }

    /**
     * Verify idempotent commands retry once after transient disconnect errors.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetriesIdempotentCommandAfterTransientFailure(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertCount(1, $firstClient->callsFor('get'));
        self::assertCount(1, $secondClient->callsFor('get'));
    }

    /**
     * Verify protocol errors are treated as transient and retried once.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetriesWhenProtocolErrorReplyTypeByteOccurs(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException('protocol error, got \'�\' as reply-type byte'));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
    }

    /**
     * Verify non-idempotent commands are not retried after transient errors.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandDoesNotRetryNonIdempotentCommands(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('set', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('set', true);

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        try {
            $connection->command('set', ['key', 'value']);
            self::fail('Expected a runtime exception for the non-idempotent command.');
        } catch (\RuntimeException $exception) {
            self::assertSame(self::TRANSIENT_RESET_MESSAGE, $exception->getMessage());
            self::assertCount(0, $secondClient->callsFor('set'));
        }
    }

    /**
     * Verify commands are not retried without a reconnect connector callback.
     *
     * @return void
     */
    #[Test]
    public function commandDoesNotRetryWhenConnectorIsMissing(): void
    {
        $client = new ValkeyGlideFake;
        $client->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $connection = new ValkeyGlideConnection($client);

        $this->expectException(\RuntimeException::class);

        $connection->command('get', ['cache-key']);
    }

    /**
     * Verify command dispatch emits command executed events on success.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandDispatchesCommandExecutedEvent(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        $connection->command('get', ['cache-key']);

        self::assertCount(1, $events);
        self::assertSame('get', $events[0]->command);
    }

    /**
     * Verify command dispatch emits command failed events on terminal failure.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandDispatchesCommandFailedEvent(): void
    {
        $client = new ValkeyGlideFake;
        $client->willThrow('get', new \RuntimeException('permanent failure'));

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->command('get', ['cache-key']);
            self::fail('Expected runtime exception to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('permanent failure', $exception->getMessage());
            self::assertCount(1, $events);
            self::assertSame('get', $events[0]->command);
        }
    }

    /**
     * Verify a cluster scan reports its cursor id in the command executed event
     * while the client still receives the cursor object.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandExecutedEventReportsClusterScanCursorId(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan('scan', [['7', ['user:1']]]);

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        $cursor = new \ClusterScanCursor('42');

        self::assertSame(['user:1'], $connection->command('scan', [$cursor, 'user:*', 100]));

        self::assertCount(1, $events);
        self::assertSame(['42', 'user:*', 100], $events[0]->parameters);
        self::assertSame('7', $cursor->getNextCursor());
    }

    /**
     * Verify a failed cluster scan reports its cursor id in the command failed
     * event.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandFailedEventReportsClusterScanCursorId(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willThrow('scan', new \RuntimeException('permanent failure'));

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->command('scan', [new \ClusterScanCursor('42'), 'user:*']);
            self::fail('Expected runtime exception to be thrown.');
        } catch (\RuntimeException) {
            self::assertCount(1, $events);
            self::assertSame(['42', 'user:*'], $events[0]->parameters);
        }
    }

    /**
     * Verify subscribe normalizes callback payload to message and channel.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionUsesSubscribeAndNormalizesCallbackPayload(): void
    {
        $client = new ValkeyGlideFake;
        $client->setSubscriptionPayload('subscribe', ['ignored', 'channel-a', 'payload-a']);

        $captured = [];

        $connection = new ValkeyGlideConnection($client);

        $connection->createSubscription('channel-a', static function (mixed $message, mixed $channel) use (&$captured): void {
            $captured = [$message, $channel];
        });

        self::assertSame(['payload-a', 'channel-a'], $captured);
        self::assertCount(1, $client->callsFor('subscribe'));
    }

    /**
     * Verify psubscribe normalizes callback payload to message and channel.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionUsesPsubscribeAndNormalizesCallbackPayload(): void
    {
        $client = new ValkeyGlideFake;
        $client->setSubscriptionPayload('psubscribe', ['ignored', self::PSUBSCRIBE_PATTERN, 'payload-a']);

        $captured = [];

        $connection = new ValkeyGlideConnection($client);

        $connection->createSubscription([self::PSUBSCRIBE_PATTERN], static function (mixed $message, mixed $channel) use (&$captured): void {
            $captured = [$message, $channel];
        }, 'psubscribe');

        self::assertSame(['payload-a', self::PSUBSCRIBE_PATTERN], $captured);
        self::assertCount(1, $client->callsFor('psubscribe'));
    }

    /**
     * Verify unsupported subscription methods throw an exception.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionRejectsUnsupportedMethod(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        $this->expectException(\InvalidArgumentException::class);

        $connection->createSubscription('channel-a', static function (): void {
            throw new \LogicException('Callback should not be executed for invalid method.');
        }, 'unknown-method');
    }

    /**
     * Verify invalid channel values throw an exception.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionRejectsUnsupportedChannelType(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        $this->expectException(\InvalidArgumentException::class);

        $connection->createSubscription(new \stdClass, static function (): void {
            throw new \LogicException('Callback should not be executed for invalid channels.');
        });
    }

    /**
     * Provide flush commands with their phpredis-style arguments.
     *
     * @return iterable<string, array{string, array<int, mixed>, bool}>
     */
    public static function clusterFlushCommands(): iterable
    {
        yield 'flushdb' => ['flushdb', [], false];
        yield 'flushdb async' => ['flushdb', ['ASYNC'], true];
        yield 'flushdb sync' => ['flushdb', ['SYNC'], false];
        yield 'flushdb async bool' => ['flushdb', [true], true];
        yield 'flushdb sync bool' => ['flushdb', [false], false];
        yield 'flushall' => ['flushall', [], false];
        yield 'flushall async' => ['flushall', ['async'], true];
    }

    /**
     * Verify cluster flush commands are routed to every primary.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @param  bool  $async
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('clusterFlushCommands')]
    #[Test]
    public function clusterFlushCommandsRunOnAllPrimaries(string $method, array $parameters, bool $async): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn($method, true);

        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command($method, $parameters));
        self::assertSame([['allPrimaries', $async]], $client->callsFor($method));
    }

    /**
     * Provide cluster flush calls that already carry an explicit GLIDE route.
     *
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function clusterFlushCommandsWithExplicitRoute(): iterable
    {
        yield 'all primaries async' => ['flushdb', ['allPrimaries', true]];
        yield 'random node' => ['flushdb', ['randomNode']];
        yield 'host and port' => ['flushdb', [['host' => '10.0.0.1', 'port' => 6379]]];
        yield 'slot key route' => ['flushall', [['type' => 'primarySlotKey', 'key' => 'key'], true]];
        yield 'flag with extra argument' => ['flushdb', ['ASYNC', 'extra']];
        yield 'non-list flag' => ['flushdb', [1 => 'ASYNC']];
        yield 'null flag' => ['flushdb', [null]];
    }

    /**
     * Verify cluster flush calls with an explicit route are passed through
     * unchanged.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('clusterFlushCommandsWithExplicitRoute')]
    #[Test]
    public function clusterFlushCommandsWithExplicitRoutePassThroughUnchanged(string $method, array $parameters): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn($method, true);

        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command($method, $parameters));
        self::assertSame([array_values($parameters)], $client->callsFor($method));
    }

    /**
     * Verify cluster flush calls with named arguments are passed through with
     * their names intact.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterFlushCommandsWithNamedArgumentsPassThroughUnchanged(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('flushdb', true);

        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command('flushdb', ['route' => 'randomNode', 'async' => true]));
        self::assertSame([['route' => 'randomNode', 'async' => true]], $client->callsFor('flushdb'));
    }

    /**
     * Verify standalone flush commands are passed through unchanged.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function standaloneFlushCommandsPassThroughUnchanged(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('flushdb', true);

        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command('flushdb', ['ASYNC']));
        self::assertSame([['ASYNC']], $client->callsFor('flushdb'));
    }

    /**
     * Verify a cluster ping without arguments is routed to every primary.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterPingWithoutArgumentsRunsOnAllPrimaries(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('ping', true);

        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command('ping'));
        self::assertSame([['allPrimaries']], $client->callsFor('ping'));
    }

    /**
     * Provide cluster ping calls that already carry an explicit GLIDE route.
     *
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function clusterPingsWithExplicitRoute(): iterable
    {
        yield 'random node' => [['randomNode']];
        yield 'all nodes with message' => [['allNodes', 'hello']];
        yield 'host and port' => [[['type' => 'routeByAddress', 'host' => '10.0.0.1', 'port' => 6379]]];
        yield 'named route' => [['route' => 'randomNode']];
    }

    /**
     * Verify cluster ping calls with an explicit route are passed through
     * unchanged.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('clusterPingsWithExplicitRoute')]
    #[Test]
    public function clusterPingWithExplicitRoutePassesThroughUnchanged(array $parameters): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('ping', true);

        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command('ping', $parameters));
        self::assertSame([$parameters], $client->callsFor('ping'));
    }

    /**
     * Verify a standalone ping without arguments is passed through unchanged.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function standalonePingWithoutArgumentsPassesThroughUnchanged(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('ping', true);

        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command('ping'));
        self::assertSame([[]], $client->callsFor('ping'));
    }

    /**
     * Provide cluster commands called without a route, with the route GLIDE
     * gives each one and the arguments the client should receive.
     *
     * @return iterable<string, array{string, array<int, mixed>, mixed, array<int, mixed>}>
     */
    public static function clusterCommandsWithoutRoute(): iterable
    {
        yield 'dbsize sums every primary' => ['dbsize', [], 42, ['allPrimaries']];
        yield 'randomkey asks every primary' => ['randomkey', [], 'user:1', ['allPrimaries']];
        yield 'time asks a random node' => ['time', [], ['1700000000', '123'], ['randomNode']];
        yield 'echo asks a random node' => ['echo', ['hello'], 'hello', ['randomNode', 'hello']];
    }

    /**
     * Verify cluster keyless commands called without a route take the route
     * GLIDE gives them.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @param  mixed  $reply
     * @param  array<int, mixed>  $expectedArguments
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('clusterCommandsWithoutRoute')]
    #[Test]
    public function clusterCommandWithoutRouteTakesGlideDefaultRoute(string $method, array $parameters, mixed $reply, array $expectedArguments): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn($method, $reply);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame($reply, $connection->command($method, $parameters));
        self::assertSame([$expectedArguments], $client->callsFor($method));
    }

    /**
     * Verify a routed cluster command's events carry the caller's arguments,
     * not the route added to them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterCommandWithoutRouteReportsCallerArgumentsToListeners(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('echo', 'hello');

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        $connection->command('echo', ['hello']);

        self::assertCount(1, $events);
        self::assertSame('echo', $events[0]->command);
        self::assertSame(['hello'], $events[0]->parameters);
    }

    /**
     * Provide cluster commands that already carry an explicit GLIDE route.
     *
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function clusterCommandsWithExplicitRoute(): iterable
    {
        yield 'dbsize on a random node' => ['dbsize', ['randomNode']];
        yield 'dbsize on a node by address' => ['dbsize', [['type' => 'routeByAddress', 'host' => '10.0.0.1', 'port' => 6379]]];
        yield 'dbsize on the primary of a key' => ['dbsize', ['user:1']];
        yield 'randomkey on a random node' => ['randomkey', ['randomNode']];
        yield 'time on every node' => ['time', ['allNodes']];
        yield 'echo on a node by address' => ['echo', [['type' => 'routeByAddress', 'host' => '10.0.0.1', 'port' => 6379], 'hello']];
        yield 'echo with named arguments' => ['echo', ['route' => 'randomNode', 'msg' => 'hello']];
        yield 'info on a random node' => ['info', ['randomNode']];
        yield 'info sections on a node by address' => ['info', [['type' => 'routeByAddress', 'host' => '10.0.0.1', 'port' => 6379], 'memory', 'cpu']];
    }

    /**
     * Verify cluster commands with an explicit route are passed through
     * unchanged.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('clusterCommandsWithExplicitRoute')]
    #[Test]
    public function clusterCommandWithExplicitRoutePassesThroughUnchanged(string $method, array $parameters): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn($method, 'reply');

        $connection = new ValkeyGlideConnection($client);

        self::assertSame('reply', $connection->command($method, $parameters));
        self::assertSame([$parameters], $client->callsFor($method));
    }

    /**
     * Verify a cluster command called with named arguments is passed through
     * unchanged, even when it has as many as a call without a route.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterCommandWithNamedArgumentsPassesThroughUnchanged(): void
    {
        $client = new ValkeyGlideClusterFake;

        $connection = new ValkeyGlideConnection($client);

        $connection->command('echo', ['msg' => 'hello']);

        self::assertSame([['msg' => 'hello']], $client->callsFor('echo'));
    }

    /**
     * Provide standalone keyless commands, which take no route.
     *
     * @return iterable<string, array{string, array<int, mixed>}>
     */
    public static function standaloneKeylessCommands(): iterable
    {
        yield 'dbsize' => ['dbsize', []];
        yield 'randomkey' => ['randomkey', []];
        yield 'time' => ['time', []];
        yield 'echo' => ['echo', ['hello']];
        yield 'info' => ['info', []];
        yield 'info sections' => ['info', ['memory']];
    }

    /**
     * Verify standalone keyless commands are passed through unchanged.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('standaloneKeylessCommands')]
    #[Test]
    public function standaloneKeylessCommandPassesThroughUnchanged(string $method, array $parameters): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn($method, 'reply');

        $connection = new ValkeyGlideConnection($client);

        self::assertSame('reply', $connection->command($method, $parameters));
        self::assertSame([$parameters], $client->callsFor($method));
    }

    /**
     * Verify a cluster INFO without a route is refused before anything is sent,
     * as no single reply could describe the cluster.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterInfoWithoutRouteIsRefusedBeforeSending(): void
    {
        $client = new ValkeyGlideClusterFake;

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen([CommandExecuted::class, CommandFailed::class], static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->command('info');
            self::fail('Expected an unrouted cluster INFO to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(self::UNROUTED_CLUSTER_INFO_MESSAGE, $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('info'));
        self::assertSame([], $events);
    }

    /**
     * Verify a cluster pipeline runs a keyless command without a route on the
     * route GLIDE gives it, in call order.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterPipelineRunsKeylessCommandWithoutRouteOnGlideDefaultRoute(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('dbsize', 42);

        $connection = new ValkeyGlideConnection($client);

        $results = $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->dbsize()->set('b', '2'));

        self::assertSame([true, 42, true], $results);
        self::assertSame([['allPrimaries']], $client->callsFor('dbsize'));
    }

    /**
     * Verify a cluster pipeline refuses an INFO without a route before anything
     * it buffered is sent.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterPipelineRefusesInfoWithoutRouteBeforeSending(): void
    {
        $client = new ValkeyGlideClusterFake;

        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->info());
            self::fail('Expected an unrouted cluster INFO to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(self::UNROUTED_CLUSTER_INFO_MESSAGE, $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('info'));
        self::assertSame([], $client->callsFor('exec'));
        self::assertFalse($connection->hasOpenBatch());
    }

    /**
     * Verify executeRaw delegates to rawcommand on the wrapped client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function executeRawDelegatesToRawcommand(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 'PONG');

        $connection = new ValkeyGlideConnection($client);

        self::assertSame('PONG', $connection->executeRaw(['PING']));
        self::assertSame([['PING']], $client->callsFor('rawcommand'));
    }

    /**
     * Verify executeRaw on a cluster client leads with the resolved route and
     * leaves the key unprefixed, as on a standalone client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterExecuteRawLeadsWithTheResolvedRoute(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 1);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(1, $connection->executeRaw(['command' => 'unlink', 'key' => 'k']));
        self::assertSame(1, $connection->executeRaw(['SCRIPT', 'LOAD', self::EVAL_TEST_SCRIPT]));
        self::assertSame(
            [
                [['type' => 'primarySlotKey', 'key' => 'k'], 'unlink', 'k'],
                ['allNodes', 'SCRIPT', 'LOAD', self::EVAL_TEST_SCRIPT],
            ],
            $client->callsFor('rawcommand'),
        );
    }

    /**
     * Verify executeRaw on a cluster client refuses a SCAN rather than sending
     * it to a single node.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterExecuteRawRefusesScan(): void
    {
        $client = new ValkeyGlideClusterFake;

        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->executeRaw(['SCAN', '0', 'MATCH', 'user:*']);
            self::fail('Expected a raw cluster SCAN to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('ClusterScanCursor', $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('rawcommand'));
    }

    /**
     * Verify rawCommand on a cluster client is passed through unchanged, with
     * the caller's route first.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterRawCommandPassesThroughUnchanged(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 'reply');

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame('reply', $connection->command('rawCommand', ['user:1', 'GET', 'user:1']));
        self::assertSame('reply', $connection->command('rawcommand', ['allPrimaries', 'DBSIZE']));
        self::assertSame(
            [['user:1', 'GET', 'user:1'], ['allPrimaries', 'DBSIZE']],
            $client->callsFor('rawcommand'),
        );
    }

    /**
     * Verify disconnect calls close on the wrapped client.
     *
     * @return void
     */
    #[Test]
    public function disconnectClosesUnderlyingClient(): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client);

        $connection->disconnect();

        self::assertCount(1, $client->callsFor('close'));
    }

    /**
     * Verify invalid command method inputs are rejected.
     *
     * @return void
     */
    #[Test]
    public function commandRejectsUnsupportedMethodTypes(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        $this->expectException(\InvalidArgumentException::class);

        $connection->command(new \stdClass, []);
    }

    /**
     * Verify idempotent command retry is limited to one reconnect attempt.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetriesAtMostOnceWhenSecondAttemptAlsoFails(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        try {
            $connection->command('get', ['retry-key']);
            self::fail('Expected a runtime exception after the retry also failed.');
        } catch (\RuntimeException $exception) {
            self::assertSame(self::TRANSIENT_RESET_MESSAGE, $exception->getMessage());
            self::assertCount(1, $firstClient->callsFor('get'));
            self::assertCount(1, $secondClient->callsFor('get'));
        }
    }

    /**
     * Verify non-transient failures are not retried for idempotent commands.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandDoesNotRetryIdempotentCommandWhenErrorIsNotTransient(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException('domain validation failure'));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        try {
            $connection->command('get', ['retry-key']);
            self::fail('Expected a runtime exception for the non-transient failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('domain validation failure', $exception->getMessage());
            self::assertCount(0, $secondClient->callsFor('get'));
        }
    }

    /**
     * Verify subscription rejects non-stringable method identifiers.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionRejectsUnsupportedMethodType(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        $this->expectException(\InvalidArgumentException::class);

        $connection->createSubscription('channel-a', static function (): void {
            throw new \LogicException('Callback should not be executed for invalid method type.');
        }, new \stdClass);
    }

    /**
     * Verify subscriptions reject channel lists that normalize to empty.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionRejectsEmptyNormalizedChannelList(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        $this->expectException(\InvalidArgumentException::class);

        $connection->createSubscription(['', new \stdClass], static function (): void {
            throw new \LogicException('Callback should not be executed for empty channel list.');
        });
    }

    /**
     * Verify reconnect helper returns false when connector callback is absent.
     *
     * @return void
     */
    #[Test]
    public function reconnectClientReturnsFalseWhenConnectorIsMissing(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertFalse($this->invokeReconnectClient($connection));
    }

    /**
     * Verify retry sleep uses configured random jitter and sleep callbacks.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryUsesConfiguredRandomIntAndSleepCallbacks(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $sleepCalls = [];

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 10,
                'retry_jitter_ms' => 5,
                'random_int'      => static fn (int $min, int $max): int => 3,
                'sleep'           => static function (int $microseconds) use (&$sleepCalls): void {
                    $sleepCalls[] = $microseconds;
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([13000], $sleepCalls);
    }

    /**
     * Verify retry jitter gracefully recovers when random generator fails.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryFallsBackWhenRandomIntCallbackThrows(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $sleepCalls = [];

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 10,
                'retry_jitter_ms' => 5,
                'random_int'      => static function (): int {
                    throw new \DomainException('entropy unavailable');
                },
                'sleep' => static function (int $microseconds) use (&$sleepCalls): void {
                    $sleepCalls[] = $microseconds;
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([10000], $sleepCalls);
    }

    /**
     * Verify retry path works with default sleep callback implementation.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryUsesDefaultSleepCallbackWhenNoCallbackConfigured(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 1,
                'retry_jitter_ms' => 0,
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
    }

    /**
     * Verify SET routes to rawcommand at exactly the four-argument boundary.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRoutesSetToRawcommandAtFourArgumentBoundary(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 'OK');

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame('OK', $connection->command('set', ['lock-key', 'owner-id', 'EX', 10]));
        self::assertSame(
            [
                ['SET', 'app:lock-key', 'owner-id', 'EX', 10],
            ],
            $client->callsFor('rawcommand'),
        );
        self::assertSame([], $client->callsFor('set'));
    }

    /**
     * Verify raw command argument unpacking reindexes string-keyed parameters.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandUnpacksRawCommandArgumentsByValueForStringKeyedParameters(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(1, $connection->command('eval', ['script' => self::EVAL_TEST_SCRIPT, 'extra' => 'value']));
        self::assertSame(
            [
                ['EVAL', self::EVAL_TEST_SCRIPT, 'value'],
            ],
            $client->callsFor('rawcommand'),
        );
    }

    /**
     * Verify a non-retryable command failure is not retried on the same client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandDoesNotReinvokeNonRetryableCommandOnTheSameClient(): void
    {
        $client = new ValkeyGlideFake;
        $client->willThrow('set', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $connection = new ValkeyGlideConnection(
            $client,
            static fn (): \ValkeyGlide => new ValkeyGlideFake,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        try {
            $connection->command('set', ['key', 'value']);
            self::fail('Expected runtime exception to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame(self::TRANSIENT_RESET_MESSAGE, $exception->getMessage());
            self::assertCount(1, $client->callsFor('set'));
        }
    }

    /**
     * Verify idempotent retries are capped at one even when a third attempt
     * would pass.
     *
     * @return void
     */
    #[Test]
    public function commandRetriesAtMostOnceEvenWhenLaterAttemptWouldSucceed(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $thirdClient = new ValkeyGlideFake;
        $thirdClient->willReturn('get', 'ok');

        $clients = [$secondClient, $thirdClient];

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static function () use (&$clients): \ValkeyGlide {
                return array_shift($clients) ?? new ValkeyGlideFake;
            },
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        $this->expectException(\RuntimeException::class);

        try {
            $connection->command('get', ['retry-key']);
        } finally {
            self::assertCount(0, $thirdClient->callsFor('get'));
        }
    }

    /**
     * Verify uppercase transient error messages are matched case-insensitively.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetriesWhenTransientErrorMessageIsUppercase(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException('CONNECTION RESET BY PEER'));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertCount(1, $secondClient->callsFor('get'));
    }

    /**
     * Verify a single-argument subscription payload is mapped to the message.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionMapsSingleArgumentPayloadToMessage(): void
    {
        $client = new ValkeyGlideFake;
        $client->setSubscriptionPayload('subscribe', ['only-message']);

        $captured = [];

        $connection = new ValkeyGlideConnection($client);

        $connection->createSubscription('channel-a', static function (mixed $message, mixed $channel) use (&$captured): void {
            $captured = [$message, $channel];
        });

        self::assertSame(['only-message', null], $captured);
    }

    /**
     * Verify a two-argument subscription payload maps to message and channel.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionMapsTwoArgumentPayloadToMessageAndChannel(): void
    {
        $client = new ValkeyGlideFake;
        $client->setSubscriptionPayload('subscribe', ['channel-a', 'message-a']);

        $captured = [];

        $connection = new ValkeyGlideConnection($client);

        $connection->createSubscription('channel-a', static function (mixed $message, mixed $channel) use (&$captured): void {
            $captured = [$message, $channel];
        });

        self::assertSame(['message-a', 'channel-a'], $captured);
    }

    /**
     * Verify subscription methods are matched case-insensitively via
     * lowercasing.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionLowercasesSubscriptionMethod(): void
    {
        $client = new ValkeyGlideFake;
        $client->setSubscriptionPayload('subscribe', ['ignored', 'channel-a', 'payload-a']);

        $connection = new ValkeyGlideConnection($client);

        $connection->createSubscription('channel-a', static function (): void {
            // No-op callback; the subscription wiring is the unit under test.
        }, 'SUBSCRIBE');

        self::assertCount(1, $client->callsFor('subscribe'));
        self::assertCount(0, $client->callsFor('psubscribe'));
    }

    /**
     * Verify non-stringable channels are skipped without aborting the channel
     * list.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionSkipsInvalidChannelsAndKeepsValidOnes(): void
    {
        $client = new ValkeyGlideFake;
        $client->setSubscriptionPayload('subscribe', ['ignored', 'valid-channel', 'payload-a']);

        $connection = new ValkeyGlideConnection($client);

        $connection->createSubscription([new \stdClass, 'valid-channel'], static function (): void {
            // No-op callback; the channel normalization is the unit under test.
        });

        self::assertSame([[['valid-channel']]], $client->callsFor('subscribe'));
    }

    /**
     * Verify scalar channel values are cast to strings before subscribing.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionCastsScalarChannelsToStrings(): void
    {
        $client = new ValkeyGlideFake;
        $client->setSubscriptionPayload('subscribe', ['ignored', '123', 'payload-a']);

        $connection = new ValkeyGlideConnection($client);

        $connection->createSubscription([123], static function (): void {
            // No-op callback; channel string casting is the unit under test.
        });

        self::assertSame([[['123']]], $client->callsFor('subscribe'));
    }

    /**
     * Verify every normalized channel is forwarded to the subscription call.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionForwardsEveryNormalizedChannel(): void
    {
        $client = new ValkeyGlideFake;
        $client->setSubscriptionPayload('subscribe', ['ignored', 'channel-a', 'payload-a']);

        $connection = new ValkeyGlideConnection($client);

        $connection->createSubscription(['channel-a', 'channel-b'], static function (): void {
            // No-op callback; the channel forwarding is the unit under test.
        });

        self::assertSame([[['channel-a', 'channel-b']]], $client->callsFor('subscribe'));
    }

    /**
     * Verify the unsupported channel type message is reported precisely.
     *
     * @return void
     */
    #[Test]
    public function createSubscriptionReportsUnsupportedChannelTypeMessage(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported subscription channel type [stdClass].');

        $connection->createSubscription(new \stdClass, static function (): void {
            throw new \LogicException('Callback should not be executed for invalid channels.');
        });
    }

    /**
     * Verify a Stringable key prefix is resolved and applied to command keys.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandAppliesStringablePrefixToKeys(): void
    {
        $client = new ValkeyGlideFake;

        $prefix = new class implements \Stringable {
            /**
             * Render the prefix as a string.
             *
             * @return string
             */
            #[\Override]
            public function __toString(): string
            {
                return 'px:';
            }
        };

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => $prefix]);

        $connection->command('get', ['user:1']);

        self::assertSame([['px:user:1']], $client->callsFor('get'));
    }

    /**
     * Verify a non-scalar non-stringable prefix resolves to an empty prefix.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandUsesEmptyPrefixForNonScalarNonStringablePrefix(): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => new \stdClass]);

        $connection->command('get', ['user:1']);

        self::assertSame([['user:1']], $client->callsFor('get'));
    }

    /**
     * Verify default retry sleep delay is used when no delay is configured.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryUsesDefaultBaseDelayWhenNoneConfigured(): void
    {
        $sleepCalls = [];

        $connection = $this->buildRetryConnection(
            [
                'random_int' => static fn (int $min, int $max): int => 0,
                'sleep'      => static function (int $microseconds) use (&$sleepCalls): void {
                    $sleepCalls[] = $microseconds;
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([25000], $sleepCalls);
    }

    /**
     * Verify the default jitter ceiling is passed to the random generator.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryUsesDefaultJitterCeilingWhenNoneConfigured(): void
    {
        $randomArgs = [];

        $connection = $this->buildRetryConnection(
            [
                'random_int' => static function (int $min, int $max) use (&$randomArgs): int {
                    $randomArgs = [$min, $max];

                    return 0;
                },
                'sleep' => static function (): void {
                    // No-op sleep; the random generator arguments are asserted
                    // instead.
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([0, 15], $randomArgs);
    }

    /**
     * Verify a configured jitter ceiling overrides the default jitter ceiling.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryUsesConfiguredJitterCeiling(): void
    {
        $randomArgs = [];

        $connection = $this->buildRetryConnection(
            [
                'retry_jitter_ms' => 8,
                'random_int'      => static function (int $min, int $max) use (&$randomArgs): int {
                    $randomArgs = [$min, $max];

                    return 0;
                },
                'sleep' => static function (): void {
                    // No-op sleep; the random generator arguments are asserted
                    // instead.
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([0, 8], $randomArgs);
    }

    /**
     * Verify jitter is skipped entirely when the configured ceiling is zero.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetrySkipsJitterWhenCeilingIsZero(): void
    {
        $randomCalled = false;
        $sleepCalls   = [];

        $connection = $this->buildRetryConnection(
            [
                'retry_delay_ms'  => 10,
                'retry_jitter_ms' => 0,
                'random_int'      => static function () use (&$randomCalled): int {
                    $randomCalled = true;

                    return 5;
                },
                'sleep' => static function (int $microseconds) use (&$sleepCalls): void {
                    $sleepCalls[] = $microseconds;
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertFalse($randomCalled);
        self::assertSame([10000], $sleepCalls);
    }

    /**
     * Verify retry jitter uses zero as the lower bound for the random
     * generator.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryUsesZeroLowerBoundForJitter(): void
    {
        $randomArgs = [];

        $connection = $this->buildRetryConnection(
            [
                'retry_delay_ms'  => 10,
                'retry_jitter_ms' => 5,
                'random_int'      => static function (int $min, int $max) use (&$randomArgs): int {
                    $randomArgs = [$min, $max];

                    return 0;
                },
                'sleep' => static function (): void {
                    // No-op sleep; the random generator arguments are asserted
                    // instead.
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([0, 5], $randomArgs);
    }

    /**
     * Verify a zero base delay short-circuits before invoking the sleep
     * callback.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryDoesNotSleepWhenBaseDelayIsZero(): void
    {
        $sleepCalls = [];

        $connection = $this->buildRetryConnection(
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
                'sleep'           => static function (int $microseconds) use (&$sleepCalls): void {
                    $sleepCalls[] = $microseconds;
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([], $sleepCalls);
    }

    /**
     * Verify a zero base delay is preserved when the random generator throws.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryDoesNotSleepWhenRandomThrowsAndBaseDelayIsZero(): void
    {
        $sleepCalls = [];

        $connection = $this->buildRetryConnection(
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 5,
                'random_int'      => static function (): int {
                    throw new \DomainException('entropy unavailable');
                },
                'sleep' => static function (int $microseconds) use (&$sleepCalls): void {
                    $sleepCalls[] = $microseconds;
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([], $sleepCalls);
    }

    /**
     * Verify client() returns the cluster fake when constructed with one.
     *
     * @return void
     */
    #[Test]
    public function clientReturnsClusterInstanceWhenConstructedWithCluster(): void
    {
        $client = new ValkeyGlideClusterFake;

        $connection = new ValkeyGlideConnection($client);

        self::assertSame($client, $connection->client());
        self::assertInstanceOf(ValkeyGlideClusterFake::class, $connection->client());
    }

    /**
     * Verify a cluster-backed EVAL raw command routes by the first key slot.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterEvalWithKeyRoutesToPrimarySlotKeyOfFirstKey(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 1);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(
            1,
            $connection->command(
                'eval',
                [self::EVAL_TEST_SCRIPT, 1, 'queue-key', 'arg-value'],
            ),
        );

        self::assertSame(
            [
                [
                    ['type' => 'primarySlotKey', 'key' => 'app:queue-key'],
                    'EVAL',
                    self::EVAL_TEST_SCRIPT,
                    1,
                    'app:queue-key',
                    'arg-value',
                ],
            ],
            $client->callsFor('rawcommand'),
        );
    }

    /**
     * Verify a cluster-backed phpredis-style SET routes by the key at index
     * zero.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterSetWithOptionsRoutesToPrimarySlotKeyOfSetKey(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 'OK');

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(
            'OK',
            $connection->command('set', ['lock-key', 'owner-id', 'EX', 10, 'NX']),
        );

        self::assertSame(
            [
                [
                    ['type' => 'primarySlotKey', 'key' => 'app:lock-key'],
                    'SET',
                    'app:lock-key',
                    'owner-id',
                    'EX',
                    10,
                    'NX',
                ],
            ],
            $client->callsFor('rawcommand'),
        );
    }

    /**
     * Verify a cluster-backed keyless EVAL (numkeys = 0) falls back to
     * randomNode.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterEvalWithZeroKeysFallsBackToRandomNode(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', null);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $connection->command('eval', [self::EVAL_TEST_SCRIPT, 0, 'arg-value']);

        $calls = $client->callsFor('rawcommand');
        self::assertCount(1, $calls);
        self::assertSame('randomNode', $calls[0][0]);
        self::assertSame('EVAL', $calls[0][1]);
    }

    /**
     * Verify a cluster EVALSHA with a key routes to the slot of its first key,
     * as EVAL does.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterEvalshaWithKeyRoutesToPrimarySlotKeyOfFirstKey(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 1);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(1, $connection->command('evalsha', ['sha', 1, 'queue-key', 'arg-value']));
        self::assertSame(
            [[['type' => 'primarySlotKey', 'key' => 'app:queue-key'], 'EVALSHA', 'sha', 1, 'app:queue-key', 'arg-value']],
            $client->callsFor('rawcommand'),
        );
    }

    /**
     * Verify a cluster EVAL whose key count is not a number routes to
     * randomNode rather than treat an argument as its key.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterEvalWithAnInvalidKeyCountRoutesToRandomNode(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', null);

        $connection = new ValkeyGlideConnection($client);

        $connection->command('eval', [self::EVAL_TEST_SCRIPT, 'many', 'arg-value']);

        self::assertSame('randomNode', $client->callsFor('rawcommand')[0][0]);
    }

    /**
     * Provide cluster-routed raw commands whose key GLIDE cannot carry in a
     * slot-key route, with the connection config and the command sent.
     *
     * @return iterable<string, array{string, array<int, mixed>, array<string, mixed>, string}>
     */
    public static function clusterRawCommandsWithUnroutableKeys(): iterable
    {
        yield 'eval with an invalid utf-8 key' => ['eval', [self::EVAL_TEST_SCRIPT, 1, "\xff", 'arg-value'], [], 'EVAL'];
        yield 'evalsha with an invalid utf-8 key' => ['evalsha', ['sha', 1, "queue:\xc3"], [], 'EVALSHA'];
        yield 'set with an invalid utf-8 key' => ['set', ["\xff", 'owner-id', 'EX', 10, 'NX'], [], 'SET'];
        yield 'set with an invalid utf-8 key after the prefix' => ['set', ["\xff", 'owner-id', 'EX', 10, 'NX'], ['prefix' => 'app:'], 'SET'];
        yield 'set with an empty key' => ['set', ['', 'owner-id', 'EX', 10, 'NX'], [], 'SET'];
    }

    /**
     * Verify a cluster-routed raw command whose key is empty or not valid UTF-8
     * falls back to randomNode, so GLIDE follows the MOVED redirect.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @param  array<string, mixed>  $config
     * @param  string  $command
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('clusterRawCommandsWithUnroutableKeys')]
    #[Test]
    public function clusterRawCommandWithUnroutableKeyFallsBackToRandomNode(string $method, array $parameters, array $config, string $command): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 'OK');

        $connection = new ValkeyGlideConnection($client, null, $config);

        $connection->command($method, $parameters);

        $calls = $client->callsFor('rawcommand');
        self::assertCount(1, $calls);
        self::assertSame('randomNode', $calls[0][0]);
        self::assertSame($command, $calls[0][1]);
    }

    /**
     * Verify a cluster-backed phpredis-style SET with a multi-byte UTF-8 key
     * still routes to the key's slot.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterSetWithMultiByteKeyRoutesToPrimarySlotKey(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('rawcommand', 'OK');

        $connection = new ValkeyGlideConnection($client);

        $connection->command('set', ['user:é', 'owner-id', 'EX', 10, 'NX']);

        self::assertSame(['type' => 'primarySlotKey', 'key' => 'user:é'], $client->callsFor('rawcommand')[0][0]);
    }

    /**
     * Verify a raw command for an unexpected method defensively routes to
     * randomNode.
     *
     * @return void
     */
    #[Test]
    public function resolveClusterRouteFallsBackToRandomNodeForUnexpectedMethod(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertSame('randomNode', $this->invokeResolveClusterRoute($connection, 'GET', ['some-key']));
    }

    /**
     * Verify the standalone path does not prepend a route argument to
     * rawcommand.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function standaloneSetWithOptionsCallsRawcommandWithoutLeadingRoute(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 'OK');

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(
            'OK',
            $connection->command('set', ['lock-key', 'owner-id', 'EX', 10, 'NX']),
        );

        self::assertSame(
            [
                ['SET', 'app:lock-key', 'owner-id', 'EX', 10, 'NX'],
            ],
            $client->callsFor('rawcommand'),
        );
    }

    /**
     * Verify a retry closes the client it replaces and keeps the new one open.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryClosesTheReplacedClient(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertCount(1, $firstClient->callsFor('close'));
        self::assertCount(0, $secondClient->callsFor('close'));
        self::assertSame($secondClient, $connection->client());
    }

    /**
     * Verify a retry closes a replaced cluster client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryClosesTheReplacedClusterClient(): void
    {
        $firstClient = new ValkeyGlideClusterFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideClusterFake;
        $secondClient->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlideCluster => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertCount(1, $firstClient->callsFor('close'));
        self::assertCount(0, $secondClient->callsFor('close'));
    }

    /**
     * Verify a failure to close the replaced client does not stop the retry.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryIgnoresFailureToCloseTheReplacedClient(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));
        $firstClient->willThrow('close', new \RuntimeException('close failed: broken pipe'));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertCount(1, $firstClient->callsFor('close'));
        self::assertSame($secondClient, $connection->client());
    }

    /**
     * Verify a failure to close the replaced client never masks the command
     * error reported when the retry also fails.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRetryReportsTheCommandErrorWhenCloseAlsoFails(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));
        $firstClient->willThrow('close', new \RuntimeException('close failed: broken pipe'));

        $retryError   = new \RuntimeException('connection lost');
        $secondClient = new ValkeyGlideFake;
        $secondClient->willThrow('get', $retryError);

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );
        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->command('get', ['retry-key']);
            self::fail('Expected the retry error to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame($retryError, $exception);
            self::assertCount(1, $events);
            self::assertSame($retryError, $events[0]->exception);
        }
    }

    /**
     * Verify the client is not closed when the connector hands back the same
     * instance.
     *
     * @return void
     */
    #[Test]
    public function reconnectClientDoesNotCloseAClientTheConnectorReturnsAgain(): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client, static fn (): \ValkeyGlide => $client);

        self::assertTrue($this->invokeReconnectClient($connection));
        self::assertCount(0, $client->callsFor('close'));
        self::assertSame($client, $connection->client());
    }

    /**
     * Verify a failed reconnect keeps the existing client open and in place.
     *
     * @return void
     */
    #[Test]
    public function reconnectClientKeepsTheExistingClientWhenTheConnectorFails(): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client, static function (): \ValkeyGlide {
            throw new \RuntimeException('Unable to establish a Valkey GLIDE connection');
        });

        try {
            $this->invokeReconnectClient($connection);
            self::fail('Expected the connector failure to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to establish a Valkey GLIDE connection', $exception->getMessage());
            self::assertCount(0, $client->callsFor('close'));
            self::assertSame($client, $connection->client());
        }
    }

    /**
     * Provide GLIDE's SETNX replies and the integer phpredis gives for each.
     *
     * @return iterable<string, array{mixed, int}>
     */
    public static function setnxReplies(): iterable
    {
        yield 'set' => [true, 1];
        yield 'not set' => [false, 0];
    }

    /**
     * Verify setnx answers with 1 or 0, as phpredis does through Laravel, for
     * the key it prefixes.
     *
     * @param  mixed  $reply
     * @param  int  $expected
     * @return void
     */
    #[DataProvider('setnxReplies')]
    #[Test]
    public function setnxReturnsAnIntegerForThePrefixedKey(mixed $reply, int $expected): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('setnx', $reply);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame($expected, $connection->setnx('lock', 1));
        self::assertSame([['app:lock', 1]], $client->callsFor('setnx'));
    }

    /**
     * Provide the scores arguments zrevrange takes, and whether each asks for
     * scores.
     *
     * @return iterable<string, array{mixed, bool}>
     */
    public static function zrevrangeScoresArguments(): iterable
    {
        yield 'omitted' => [null, false];
        yield 'false' => [false, false];
        yield 'true' => [true, true];
        yield 'options with scores' => [['withscores' => true], true];
        yield 'options without scores' => [['withscores' => false], false];
        yield 'empty options' => [[], false];
    }

    /**
     * Verify zrevrange reads the range with ZRANGE ... REV for the prefixed
     * key, asking for scores as the caller did.
     *
     * @param  mixed  $scores
     * @param  bool  $withScores
     * @return void
     */
    #[DataProvider('zrevrangeScoresArguments')]
    #[Test]
    public function zrevrangeReadsTheRangeInReverseThroughZrange(mixed $scores, bool $withScores): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zrange', ['job-c', 'job-b']);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(['job-c', 'job-b'], $connection->zrevrange('tag', 0, 1, $scores));
        self::assertSame([['app:tag', 0, 1, ['rev' => true, 'withscores' => $withScores]]], $client->callsFor('zrange'));
    }

    /**
     * Verify scan returns the next cursor and the page's keys, sending the
     * match pattern unprefixed and the count as given.
     *
     * @return void
     */
    #[Test]
    public function scanReturnsTheNextCursorAndKeysOfOnePage(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [['17', ['app:job:1', 'app:job:2']]]);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(['17', ['app:job:1', 'app:job:2']], $connection->scan('0', ['match' => 'app:job:*', 'count' => 100]));
        self::assertSame([['0', 'app:job:*', 100, null]], $client->callsFor('scan'));
    }

    /**
     * Provide the cursors a caller may start a scan from.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function scanStartCursors(): iterable
    {
        yield 'string zero' => ['0'];
        yield 'integer zero' => [0];
        yield 'null' => [null];
    }

    /**
     * Verify scan starts from cursor '0', matching every key ten at a time when
     * no options are given, as phpredis does.
     *
     * @param  mixed  $cursor
     * @return void
     */
    #[DataProvider('scanStartCursors')]
    #[Test]
    public function scanStartsFromCursorZeroWithPhpRedisDefaults(mixed $cursor): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['0', []], $connection->scan($cursor));
        self::assertSame([['0', '*', 10, null]], $client->callsFor('scan'));
    }

    /**
     * Verify scan resumes from an integer cursor sent as its string.
     *
     * @return void
     */
    #[Test]
    public function scanResumesFromAnIntegerCursor(): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client);

        $connection->scan(17, ['match' => 'job:*']);

        self::assertSame([['17', 'job:*', 10, null]], $client->callsFor('scan'));
    }

    /**
     * Verify a scan GLIDE answers with false throws, as GLIDE answers a failed
     * scan with false and writes '0' back, which would otherwise read as the
     * empty last page of a completed scan.
     *
     * @return void
     */
    #[Test]
    public function scanThrowsWhenGlideAnswersFalse(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [['0', false]]);

        $connection = new ValkeyGlideConnection($client);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected array response from scan on [job:*], received [bool].');

        $connection->scan('5', ['match' => 'job:*']);
    }

    /**
     * Verify an empty page GLIDE answers with an empty array is returned as an
     * empty page with the next cursor.
     *
     * @return void
     */
    #[Test]
    public function scanReturnsAnEmptyPageGlideAnswersWithAnEmptyArray(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [['12', []]]);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['12', []], $connection->scan('0'));
    }

    /**
     * Verify a scan loop written for Laravel's connections, from '0' until the
     * cursor is '0' again, reads every page once and ends.
     *
     * @return void
     */
    #[Test]
    public function scanLoopFromCursorZeroReadsEveryPageAndEnds(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [['12', ['job:1']], ['30', []], ['0', ['job:2', 'job:3']]]);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['job:1', 'job:2', 'job:3'], $this->scanAll($connection, 'job:*'));
        self::assertSame(['0', '12', '30'], array_column($client->callsFor('scan'), 0));
    }

    /**
     * Verify scan dispatches a command event carrying the cursor GLIDE wrote
     * back.
     *
     * @return void
     */
    #[Test]
    public function scanDispatchesACommandEventWithTheNextCursor(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [['42', ['job:1']]]);

        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $connection->setEventDispatcher($dispatcher);
        $connection->scan('0', ['match' => 'job:*']);

        self::assertCount(1, $events);
        self::assertSame('scan', $events[0]->command);
        self::assertSame(['42', 'job:*', 10], $events[0]->parameters);
    }

    /**
     * Verify a cluster scan starts from a new ClusterScanCursor, resumes from a
     * cursor built from the id it returned, and ends on '0'.
     *
     * @return void
     */
    #[Test]
    public function clusterScanResumesFromTheCursorIdsItReturns(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan('scan', [['id-1', ['job:1']], ['id-2', []], ['0', ['job:2']]]);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['job:1', 'job:2'], $this->scanAll($connection, 'job:*'));
        self::assertSame([
            ['0', 'job:*', 10, null],
            ['id-1', 'job:*', 10, null],
            ['id-2', 'job:*', 10, null],
        ], $client->callsFor('scan'));
    }

    /**
     * Verify a cluster scan refused inside a batch leaves the cursor id it was
     * given unused, so the scan resumes from it once the batch is discarded.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterScanRefusedInsideABatchCanResumeAfterIt(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan('scan', [['id-1', ['job:1']], ['0', ['job:2']]]);

        $connection = new ValkeyGlideConnection($client);

        [$cursor] = $connection->scan('0', ['match' => 'job:*']);

        $connection->pipeline();

        try {
            $connection->scan($cursor, ['match' => 'job:*']);
            self::fail('Expected the scan inside the batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringStartsWith('[SCAN] cannot be used inside a pipeline or transaction', $exception->getMessage());
        }

        self::assertTrue($connection->discard());
        self::assertSame(['0', ['job:2']], $connection->scan($cursor, ['match' => 'job:*']));
        self::assertSame([['0', 'job:*', 10, null], ['id-1', 'job:*', 10, null]], $client->callsFor('scan'));
    }

    /**
     * Verify a cluster scan sends the match pattern unprefixed and the count as
     * given.
     *
     * @return void
     */
    #[Test]
    public function clusterScanSendsTheMatchPatternAndCount(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan('scan', [['id-1', ['app:job:1']]]);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(['id-1', ['app:job:1']], $connection->scan(0, ['match' => 'app:job:*', 'count' => 1000]));
        self::assertSame([['0', 'app:job:*', 1000, null]], $client->callsFor('scan'));
    }

    /**
     * Verify each page of a cluster scan dispatches a command executed event
     * carrying the cursor id it scans from, which listeners can turn into a
     * string.
     *
     * @return void
     */
    #[Test]
    public function clusterScanDispatchesACommandEventWithTheCursorIdOfEachPage(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan('scan', [['id-1', ['job:1']], ['0', ['job:2']]]);

        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $connection->setEventDispatcher($dispatcher);

        self::assertSame(['job:1', 'job:2'], $this->scanAll($connection, 'job:*'));
        self::assertSame(['scan', 'scan'], array_column($events, 'command'));
        self::assertSame([['0', 'job:*', 10], ['id-1', 'job:*', 10]], array_column($events, 'parameters'));
        self::assertSame(['0 job:* 10', 'id-1 job:* 10'], array_map(static fn (CommandExecuted $event): string => implode(' ', $event->parameters), $events));
    }

    /**
     * Verify a cluster scan GLIDE fails dispatches a command failed event
     * carrying the cursor id it scanned from.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterScanDispatchesACommandFailedEventWithTheCursorId(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willThrow('scan', new \RuntimeException('permanent failure'));

        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $event) use (&$events): void {
            $events[] = $event;
        });

        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->scan('0', ['match' => 'job:*']);
            self::fail('Expected the failed cluster scan to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('permanent failure', $exception->getMessage());
            self::assertCount(1, $events);
            self::assertSame('scan', $events[0]->command);
            self::assertSame(['0', 'job:*', 10], $events[0]->parameters);
        }
    }

    /**
     * Verify a cluster scan GLIDE answers without a list of keys throws, and
     * that the cursor it leaves is not recorded as one to resume from.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterScanThrowsWhenGlideAnswersWithoutKeys(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan('scan', [['7', false]]);

        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->scan('0', ['match' => 'job:*']);
            self::fail('A failed cluster scan should throw.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Expected array response from scan on [job:*], received [bool].', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);

        $connection->scan('7');
    }

    /**
     * Verify a cluster scan refuses a cursor id the connection did not return,
     * before GLIDE is called, as GLIDE aborts the process on one.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterScanRefusesACursorItDidNotReturn(): void
    {
        $client = new ValkeyGlideClusterFake;

        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->scan('17');
            self::fail('Expected an unknown cluster scan cursor to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Cannot resume a cluster scan from cursor [17]: only a cursor this connection returned can be resumed, once.', $exception->getMessage());
            self::assertSame([], $client->callsFor('scan'));
        }
    }

    /**
     * Verify a cluster scan refuses a cursor id it has already resumed from, as
     * GLIDE aborts the process on one once its scan has ended.
     *
     * @return void
     */
    #[Test]
    public function clusterScanRefusesACursorItHasAlreadyResumed(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan('scan', [['id-1', []], ['0', []]]);

        $connection = new ValkeyGlideConnection($client);

        $connection->scan('0');
        $connection->scan('id-1');

        $this->expectException(\InvalidArgumentException::class);

        $connection->scan('id-1');
    }

    /**
     * Verify a scan given GLIDE's own ClusterScanCursor, pattern and count is
     * passed to GLIDE unchanged, so the cursor's next id is written as before.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function scanPassesAClusterScanCursorToGlideUnchanged(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan('scan', [['id-1', ['user:1']], ['0', ['user:2']]]);

        $connection = new ValkeyGlideConnection($client);
        $cursor     = new \ClusterScanCursor;

        self::assertSame(['user:1'], $connection->scan($cursor, 'user:*', 100));
        self::assertSame('id-1', $cursor->getNextCursor());

        $cursor = new \ClusterScanCursor($cursor->getNextCursor());

        self::assertSame(['user:2'], $connection->scan($cursor));
        self::assertSame([['0', 'user:*', 100, null], ['id-1', null, 0, null]], $client->callsFor('scan'));
    }

    /**
     * Verify a scan given GLIDE's own pattern argument rather than an options
     * array is passed to GLIDE unchanged, which refuses a string cursor on a
     * cluster as it did before.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function scanPassesGlidePositionalArgumentsToGlideUnchanged(): void
    {
        $client = new ValkeyGlideClusterFake;

        $connection = new ValkeyGlideConnection($client);

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('must be of type ClusterScanCursor, string given');

        $connection->scan('17', 'user:*', 100);
    }

    /**
     * Verify a pipeline callback's commands are prefixed, sent together, and
     * returned in call order.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineReturnsTheResultsInCallOrder(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');
        $client->willReturn('hmget', ['field' => 'value']);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('key', 'value');
            $pipe->get('key');
            $pipe->hmget('hash', ['field']);
        });

        self::assertSame([true, 'value', ['field' => 'value']], $results);
        self::assertSame(['pipeline', 'set', 'get', 'hmget', 'exec'], $client->callOrder());
        self::assertSame([['app:key', 'value', null]], $client->callsFor('set'));
        self::assertSame([['app:key']], $client->callsFor('get'));
        self::assertSame([['app:hash', ['field']]], $client->callsFor('hmget'));
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a pipeline callback receives the batch object.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineHandsTheBatchToTheCallback(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);
        $received   = [];

        $connection->pipeline(static function (mixed $pipe) use (&$received): void {
            $received[] = $pipe;
        });

        self::assertCount(1, $received);
        self::assertInstanceOf(ValkeyGlideBatch::class, $received[0]);
    }

    /**
     * Verify a transaction callback runs its commands in a MULTI.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function transactionRunsItsCommandsInAMulti(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incr', 1);
        $client->willReturn('expire', true);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame([1, true], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->incr('counter')->expire('counter', 60)));
        self::assertSame(['multi', 'incr', 'expire', 'exec'], $client->callOrder());
        self::assertSame([[]], $client->callsFor('multi'));
    }

    /**
     * Verify pipeline() without a callback returns a fluent batch.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineWithoutACallbackReturnsAFluentBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $batch = (new ValkeyGlideConnection($client))->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);
        self::assertSame([], $client->callOrder());
        self::assertSame([true, 'value'], $batch->set('key', 'value')->get('key')->exec());
    }

    /**
     * Verify transaction() without a callback returns a fluent batch that runs
     * in a MULTI.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function transactionWithoutACallbackReturnsAFluentBatch(): void
    {
        $client = new ValkeyGlideFake;
        $batch  = (new ValkeyGlideConnection($client))->transaction();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);
        self::assertSame([true], $batch->set('key', 'value')->exec());
        self::assertSame(['multi', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Provide the batch methods.
     *
     * @return iterable<string, array{string}>
     */
    public static function batchMethods(): iterable
    {
        yield 'pipeline' => ['pipeline'];
        yield 'transaction' => ['transaction'];
    }

    /**
     * Verify an empty batch never reaches GLIDE, which an empty exec() would
     * leave stuck in batch mode.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('batchMethods')]
    #[Test]
    public function emptyBatchNeverReachesGlide(string $method): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);

        self::assertSame([], $this->runBatch($connection, $method, static function (): void {
            // No commands: Horizon runs empty pipelines on every worker pop.
        }));
        self::assertSame([], $client->callOrder());
        self::assertSame('value', $connection->get('key'));
    }

    /**
     * Verify a pipeline of immediate commands runs them without starting
     * GLIDE's batch mode.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineOfImmediateCommandsNeverStartsGlideBatchMode(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 7);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame([7, 7], $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->eval(self::EVAL_TEST_SCRIPT, 0);
            $pipe->evalsha('sha', 0);
        }));
        self::assertSame(['rawcommand', 'rawcommand'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify an immediate command in a pipeline sends what was buffered first
     * and keeps the results in call order.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineFlushesBeforeAnImmediateCommand(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 'old');
        $client->willReturn('get', 'new');

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('key', 'old');
            $pipe->eval(self::EVAL_TEST_SCRIPT, 1, 'key');
            $pipe->set('key', 'new');
            $pipe->get('key');
        });

        self::assertSame([true, 'old', true, 'new'], $results);
        self::assertSame(['pipeline', 'set', 'exec', 'rawcommand', 'pipeline', 'set', 'get', 'exec'], $client->callOrder());
        self::assertSame([['EVAL', self::EVAL_TEST_SCRIPT, 1, 'app:key']], $client->callsFor('rawcommand'));
    }

    /**
     * Verify commands called on the connection inside a callback join the
     * batch, as Laravel's RedisQueue::bulk() issues them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectionCommandsInsideACallbackJoinTheBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);
        $client->willReturn('zadd', 1);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);
        $returned   = [];

        $results = $connection->pipeline(static function () use ($connection, &$returned): void {
            $connection->transaction(static function () use ($connection, &$returned): void {
                $returned[] = $connection->command('eval', [self::EVAL_TEST_SCRIPT, 1, 'queue', 'job-1']);
                $returned[] = $connection->command('zadd', ['queue:delayed', 10, 'job-2']);
                $returned[] = $connection->command('eval', [self::EVAL_TEST_SCRIPT, 1, 'queue', 'job-3']);
            });
        });

        self::assertSame([1, 1, 1], $results);
        self::assertContainsOnlyInstancesOf(ValkeyGlideBatch::class, $returned);
        self::assertSame(['rawcommand', 'pipeline', 'zadd', 'exec', 'rawcommand'], $client->callOrder());
        self::assertSame([['app:queue:delayed', 10, 'job-2']], $client->callsFor('zadd'));
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a transaction nested in a pipeline joins the pipeline and returns
     * the batch.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function transactionNestedInAPipelineJoinsThePipeline(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $nested     = [];

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection, &$nested): void {
            $pipe->set('a', '1');
            $nested[] = $connection->transaction(static function (ValkeyGlideBatch $tx) use ($pipe): void {
                self::assertSame($pipe, $tx);
                $tx->set('b', '2');
            });
            $nested[] = $connection->pipeline();
            $pipe->set('c', '3');
        });

        self::assertSame([true, true, true], $results);
        self::assertSame(['pipeline', 'set', 'set', 'set', 'exec'], $client->callOrder());
        self::assertContainsOnlyInstancesOf(ValkeyGlideBatch::class, $nested);
    }

    /**
     * Verify a pipeline nested in a transaction stays in the transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineNestedInATransactionStaysAtomic(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);

        $connection = new ValkeyGlideConnection($client);

        $connection->transaction(static function () use ($connection): void {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1'));
        });

        self::assertSame(['multi', 'set', 'exec'], $client->callOrder());

        $this->expectException(\LogicException::class);

        $connection->transaction(static function () use ($connection): void {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->eval(self::EVAL_TEST_SCRIPT, 0));
        });
    }

    /**
     * Verify a callback that throws discards the batch, resets the connection,
     * and rethrows.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function throwingCallbackDiscardsTheBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);
        $failure    = new \DomainException('callback failed');

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($failure): void {
                $pipe->set('key', 'value');

                throw $failure;
            });
            self::fail('Expected the callback exception to be rethrown.');
        } catch (\DomainException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(['pipeline', 'set', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
        self::assertSame('value', $connection->get('key'));
    }

    /**
     * Verify a nested callback that throws discards the whole batch.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function throwingNestedCallbackDiscardsTheWholeBatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection): void {
                $pipe->set('a', '1');
                $connection->transaction(static function (): void {
                    throw new \DomainException('nested failure');
                });
            });
            self::fail('Expected the nested callback exception to be rethrown.');
        } catch (\DomainException) {
            self::assertSame(['pipeline', 'set', 'discard'], $client->callOrder());
        }

        self::assertSame([], $connection->pipeline(static function (): void {
            // A new batch starts empty once the failed one was discarded.
        }));
    }

    /**
     * Verify a callback that catches the discard of its batch cannot carry on
     * as if the batch were still active.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function callbackThatSwallowsADiscardIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $refusals   = [];

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx) use (&$refusals): void {
                $tx->set('a', '1');

                try {
                    $tx->eval(self::EVAL_TEST_SCRIPT, 0);
                } catch (\LogicException $exception) {
                    $refusals[] = $exception->getMessage();
                }

                try {
                    $tx->set('b', '2');
                } catch (\LogicException $exception) {
                    $refusals[] = $exception->getMessage();
                }
            });
            self::fail('Expected the discarded transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::ENDED_IN_CALLBACK_MESSAGE, $exception->getMessage());
        }

        self::assertCount(2, $refusals);
        self::assertSame('This pipeline or transaction has already been executed or discarded.', $refusals[1]);
        self::assertSame(['multi', 'set', 'discard'], $client->callOrder());
    }

    /**
     * Provide the connection calls a callback could make once its batch has
     * ended inside it, with the method each is refused as.
     *
     * @return iterable<string, array{\Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed, string}>
     */
    public static function callsAfterAnEndedBatch(): iterable
    {
        yield 'command through __call' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->__call('incr', ['n']), 'incr'];
        yield 'command()' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->command('set', ['b', '2']), 'set'];
        yield 'command() in upper case' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->command('SET', ['b', '2']), 'set'];
        yield 'typed get()' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->get('b'), 'get'];
        yield 'typed mget()' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->mget(['b']), 'mget'];
        yield 'executeRaw()' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->executeRaw(['GET', 'b']), 'rawcommand'];
        yield 'bare multi()' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->multi(), 'multi'];
        yield 'bare pipeline command' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->command('pipeline'), 'pipeline'];
        yield 'pipeline()' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->pipeline(), 'pipeline'];
        yield 'transaction() with a callback' => [
            static fn (ValkeyGlideConnection $connection): mixed => $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('b', '2')),
            'transaction',
        ];
        yield 'exec()' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->exec(), 'exec'];
        yield 'discard()' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->discard(), 'discard'];
        yield 'watch' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->command('watch', ['b']), 'watch'];
        yield 'subscribe' => [static function (ValkeyGlideConnection $connection): mixed {
            $connection->subscribe(['channel'], static function (): void {});

            return null;
        }, 'subscribe'];
    }

    /**
     * Verify a connection call that a transaction callback makes after it
     * catches the exception that ended its transaction is refused, rather than
     * run outside any transaction, and that the callback's end then reports the
     * batch as not executed.
     *
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed  $call
     * @param  string  $method
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('callsAfterAnEndedBatch')]
    #[Test]
    public function connectionCallAfterTheTransactionEndedInsideItsCallbackIsRefused(\Closure $call, string $method): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);
        $refusals   = [];

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx) use ($connection, $call, &$refusals): void {
                $tx->set('a', '1');

                self::assertNotNull(self::logicRefusalOf(static fn (): ValkeyGlideBatch => $tx->eval(self::EVAL_TEST_SCRIPT, 0)));

                $refusals[] = self::logicRefusalOf(static fn (): mixed => $call($connection));
            });
            self::fail('Expected the ended transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::ENDED_IN_CALLBACK_MESSAGE, $exception->getMessage());
        }

        self::assertSame([sprintf(self::ENDED_BATCH_MESSAGE, $method)], $refusals);
        self::assertSame(['multi', 'set', 'discard'], $client->callOrder());
        self::assertFalse($connection->exec());
        self::assertSame('v', $connection->get('a'));
    }

    /**
     * Verify connection calls that a pipeline callback makes after it catches
     * the exception of a nested callback, which ended the pipeline, are
     * refused, rather than run outside the pipeline.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectionCallAfterANestedCallbackEndedThePipelineIsRefused(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incr', 2);

        $connection = new ValkeyGlideConnection($client);
        $failure    = new \DomainException('nested failure');
        $refusals   = [];

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection, $failure, &$refusals): void {
                $pipe->incr('n');

                self::assertSame($failure, self::exceptionOf(static fn (): mixed => $connection->transaction(static function (ValkeyGlideBatch $tx) use ($failure): never {
                    $tx->incr('n');

                    throw $failure;
                })));

                $refusals[] = self::logicRefusalOf(static fn (): mixed => $connection->command('incr', ['n']));
                $refusals[] = self::logicRefusalOf(static fn (): mixed => $connection->command('incr', ['n']));
            });
            self::fail('Expected the ended pipeline to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::ENDED_IN_CALLBACK_MESSAGE, $exception->getMessage());
        }

        self::assertSame(array_fill(0, 2, sprintf(self::ENDED_BATCH_MESSAGE, 'incr')), $refusals);
        self::assertSame(['pipeline', 'incr', 'incr', 'discard'], $client->callOrder());
        self::assertSame(2, $connection->command('incr', ['n']));
    }

    /**
     * Verify a connection call that a pipeline callback makes after it catches
     * the failure of an immediate command, which ended the pipeline, is
     * refused, rather than run outside the pipeline.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectionCallAfterAFailedImmediateCommandIsRefused(): void
    {
        $client = new ValkeyGlideFake;
        $client->willThrow('rawcommand', new \RuntimeException('script failed'));

        $connection = new ValkeyGlideConnection($client);
        $refusals   = [];

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection, &$refusals): void {
                $pipe->set('a', '1');

                self::assertInstanceOf(\RuntimeException::class, self::exceptionOf(static fn (): ValkeyGlideBatch => $pipe->eval(self::EVAL_TEST_SCRIPT, 0)));

                $refusals[] = self::logicRefusalOf(static fn (): mixed => $connection->command('set', ['b', '2']));
            });
            self::fail('Expected the ended pipeline to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::ENDED_IN_CALLBACK_MESSAGE, $exception->getMessage());
        }

        self::assertSame([sprintf(self::ENDED_BATCH_MESSAGE, 'set')], $refusals);
        self::assertSame(['pipeline', 'set', 'exec', 'rawcommand'], $client->callOrder());
        self::assertFalse($connection->exec());
    }

    /**
     * Verify a callback that throws once its batch has ended inside it leaves
     * the connection free, so later commands run directly.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function callbackThrowingAfterItsBatchEndedLeavesTheConnectionFree(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);
        $failure    = new \DomainException('callback failed');

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx) use ($failure): never {
                try {
                    $tx->set('a', '1')->eval(self::EVAL_TEST_SCRIPT, 0);
                } catch (\LogicException) {
                    // The transaction refuses the script and ends.
                }

                throw $failure;
            });
            self::fail('Expected the callback exception to be thrown.');
        } catch (\DomainException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($connection->exec());
        self::assertSame('v', $connection->get('a'));
        self::assertSame(['multi', 'set', 'discard', 'get'], $client->callOrder());
    }

    /**
     * Verify a fluent pipeline that a nested callback ended leaves the
     * connection free, as no callback owns the pipeline.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function fluentPipelineEndedByANestedCallbackLeavesTheConnectionFree(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);
        $pipe       = self::activeBatch($connection);
        $failure    = new \DomainException('nested failure');

        $pipe->set('a', '1');

        try {
            $pipe->transaction(static function (ValkeyGlideBatch $tx) use ($failure): never {
                $tx->set('b', '2');

                throw $failure;
            });
            self::fail('Expected the nested callback exception to be thrown.');
        } catch (\DomainException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($connection->exec());
        self::assertSame('v', $connection->get('a'));
        self::assertSame(['pipeline', 'set', 'set', 'discard', 'get'], $client->callOrder());
    }

    /**
     * Verify an immediate command in a transaction discards it and is refused,
     * as GLIDE would run it outside the MULTI block.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function immediateCommandInATransactionIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx): void {
                $tx->set('key', 'value');
                $tx->evalsha('sha', 0);
            });
            self::fail('Expected the immediate command to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::IMMEDIATE_IN_TRANSACTION_MESSAGE, 'EVALSHA'), $exception->getMessage());
        }

        self::assertSame(['multi', 'set', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify phpredis-style SET options are buffered as GLIDE's options array.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function setOptionsAreBufferedAsAnOptionsArray(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('lock', 'owner', 'EX', 10, 'NX'));

        self::assertSame([['app:lock', 'owner', ['EX' => 10, 'NX']]], $client->callsFor('set'));
        self::assertSame([], $client->callsFor('rawcommand'));
    }

    /**
     * Verify SET options GLIDE cannot buffer run immediately in a pipeline.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function unconvertibleSetOptionsRunImmediately(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', false);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame([true, false], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->set('lock', 'owner', 'EX', 0)));
        self::assertSame([['SET', 'app:lock', 'owner', 'EX', 0]], $client->callsFor('rawcommand'));
    }

    /**
     * Verify a key-list del() is spread into variadic keys so it is buffered.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function keyListDelIsSpreadSoItIsBuffered(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('del', 2);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame([2], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->del(['a', 'b'])));
        self::assertSame([['app:a', 'app:b']], $client->callsFor('del'));
    }

    /**
     * Verify a key-list del() outside a batch keeps its form and is prefixed.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function keyListDelOutsideABatchIsPrefixed(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('del', 2);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(2, $connection->command('del', [['a', 'b']]));
        self::assertSame([[['app:a', 'app:b']]], $client->callsFor('del'));
    }

    /**
     * Verify the newly prefixed commands are prefixed inside a batch as they
     * are outside one.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchedCommandsArePrefixedAsOutsideABatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'horizon:']);

        $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->hmset('job:1', ['status' => 'pending']);
            $pipe->zremrangebyscore('recent_jobs', 0, 100);
            $pipe->zremrangebyrank('metrics', 0, -10);
            $pipe->zrevrangebyscore('supervisors', '+inf', 0);
            $pipe->exists('a', 'b');
            $pipe->mget(['a', 'b']);
        });

        $connection->command('hmset', ['job:2', ['status' => 'done']]);

        self::assertSame([['horizon:job:1', ['status' => 'pending']], ['horizon:job:2', ['status' => 'done']]], $client->callsFor('hmset'));
        self::assertSame([['horizon:recent_jobs', 0, 100]], $client->callsFor('zremrangebyscore'));
        self::assertSame([['horizon:metrics', 0, -10]], $client->callsFor('zremrangebyrank'));
        self::assertSame([['horizon:supervisors', '+inf', 0]], $client->callsFor('zrevrangebyscore'));
        self::assertSame([['horizon:a', 'horizon:b']], $client->callsFor('exists'));
        self::assertSame([[['horizon:a', 'horizon:b']]], $client->callsFor('mget'));
    }

    /**
     * Verify batched commands reach GLIDE with coercive typing, so the integer
     * arguments Laravel and Horizon pass are accepted.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchedCommandsReachGlideWithCoerciveTyping(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['value'], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->get(42)));
        self::assertSame([['42']], $client->callsFor('get'));
    }

    /**
     * Verify commands sent outside a batch reach GLIDE with coercive typing, so
     * the integer arguments Laravel and Horizon pass are accepted.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function directCommandsReachGlideWithCoerciveTyping(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);

        self::assertSame('value', $connection->command('get', [42]));
        self::assertSame([['42']], $client->callsFor('get'));
    }

    /**
     * Verify a watch() that GLIDE buffers, rather than sends, on a client put
     * in batch mode outside the driver is not tracked, so a later transaction
     * that fails throws rather than report a WATCH abort.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function watchBufferedOutsideTheDriverIsNotTracked(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $client->pipeline();

        self::assertSame($client, $connection->command('watch', ['a']));

        $client->discard();
        $client->willReturn('exec', false);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('The transaction failed and its outcome is unknown; it may have been committed.');

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('b', '1'));
    }

    /**
     * Provide commands a batch refuses.
     *
     * @return iterable<string, array{string, array<int, mixed>}>
     */
    public static function refusedBatchCommands(): iterable
    {
        yield 'select' => ['select', [1]];
        yield 'scan' => ['scan', [0]];
        yield 'hscan' => ['hscan', ['hash', 0]];
        yield 'sscan' => ['sscan', ['set', 0]];
        yield 'zscan' => ['zscan', ['zset', 0]];
        yield 'subscribe' => ['subscribe', [['channel']]];
        yield 'unsubscribe' => ['unsubscribe', [['channel']]];
    }

    /**
     * Verify commands a batch cannot carry are refused before anything is
     * buffered or sent.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('refusedBatchCommands')]
    #[Test]
    public function refusedCommandsAreRefusedBeforeBuffering(string $method, array $parameters): void
    {
        $client = new ValkeyGlideFake;
        $batch  = (new ValkeyGlideConnection($client))->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        try {
            $batch->__call($method, $parameters);
            self::fail('Expected the command to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringStartsWith('[' . strtoupper($method) . '] cannot be used inside a pipeline or transaction', $exception->getMessage());
        }

        self::assertSame([], $client->callOrder());
        self::assertSame([], $batch->exec());
    }

    /**
     * Provide connection methods that are refused while a batch is active.
     *
     * @return iterable<string, array{\Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed, string, string}>
     */
    public static function methodsRefusedDuringABatch(): iterable
    {
        yield 'get' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->get('key'), 'get', 'call get() on the batch instead'];
        yield 'mget' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->mget(['key']), 'mget', 'call mget() on the batch instead'];
        yield 'setnx' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->setnx('key', 'value'), 'setnx', 'call setnx() on the batch instead'];
        yield 'watch' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->command('watch', ['key']), 'watch', 'call watch() before starting the transaction'];
        yield 'unwatch' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->command('UNWATCH'), 'unwatch', 'call unwatch() outside the transaction'];
        yield 'subscribe' => [static function (ValkeyGlideConnection $connection): mixed {
            $connection->createSubscription('channel', static function (): void {
                // Never reached: the subscription is refused first.
            });

            return null;
        }, 'subscribe', 'subscribe outside the batch'];
        yield 'psubscribe' => [static function (ValkeyGlideConnection $connection): mixed {
            $connection->createSubscription(self::PSUBSCRIBE_PATTERN, static function (): void {
                // Never reached: the subscription is refused first.
            }, 'PSUBSCRIBE');

            return null;
        }, 'psubscribe', 'subscribe outside the batch'];
        yield 'watch on the batch' => [
            static fn (ValkeyGlideConnection $connection): mixed => self::activeBatch($connection)->__call('watch', ['key']),
            'watch',
            'call watch() before starting the transaction',
        ];
        yield 'unwatch on the batch' => [
            static fn (ValkeyGlideConnection $connection): mixed => self::activeBatch($connection)->__call('unwatch', []),
            'unwatch',
            'call unwatch() outside the transaction',
        ];
    }

    /**
     * Verify connection methods whose results are needed at once are refused
     * while a batch is active, without buffering anything.
     *
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed  $call
     * @param  string  $method
     * @param  string  $advice
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('methodsRefusedDuringABatch')]
    #[Test]
    public function connectionMethodsAreRefusedDuringABatch(\Closure $call, string $method, string $advice): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->pipeline();

        try {
            $call($connection);
            self::fail('Expected the call to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::ACTIVE_BATCH_MESSAGE, $method, $advice), $exception->getMessage());
        }

        self::assertSame([], $client->callOrder());
    }

    /**
     * Verify a command GLIDE refuses to buffer is reported rather than left as
     * a phantom slot.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandGlideRefusesToBufferIsReported(): void
    {
        $client = new ValkeyGlideFake;
        $client->refuseInBatch('zadd');

        $batch = (new ValkeyGlideConnection($client))->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        try {
            $batch->zadd('zset', 'NX', 1, 'member');
            self::fail('Expected the refused command to be reported.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('GLIDE refused to buffer [ZADD], most likely due to its arguments, so it was not added to the batch.', $exception->getMessage());
        }

        self::assertSame([], $batch->exec());
        self::assertSame(['pipeline', 'zadd', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a command GLIDE refuses to buffer binds a cluster transaction to
     * no hash slot, so a later command in another slot is accepted.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterCommandGlideRefusesToBufferClaimsNoSlot(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->refuseInBatch('hmget');

        $batch = (new ValkeyGlideConnection($client))->transaction();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        try {
            $batch->hmget('{a}k', []);
            self::fail('Expected the refused command to be reported.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('GLIDE refused to buffer [HMGET], most likely due to its arguments, so it was not added to the batch.', $exception->getMessage());
        }

        self::assertSame([true], $batch->set('{b}k', '1')->exec());
        self::assertSame(['multi', 'hmget', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify a command that throws in GLIDE ends the batch and leaves no batch
     * mode behind, as GLIDE may have buffered it before it threw.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedFirstCommandLeavesNoStuckBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willThrow('zadd', new \TypeError('zAdd(): Argument #2 must be of type string'));
        $client->willReturn('rawcommand', 1);

        $connection = new ValkeyGlideConnection($client);
        $batch      = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        try {
            $batch->zadd('zset', [], 'member');
            self::fail('Expected the GLIDE error to be thrown.');
        } catch (\TypeError) {
            self::assertFalse($client->isBatching());
        }

        try {
            $batch->eval(self::EVAL_TEST_SCRIPT, 0);
            self::fail('Expected the ended batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame('This pipeline or transaction has already been executed or discarded.', $exception->getMessage());
        }

        self::assertSame(1, $connection->command('eval', [self::EVAL_TEST_SCRIPT, 0]));
        self::assertSame(['pipeline', 'zadd', 'discard', 'rawcommand'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a command GLIDE refuses to buffer leaves the batch going, with
     * GLIDE's batch mode cleared before the next immediate command runs and
     * started again for the next buffered one.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function refusedFirstCommandLeavesNoStuckBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->refuseInBatch('zadd');
        $client->willReturn('rawcommand', 1);
        $client->willReturn('get', 'v');

        $batch = (new ValkeyGlideConnection($client))->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        try {
            $batch->zadd('zset', 1, 'member');
            self::fail('Expected the refused command to throw.');
        } catch (\InvalidArgumentException) {
            self::assertTrue($client->isBatching());
        }

        self::assertSame([1, 'v'], $batch->eval(self::EVAL_TEST_SCRIPT, 0)->get('k')->exec());
        self::assertSame(['pipeline', 'zadd', 'discard', 'rawcommand', 'pipeline', 'get', 'exec'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a closed client, which refuses batch mode, is reported.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function closedClientIsReported(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->disconnect();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Unable to start the transaction; GLIDE refused batch mode, so the connection may be closed.');

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('key', 'value'));
    }

    /**
     * Verify a client already in batch mode, which refuses a nested batch, is
     * reported.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clientAlreadyInBatchModeIsReported(): void
    {
        $client = new ValkeyGlideFake;
        $client->multi();

        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('key', 'value'));
            self::fail('Expected the refused batch mode to be reported.');
        } catch (ConnectionException $exception) {
            self::assertSame('Unable to start the pipeline; GLIDE refused batch mode, so the connection may be closed.', $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('set'));
        self::assertSame(['multi', 'pipeline', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
        self::assertFalse($connection->exec());
    }

    /**
     * Provide batch failures and the exception message each one reports.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function failedBatches(): iterable
    {
        yield 'pipeline' => ['pipeline', self::PIPELINE_FAILED_MESSAGE];
        yield 'transaction' => ['transaction', 'The transaction failed and its outcome is unknown; it may have been committed.'];
    }

    /**
     * Verify a failed exec() throws, never retries, and leaves no batch mode.
     *
     * @param  string  $method
     * @param  string  $message
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('failedBatches')]
    #[Test]
    public function failedExecThrowsWithoutRetrying(string $method, string $message): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        $connection = new ValkeyGlideConnection($client, static fn (): \ValkeyGlide => new ValkeyGlideFake);

        try {
            $this->runBatch($connection, $method, static fn (ValkeyGlideBatch $batch): ValkeyGlideBatch => $batch->set('key', 'value'));
            self::fail('Expected the failed exec() to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertCount(1, $client->callsFor('exec'));
        self::assertCount(1, $client->callsFor('discard'));
        self::assertSame([], $client->callsFor('unwatch'));
        self::assertSame($client, $connection->client());
    }

    /**
     * Verify a failed flush before an immediate command throws and discards the
     * rest of the pipeline.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedFlushThrowsAndDiscardsThePipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
                $pipe->set('key', 'value');
                $pipe->eval(self::EVAL_TEST_SCRIPT, 0);
                $pipe->set('other', 'value');
            });
            self::fail('Expected the failed flush to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame(self::PIPELINE_FAILED_MESSAGE, $exception->getMessage());
        }

        self::assertSame(['pipeline', 'set', 'exec', 'discard'], $client->callOrder());
    }

    /**
     * Verify a failed flush in a fluent pipeline ends the pipeline, so the
     * connection takes the usual path again.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedFlushEndsAFluentPipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);
        $batch      = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        try {
            $batch->set('key', 'value')->eval(self::EVAL_TEST_SCRIPT, 0);
            self::fail('Expected the failed flush to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame(self::PIPELINE_FAILED_MESSAGE, $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertSame('value', $connection->get('key'));
        self::assertSame(['pipeline', 'set', 'exec', 'discard', 'get'], $client->callOrder());
    }

    /**
     * Verify a watched transaction that exec() aborts returns false and clears
     * the WATCH.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function abortedWatchedTransactionReturnsFalse(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertTrue($connection->command('watch', ['balance']));
        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('balance', '10')));
        self::assertSame([['app:balance']], $client->callsFor('watch'));
        self::assertSame(['watch', 'multi', 'set', 'exec', 'discard', 'unwatch'], $client->callOrder());

        $this->expectException(ConnectionException::class);

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('balance', '20'));
    }

    /**
     * Verify a committed standalone transaction clears the WATCH without an
     * unwatch(), as EXEC clears it on the server.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function committedTransactionClearsTheWatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', [['a', 'b']]);

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')));
        self::assertSame([['a', 'b']], $client->callsFor('watch'));
        self::assertSame([], $client->callsFor('unwatch'));

        $client->willReturn('exec', false);

        $this->expectException(ConnectionException::class);

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '2'));
    }

    /**
     * Verify every path that ends a watched transaction without EXEC clears the
     * WATCH, as GLIDE's discard() does not.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function discardedWatchedTransactionUnwatches(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);
        $connection->transaction(static function (): void {
            // An empty transaction never reaches EXEC.
        });

        $connection->command('watch', ['a']);

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx): void {
                $tx->set('a', '1');

                throw new \DomainException('callback failed');
            });
            self::fail('Expected the callback exception to be rethrown.');
        } catch (\DomainException) {
            // The failed callback discards the transaction.
        }

        $connection->command('watch', ['a']);

        $batch = $connection->transaction();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);
        self::assertTrue($batch->set('a', '1')->discard());
        self::assertCount(3, $client->callsFor('unwatch'));
        self::assertSame([], $client->callsFor('exec'));
        self::assertSame([
            'watch', 'unwatch',
            'watch', 'multi', 'set', 'discard', 'unwatch',
            'watch', 'multi', 'set', 'discard', 'unwatch',
        ], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a pipeline neither clears nor depends on a WATCH.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineLeavesTheWatchInPlace(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);

        self::assertSame([], $connection->pipeline(static function (): void {
            // An empty pipeline.
        }));
        self::assertSame([true], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')));
        self::assertSame([], $client->callsFor('unwatch'));

        $client->willReturn('exec', false);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '2')));
    }

    /**
     * Verify unwatch() forgets the watched keys.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function unwatchForgetsTheWatchedKeys(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);

        self::assertTrue($connection->command('unwatch'));

        $this->expectException(ConnectionException::class);

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1'));
    }

    /**
     * Verify a discarded watched transaction whose unwatch() fails keeps the
     * keys tracked, as the server still holds the WATCH, so a later transaction
     * it aborts returns false.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedUnwatchKeepsTheKeysOfADiscardedWatchedTransaction(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);
        $client->willReturn('unwatch', false);

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx): void {
                $tx->set('a', '1');

                throw new \DomainException('callback failed');
            });
            self::fail('Expected the callback exception to be rethrown.');
        } catch (\DomainException) {
            // The failed callback discards the transaction.
        }

        $client->willReturn('exec', false);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('b', '1')));
        self::assertCount(2, $client->callsFor('unwatch'));
    }

    /**
     * Verify a committed watched cluster transaction forgets its keys even when
     * the unwatch() after it fails, as EXEC cleared the WATCH on the node that
     * holds them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedUnwatchAfterACommittedClusterTransactionForgetsTheKeys(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('unwatch', false);

        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['{a}x']);

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{a}y', '1')));
        self::assertCount(1, $client->callsFor('unwatch'));

        $client->willReturn('exec', false);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('The transaction failed and its outcome is unknown; it may have been committed.');

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{b}z', '1'));
    }

    /**
     * Verify an unwatch() that GLIDE fails keeps the keys tracked, as the
     * server may still hold the WATCH, as a refused watch() is not tracked.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedUnwatchKeepsTheWatchedKeys(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);
        $client->willReturn('unwatch', false);

        self::assertFalse($connection->command('unwatch'));

        $client->willReturn('exec', false);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')));
    }

    /**
     * Verify an unwatch() that throws keeps the keys tracked, so a transaction
     * still cannot join a pipeline that would flatten it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function throwingUnwatchKeepsTheWatchedKeys(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);
        $client->willThrow('unwatch', new \RuntimeException('unwatch failed'));

        try {
            $connection->command('unwatch');
            self::fail('Expected the unwatch() exception to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('unwatch failed', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A transaction cannot join a pipeline while keys are watched');

        $connection->pipeline(static fn (): mixed => $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')));
    }

    /**
     * Verify a transport error is not retried while keys are watched, as the
     * reconnect would drop the WATCH and let the transaction commit over a
     * change to a watched key; once unwatched, the retry applies again.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function transportErrorIsNotRetriedWhileKeysAreWatched(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', '100');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        $connection->command('watch', ['balance']);

        try {
            $connection->command('get', ['balance']);
            self::fail('Expected the transport error to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame(self::TRANSIENT_RESET_MESSAGE, $exception->getMessage());
        }

        self::assertSame($firstClient, $connection->client());
        self::assertSame([], $secondClient->callOrder());
        self::assertSame([], $firstClient->callsFor('close'));

        self::assertTrue($connection->command('unwatch'));
        self::assertSame('100', $connection->command('get', ['balance']));
        self::assertSame($secondClient, $connection->client());
    }

    /**
     * Provide batches with the calls GLIDE reports buffered without buffering.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function unbufferedBatchCalls(): iterable
    {
        yield 'zrem in a pipeline' => ['pipeline', 'zrem', false];
        yield 'zmscore in a pipeline' => ['pipeline', 'zmscore', false];
        yield 'zrem in a transaction' => ['transaction', 'zrem', false];
        yield 'zmscore in a watched transaction' => ['transaction', 'zmscore', true];
    }

    /**
     * Verify a call GLIDE would report buffered without buffering it is refused
     * before it is sent, so it never leaves a phantom slot that fails a batch
     * the server applied.
     *
     * @param  string  $method
     * @param  string  $command
     * @param  bool  $watched
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('unbufferedBatchCalls')]
    #[Test]
    public function unbufferedCallIsRefusedBeforeItIsSent(string $method, string $command, bool $watched): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        if ($watched) {
            $connection->command('watch', ['z']);
        }

        try {
            $this->runBatch($connection, $method, static function (ValkeyGlideBatch $batch) use ($connection, $command): void {
                $batch->set('a', '1');
                $connection->command($command, ['z']);
                $batch->set('b', '2');
            });
            self::fail('Expected the unbuffered call to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                sprintf('GLIDE would not buffer [%s] with these arguments, although it reports it buffered, so it was not added to the batch.', strtoupper($command)),
                $exception->getMessage(),
            );
        }

        self::assertSame([], $client->callsFor($command));
        self::assertSame([], $client->callsFor('exec'));
        self::assertSame($watched ? [[]] : [], $client->callsFor('unwatch'));
        self::assertSame($watched ? ['discard', 'unwatch'] : ['discard'], array_slice($client->callOrder(), $watched ? -2 : -1));
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a fluent batch that refuses an unbuffered call keeps its results
     * in line with what it buffered.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function fluentBatchKeepsItsResultsAfterRefusingAnUnbufferedCall(): void
    {
        $client = new ValkeyGlideFake;
        $batch  = (new ValkeyGlideConnection($client))->pipeline();

        $batch->set('a', '1');

        try {
            $batch->zrem('z');
            self::fail('Expected the unbuffered call to be refused.');
        } catch (\InvalidArgumentException) {
            // Nothing was buffered for the call.
        }

        self::assertSame([true, true], $batch->set('b', '2')->exec());
        self::assertSame([], $client->callsFor('zrem'));
    }

    /**
     * Verify a phpredis-style SET with two different expiries is not converted
     * in a batch, so it runs as it does outside one, where the server rejects
     * it, and a transaction refuses it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function setWithTwoExpiriesRunsAsOutsideABatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', false);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame([true, false], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->set('k', 'v', 'EX', 60, 'PX', 1000)));
        self::assertSame([['SET', 'app:k', 'v', 'EX', 60, 'PX', 1000]], $client->callsFor('rawcommand'));
        self::assertSame([['app:a', '1', null]], $client->callsFor('set'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('[SET] cannot run inside a transaction');

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('k', 'v', 'PX', 5000, 'EXAT', 1700000000));
    }

    /**
     * Verify a phpredis-style SET converted in a batch sends its value as a raw
     * command would, so a batch stores what a direct call stores.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function convertedSetSendsTheValueAsARawCommandWould(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', false, 'EX', 60)->set('b', 1e20, 'NX'));

        self::assertSame([['app:a', '', ['EX' => 60]], ['app:b', '1.0E+20', ['NX']]], $client->callsFor('set'));
        self::assertSame([], $client->callsFor('rawcommand'));
    }

    /**
     * Verify a bare discard() while a multi() opened in a pipeline is still
     * open drops the whole pipeline, as phpredis does.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bareDiscardInsideAJoinedMultiDropsTheWholeBatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('pipeline');
        $connection->command('set', ['a', '1']);
        $connection->command('multi');
        $connection->command('set', ['b', '2']);

        self::assertTrue($connection->command('discard'));
        self::assertFalse($connection->exec());
        self::assertSame([], $client->callsFor('exec'));
        self::assertSame(['pipeline', 'set', 'set', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify RedisStore::putMany()'s bare multi(), commands and exec() on the
     * connection run as a transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bareMultiAndExecRunATransaction(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('setex', true);
        $client->willReturn('del', 2);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertInstanceOf(ValkeyGlideBatch::class, $connection->command('multi'));
        self::assertInstanceOf(ValkeyGlideBatch::class, $connection->command('setex', ['a', 60, '1']));

        $connection->command('setex', ['b', 60, '2']);
        $connection->command('del', ['c', 'd']);

        self::assertSame([true, true, 2], $connection->command('exec'));
        self::assertSame(['multi', 'setex', 'setex', 'del', 'exec'], $client->callOrder());
        self::assertSame([['app:a', 60, '1'], ['app:b', 60, '2']], $client->callsFor('setex'));
    }

    /**
     * Verify an empty bare multi() and exec() never leave GLIDE stuck in batch
     * mode, as RedisStore::putMany([]) would.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function emptyBareMultiAndExecLeaveNoStuckBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);

        $connection->command('multi');

        self::assertSame([], $connection->command('exec'));
        self::assertSame('value', $connection->get('key'));
        self::assertSame(['get'], $client->callOrder());
    }

    /**
     * Verify a bare pipeline() on the connection starts a pipeline.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function barePipelineStartsAPipeline(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('pipeline');
        $connection->command('set', ['a', '1']);

        self::assertSame([true], $connection->command('exec'));
        self::assertSame(['pipeline', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify a bare multi() inside a batch joins it until its exec(), which
     * returns the batch, as phpredis does in pipeline mode.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bareMultiInsideABatchJoinsItUntilItsExec(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $returned   = [];

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection, &$returned): void {
            $returned[] = $connection->command('multi');
            $connection->command('setex', ['a', 60, '1']);
            $returned[] = $connection->command('exec');
            $returned[] = $pipe->multi();
            $pipe->set('b', '2');
        });

        self::assertSame([null, true], $results);
        self::assertContainsOnlyInstancesOf(ValkeyGlideBatch::class, $returned);
        self::assertSame(['pipeline', 'setex', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify exec() inside a callback is refused, as the callback's caller runs
     * the batch.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function execInsideACallbackIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('exec() cannot be called inside a pipeline or transaction callback; the batch runs when the callback returns.');

        try {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe): mixed => $pipe->set('a', '1')->exec());
        } finally {
            self::assertSame(['pipeline', 'set', 'discard'], $client->callOrder());
        }
    }

    /**
     * Provide discard() calls that a callback owning the batch refuses.
     *
     * @return iterable<string, array{\Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed}>
     */
    public static function nestedDiscards(): iterable
    {
        yield 'inside a callback' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->pipeline(static fn (ValkeyGlideBatch $pipe): bool => $pipe->discard())];
        yield 'inside a joined multi in a callback' => [
            static fn (ValkeyGlideConnection $connection): mixed => $connection->pipeline(static fn (ValkeyGlideBatch $pipe): bool => $pipe->multi()->discard()),
        ];
    }

    /**
     * Verify discard() inside a callback, even within a joined multi(), is
     * refused.
     *
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed  $call
     * @return void
     */
    #[DataProvider('nestedDiscards')]
    #[Test]
    public function discardInsideACallbackIsRefused(\Closure $call): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('discard() cannot be called inside a pipeline or transaction callback; throw from the callback to discard the batch.');

        $call(new ValkeyGlideConnection(new ValkeyGlideFake));
    }

    /**
     * Verify a bare discard() drops the batch without sending it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bareDiscardDropsTheBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);

        $connection->command('multi');

        self::assertTrue($connection->command('discard'));
        self::assertSame([], $client->callOrder());

        $connection->command('multi');
        $connection->command('set', ['a', '1']);

        self::assertTrue($connection->command('discard'));
        self::assertSame(['multi', 'set', 'discard'], $client->callOrder());
        self::assertSame('value', $connection->get('a'));
    }

    /**
     * Provide the batch control commands that act on the active batch.
     *
     * @return iterable<string, array{string}>
     */
    public static function batchControlsOutsideABatch(): iterable
    {
        yield 'exec' => ['exec'];
        yield 'discard' => ['discard'];
    }

    /**
     * Verify exec() and discard() outside a batch return false without reaching
     * GLIDE.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('batchControlsOutsideABatch')]
    #[Test]
    public function batchControlsOutsideABatchReturnFalse(string $method): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        self::assertFalse($connection->command($method));
        self::assertSame([], $client->callOrder());
    }

    /**
     * Verify a batch dispatches one event per exec() and none for buffered
     * commands, while immediate commands keep their own events.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchDispatchesOneEventPerExec(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->zadd('z', 1, 'm')->eval(self::EVAL_TEST_SCRIPT, 0)->get('a'));
        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->incr('n'));

        self::assertSame(['pipeline', 'eval', 'pipeline', 'transaction'], array_map(static fn (CommandExecuted $event): string => $event->command, $events));
        self::assertSame(['set', 'zadd'], $events[0]->parameters);
        self::assertSame(['get'], $events[2]->parameters);
        self::assertSame(['incr'], $events[3]->parameters);
        self::assertSame($connection, $events[0]->connection);
    }

    /**
     * Verify a failed exec() dispatches a command failed event for the batch.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedExecDispatchesACommandFailedEvent(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);
        $connection->command('watch', ['a']);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')));
        self::assertCount(1, $events);
        self::assertSame('transaction', $events[0]->command);
        self::assertSame(['set'], $events[0]->parameters);
        self::assertSame(self::WATCHED_TRANSACTION_FAILED_MESSAGE, $events[0]->exception->getMessage());
    }

    /**
     * Verify an immediate command in a pipeline may reconnect, and the next
     * buffered command starts batch mode on the new client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function immediateCommandMayReconnectBetweenFlushes(): void
    {
        $firstClient = new ValkeyGlideClusterFake;
        $firstClient->willThrow('ping', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideClusterFake;
        $secondClient->willReturn('ping', 'PONG');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlideCluster => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        $results = $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->ping('randomNode')->set('b', '2'));

        self::assertSame([true, 'PONG', true], $results);
        self::assertSame([['randomNode']], $secondClient->callsFor('ping'));
        self::assertSame(['pipeline', 'set', 'exec', 'ping', 'close'], $firstClient->callOrder());
        self::assertSame(['ping', 'pipeline', 'set', 'exec'], $secondClient->callOrder());
    }

    /**
     * Verify each connection keeps its own batch, as Horizon opens a pipeline
     * on its own connection inside a queue connection's pipeline.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function eachConnectionKeepsItsOwnBatch(): void
    {
        $queueClient   = new ValkeyGlideFake;
        $horizonClient = new ValkeyGlideFake;
        $horizonClient->willReturn('hmset', true);

        $queue   = new ValkeyGlideConnection($queueClient);
        $horizon = new ValkeyGlideConnection($horizonClient, null, ['prefix' => 'horizon:']);

        $inner   = [];
        $results = $queue->pipeline(static function (ValkeyGlideBatch $pipe) use ($horizon, &$inner): void {
            $pipe->set('a', '1');
            $inner = $horizon->pipeline(static fn (ValkeyGlideBatch $horizonPipe): ValkeyGlideBatch => $horizonPipe->hmset('job:1', ['status' => 'pending']));
        });

        self::assertSame([true], $results);
        self::assertSame([true], $inner);
        self::assertSame(['pipeline', 'set', 'exec'], $queueClient->callOrder());
        self::assertSame(['pipeline', 'hmset', 'exec'], $horizonClient->callOrder());
    }

    /**
     * Verify a cluster pipeline may span hash slots.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterPipelineMaySpanHashSlots(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        self::assertSame([true, true], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->set('b', '2')));
        self::assertSame(['pipeline', 'set', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify a cluster transaction whose keys share a hash tag is sent.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterTransactionWithinOneHashSlotIsSent(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('del', 2);
        $client->willReturn('incr', 1);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => '{horizon:}']);

        $connection->command('watch', ['lock']);

        $results = $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')->del(['b', 'c'])->incr('d'));

        self::assertSame([true, 2, 1], $results);
        self::assertSame(['watch', 'multi', 'set', 'del', 'incr', 'exec', 'unwatch'], $client->callOrder());
    }

    /**
     * Verify a cross-slot cluster transaction is refused before it is sent.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function crossSlotClusterTransactionIsRefusedBeforeItIsSent(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{a}x', '1')->mset(['{a}y' => '2', 'foo' => '3']));
            self::fail('Expected the cross-slot transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::CROSS_SLOT_MESSAGE, 'MSET', 'foo', 12182, 15495), $exception->getMessage());
        }

        self::assertSame(['multi', 'set', 'discard'], $client->callOrder());
    }

    /**
     * Verify the first command a cluster transaction buffers binds it to the
     * slot of its keys, so a later command in another slot is refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bufferedCommandBindsTheClusterTransactionToItsSlot(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{a}x', '1')->set('foo', '2'));
            self::fail('Expected the cross-slot transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::CROSS_SLOT_MESSAGE, 'SET', 'foo', 12182, 15495), $exception->getMessage());
        }

        self::assertSame(['multi', 'set', 'discard'], $client->callOrder());
    }

    /**
     * Verify a cluster transaction is refused when a watched key is in another
     * hash slot, and the watch is cleared.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterTransactionWithAWatchInAnotherSlotIsRefused(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['foo']);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1'));
            self::fail('Expected the transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::WATCHED_CROSS_SLOT_MESSAGE, 'SET', 'a', 15495, 12182, 'foo'), $exception->getMessage());
        }

        $connection->command('watch', ['a', 'foo']);

        try {
            $connection->transaction();
            self::fail('Expected the transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('[WATCH] key [foo] is in slot [12182], not slot [15495]', $exception->getMessage());
        }

        self::assertSame(['watch', 'unwatch', 'watch', 'unwatch'], $client->callOrder());
    }

    /**
     * Verify a cluster transaction refuses the keyless commands a cluster batch
     * cannot buffer, while a cluster pipeline runs them in call order.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterKeylessCommandsRunImmediatelyInAPipeline(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('flushdb', true);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame([true, true], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->flushdb()));
        self::assertSame([['allPrimaries', false]], $client->callsFor('flushdb'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('[PING] cannot run inside a transaction');

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->ping());
    }

    /**
     * Verify a standalone transaction may span keys in any hash slot.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function standaloneTransactionMaySpanHashSlots(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['foo']);

        self::assertSame([true, true, null], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')->set('foo', '2')->ping()));
    }

    /**
     * Verify executeRaw() inside a pipeline runs in call order.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function executeRawInsideAPipelineRunsInCallOrder(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 'PONG');

        $connection = new ValkeyGlideConnection($client);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection): void {
            $pipe->set('a', '1');
            $connection->executeRaw(['PING']);
        });

        self::assertSame([true, 'PONG'], $results);
        self::assertSame(['pipeline', 'set', 'exec', 'rawcommand'], $client->callOrder());
    }

    /**
     * Verify exec() and discard() are public, so callers get their declared
     * return types rather than those of __call().
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function execAndDiscardArePublic(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertFalse($connection->exec());
        self::assertFalse($connection->discard());
        self::assertTrue((new \ReflectionMethod(ValkeyGlideConnection::class, 'exec'))->isPublic());
        self::assertTrue((new \ReflectionMethod(ValkeyGlideConnection::class, 'discard'))->isPublic());
    }

    /**
     * Verify a nested callback owns the batch only while it runs, after which
     * the fluent batch's caller owns it again.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function nestedCallbackOwnsTheBatchOnlyWhileItRuns(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incr', 7);

        $connection = new ValkeyGlideConnection($client);
        $batch      = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);
        self::assertSame($batch, $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')));

        $batch->incr('b');

        self::assertSame([true, 7], $batch->exec());
        self::assertSame(['pipeline', 'set', 'incr', 'exec'], $client->callOrder());
    }

    /**
     * Provide nested callbacks that fail inside a fluent batch.
     *
     * @return iterable<string, array{\Closure(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed, class-string<\Throwable>}>
     */
    public static function failingNestedCallbacks(): iterable
    {
        yield 'callback that throws' => [static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('b', '2');

            throw new \DomainException('nested failure');
        }, \DomainException::class];
        yield 'callback whose exec() is refused' => [static fn (ValkeyGlideBatch $pipe): mixed => $pipe->set('b', '2')->exec(), \LogicException::class];
    }

    /**
     * Verify a nested callback that fails inside a fluent batch discards the
     * whole batch, so its half-buffered commands are never sent.
     *
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed  $callback
     * @param  class-string<\Throwable>  $exception
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('failingNestedCallbacks')]
    #[Test]
    public function failedNestedCallbackDiscardsTheFluentBatch(\Closure $callback, string $exception): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);
        $batch      = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        $batch->set('a', '1');

        try {
            $connection->transaction($callback);
            self::fail('Expected the nested callback to fail.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
        }

        self::assertSame(['pipeline', 'set', 'set', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
        self::assertSame('value', $connection->get('key'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('This pipeline or transaction has already been executed or discarded.');

        $batch->exec();
    }

    /**
     * Verify a transaction opened without a callback inside a pipeline joins
     * it, so its exec() returns the batch and the outer exec() sends it all.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function fluentTransactionNestedInAPipelineJoinsIt(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incr', 2);
        $client->willReturn('get', '2');

        $connection = new ValkeyGlideConnection($client);
        $outer      = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $outer);

        $outer->set('a', '1');

        $inner = $connection->transaction();

        self::assertSame($outer, $inner);
        self::assertInstanceOf(ValkeyGlideBatch::class, $inner);
        self::assertSame($outer, $inner->incr('a')->exec());
        self::assertSame([true, 2, '2'], $outer->get('a')->exec());
        self::assertSame(['pipeline', 'set', 'incr', 'get', 'exec'], $client->callOrder());
    }

    /**
     * Verify a transaction opened without a callback inside a pipeline's
     * callback joins it, so its exec() returns the batch rather than being
     * refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function fluentTransactionInsideAPipelineCallbackJoinsIt(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incr', 2);

        $connection = new ValkeyGlideConnection($client);
        $returned   = [];

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection, &$returned): void {
            $pipe->set('a', '1');

            $transaction = $connection->transaction();

            self::assertInstanceOf(ValkeyGlideBatch::class, $transaction);

            $returned[] = $transaction->incr('a')->exec();
        });

        self::assertSame([true, 2], $results);
        self::assertContainsOnlyInstancesOf(ValkeyGlideBatch::class, $returned);
        self::assertSame(['pipeline', 'set', 'incr', 'exec'], $client->callOrder());
    }

    /**
     * Provide nested batch calls that change nothing, as in phpredis, so the
     * next exec() runs the batch.
     *
     * @return iterable<string, array{bool, \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed}>
     */
    public static function nestedBatchNoOps(): iterable
    {
        yield 'pipeline() in a pipeline' => [false, static fn (ValkeyGlideConnection $connection): mixed => $connection->pipeline()];
        yield 'bare pipeline() in a pipeline' => [false, static fn (ValkeyGlideConnection $connection): mixed => $connection->command('pipeline')];
        yield 'pipeline() on the batch' => [false, static fn (ValkeyGlideConnection $connection): mixed => self::activeBatch($connection)->pipeline()];
        yield 'multi(PIPELINE) in a pipeline' => [false, static fn (ValkeyGlideConnection $connection): mixed => $connection->command('multi', [\ValkeyGlide::PIPELINE])];
        yield 'transaction() in a transaction' => [true, static fn (ValkeyGlideConnection $connection): mixed => $connection->transaction()];
        yield 'bare multi() in a transaction' => [true, static fn (ValkeyGlideConnection $connection): mixed => $connection->command('multi')];
        yield 'pipeline() in a transaction' => [true, static fn (ValkeyGlideConnection $connection): mixed => $connection->pipeline()];
    }

    /**
     * Verify a nested pipeline() or multi() that phpredis treats as a no-op
     * changes nothing, so the next exec() runs the batch.
     *
     * @param  bool  $atomic
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed  $open
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('nestedBatchNoOps')]
    #[Test]
    public function nestedBatchOfTheSameKindChangesNothing(bool $atomic, \Closure $open): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $batch      = $atomic ? $connection->transaction() : $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        $batch->set('a', '1');

        self::assertSame($batch, $open($connection));
        self::assertSame([true], $batch->exec());
        self::assertSame([$atomic ? 'multi' : 'pipeline', 'set', 'exec'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a bare multi() starts a transaction or a pipeline as its mode
     * asks, as GLIDE and phpredis do.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bareMultiStartsTheModeItIsGiven(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('multi', [\ValkeyGlide::PIPELINE]);
        $connection->command('set', ['a', '1']);
        $connection->command('set', ['b', '2']);

        self::assertSame([true, true], $connection->exec());

        $connection->command('multi', [\ValkeyGlide::MULTI]);
        $connection->command('set', ['{a}x', '1']);

        self::assertSame([true], $connection->exec());

        $connection->command('multi', ['value' => \ValkeyGlide::PIPELINE]);
        $connection->command('set', ['c', '3']);

        self::assertSame([true], $connection->exec());
        self::assertSame(['pipeline', 'set', 'set', 'exec', 'multi', 'set', 'exec', 'pipeline', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Provide multi() modes that neither GLIDE nor phpredis accept.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidMultiModes(): iterable
    {
        yield 'unknown integer' => [99, '99'];
        yield 'numeric string' => ['1', 'string'];
        yield 'float' => [1.0, 'float'];
    }

    /**
     * Verify a bare multi() with an unknown mode is refused before a batch
     * starts.
     *
     * @param  mixed  $mode
     * @param  string  $described
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('invalidMultiModes')]
    #[Test]
    public function bareMultiWithAnUnknownModeIsRefused(mixed $mode, string $described): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->command('multi', [$mode]);
            self::fail('Expected the unknown mode to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(sprintf('Unsupported multi() mode [%s]; use \ValkeyGlide::MULTI or \ValkeyGlide::PIPELINE.', $described), $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertSame([], $client->callOrder());
    }

    /**
     * Provide listeners that throw when they hear of a batch's exec().
     *
     * @return iterable<string, array{class-string, string, string|null, bool}>
     */
    public static function throwingBatchListeners(): iterable
    {
        yield 'executed pipeline' => [CommandExecuted::class, 'pipeline', null, false];
        yield 'executed transaction' => [CommandExecuted::class, 'transaction', null, false];
        yield 'executed watched transaction' => [CommandExecuted::class, 'transaction', null, true];
        yield 'failed pipeline' => [CommandFailed::class, 'pipeline', 'exec', false];
        yield 'failed transaction' => [CommandFailed::class, 'transaction', 'exec', false];
        yield 'aborted watched transaction' => [CommandFailed::class, 'transaction', 'exec', true];
    }

    /**
     * Verify a listener that throws on a batch's exec() cannot leave the
     * connection in batch mode or watching.
     *
     * @param  class-string  $event
     * @param  string  $method
     * @param  string|null  $failing
     * @param  bool  $watched
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('throwingBatchListeners')]
    #[Test]
    public function throwingBatchListenerLeavesTheConnectionUsable(string $event, string $method, ?string $failing, bool $watched): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        if ($failing !== null) {
            $client->willReturn($failing, false);
        }

        $dispatcher = new Dispatcher;
        $failure    = new \DomainException('listener failed');

        $dispatcher->listen($event, static function (CommandExecuted|CommandFailed $heard) use ($failure): void {
            if (in_array($heard->command, ['pipeline', 'transaction'], true)) {
                throw $failure;
            }
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        if ($watched) {
            $connection->command('watch', ['a']);
        }

        try {
            $this->runBatch($connection, $method, static fn (ValkeyGlideBatch $batch): ValkeyGlideBatch => $batch->set('a', '1'));
            self::fail('Expected the listener exception to be thrown.');
        } catch (\DomainException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($client->isBatching());
        self::assertFalse($connection->discard());
        self::assertSame('value', $connection->get('a'));
        self::assertCount($watched && $failing !== null ? 1 : 0, $client->callsFor('unwatch'));

        $client->willReturn('exec', false);
        $dispatcher->forget(CommandFailed::class);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('The transaction failed and its outcome is unknown; it may have been committed.');

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '2'));
    }

    /**
     * Verify an exec() that GLIDE throws from ends the batch, so the connection
     * stays usable.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function execThatThrowsEndsTheBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willThrow('exec', new \RuntimeException('exec failed'));
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);
        $connection->command('watch', ['a']);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1'));
            self::fail('Expected the exec() exception to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('exec failed', $exception->getMessage());
        }

        self::assertSame(['watch', 'multi', 'set', 'exec', 'discard', 'unwatch'], $client->callOrder());
        self::assertFalse($client->isBatching());
        self::assertSame('value', $connection->get('a'));
    }

    /**
     * Verify an exec() whose results do not match what was buffered fails the
     * batch rather than return results out of call order.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function execWithMisalignedResultsFailsTheBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', [true]);

        $connection = new ValkeyGlideConnection($client);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage(sprintf(self::MISALIGNED_BATCH_MESSAGE, 'pipeline'));

        $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->set('b', '2'));
    }

    /**
     * Verify a failed pipeline throws even while a WATCH is pending, and leaves
     * the WATCH for the transaction it guards.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedPipelineThrowsWhileAWatchIsPending(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);
        $connection->command('watch', ['a']);

        try {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1'));
            self::fail('Expected the failed pipeline to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame(self::PIPELINE_FAILED_MESSAGE, $exception->getMessage());
        }

        self::assertCount(1, $events);
        self::assertSame('pipeline', $events[0]->command);
        self::assertSame(self::PIPELINE_FAILED_MESSAGE, $events[0]->exception->getMessage());
        self::assertSame([], $client->callsFor('unwatch'));
        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '2')));
    }

    /**
     * Verify a bare multi() on a client that refuses batch mode ends the batch,
     * so later commands take the usual path.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function refusedBatchModeEndsTheBatch(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);
        $connection->command('watch', ['k']);
        $client->multi();

        $connection->command('multi');

        try {
            $connection->command('setex', ['k', 60, 'v']);
            self::fail('Expected the refused batch mode to be reported.');
        } catch (ConnectionException $exception) {
            self::assertSame('Unable to start the transaction; GLIDE refused batch mode, so the connection may be closed.', $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertFalse($client->isBatching());
        self::assertSame(['watch', 'multi', 'multi', 'discard', 'unwatch'], $client->callOrder());
        self::assertSame('value', $connection->get('k'));
    }

    /**
     * Provide the static client-local methods GLIDE runs at once, even in batch
     * mode, with their fake results.
     *
     * @return iterable<string, array{string, list<mixed>, mixed}>
     */
    public static function clientLocalMethods(): iterable
    {
        yield 'getOtelSamplePercentage' => ['getOtelSamplePercentage', [], 25];
        yield 'setOtelSamplePercentage' => ['setOtelSamplePercentage', [50], null];
        yield 'registerPHPRedisAliases' => ['registerPHPRedisAliases', [], true];
    }

    /**
     * Verify client-local methods GLIDE never buffers run in call order rather
     * than take a buffered slot they never fill.
     *
     * @param  string  $method
     * @param  list<mixed>  $parameters
     * @param  mixed  $result
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('clientLocalMethods')]
    #[Test]
    public function clientLocalMethodsRunInCallOrder(string $method, array $parameters, mixed $result): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);

        self::assertSame([true, $result, 'v'], $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($method, $parameters): void {
            $pipe->set('k', 'v');
            $pipe->__call($method, $parameters);
            $pipe->get('k');
        }));
        self::assertSame([$result], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->__call($method, $parameters)));
        self::assertSame(['pipeline', 'set', 'exec', 'pipeline', 'get', 'exec'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a phpredis-style SET with a single string option is buffered as
     * GLIDE's options array, which GLIDE buffers, while an integer expiry is
     * left as it is.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function setWithASingleStringOptionIsBuffered(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('set', 'old');

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $results = $connection->pipeline(
            static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1', 'NX')->set('b', '2', 'get')->set('c', '3', 'KEEPTTL')->set('d', '4', 60),
        );

        self::assertSame(['old', 'old', 'old', 'old'], $results);
        self::assertSame([['app:a', '1', ['NX']], ['app:b', '2', ['GET']], ['app:c', '3', ['KEEPTTL']], ['app:d', '4', 60]], $client->callsFor('set'));
        self::assertSame([], $client->callsFor('rawcommand'));
    }

    /**
     * Verify the exec() that closes a joined multi() leaves the outer batch to
     * be executed by the next exec().
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function execClosingAJoinedMultiLeavesTheOuterBatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('pipeline');
        $connection->command('multi');
        $connection->command('set', ['a', '1']);

        self::assertInstanceOf(ValkeyGlideBatch::class, $connection->exec());
        self::assertSame([true], $connection->exec());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify the keys of every watch() are tracked together for a cluster
     * transaction's slot check.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterTransactionChecksTheKeysOfEveryWatch(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a', 'foo']);
        $connection->command('watch', ['{a}b']);

        try {
            $connection->transaction();
            self::fail('Expected the cross-slot watch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::CROSS_SLOT_MESSAGE, 'WATCH', 'foo', 12182, 15495), $exception->getMessage());
        }

        $connection->command('watch', ['{a}b']);
        $connection->command('watch', ['foo']);

        try {
            $connection->transaction();
            self::fail('Expected the cross-slot watch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::CROSS_SLOT_MESSAGE, 'WATCH', 'foo', 12182, 15495), $exception->getMessage());
        }
    }

    /**
     * Verify no batch can be started inside the callback of a batch that has
     * ended, and that the connection is free once the callback throws.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchCannotBeStartedInsideTheCallbackOfAnEndedBatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $refusals   = [];
        $failure    = new \DomainException('callback failed');

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx) use ($connection, $failure, &$refusals): never {
                try {
                    $tx->eval(self::EVAL_TEST_SCRIPT, 0);
                } catch (\LogicException) {
                    // The transaction refuses the script and ends.
                }

                try {
                    $connection->pipeline();
                } catch (\LogicException $exception) {
                    $refusals[] = $exception->getMessage();
                }

                throw $failure;
            });
            self::fail('Expected the callback exception to be thrown.');
        } catch (\DomainException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame([sprintf(self::ENDED_BATCH_MESSAGE, 'pipeline')], $refusals);
        self::assertSame([true], self::activeBatch($connection)->set('a', '1')->exec());
        self::assertSame(['pipeline', 'set', 'exec'], $client->callOrder());
    }

    /**
     * Verify a fluent pipeline ends when a listener throws on the flush that
     * runs before an immediate command, so later commands are not buffered.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function throwingFlushListenerEndsAFluentPipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');

        $dispatcher = new Dispatcher;
        $failure    = new \DomainException('listener failed');

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $heard) use ($failure): void {
            if ($heard->command === 'pipeline') {
                throw $failure;
            }
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        $batch = self::activeBatch($connection);

        try {
            $batch->set('key', 'value')->eval(self::EVAL_TEST_SCRIPT, 0);
            self::fail('Expected the listener exception to be thrown.');
        } catch (\DomainException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($connection->discard());
        self::assertFalse($client->isBatching());
        self::assertTrue($connection->command('set', ['other', 'value']));
        self::assertSame('value', $connection->get('key'));
        self::assertSame(['pipeline', 'set', 'exec', 'set', 'get'], $client->callOrder());
    }

    /**
     * Verify a fluent pipeline ends when the immediate command it runs throws.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function throwingImmediateCommandEndsAFluentPipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willThrow('rawcommand', new \RuntimeException('script failed'));
        $client->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection($client);
        $batch      = self::activeBatch($connection);

        try {
            $batch->set('key', 'value')->eval(self::EVAL_TEST_SCRIPT, 0);
            self::fail('Expected the script exception to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('script failed', $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertSame('value', $connection->get('key'));
        self::assertSame(['pipeline', 'set', 'exec', 'rawcommand', 'get'], $client->callOrder());
    }

    /**
     * Verify a failed flush in a fluent pipeline has ended the pipeline before
     * a CommandFailed listener hears of it, even one that throws.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function throwingFailedFlushListenerEndsAFluentPipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);
        $client->willReturn('get', 'value');

        $dispatcher = new Dispatcher;
        $failure    = new \DomainException('listener failed');
        $heard      = [];
        $connection = new ValkeyGlideConnection($client);

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $event) use ($connection, $failure, &$heard): void {
            if ($event->command === 'pipeline') {
                $heard[] = $connection->command('get', ['key']);

                throw $failure;
            }
        });

        $connection->setEventDispatcher($dispatcher);

        $batch = self::activeBatch($connection);

        try {
            $batch->set('key', 'value')->eval(self::EVAL_TEST_SCRIPT, 0);
            self::fail('Expected the listener exception to be thrown.');
        } catch (\DomainException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(['value'], $heard);
        self::assertFalse($connection->discard());
        self::assertFalse($client->isBatching());
        self::assertSame('value', $connection->get('key'));
        self::assertSame(['pipeline', 'set', 'exec', 'discard', 'get', 'get'], $client->callOrder());
    }

    /**
     * Verify a failed flush dispatches only the batch's CommandFailed event,
     * and none for the immediate command that never ran.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedFlushDispatchesOnlyItsFailure(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen([CommandExecuted::class, CommandFailed::class], static function (CommandExecuted|CommandFailed $event) use (&$events): void {
            $events[] = [$event::class, $event->command, $event->parameters];
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('key', 'value')->eval(self::EVAL_TEST_SCRIPT, 0));
            self::fail('Expected the failed flush to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame(self::PIPELINE_FAILED_MESSAGE, $exception->getMessage());
        }

        self::assertSame([[CommandFailed::class, 'pipeline', ['set']]], $events);
    }

    /**
     * Verify a client put in batch mode outside the driver is taken out of it
     * when it refuses the batch, so the next command takes the usual path.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clientRefusingBatchModeIsTakenOutOfIt(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'value');
        $client->pipeline();

        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('key', 'value'));
            self::fail('Expected the refused batch mode to be reported.');
        } catch (ConnectionException $exception) {
            self::assertSame('Unable to start the transaction; GLIDE refused batch mode, so the connection may be closed.', $exception->getMessage());
        }

        self::assertFalse($client->isBatching());
        self::assertSame('value', $connection->get('key'));
        self::assertSame(['pipeline', 'multi', 'discard', 'get'], $client->callOrder());
    }

    /**
     * Verify a WATCH is tracked before listeners hear of it, so one that throws
     * cannot leave the WATCH untracked.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function watchIsTrackedBeforeItsListenersRun(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($this->throwingDispatcherFor('watch'));

        try {
            $connection->command('watch', ['a']);
            self::fail('Expected the listener exception to be thrown.');
        } catch (\DomainException) {
            // The WATCH was sent, and is tracked.
        }

        $client->willReturn('exec', false);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')));
        self::assertCount(1, $client->callsFor('unwatch'));
    }

    /**
     * Verify an UNWATCH is tracked before listeners hear of it, so one that
     * throws cannot leave stale watched keys.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function unwatchIsTrackedBeforeItsListenersRun(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);
        $connection->setEventDispatcher($this->throwingDispatcherFor('unwatch'));

        try {
            $connection->command('unwatch');
            self::fail('Expected the listener exception to be thrown.');
        } catch (\DomainException) {
            // The UNWATCH was sent, and the keys are forgotten.
        }

        $client->willReturn('exec', false);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('The transaction failed and its outcome is unknown; it may have been committed.');

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1'));
    }

    /**
     * Verify a WATCH that GLIDE refuses is not tracked, so a failed transaction
     * after it is not mistaken for an aborted one.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function refusedWatchIsNotTracked(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('watch', false);

        $connection = new ValkeyGlideConnection($client);

        self::assertFalse($connection->command('watch', ['a']));

        $client->willReturn('exec', false);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1'));
            self::fail('Expected the failed transaction to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame('The transaction failed and its outcome is unknown; it may have been committed.', $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('unwatch'));
    }

    /**
     * Verify integer keys in a key list are sent as strings, which GLIDE takes
     * in the variadic form the list is spread into.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function integerKeyListsAreSpreadAsStrings(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('del', 2);
        $client->willReturn('unlink', 1);

        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command('watch', [[1, 2]]));
        self::assertSame([2, 1], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->del([1, 2])->unlink([3])));
        self::assertSame([['1', '2']], $client->callsFor('watch'));
        self::assertSame([['1', '2']], $client->callsFor('del'));
        self::assertSame([['3']], $client->callsFor('unlink'));
    }

    /**
     * Provide commands whose keys a watched cluster transaction cannot check
     * against the watched slot.
     *
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function unguardedClusterCommands(): iterable
    {
        yield 'unknown key positions' => ['sinterstore', ['{b}dst', '{b}s1']];
        yield 'keyless' => ['fcall', ['refresh', [], []]];
        yield 'no keys given' => ['exists', []];
    }

    /**
     * Verify a watched cluster transaction refuses a command whose keys it
     * cannot check, as GLIDE could send it to a node the WATCH does not guard.
     *
     * @param  string  $method
     * @param  list<mixed>  $parameters
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('unguardedClusterCommands')]
    #[Test]
    public function watchedClusterTransactionRefusesCommandsItCannotGuard(string $method, array $parameters): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['{a}k']);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->__call($method, $parameters));
            self::fail('Expected the unguarded command to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::UNGUARDED_WATCH_MESSAGE, strtoupper($method)), $exception->getMessage());
        }

        self::assertSame(['watch', 'unwatch'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a command whose keys are unknown is left to the server in a
     * cluster transaction without a WATCH, and in a watched standalone one.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function unknownKeyCommandsAreLeftToTheServerWithoutAClusterWatch(): void
    {
        $cluster = new ValkeyGlideClusterFake;
        $cluster->willReturn('sinterstore', 1);

        $results = (new ValkeyGlideConnection($cluster))->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->__call('sinterstore', ['{b}dst', '{b}s1']));

        self::assertSame([1], $results);

        $standalone = new ValkeyGlideFake;
        $standalone->willReturn('sinterstore', 1);

        $connection = new ValkeyGlideConnection($standalone);
        $connection->command('watch', ['a']);

        self::assertSame([1], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->__call('sinterstore', ['dst', 's1'])));
    }

    /**
     * Provide the ways a transaction can join an active pipeline.
     *
     * @return iterable<string, array{\Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed}>
     */
    public static function transactionsJoiningAPipeline(): iterable
    {
        yield 'callback' => [
            static fn (ValkeyGlideConnection $connection): mixed => $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('c', '3')),
        ];
        yield 'fluent' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->transaction()];
        yield 'bare multi' => [static fn (ValkeyGlideConnection $connection): mixed => $connection->command('multi')];
        yield 'batch transaction' => [static fn (ValkeyGlideConnection $connection): mixed => self::activeBatch($connection)->transaction()];
    }

    /**
     * Verify a transaction cannot join a pipeline while keys are watched, as
     * the pipeline would flatten it and the WATCH could not guard it.
     *
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed  $join
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('transactionsJoiningAPipeline')]
    #[Test]
    public function watchedTransactionCannotJoinAPipeline(\Closure $join): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);

        $batch = self::activeBatch($connection)->set('b', '2');

        try {
            $join($connection);
            self::fail('Expected the watched transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(self::WATCHED_TRANSACTION_IN_PIPELINE_MESSAGE, $exception->getMessage());
        }

        self::assertSame([true], $batch->exec());
        self::assertSame([], $client->callsFor('unwatch'));

        $client->willReturn('exec', false);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')));
    }

    /**
     * Verify a pipeline may still join a pipeline, and a transaction a
     * transaction, while keys are watched.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function watchedBatchesOfTheSameKindStillJoin(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection): void {
            $connection->pipeline(static fn (ValkeyGlideBatch $inner): ValkeyGlideBatch => $inner->set('a', '1'));
            $connection->command('pipeline');
        });

        self::assertSame([true], $results);

        $client->willReturn('exec', false);

        self::assertFalse($connection->transaction(static function (ValkeyGlideBatch $tx) use ($connection): void {
            $connection->transaction(static fn (ValkeyGlideBatch $inner): ValkeyGlideBatch => $inner->set('a', '2'));
            $connection->pipeline();
        }));
    }

    /**
     * Verify a fluent watched cluster transaction that refuses a command it
     * cannot guard has ended, and cleared the WATCH.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function fluentWatchedClusterTransactionEndsWhenItRefusesACommand(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['{a}k']);

        $batch = $connection->transaction();

        try {
            $batch->set('{a}k', '1')->__call('fcall', ['refresh', [], []]);
            self::fail('Expected the unguarded command to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::UNGUARDED_WATCH_MESSAGE, 'FCALL'), $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertFalse($client->isBatching());
        self::assertSame(['watch', 'multi', 'set', 'discard', 'unwatch'], $client->callOrder());
    }

    /**
     * Verify a phpredis-style SET with a single named string option runs
     * through rawcommand outside a batch.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRoutesANamedSingleSetFlagToRawcommand(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', true);

        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command('set', ['key' => 'lock', 'value' => 'owner', 'options' => 'NX']));
        self::assertSame([['SET', 'lock', 'owner', 'NX']], $client->callsFor('rawcommand'));
    }

    /**
     * Verify a phpredis-style SET with a single string option runs through
     * rawcommand outside a batch, as GLIDE's own set() refuses it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRoutesASingleSetFlagToRawcommand(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', true);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertTrue($connection->command('set', ['lock', 'owner', 'NX']));
        self::assertSame([['SET', 'app:lock', 'owner', 'NX']], $client->callsFor('rawcommand'));
        self::assertSame([], $client->callsFor('set'));
    }

    /**
     * Verify a single SET option that cannot be converted runs immediately in a
     * pipeline, as it does outside one.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function unconvertibleSingleSetOptionRunsImmediately(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', false);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame([true, false], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->set('b', '2', 'FOREVER')));
        self::assertSame([['SET', 'b', '2', 'FOREVER']], $client->callsFor('rawcommand'));
    }

    /**
     * Verify disconnect() ends the active batch, whose buffered commands GLIDE
     * drops on close, and forgets the WATCH the closed connection held.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function disconnectEndsTheActiveBatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);

        $batch = self::activeBatch($connection)->set('a', '1');

        $connection->disconnect();

        try {
            $batch->set('b', '2');
            self::fail('Expected the ended batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame('This pipeline or transaction has already been executed or discarded.', $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertFalse($connection->command('get', ['a']));
        self::assertSame(['watch', 'pipeline', 'set', 'discard', 'close', 'get'], $client->callOrder());

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1'));
            self::fail('Expected the closed client to refuse batch mode.');
        } catch (ConnectionException) {
            // The closed client refuses batch mode.
        }

        self::assertSame([], $client->callsFor('unwatch'));
    }

    /**
     * Verify a bare pipeline command with arguments is refused rather than
     * ignore them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function barePipelineWithArgumentsIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->command('pipeline', [static fn (): null => null]);
            self::fail('Expected the pipeline arguments to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('pipeline() takes a callback only as a method of the connection or the batch, not through command().', $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertSame([], $client->callOrder());
    }

    /**
     * Verify a command a listener of an immediate command runs on the same
     * connection runs outside the pipeline, as it would after the pipeline's
     * final send, so its result takes no place in the pipeline's results.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRunByAListenerOfAnImmediateCommandRunsOutsideThePipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);
        $client->willReturn('publish', 7);
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $published  = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($connection, &$published): void {
            if ($event->command !== 'eval') {
                return;
            }

            $published[] = $connection->command('publish', ['channel', 'message']);
        });

        $connection->setEventDispatcher($dispatcher);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('k', 'v');
            $pipe->eval(self::EVAL_TEST_SCRIPT, 0);
            $pipe->get('k');
        });

        self::assertSame([true, 1, 'v'], $results);
        self::assertSame([7], $published);
        self::assertSame(['pipeline', 'set', 'exec', 'rawcommand', 'publish', 'pipeline', 'get', 'exec'], $client->callOrder());
    }

    /**
     * Verify a command a listener runs while part of a cluster pipeline is
     * sent, as an immediate command waits to run, runs outside the pipeline, so
     * GLIDE's batch mode is closed when the keyless command runs, which a
     * cluster batch would refuse.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRunByAFlushListenerRunsOutsideAClusterPipeline(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willReturn('ping', 'PONG');
        $client->willReturn('incr', 5);

        $connection = new ValkeyGlideConnection($client);
        $heard      = [];

        $this->onFirstFlush($connection, static function () use ($connection, &$heard): void {
            $heard[] = $connection->command('incr', ['n']);
        });

        $results = $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('k', 'v')->ping('randomNode'));

        self::assertSame([true, 'PONG'], $results);
        self::assertSame([5], $heard);
        self::assertSame(['pipeline', 'set', 'exec', 'incr', 'ping'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a command a listener runs while part of a pipeline is sent, as a
     * script waits to run, runs before the script and outside the pipeline, so
     * the script sees its write and its result takes no place in the list.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRunByAFlushListenerRunsBeforeThePendingScriptOutsideThePipeline(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);
        $client->willReturn('incr', 5);

        $connection = new ValkeyGlideConnection($client);
        $heard      = [];

        $this->onFirstFlush($connection, static function () use ($connection, &$heard): void {
            $heard[] = $connection->command('incr', ['n']);
        });

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('k', 'v');
            $pipe->eval(self::EVAL_TEST_SCRIPT, 0);
            $pipe->get('k');
        });

        self::assertSame([true, 1, null], $results);
        self::assertSame([5], $heard);
        self::assertSame(['pipeline', 'set', 'exec', 'incr', 'rawcommand', 'pipeline', 'get', 'exec'], $client->callOrder());
    }

    /**
     * Provide the ways a flush listener could try to end a pipeline started
     * without a callback.
     *
     * @return iterable<string, array{string}>
     */
    public static function pipelineEndingListeners(): iterable
    {
        yield 'discard' => ['discard'];
        yield 'exec' => ['exec'];
    }

    /**
     * Verify a listener of a flush cannot end the pipeline, as the pipeline is
     * not active on the connection while its listeners run, so the immediate
     * command still runs and the pipeline carries on.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('pipelineEndingListeners')]
    #[Test]
    public function flushListenerCannotEndThePipeline(string $method): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);
        $heard      = [];

        $this->onFirstFlush($connection, static function () use ($connection, $method, &$heard): void {
            $heard[] = $connection->command($method);
        });

        $pipe = self::activeBatch($connection);

        self::assertSame([true, 1, 'v'], $pipe->set('k', 'v')->eval(self::EVAL_TEST_SCRIPT, 0)->get('k')->exec());
        self::assertSame([false], $heard);
        self::assertSame(['pipeline', 'set', 'exec', 'rawcommand', 'pipeline', 'get', 'exec'], $client->callOrder());
    }

    /**
     * Provide the immediate commands a guarded listener hears of in a pipeline,
     * by client.
     *
     * @return iterable<string, array{bool, \Closure(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed, string, string, mixed}>
     */
    public static function guardedListenerPipelines(): iterable
    {
        yield 'standalone script' => [
            false,
            static fn (ValkeyGlideBatch $pipe): mixed => $pipe->eval(self::EVAL_TEST_SCRIPT, 0),
            'eval',
            'rawcommand',
            1,
        ];

        yield 'cluster keyless command' => [
            true,
            static fn (ValkeyGlideBatch $pipe): mixed => $pipe->ping('randomNode'),
            'ping',
            'ping',
            'PONG',
        ];
    }

    /**
     * Verify a listener that writes on every command it hears of, skipping its
     * own write, stops in a pipeline with an immediate command, as it does
     * outside a batch: its writes run outside the pipeline, so they never
     * reopen GLIDE's batch mode and are each heard of as themselves.
     *
     * @param  bool  $cluster
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed  $immediate
     * @param  string  $event
     * @param  string  $sent
     * @param  mixed  $result
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('guardedListenerPipelines')]
    #[Test]
    public function guardedListenerThatWritesOnEveryCommandStopsInAPipeline(bool $cluster, \Closure $immediate, string $event, string $sent, mixed $result): void
    {
        $client = $cluster ? new ValkeyGlideClusterFake : new ValkeyGlideFake;
        $client->willReturn($sent, $result);
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $heard      = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $executed) use (&$heard): void {
            $heard[] = $executed->command;

            if ($executed->command === 'hincrby') {
                return;
            }

            $executed->connection->command('hincrby', ['stats', $executed->command, 1]);
        });

        $connection->setEventDispatcher($dispatcher);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($immediate): void {
            $pipe->set('k', 'v');
            $immediate($pipe);
            $pipe->get('k');
        });

        self::assertSame([true, $result, 'v'], $results);
        self::assertSame(['pipeline', 'hincrby', $event, 'hincrby', 'pipeline', 'hincrby'], $heard);
        self::assertSame(['pipeline', 'set', 'exec', 'hincrby', $sent, 'hincrby', 'pipeline', 'get', 'exec', 'hincrby'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a listener can read with the connection's typed get() while part
     * of a pipeline is sent and while an immediate command runs, as it can
     * outside a batch, since the pipeline is not active on the connection while
     * its listeners run.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function listenerReadsWithTypedGetWhileAnImmediateCommandRuns(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);
        $client->willReturn('get', 'flag-value');

        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $read       = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $executed) use ($connection, &$read): void {
            if ($executed->command === 'get') {
                return;
            }

            $read[] = $connection->get('flag');
        });

        $connection->setEventDispatcher($dispatcher);

        $results = $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '2')->eval(self::EVAL_TEST_SCRIPT, 0));

        self::assertSame([true, 1], $results);
        self::assertSame(['flag-value', 'flag-value'], $read);
        self::assertSame(['pipeline', 'set', 'exec', 'get', 'rawcommand', 'get'], $client->callOrder());
    }

    /**
     * Provide the points at which a listener leaves a batch of its own open
     * while a pipeline runs an immediate command.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function batchesLeftOpenByListeners(): iterable
    {
        yield 'flush' => ['pipeline', ['pipeline', 'set', 'exec', 'multi', 'set', 'discard']];
        yield 'immediate command' => ['eval', ['pipeline', 'set', 'exec', 'rawcommand', 'multi', 'set', 'discard']];
    }

    /**
     * Verify a batch a listener leaves open while a pipeline runs an immediate
     * command is discarded, and the pipeline ends with an exception, rather
     * than either batch taking the other's commands.
     *
     * @param  string  $heard
     * @param  list<string>  $calls
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('batchesLeftOpenByListeners')]
    #[Test]
    public function batchLeftOpenByAListenerIsDiscarded(string $heard, array $calls): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $opened     = false;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $executed) use ($connection, $heard, &$opened): void {
            if ($executed->command !== $heard || $opened) {
                return;
            }

            $opened = true;

            $connection->multi();
            $connection->command('set', ['x', '1']);
        });

        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
                $pipe->set('k', 'v');
                $pipe->eval(self::EVAL_TEST_SCRIPT, 0);
                $pipe->get('k');
            });
            self::fail('Expected the batch left open by the listener to be reported.');
        } catch (\LogicException $exception) {
            self::assertSame('A listener left a pipeline or transaction open while [EVAL] ran in a pipeline, so both were ended.', $exception->getMessage());
        }

        self::assertSame($calls, $client->callOrder());
        self::assertFalse($client->isBatching());
        self::assertFalse($connection->exec());
        self::assertSame('v', $connection->get('k'));
    }

    /**
     * Verify a batch a listener leaves open is discarded without hiding the
     * exception that ends the pipeline, when the listener then throws.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchLeftOpenByAThrowingListenerIsDiscarded(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);
        $failure    = new \DomainException('listener failed');

        $this->onFirstFlush($connection, static function () use ($connection, $failure): void {
            $connection->multi();
            $connection->command('set', ['x', '1']);

            throw $failure;
        });

        try {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('k', 'v')->eval(self::EVAL_TEST_SCRIPT, 0));
            self::fail('Expected the listener exception to be thrown.');
        } catch (\DomainException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(['pipeline', 'set', 'exec', 'multi', 'set', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
        self::assertFalse($connection->exec());
        self::assertSame('v', $connection->get('k'));
    }

    /**
     * Verify a batch a listener runs to completion while a pipeline runs an
     * immediate command is sent on its own, and the pipeline carries on.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchRunByAFlushListenerIsSentOnItsOwn(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);
        $client->willReturn('get', 'v');

        $connection = new ValkeyGlideConnection($client);
        $heard      = [];

        $this->onFirstFlush($connection, static function () use ($connection, &$heard): void {
            $heard[] = $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('x', '1'));
        });

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('k', 'v');
            $pipe->eval(self::EVAL_TEST_SCRIPT, 0);
            $pipe->get('k');
        });

        self::assertSame([true, 1, 'v'], $results);
        self::assertSame([[true]], $heard);
        self::assertSame(['pipeline', 'set', 'exec', 'multi', 'set', 'exec', 'rawcommand', 'pipeline', 'get', 'exec'], $client->callOrder());
    }

    /**
     * Verify a pipeline whose first buffered command GLIDE refused sends no
     * exec() before an immediate command, and leaves GLIDE's batch mode first,
     * so a cluster runs its keyless command outside batch mode.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function refusedFirstCommandLeavesNoStuckClusterBatch(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->refuseInBatch('zadd');
        $client->willReturn('ping', 'PONG');
        $client->willReturn('get', 'v');

        $batch = self::activeBatch(new ValkeyGlideConnection($client));

        try {
            $batch->zadd('zset', 1, 'member');
            self::fail('Expected the refused command to throw.');
        } catch (\InvalidArgumentException) {
            self::assertTrue($client->isBatching());
        }

        self::assertSame(['PONG', 'v'], $batch->ping('randomNode')->get('k')->exec());
        self::assertSame(['pipeline', 'zadd', 'discard', 'ping', 'pipeline', 'get', 'exec'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Provide the batches that zinter() with an empty key list is refused in.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function emptyZinterBatches(): iterable
    {
        yield 'pipeline' => ['pipeline', false];
        yield 'transaction' => ['transaction', false];
        yield 'watched transaction' => ['transaction', true];
    }

    /**
     * Verify zinter() with an empty key list, which GLIDE reports buffered
     * without buffering it, is refused before it is sent, so a batch the server
     * applied never reports a failure or a WATCH abort.
     *
     * @param  string  $method
     * @param  bool  $watched
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('emptyZinterBatches')]
    #[Test]
    public function zinterWithAnEmptyKeyListIsRefusedBeforeItIsSent(string $method, bool $watched): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        if ($watched) {
            $connection->command('watch', ['bal']);
        }

        try {
            $this->runBatch($connection, $method, static fn (ValkeyGlideBatch $batch): ValkeyGlideBatch => $batch->decrby('bal', 10)->zinter([]));
            self::fail('Expected the empty zinter() to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('GLIDE would not buffer [ZINTER] with these arguments, although it reports it buffered, so it was not added to the batch.', $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('zinter'));
        self::assertSame([], $client->callsFor('exec'));
        self::assertSame($watched ? [[]] : [], $client->callsFor('unwatch'));
        self::assertFalse($client->isBatching());
    }

    /**
     * Provide the batches whose exec() can return a result list that does not
     * match what they buffered.
     *
     * @return iterable<string, array{string, bool, string}>
     */
    public static function misalignedBatches(): iterable
    {
        yield 'pipeline' => ['pipeline', false, 'pipeline'];
        yield 'transaction' => ['transaction', false, 'transaction'];
        yield 'watched transaction' => ['transaction', true, 'transaction'];
    }

    /**
     * Verify an exec() that returns results not matching what was buffered
     * throws, as the batch was applied, even when keys are watched, where a
     * false return would report a WATCH abort.
     *
     * @param  string  $method
     * @param  bool  $watched
     * @param  string  $event
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('misalignedBatches')]
    #[Test]
    public function misalignedExecResultsThrowEvenWhenKeysAreWatched(string $method, bool $watched, string $event): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', [true]);

        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $failed) use (&$events): void {
            $events[] = $failed;
        });

        $connection = new ValkeyGlideConnection($client);
        $connection->setEventDispatcher($dispatcher);

        if ($watched) {
            $connection->command('watch', ['a']);
        }

        $message = sprintf(self::MISALIGNED_BATCH_MESSAGE, $event);

        try {
            $this->runBatch($connection, $method, static fn (ValkeyGlideBatch $batch): ValkeyGlideBatch => $batch->set('a', '1')->set('b', '2'));
            self::fail('Expected the misaligned results to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertCount(1, $events);
        self::assertSame($event, $events[0]->command);
        self::assertSame($message, $events[0]->exception->getMessage());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a pipeline whose flush before an immediate command returns
     * misaligned results reports them as applied.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function misalignedFlushResultsThrow(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', []);

        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->eval(self::EVAL_TEST_SCRIPT, 0));
            self::fail('Expected the misaligned flush to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame(sprintf(self::MISALIGNED_BATCH_MESSAGE, 'pipeline'), $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('rawcommand'));
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a command that GLIDE buffers and then throws for ends the batch,
     * so a caller that catches the error cannot send a batch whose results no
     * longer match what it buffered.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandThatThrowsAfterBufferingEndsTheBatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $pipe = $connection->pipeline();
        $pipe->set('f', '1');

        try {
            $pipe->sinter('s1');
            self::fail('Expected the key that is not a list to throw.');
        } catch (\TypeError) {
            // GLIDE buffered the command before it threw.
        }

        try {
            $pipe->get('f');
            self::fail('Expected the ended batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame('This pipeline or transaction has already been executed or discarded.', $exception->getMessage());
        }

        self::assertSame(['pipeline', 'set', 'sinter', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
        self::assertFalse($connection->exec());
    }

    /**
     * Verify a watched transaction whose command throws while it is buffered
     * clears the WATCH it ends.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandThatThrowsWhileBufferedInAWatchedTransactionUnwatches(): void
    {
        $client = new ValkeyGlideFake;
        $client->willThrow('incr', new \DomainException('incr failed'));

        $connection = new ValkeyGlideConnection($client);
        $connection->command('watch', ['a']);

        $transaction = $connection->transaction();
        $transaction->set('a', '1');

        try {
            $transaction->incr('a');
            self::fail('Expected the failed command to throw.');
        } catch (\DomainException $exception) {
            self::assertSame('incr failed', $exception->getMessage());
        }

        self::assertSame(['watch', 'multi', 'set', 'incr', 'discard', 'unwatch'], $client->callOrder());
        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('b', '1')));
        self::assertCount(1, $client->callsFor('unwatch'));
    }

    /**
     * Provide the key-list commands that refuse an empty key list.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function emptyKeyListCommands(): iterable
    {
        yield 'del in a pipeline' => ['pipeline', 'del'];
        yield 'unlink in a pipeline' => ['pipeline', 'unlink'];
        yield 'del in a transaction' => ['transaction', 'del'];
        yield 'unlink in a transaction' => ['transaction', 'unlink'];
    }

    /**
     * Verify del() and unlink() given an empty key list are refused as GLIDE
     * refuses them, rather than sent without keys.
     *
     * @param  string  $method
     * @param  string  $command
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('emptyKeyListCommands')]
    #[Test]
    public function emptyKeyListIsRefusedAsGlideRefusesIt(string $method, string $command): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        try {
            $this->runBatch($connection, $method, static fn (ValkeyGlideBatch $batch): ValkeyGlideBatch => $batch->set('x', '1')->__call($command, [[]]));
            self::fail('Expected the empty key list to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                sprintf('GLIDE refused to buffer [%s], most likely due to its arguments, so it was not added to the batch.', strtoupper($command)),
                $exception->getMessage(),
            );
        }

        self::assertSame([[[]]], $client->callsFor($command));
        self::assertSame([], $client->callsFor('exec'));
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a cluster transaction refused for a key outside the slot of the
     * watched keys says so, as keys left watched by a failed unwatch() hold
     * that slot until unwatch() succeeds.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterTransactionOutsideTheWatchedSlotNamesTheWatchedKeys(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['{a}k']);
        $client->willReturn('unwatch', false);

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx): void {
                $tx->set('{a}x', '1');

                throw new \DomainException('callback failed');
            });
            self::fail('Expected the callback exception to be rethrown.');
        } catch (\DomainException) {
            // The callback discards the transaction; its UNWATCH fails.
        }

        $client->willReturn('unwatch', true);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{b}x', '1'));
            self::fail('Expected the transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::WATCHED_CROSS_SLOT_MESSAGE, 'SET', '{b}x', 3300, 15495, '{a}k'), $exception->getMessage());
        }

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{b}x', '1')));
    }

    /**
     * Verify a client-local method is refused in a transaction without the
     * claim that it would break atomicity, as it never reaches the server.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clientLocalMethodInATransactionIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx): void {
                $tx->set('key', 'value');
                $tx->__call('getOption', [1]);
            });
            self::fail('Expected the client-local method to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::LOCAL_IN_TRANSACTION_MESSAGE, 'GETOPTION'), $exception->getMessage());
        }

        self::assertSame(['multi', 'set', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a key list beside other arguments, or a list of key lists, is
     * refused before anything is sent, outside a batch and inside one, as GLIDE
     * would send it as the key `Array` or drop keys; a refused call leaves a
     * batch without a slot, and the batch carries on.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function keyListsGlideDoesNotTakeAsKeysAreRefusedBeforeAnythingIsSent(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);
        $unprefixed = new ValkeyGlideConnection($client);
        $shapes     = [
            ['del', ['a', ['b', 'c']]],
            ['unlink', [['a'], 'b']],
            ['exists', [['a'], ['b']]],
            ['touch', [[['a'], 'b']]],
            ['sInter', ['a', ['b']]],
            ['mget', [['a'], 'b']],
            ['watch', ['a', ['b']]],
        ];

        foreach ($shapes as [$method, $parameters]) {
            foreach ([$connection, $unprefixed] as $target) {
                $exception = self::exceptionOf(static fn (): mixed => $target->command($method, $parameters));

                self::assertInstanceOf(\InvalidArgumentException::class, $exception, $method);
                self::assertSame(sprintf(self::UNSUPPORTED_KEY_LIST_MESSAGE, strtoupper($method)), $exception->getMessage());
            }
        }

        self::assertSame([], $client->callOrder());

        $batch     = $connection->pipeline();
        $exception = self::exceptionOf(static fn (): mixed => $batch->del('a', ['b']));

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame([true], $batch->set('a', '1')->exec());

        $exception = self::exceptionOf(static fn (): mixed => $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')->del('a', ['b'])));

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame(['pipeline', 'set', 'exec', 'multi', 'set', 'discard'], $client->callOrder());
        self::assertSame([['app:a', '1', null], ['app:a', '1', null]], $client->callsFor('set'));
    }

    /**
     * Verify the exception of a transaction's callback is the one rethrown when
     * the discard() or the UNWATCH that ends the transaction throws in turn.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function callbackExceptionSurvivesACleanupThatThrows(): void
    {
        foreach (['discard', 'unwatch'] as $cleanup) {
            $client     = new ValkeyGlideFake;
            $connection = new ValkeyGlideConnection($client);
            $original   = new \DomainException('original');

            $connection->command('watch', ['w']);
            $client->willThrow($cleanup, new \RuntimeException('cleanup'));

            $caught = self::exceptionOf(static fn (): mixed => $connection->transaction(static function (ValkeyGlideBatch $tx) use ($original): void {
                $tx->set('a', '1');

                throw $original;
            }));

            self::assertSame($original, $caught, $cleanup);
            self::assertContains($cleanup, $client->callOrder(), $cleanup);
            self::assertFalse($connection->exec(), $cleanup);
        }
    }

    /**
     * Verify the exception that ends a batch is the one thrown when the
     * discard() or UNWATCH that follows throws in turn: an exec() that throws,
     * a command that throws as GLIDE buffers it, and an exec() that throws
     * while a pipeline sends what it holds before a script.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchFailureSurvivesACleanupThatThrows(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $original   = new \DomainException('exec failed');

        $connection->command('watch', ['w']);
        $client->willThrow('exec', $original);
        $client->willThrow('unwatch', new \RuntimeException('cleanup'));

        self::assertSame($original, self::exceptionOf(static fn (): mixed => $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1'))));
        self::assertFalse($connection->exec());

        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $original   = new \TypeError('get failed');
        $batch      = $connection->pipeline()->set('a', '1');

        $client->willThrow('get', $original);
        $client->willThrow('discard', new \RuntimeException('cleanup'));

        self::assertSame($original, self::exceptionOf(static fn (): mixed => $batch->get('b')));
        self::assertFalse($connection->exec());

        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $original   = new \DomainException('flush failed');

        $client->willThrow('exec', $original);
        $client->willThrow('discard', new \RuntimeException('cleanup'));

        $script = static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->eval(self::EVAL_TEST_SCRIPT, 0);

        self::assertSame($original, self::exceptionOf(static fn (): mixed => $connection->pipeline($script)));
        self::assertSame(['pipeline', 'set', 'exec', 'discard'], $client->callOrder());
        self::assertFalse($connection->exec());
    }

    /**
     * Provide batches that a failed exec() or a refusal ends, with the cleanup
     * that throws as each one ends, what each one reports: the message of its
     * exception, or false for an aborted watched transaction, and the batch a
     * failed exec() reports in a `CommandFailed` event, if any.
     *
     * @return iterable<string, array{bool, string, list<string>, mixed, \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed, false|string, string|null}>
     */
    public static function batchesEndedByAFailure(): iterable
    {
        $set         = static fn (ValkeyGlideBatch $batch): ValkeyGlideBatch => $batch->set('{a}x', '1');
        $script      = static fn (ValkeyGlideBatch $batch): ValkeyGlideBatch => $batch->set('{a}x', '1')->eval(self::EVAL_TEST_SCRIPT, 0);
        $pipeline    = static fn (\Closure $callback): \Closure => static fn (ValkeyGlideConnection $connection): mixed => $connection->pipeline($callback);
        $transaction = static fn (\Closure $callback): \Closure => static fn (ValkeyGlideConnection $connection): mixed => $connection->transaction($callback);
        $refused     = static function (ValkeyGlideConnection $connection) use ($set): mixed {
            $connection->client()->close();

            return $connection->transaction($set);
        };

        $bothSlots = $transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{a}x', '1')->set('{b}x', '1'));
        $otherSlot = $transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{b}x', '1'));
        $keyless   = $transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->fcall('refresh', [], []));

        $failed     = 'The transaction failed and its outcome is unknown; it may have been committed.';
        $misaligned = sprintf(self::MISALIGNED_BATCH_MESSAGE, 'transaction');
        $immediate  = sprintf(self::IMMEDIATE_IN_TRANSACTION_MESSAGE, 'EVAL');
        $modeless   = 'Unable to start the transaction; GLIDE refused batch mode, so the connection may be closed.';
        $outside    = sprintf(self::WATCHED_CROSS_SLOT_MESSAGE, 'SET', '{b}x', 3300, 15495, '{a}k');
        $unguarded  = sprintf(self::UNGUARDED_WATCH_MESSAGE, 'FCALL');
        $crossSlot  = sprintf(self::CROSS_SLOT_MESSAGE, 'WATCH', 'foo', 12182, 15495);

        yield 'failed pipeline, discard throws' => [false, 'discard', [], false, $pipeline($set), self::PIPELINE_FAILED_MESSAGE, 'pipeline'];
        yield 'failed transaction, discard throws' => [false, 'discard', [], false, $transaction($set), $failed, 'transaction'];
        yield 'aborted watched transaction, discard throws' => [false, 'discard', ['w'], false, $transaction($set), false, 'transaction'];
        yield 'aborted watched transaction, unwatch throws' => [false, 'unwatch', ['w'], false, $transaction($set), false, 'transaction'];
        yield 'misaligned watched transaction, unwatch throws' => [false, 'unwatch', ['w'], [true, true], $transaction($set), $misaligned, 'transaction'];
        yield 'failed flush before a script, discard throws' => [false, 'discard', [], false, $pipeline($script), self::PIPELINE_FAILED_MESSAGE, 'pipeline'];
        yield 'script in a watched transaction, discard throws' => [false, 'discard', ['w'], null, $transaction($script), $immediate, null];
        yield 'script in a watched transaction, unwatch throws' => [false, 'unwatch', ['w'], null, $transaction($script), $immediate, null];
        yield 'refused batch mode, discard throws' => [false, 'discard', ['w'], null, $refused, $modeless, null];
        yield 'refused batch mode, unwatch throws' => [false, 'unwatch', ['w'], null, $refused, $modeless, null];
        yield 'cluster failed pipeline, discard throws' => [true, 'discard', [], false, $pipeline($set), self::PIPELINE_FAILED_MESSAGE, 'pipeline'];
        yield 'cluster failed transaction, discard throws' => [true, 'discard', [], false, $transaction($set), $failed, 'transaction'];
        yield 'cluster aborted watched transaction, unwatch throws' => [true, 'unwatch', ['{a}k'], false, $transaction($set), false, 'transaction'];
        yield 'cluster key outside the watched slot, discard throws' => [true, 'discard', ['{a}k'], null, $bothSlots, $outside, null];
        yield 'cluster key outside the watched slot, unwatch throws' => [true, 'unwatch', ['{a}k'], null, $otherSlot, $outside, null];
        yield 'cluster command the watch cannot guard, unwatch throws' => [true, 'unwatch', ['{a}k'], null, $keyless, $unguarded, null];
        yield 'cluster watched keys in two slots, unwatch throws' => [true, 'unwatch', ['a', 'foo'], null, $transaction($set), $crossSlot, null];
    }

    /**
     * Verify what ends a batch, a failed exec() or a refusal, is what the
     * caller gets when the discard() or UNWATCH that follows throws in turn,
     * that a failed exec() still dispatches its `CommandFailed` event with the
     * exception it reports, and the batch still ends.
     *
     * @param  bool  $cluster
     * @param  string  $cleanup
     * @param  list<string>  $watch
     * @param  mixed  $exec
     * @param  \Closure(\SineMacula\Valkey\Connections\ValkeyGlideConnection): mixed  $run
     * @param  false|string  $expected
     * @param  string|null  $event
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('batchesEndedByAFailure')]
    #[Test]
    public function batchEndedByAFailureIsReportedWhenItsCleanupThrows(
        bool $cluster,
        string $cleanup,
        array $watch,
        mixed $exec,
        \Closure $run,
        false|string $expected,
        ?string $event,
    ): void {
        $client     = $cluster ? new ValkeyGlideClusterFake : new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $result     = null;
        $failures   = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $failure) use (&$failures): void {
            $failures[] = $failure;
        });

        $connection->setEventDispatcher($dispatcher);

        if ($watch !== []) {
            $connection->command('watch', $watch);
        }

        $client->willReturn('exec', $exec);
        $client->willThrow($cleanup, new \RuntimeException('cleanup'));

        $caught = self::exceptionOf(static function () use ($run, $connection, &$result): void {
            $result = $run($connection);
        });

        self::assertSame($expected, $caught?->getMessage() ?? $result);
        self::assertContains($cleanup, $client->callOrder());
        self::assertSame($event === null ? [] : [$event], array_map(static fn (CommandFailed $failure): string => $failure->command, $failures));

        if ($event !== null) {
            $reported = $failures[0]->exception;

            // An aborted watched transaction throws nothing, so only its event
            // reports the failure; any other batch reports what it throws.
            self::assertInstanceOf(ConnectionException::class, $reported);
            self::assertSame($expected === false ? self::WATCHED_TRANSACTION_FAILED_MESSAGE : $expected, $reported->getMessage());

            if ($caught !== null) {
                self::assertSame($caught, $reported);
            }
        }

        self::assertFalse($connection->exec());
    }

    /**
     * Verify a bare multi() that GLIDE refuses batch mode for is ended, so the
     * next exec() finds no batch, even when its discard() throws, and the
     * refusal is what the caller sees.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function refusedBareMultiEndsWhenItsDiscardThrows(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $batch      = $connection->multi();

        $client->close();
        $client->willThrow('discard', new \RuntimeException('cleanup'));

        $caught = self::exceptionOf(static fn (): mixed => $batch->set('a', '1'));

        self::assertInstanceOf(ConnectionException::class, $caught);
        self::assertSame('Unable to start the transaction; GLIDE refused batch mode, so the connection may be closed.', $caught->getMessage());
        self::assertSame(['close', 'multi', 'discard'], $client->callOrder());
        self::assertFalse($connection->exec());
    }

    /**
     * Verify a batch a listener leaves open is ended without hiding the
     * exception that reports it, when its discard() throws, and the pipeline
     * ends too.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchLeftByAListenerIsReportedWhenItsDiscardThrows(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $this->onFirstFlush($connection, static function () use ($connection, $client): void {
            $connection->multi();
            $connection->command('set', ['x', '1']);
            $client->willThrow('discard', new \RuntimeException('cleanup'));
        });

        $script = static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('k', 'v')->eval(self::EVAL_TEST_SCRIPT, 0);
        $caught = self::exceptionOf(static fn (): mixed => $connection->pipeline($script));

        self::assertInstanceOf(\LogicException::class, $caught);
        self::assertSame('A listener left a pipeline or transaction open while [EVAL] ran in a pipeline, so both were ended.', $caught->getMessage());
        self::assertSame(['pipeline', 'set', 'exec', 'multi', 'set', 'discard'], $client->callOrder());
        self::assertFalse($connection->exec());
    }

    /**
     * Verify a native SET options array whose expiry GLIDE refuses, such as
     * `['EX' => '60']`, returns false outside a batch, and inside one is
     * reported and leaves no slot, so the next command's result keeps its
     * place; an expiry GLIDE reads as a positive number is buffered.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function nativeSetExpiryGlideRefusesLeavesNoSlot(): void
    {
        foreach ([new ValkeyGlideFake, new ValkeyGlideClusterFake] as $client) {
            $connection = new ValkeyGlideConnection($client);

            foreach ([['EX' => '60'], ['ex' => 0], ['PX' => -1], ['EXAT' => 0.5], ['PXAT' => null], ['NX', 'EX' => true]] as $options) {
                self::assertFalse($connection->command('set', ['k', 'v', $options]), (string) json_encode($options));
            }

            self::assertTrue($connection->command('set', ['k', 'v', ['NX', 'EX' => 1.5, 'PX' => 100, 'FOO' => '1']]));

            $batch     = $connection->pipeline();
            $exception = self::exceptionOf(static fn (): mixed => $batch->set('k', 'v', ['EX' => '60']));

            self::assertInstanceOf(\InvalidArgumentException::class, $exception);
            self::assertSame('GLIDE refused to buffer [SET], most likely due to its arguments, so it was not added to the batch.', $exception->getMessage());

            $client->willReturn('get', 'v');

            self::assertSame([true, 'v'], $batch->set('k', 'v', ['EX' => 60])->get('k')->exec());
        }
    }

    /**
     * Get the connection's active batch, as a nested pipeline() returns it.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return \SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private static function activeBatch(ValkeyGlideConnection $connection): ValkeyGlideBatch
    {
        $batch = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        return $batch;
    }

    /**
     * Run a call and return the message of the LogicException it throws, or
     * null when it throws none.
     *
     * @param  \Closure(): mixed  $call
     * @return string|null
     */
    private static function logicRefusalOf(\Closure $call): ?string
    {
        try {
            $call();
        } catch (\LogicException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Run a call and return the exception it throws, or null when it throws
     * none.
     *
     * @param  \Closure(): mixed  $call
     * @return \Throwable|null
     */
    private static function exceptionOf(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * Run a pipeline or a transaction on the connection.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $method
     * @param  callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed  $callback
     * @return array<int, mixed>|false|\SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function runBatch(ValkeyGlideConnection $connection, string $method, callable $callback): array|false|ValkeyGlideBatch
    {
        return $method === 'transaction' ? $connection->transaction($callback) : $connection->pipeline($callback);
    }

    /**
     * Build a dispatcher whose listener throws when it hears of the command.
     *
     * @param  string  $command
     * @return \Illuminate\Events\Dispatcher
     */
    private function throwingDispatcherFor(string $command): Dispatcher
    {
        $dispatcher = new Dispatcher;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($command): void {
            if ($event->command === $command) {
                throw new \DomainException('listener failed');
            }
        });

        return $dispatcher;
    }

    /**
     * Invoke private reconnectClient for reconnect path coverage.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return bool
     *
     * @imperative
     */
    private function invokeReconnectClient(ValkeyGlideConnection $connection): bool
    {
        $reconnector = \Closure::bind(
            fn (): bool => $this->reconnectClient(),
            $connection,
            ValkeyGlideConnection::class,
        );

        return $reconnector();
    }

    /**
     * Invoke private resolveClusterRoute for cluster routing coverage.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $method
     * @param  array<int, mixed>  $values
     * @return array<array-key, mixed>|string
     */
    private function invokeResolveClusterRoute(ValkeyGlideConnection $connection, string $method, array $values): array|string
    {
        $resolver = \Closure::bind(
            fn (string $method, array $values): array|string => $this->resolveClusterRoute($method, $values),
            $connection,
            ValkeyGlideConnection::class,
        );

        return $resolver($method, $values);
    }

    /**
     * Scan every key matching the pattern the way Laravel and Horizon do for
     * connections other than phpredis: from cursor '0' until it is '0' again.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $pattern
     * @return array<int, string>
     */
    private function scanAll(ValkeyGlideConnection $connection, string $pattern): array
    {
        $cursor = '0';
        $keys   = [];

        do {
            [$cursor, $page] = $connection->scan($cursor, ['match' => $pattern]);
            $keys            = [...$keys, ...$page];
        } while ($cursor !== '0');

        return $keys;
    }

    /**
     * Build a connection that fails once transiently then succeeds on retry.
     *
     * @param  array<string, mixed>  $config
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     */
    private function buildRetryConnection(array $config): ValkeyGlideConnection
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        return new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            $config,
        );
    }

    /**
     * Run an action from the listener of the first pipeline flush on the
     * connection.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  \Closure(): mixed  $action
     * @return void
     */
    private function onFirstFlush(ValkeyGlideConnection $connection, \Closure $action): void
    {
        $dispatcher = new Dispatcher;
        $done       = false;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($action, &$done): void {
            if ($event->command !== 'pipeline' || $done) {
                return;
            }

            $done = true;

            $action();
        });

        $connection->setEventDispatcher($dispatcher);
    }
}
