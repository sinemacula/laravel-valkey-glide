<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Stringable;
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
     * Verify get returns null when the underlying client returns false
     * (cache miss).
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
     * Verify multi-key commands prefix every key parameter.
     *
     * @return void
     */
    #[Test]
    public function commandPrefixesAllKeysForAllKeyCommands(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters($connection, 'mget', ['a', 'b']);

        self::assertSame(['app:a', 'app:b'], $normalizedParameters);
    }

    /**
     * Verify double-key commands prefix both key positions.
     *
     * @return void
     */
    #[Test]
    public function commandPrefixesBothKeyParametersForDoubleKeyCommands(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters($connection, 'rename', ['old-key', 'new-key']);

        self::assertSame(['app:old-key', 'app:new-key'], $normalizedParameters);
    }

    /**
     * Verify EVAL key prefixes apply only to key segments.
     *
     * @return void
     */
    #[Test]
    public function commandPrefixesEvalKeyArgumentsOnly(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters(
            $connection,
            'eval',
            [self::EVAL_TEST_SCRIPT, 2, 'k1', 'k2', 'arg1'],
        );

        self::assertSame([self::EVAL_TEST_SCRIPT, 2, 'app:k1', 'app:k2', 'arg1'], $normalizedParameters);
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
     * Verify native set argument shapes continue to use the set method directly.
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
        } catch (\RuntimeException) {
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
        } catch (\RuntimeException) {
            self::assertCount(1, $events);
            self::assertSame('get', $events[0]->command);
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
        } catch (\RuntimeException) {
            self::assertCount(1, $firstClient->callsFor('get'));
            self::assertCount(1, $secondClient->callsFor('get'));
        }
    }

    /**
     * Verify non-transient failures are not retried for idempotent commands.
     *
     * @return void
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

        $this->expectException(\RuntimeException::class);

        $connection->command('get', ['retry-key']);

        self::assertCount(0, $secondClient->callsFor('get'));
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
     * Verify normalizeCommandParameters leaves missing second key untouched.
     *
     * @return void
     */
    #[Test]
    public function normalizeCommandParametersSkipsMissingDoubleKeyIndex(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters($connection, 'rename', ['single-key']);

        self::assertSame(['app:single-key'], $normalizedParameters);
    }

    /**
     * Verify non-scalar key values are ignored during prefixing.
     *
     * @return void
     */
    #[Test]
    public function normalizeCommandParametersSkipsNonScalarSingleKeyValues(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);
        $keyObject  = new \stdClass;

        $normalizedParameters = $this->invokeNormalizeCommandParameters($connection, 'get', [$keyObject]);

        self::assertSame($keyObject, $normalizedParameters[0]);
    }

    /**
     * Verify EVAL normalization skips prefixing when key count is missing.
     *
     * @return void
     */
    #[Test]
    public function normalizeCommandParametersLeavesEvalUntouchedWhenKeyCountIsMissing(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters($connection, 'eval', [self::EVAL_TEST_SCRIPT]);

        self::assertSame([self::EVAL_TEST_SCRIPT], $normalizedParameters);
    }

    /**
     * Verify EVAL normalization skips prefixing when key count is invalid.
     *
     * @return void
     */
    #[Test]
    public function normalizeCommandParametersLeavesEvalUntouchedWhenKeyCountIsInvalid(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters(
            $connection,
            'eval',
            [self::EVAL_TEST_SCRIPT, 'not-numeric', 'key-a'],
        );

        self::assertSame([self::EVAL_TEST_SCRIPT, 'not-numeric', 'key-a'], $normalizedParameters);
    }

    /**
     * Verify normalizeNonNegativeInt supports float, string, and stringables.
     *
     * @return void
     */
    #[Test]
    public function normalizeNonNegativeIntSupportsSupportedInputTypes(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertSame(4, $this->invokeNormalizeNonNegativeInt($connection, 4.7));
        self::assertSame(7, $this->invokeNormalizeNonNegativeInt($connection, '7'));
        self::assertSame(
            9,
            $this->invokeNormalizeNonNegativeInt(
                $connection,
                new \SimpleXMLElement('<root>9</root>'),
            ),
        );
    }

    /**
     * Verify normalizeNonNegativeInt returns null for invalid numeric values.
     *
     * @return void
     */
    #[Test]
    public function normalizeNonNegativeIntReturnsNullForNegativeValues(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertNull($this->invokeNormalizeNonNegativeInt($connection, -1));
    }

    /**
     * Verify normalizeNonEmptyStringable casts scalar values correctly.
     *
     * @return void
     */
    #[Test]
    public function normalizeNonEmptyStringableCastsScalarValues(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertSame('42', $this->invokeNormalizeNonEmptyStringable($connection, 42));
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
        } catch (\RuntimeException) {
            self::assertCount(1, $client->callsFor('set'));
        }
    }

    /**
     * Verify idempotent retries are capped at one even when a third attempt would pass.
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
     * Verify subscription methods are matched case-insensitively via lowercasing.
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
     * Verify non-stringable channels are skipped without aborting the channel list.
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
                    // No-op sleep; the random generator arguments are asserted instead.
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
                    // No-op sleep; the random generator arguments are asserted instead.
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
     * Verify retry jitter uses zero as the lower bound for the random generator.
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
                    // No-op sleep; the random generator arguments are asserted instead.
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([0, 5], $randomArgs);
    }

    /**
     * Verify a zero base delay short-circuits before invoking the sleep callback.
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
     * Verify normalizeNonNegativeInt rejects non-numeric strings as null.
     *
     * @return void
     */
    #[Test]
    public function normalizeNonNegativeIntRejectsNonNumericStrings(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertNull($this->invokeNormalizeNonNegativeInt($connection, 'not-numeric'));
    }

    /**
     * Verify normalizeNonNegativeInt rejects non-numeric stringables as null.
     *
     * @return void
     */
    #[Test]
    public function normalizeNonNegativeIntRejectsNonNumericStringables(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertNull(
            $this->invokeNormalizeNonNegativeInt(
                $connection,
                new \SimpleXMLElement('<root>not-a-number</root>'),
            ),
        );
    }

    /**
     * Verify normalizeNonNegativeInt casts the string form of numeric stringables.
     *
     * @return void
     */
    #[Test]
    public function normalizeNonNegativeIntCastsStringFormOfStringables(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        $stringable = new class implements \Stringable {
            /**
             * Render a numeric string whose direct int cast differs from its value.
             *
             * @return string
             */
            public function __toString(): string
            {
                return '9';
            }
        };

        self::assertSame(9, $this->invokeNormalizeNonNegativeInt($connection, $stringable));
    }

    /**
     * Verify normalizeNonNegativeInt preserves a zero value as non-negative.
     *
     * @return void
     */
    #[Test]
    public function normalizeNonNegativeIntPreservesZero(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertSame(0, $this->invokeNormalizeNonNegativeInt($connection, 0));
    }

    /**
     * Verify normalizeNonEmptyStringable casts boolean values to their string form.
     *
     * @return void
     */
    #[Test]
    public function normalizeNonEmptyStringableCastsBooleanValues(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);

        self::assertSame('1', $this->invokeNormalizeNonEmptyStringable($connection, true));
    }

    /**
     * Verify unknown key commands leave their parameters untouched under a prefix.
     *
     * @return void
     */
    #[Test]
    public function normalizeCommandParametersLeavesUnknownCommandsUntouched(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters($connection, 'ping', ['payload']);

        self::assertSame(['payload'], $normalizedParameters);
    }

    /**
     * Verify single-key prefixing keeps trailing parameters at a missing index.
     *
     * @return void
     */
    #[Test]
    public function normalizeCommandParametersKeepsTrailingParametersWhenKeyIndexMissing(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters($connection, 'get', [1 => 'a', 2 => 'b']);

        self::assertSame([1 => 'a', 2 => 'b'], $normalizedParameters);
    }

    /**
     * Verify non-scalar key values keep every trailing parameter intact.
     *
     * @return void
     */
    #[Test]
    public function normalizeCommandParametersKeepsTrailingParametersWhenKeyIsNonScalar(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);
        $keyObject  = new \stdClass;

        $normalizedParameters = $this->invokeNormalizeCommandParameters($connection, 'get', [$keyObject, 'trailing']);

        self::assertSame([$keyObject, 'trailing'], $normalizedParameters);
    }

    /**
     * Verify EVAL prefixing keeps trailing parameters when the key count is missing.
     *
     * @return void
     */
    #[Test]
    public function normalizeCommandParametersKeepsEvalTrailingParametersWhenKeyCountIndexMissing(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters(
            $connection,
            'eval',
            [0 => self::EVAL_TEST_SCRIPT, 2 => 'trailing'],
        );

        self::assertSame([0 => self::EVAL_TEST_SCRIPT, 2 => 'trailing'], $normalizedParameters);
    }

    /**
     * Verify EVAL prefixing reads the key count from the second parameter index.
     *
     * @return void
     */
    #[Test]
    public function normalizeCommandParametersReadsEvalKeyCountFromSecondIndex(): void
    {
        $connection = new ValkeyGlideConnection(new ValkeyGlideFake, null, ['prefix' => 'app:']);

        $normalizedParameters = $this->invokeNormalizeCommandParameters(
            $connection,
            'eval',
            [1 => 2, 2 => 'k1', 3 => 'k2'],
        );

        self::assertSame([1 => 2, 2 => 'app:k1', 3 => 'app:k2'], $normalizedParameters);
    }

    /**
     * Invoke private normalizeCommandParameters for deterministic prefix tests.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function invokeNormalizeCommandParameters(ValkeyGlideConnection $connection, string $method, array $parameters): array
    {
        $normalizer = \Closure::bind(
            fn (string $method, array $parameters): array => $this->normalizeCommandParameters($method, $parameters),
            $connection,
            ValkeyGlideConnection::class,
        );

        return $normalizer($method, $parameters);
    }

    /**
     * Invoke private normalizeNonNegativeInt for type coercion coverage.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  mixed  $value
     * @return int|null
     */
    private function invokeNormalizeNonNegativeInt(ValkeyGlideConnection $connection, mixed $value): ?int
    {
        $normalizer = \Closure::bind(
            fn (mixed $value): ?int => $this->normalizeNonNegativeInt($value),
            $connection,
            ValkeyGlideConnection::class,
        );

        return $normalizer($value);
    }

    /**
     * Invoke private normalizeNonEmptyStringable for method normalization.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  mixed  $value
     * @return string|null
     */
    private function invokeNormalizeNonEmptyStringable(ValkeyGlideConnection $connection, mixed $value): ?string
    {
        $normalizer = \Closure::bind(
            fn (mixed $value): ?string => $this->normalizeNonEmptyStringable($value),
            $connection,
            ValkeyGlideConnection::class,
        );

        return $normalizer($value);
    }

    /**
     * Invoke private reconnectClient for reconnect path coverage.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return bool
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
}
