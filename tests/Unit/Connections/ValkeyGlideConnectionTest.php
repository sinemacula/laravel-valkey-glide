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

        $normalized_parameters = $this->invokeNormalizeCommandParameters($connection, 'mget', ['a', 'b']);

        self::assertSame(['app:a', 'app:b'], $normalized_parameters);
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

        $normalized_parameters = $this->invokeNormalizeCommandParameters($connection, 'rename', ['old-key', 'new-key']);

        self::assertSame(['app:old-key', 'app:new-key'], $normalized_parameters);
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

        $normalized_parameters = $this->invokeNormalizeCommandParameters(
            $connection,
            'eval',
            [self::EVAL_TEST_SCRIPT, 2, 'k1', 'k2', 'arg1'],
        );

        self::assertSame([self::EVAL_TEST_SCRIPT, 2, 'app:k1', 'app:k2', 'arg1'], $normalized_parameters);
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
        $first_client = new ValkeyGlideFake;
        $first_client->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $second_client = new ValkeyGlideFake;
        $second_client->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $first_client,
            static fn (): \ValkeyGlide => $second_client,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertCount(1, $first_client->callsFor('get'));
        self::assertCount(1, $second_client->callsFor('get'));
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
        $first_client = new ValkeyGlideFake;
        $first_client->willThrow('get', new \RuntimeException('protocol error, got \'�\' as reply-type byte'));

        $second_client = new ValkeyGlideFake;
        $second_client->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $first_client,
            static fn (): \ValkeyGlide => $second_client,
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
        $first_client = new ValkeyGlideFake;
        $first_client->willThrow('set', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $second_client = new ValkeyGlideFake;
        $second_client->willReturn('set', true);

        $connection = new ValkeyGlideConnection(
            $first_client,
            static fn (): \ValkeyGlide => $second_client,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        $this->expectException(\RuntimeException::class);
        $connection->command('set', ['key', 'value']);

        self::assertCount(0, $second_client->callsFor('set'));
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
        $first_client = new ValkeyGlideFake;
        $first_client->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $second_client = new ValkeyGlideFake;
        $second_client->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $connection = new ValkeyGlideConnection(
            $first_client,
            static fn (): \ValkeyGlide => $second_client,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        $this->expectException(\RuntimeException::class);

        $connection->command('get', ['retry-key']);

        self::assertCount(1, $first_client->callsFor('get'));
        self::assertCount(1, $second_client->callsFor('get'));
    }

    /**
     * Verify non-transient failures are not retried for idempotent commands.
     *
     * @return void
     */
    #[Test]
    public function commandDoesNotRetryIdempotentCommandWhenErrorIsNotTransient(): void
    {
        $first_client = new ValkeyGlideFake;
        $first_client->willThrow('get', new \RuntimeException('domain validation failure'));

        $second_client = new ValkeyGlideFake;
        $second_client->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $first_client,
            static fn (): \ValkeyGlide => $second_client,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        $this->expectException(\RuntimeException::class);

        $connection->command('get', ['retry-key']);

        self::assertCount(0, $second_client->callsFor('get'));
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

        $normalized_parameters = $this->invokeNormalizeCommandParameters($connection, 'rename', ['single-key']);

        self::assertSame(['app:single-key'], $normalized_parameters);
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
        $key_object = new \stdClass;

        $normalized_parameters = $this->invokeNormalizeCommandParameters($connection, 'get', [$key_object]);

        self::assertSame($key_object, $normalized_parameters[0]);
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

        $normalized_parameters = $this->invokeNormalizeCommandParameters($connection, 'eval', [self::EVAL_TEST_SCRIPT]);

        self::assertSame([self::EVAL_TEST_SCRIPT], $normalized_parameters);
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

        $normalized_parameters = $this->invokeNormalizeCommandParameters(
            $connection,
            'eval',
            [self::EVAL_TEST_SCRIPT, 'not-numeric', 'key-a'],
        );

        self::assertSame([self::EVAL_TEST_SCRIPT, 'not-numeric', 'key-a'], $normalized_parameters);
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
        $first_client = new ValkeyGlideFake;
        $first_client->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $second_client = new ValkeyGlideFake;
        $second_client->willReturn('get', 'ok');

        $sleep_calls = [];

        $connection = new ValkeyGlideConnection(
            $first_client,
            static fn (): \ValkeyGlide => $second_client,
            [
                'retry_delay_ms'  => 10,
                'retry_jitter_ms' => 5,
                'random_int'      => static fn (int $min, int $max): int => 3,
                'sleep'           => static function (int $microseconds) use (&$sleep_calls): void {
                    $sleep_calls[] = $microseconds;
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([13000], $sleep_calls);
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
        $first_client = new ValkeyGlideFake;
        $first_client->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $second_client = new ValkeyGlideFake;
        $second_client->willReturn('get', 'ok');

        $sleep_calls = [];

        $connection = new ValkeyGlideConnection(
            $first_client,
            static fn (): \ValkeyGlide => $second_client,
            [
                'retry_delay_ms'  => 10,
                'retry_jitter_ms' => 5,
                'random_int'      => static function (): int {
                    throw new \DomainException('entropy unavailable');
                },
                'sleep' => static function (int $microseconds) use (&$sleep_calls): void {
                    $sleep_calls[] = $microseconds;
                },
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
        self::assertSame([10000], $sleep_calls);
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
        $first_client = new ValkeyGlideFake;
        $first_client->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        $second_client = new ValkeyGlideFake;
        $second_client->willReturn('get', 'ok');

        $connection = new ValkeyGlideConnection(
            $first_client,
            static fn (): \ValkeyGlide => $second_client,
            [
                'retry_delay_ms'  => 1,
                'retry_jitter_ms' => 0,
            ],
        );

        self::assertSame('ok', $connection->command('get', ['retry-key']));
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
}
