<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Support\FakeValkeyGlide;

/**
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
final class ValkeyGlideConnectionTest extends TestCase
{
    private const string TRANSIENT_CONNECTION_MESSAGE = 'connection reset by peer';
    private const string NEWS_PATTERN                 = 'news.*';

    /**
     * Verify successful command execution returns client results.
     *
     * @return void
     */
    public function testCommandReturnsClientResultOnSuccess(): void
    {
        $client = new FakeValkeyGlide;
        $client->queueResponse('get', 'value');

        $connection = new ValkeyGlideConnection($client);
        $result     = $connection->command('get', ['key']);

        self::assertSame('value', $result);
        self::assertSame([['key']], $client->calls['get']);
    }

    /**
     * Verify idempotent commands are retried once after transient failures.
     *
     * @return void
     */
    public function testCommandRetriesIdempotentCommandAfterTransientFailure(): void
    {
        $failing_client = new FakeValkeyGlide;
        $failing_client->queueResponse('get', new \RuntimeException('SSL: Connection reset by peer'));

        $reconnected_client = new FakeValkeyGlide;
        $reconnected_client->queueResponse('get', 'recovered');

        $slept_microseconds = 0;
        $reconnect_count    = 0;

        $connector = static function () use (&$reconnect_count, $reconnected_client): \ValkeyGlide {
            $reconnect_count++;

            return $reconnected_client;
        };

        $connection = new ValkeyGlideConnection(
            $failing_client,
            $connector,
            [
                'retry_delay_ms'  => 5,
                'retry_jitter_ms' => 10,
                'random_int'      => static fn (int $min, int $max): int => $min + ($max - $min),
                'sleep'           => static function (int $microseconds) use (&$slept_microseconds): void {
                    $slept_microseconds += $microseconds;
                },
            ],
        );

        $result = $connection->command('get', ['key']);

        self::assertSame('recovered', $result);
        self::assertSame(1, $reconnect_count);
        self::assertSame(15000, $slept_microseconds);
    }

    /**
     * Verify non-idempotent commands are not retried.
     *
     * @return void
     */
    public function testCommandDoesNotRetryForNonIdempotentCommand(): void
    {
        $client = new FakeValkeyGlide;
        $client->queueResponse('set', new \RuntimeException(self::TRANSIENT_CONNECTION_MESSAGE));

        $reconnect_count = 0;
        $connection      = new ValkeyGlideConnection(
            $client,
            static function () use (&$reconnect_count, $client): \ValkeyGlide {
                $reconnect_count++;

                return $client;
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(self::TRANSIENT_CONNECTION_MESSAGE);

        try {
            $connection->command('set', ['key', 'value']);
        } finally {
            self::assertSame(0, $reconnect_count);
        }
    }

    /**
     * Verify retries are skipped when no reconnect callback exists.
     *
     * @return void
     */
    public function testCommandDoesNotRetryWithoutConnector(): void
    {
        $client = new FakeValkeyGlide;
        $client->queueResponse('get', new \RuntimeException(self::TRANSIENT_CONNECTION_MESSAGE));

        $connection = new ValkeyGlideConnection($client);

        $this->expectException(\RuntimeException::class);
        $connection->command('get', ['key']);
    }

    /**
     * Verify retries are skipped for non-transient failures.
     *
     * @return void
     */
    public function testCommandDoesNotRetryForNonTransientException(): void
    {
        $client = new FakeValkeyGlide;
        $client->queueResponse('get', new \RuntimeException('syntax error'));

        $reconnected = false;
        $connection  = new ValkeyGlideConnection(
            $client,
            static function () use (&$reconnected, $client): \ValkeyGlide {
                $reconnected = true;

                return $client;
            },
        );

        $this->expectException(\RuntimeException::class);

        try {
            $connection->command('get', ['key']);
        } finally {
            self::assertFalse($reconnected);
        }
    }

    /**
     * Verify invalid method names are rejected.
     *
     * @return void
     */
    public function testCommandRejectsInvalidMethodTypes(): void
    {
        $connection = new ValkeyGlideConnection(new FakeValkeyGlide);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported command method type');

        $connection->command([], []);
    }

    /**
     * Verify subscribe channels normalize callback arguments.
     *
     * @return void
     */
    public function testCreateSubscriptionUsesSubscribeAndNormalizesCallbackArguments(): void
    {
        $client = new FakeValkeyGlide;
        $client->queueSubscriptionMessage('subscribe', [null, 'updates', 'payload']);

        $captured   = [];
        $connection = new ValkeyGlideConnection($client);
        $connection->createSubscription(
            'updates',
            static function (mixed $message, mixed $channel) use (&$captured): void {
                $captured[] = [$message, $channel];
            },
            'subscribe',
        );

        self::assertSame([['payload', 'updates']], $captured);
        self::assertSame([[['updates']]], $client->calls['subscribe']);
    }

    /**
     * Verify pattern subscribe channels normalize callback arguments.
     *
     * @return void
     */
    public function testCreateSubscriptionUsesPsubscribeAndNormalizesCallbackArguments(): void
    {
        $client = new FakeValkeyGlide;
        $client->queueSubscriptionMessage('psubscribe', [null, self::NEWS_PATTERN, 'news.uk', 'update']);

        $captured   = [];
        $connection = new ValkeyGlideConnection($client);
        $connection->createSubscription(
            [self::NEWS_PATTERN],
            static function (mixed $message, mixed $channel) use (&$captured): void {
                $captured[] = [$message, $channel];
            },
            'psubscribe',
        );

        self::assertSame([['update', 'news.uk']], $captured);
        self::assertSame([[[self::NEWS_PATTERN]]], $client->calls['psubscribe']);
    }

    /**
     * Verify scalar and stringable channel values are normalized.
     *
     * @return void
     */
    public function testCreateSubscriptionNormalizesScalarAndStringableChannels(): void
    {
        $client     = new FakeValkeyGlide;
        $connection = new ValkeyGlideConnection($client);

        $connection->createSubscription(
            [
                123,
                false,
                new class implements \Stringable {
                    /**
                     * @return string
                     */
                    public function __toString(): string
                    {
                        return 'alerts';
                    }
                },
            ],
            static function (): void {
                $noop = null;
                unset($noop);
            },
            'subscribe',
        );

        self::assertSame([[['123', 'alerts']]], $client->calls['subscribe']);
    }

    /**
     * Verify unsupported callback types are rejected.
     *
     * @return void
     */
    public function testCreateSubscriptionRejectsUnsupportedCallbackType(): void
    {
        $connection = new ValkeyGlideConnection(new FakeValkeyGlide);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported subscription callback type');

        $connection->createSubscription('news', 'not-a-closure', 'subscribe');
    }

    /**
     * Verify unsupported method types are rejected.
     *
     * @return void
     */
    public function testCreateSubscriptionRejectsUnsupportedMethodType(): void
    {
        $connection = new ValkeyGlideConnection(new FakeValkeyGlide);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported subscription method type');

        $connection->createSubscription('news', static function (): void {
            $noop = null;
            unset($noop);
        }, ['invalid']);
    }

    /**
     * Verify unsupported method names are rejected.
     *
     * @return void
     */
    public function testCreateSubscriptionRejectsUnsupportedMethodName(): void
    {
        $connection = new ValkeyGlideConnection(new FakeValkeyGlide);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported subscription method [consume]');

        $connection->createSubscription('news', static function (): void {
            $noop = null;
            unset($noop);
        }, 'consume');
    }

    /**
     * Verify empty channel sets are rejected.
     *
     * @return void
     */
    public function testCreateSubscriptionRejectsEmptyChannelInput(): void
    {
        $connection = new ValkeyGlideConnection(new FakeValkeyGlide);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one valid subscription channel is required.');

        $connection->createSubscription(['', null], static function (): void {
            $noop = null;
            unset($noop);
        }, 'subscribe');
    }

    /**
     * Verify executeRaw delegates to rawcommand.
     *
     * @return void
     */
    public function testExecuteRawDelegatesToRawcommand(): void
    {
        $client = new FakeValkeyGlide;
        $client->queueResponse('rawcommand', 'PONG');

        $connection = new ValkeyGlideConnection($client);
        $result     = $connection->executeRaw(['PING']);

        self::assertSame('PONG', $result);
        self::assertSame([['PING']], $client->calls['rawcommand']);
    }

    /**
     * Verify disconnect closes the underlying client.
     *
     * @return void
     */
    public function testDisconnectClosesUnderlyingClient(): void
    {
        $client     = new FakeValkeyGlide;
        $connection = new ValkeyGlideConnection($client);
        $connection->disconnect();

        self::assertSame([[]], $client->calls['close']);
    }

    /**
     * Verify jitter falls back to base delay when random generation fails.
     *
     * @return void
     */
    public function testRetryDelayFallsBackToBaseDelayWhenRandomIntThrows(): void
    {
        $failing_client = new FakeValkeyGlide;
        $failing_client->queueResponse('get', new \RuntimeException(self::TRANSIENT_CONNECTION_MESSAGE));

        $reconnected_client = new FakeValkeyGlide;
        $reconnected_client->queueResponse('get', 'ok');

        $slept_microseconds = 0;
        $connection         = new ValkeyGlideConnection(
            $failing_client,
            static fn (): \ValkeyGlide => $reconnected_client,
            [
                'retry_delay_ms'  => 7,
                'retry_jitter_ms' => 10,
                'random_int'      => static function (): int {
                    throw new \OverflowException('entropy failed');
                },
                'sleep' => static function (int $microseconds) use (&$slept_microseconds): void {
                    $slept_microseconds += $microseconds;
                },
            ],
        );

        $result = $connection->command('get', ['key']);

        self::assertSame('ok', $result);
        self::assertSame(7000, $slept_microseconds);
    }

    /**
     * Verify zero retry delay skips sleep calls.
     *
     * @return void
     */
    public function testSleepBeforeRetrySkipsSleepWhenDelayIsZero(): void
    {
        $failing_client = new FakeValkeyGlide;
        $failing_client->queueResponse('get', new \RuntimeException(self::TRANSIENT_CONNECTION_MESSAGE));

        $reconnected_client = new FakeValkeyGlide;
        $reconnected_client->queueResponse('get', 'ok');

        $slept_microseconds = 0;
        $connection         = new ValkeyGlideConnection(
            $failing_client,
            static fn (): \ValkeyGlide => $reconnected_client,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
                'sleep'           => static function (int $microseconds) use (&$slept_microseconds): void {
                    $slept_microseconds += $microseconds;
                },
            ],
        );

        $result = $connection->command('get', ['key']);

        self::assertSame('ok', $result);
        self::assertSame(0, $slept_microseconds);
    }

    /**
     * Verify zero jitter uses only the base delay.
     *
     * @return void
     */
    public function testRetryDelayReturnsBaseDelayWhenJitterIsZero(): void
    {
        $failing_client = new FakeValkeyGlide;
        $failing_client->queueResponse('get', new \RuntimeException(self::TRANSIENT_CONNECTION_MESSAGE));

        $reconnected_client = new FakeValkeyGlide;
        $reconnected_client->queueResponse('get', 'ok');

        $random_called      = false;
        $slept_microseconds = 0;

        $connection = new ValkeyGlideConnection(
            $failing_client,
            static fn (): \ValkeyGlide => $reconnected_client,
            [
                'retry_delay_ms'  => 11,
                'retry_jitter_ms' => 0,
                'random_int'      => static function () use (&$random_called): int {
                    $random_called = true;

                    return 5;
                },
                'sleep' => static function (int $microseconds) use (&$slept_microseconds): void {
                    $slept_microseconds += $microseconds;
                },
            ],
        );

        $result = $connection->command('get', ['key']);

        self::assertSame('ok', $result);
        self::assertFalse($random_called);
        self::assertSame(11000, $slept_microseconds);
    }
}
