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
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Valkey GLIDE connection test case for the use of a connection after
 * disconnect().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
final class DisconnectedValkeyGlideConnectionTest extends TestCase
{
    /** @var string Message for a disconnected connection that has no connector to reconnect it. */
    private const string DISCONNECTED_MESSAGE = 'The connection was closed by disconnect() and has no connector to reconnect it.';

    /** @var string Message of a connector that cannot reach the server. */
    private const string REFUSED_MESSAGE = 'Unable to establish a Valkey GLIDE connection: refused';

    /**
     * Verify the first command after disconnect() runs on a client recreated
     * through the connector, once, without closing the replaced client again.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandAfterDisconnectReconnectsThroughTheConnector(): void
    {
        $firstClient  = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'value');

        $connects   = 0;
        $connection = new ValkeyGlideConnection($firstClient, static function () use (&$connects, $secondClient): \ValkeyGlide {
            $connects++;

            return $secondClient;
        });

        $connection->command('set', ['key', 'value']);
        $connection->disconnect();

        self::assertSame($firstClient, $connection->client());
        self::assertSame('value', $connection->command('get', ['key']));
        self::assertTrue($connection->command('set', ['other', 'value']));
        self::assertSame(1, $connects);
        self::assertSame($secondClient, $connection->client());
        self::assertSame(['set', 'close'], $firstClient->callOrder());
        self::assertSame(['get', 'set'], $secondClient->callOrder());
    }

    /**
     * Verify a command that is not safe to retry also reconnects after
     * disconnect(), as nothing was sent on the closed client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function nonIdempotentCommandAfterDisconnectReconnects(): void
    {
        $firstClient  = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('incr', 1);

        $connection = new ValkeyGlideConnection($firstClient, static fn (): \ValkeyGlide => $secondClient);

        $connection->disconnect();

        self::assertSame(1, $connection->command('incr', ['counter']));
        self::assertSame([], $firstClient->callsFor('incr'));
        self::assertSame([['counter']], $secondClient->callsFor('incr'));
    }

    /**
     * Verify a cluster connection reconnects after disconnect(), and a raw
     * command keeps its cluster route on the new client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterCommandAfterDisconnectReconnects(): void
    {
        $firstClient  = new ValkeyGlideClusterFake;
        $secondClient = new ValkeyGlideClusterFake;
        $secondClient->willReturn('rawcommand', 'value');

        $connection = new ValkeyGlideConnection($firstClient, static fn (): \ValkeyGlideCluster => $secondClient);

        $connection->disconnect();

        self::assertSame('value', $connection->executeRaw(['GET', 'key']));
        self::assertSame([], $firstClient->callsFor('rawcommand'));
        self::assertSame([[['type' => 'primarySlotKey', 'key' => 'key'], 'GET', 'key']], $secondClient->callsFor('rawcommand'));
    }

    /**
     * Verify a reconnect that fails leaves the connection disconnected, so the
     * next command tries the connector again.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedReconnectIsTriedAgainByTheNextCommand(): void
    {
        $firstClient  = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'value');

        $attempts   = 0;
        $connection = new ValkeyGlideConnection($firstClient, static function () use (&$attempts, $secondClient): \ValkeyGlide {
            if (++$attempts === 1) {
                throw new ConnectionException(self::REFUSED_MESSAGE);
            }

            return $secondClient;
        });

        $connection->disconnect();

        try {
            $connection->command('get', ['key']);
            self::fail('Expected the failed reconnect to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame(self::REFUSED_MESSAGE, $exception->getMessage());
        }

        self::assertSame('value', $connection->command('get', ['key']));
        self::assertSame(2, $attempts);
        self::assertSame([], $firstClient->callsFor('get'));
    }

    /**
     * Verify a cluster scan resumed while the reconnect fails keeps its cursor,
     * as no SCAN was sent, so the scan can resume once the reconnect succeeds.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedReconnectKeepsTheClusterScanCursor(): void
    {
        $firstClient = new ValkeyGlideClusterFake;
        $firstClient->willScan('scan', [['cursor-1', ['a']]]);

        $secondClient = new ValkeyGlideClusterFake;
        $secondClient->willScan('scan', [['0', ['b']]]);

        $attempts   = 0;
        $connection = new ValkeyGlideConnection($firstClient, static function () use (&$attempts, $secondClient): \ValkeyGlideCluster {
            if (++$attempts === 1) {
                throw new ConnectionException(self::REFUSED_MESSAGE);
            }

            return $secondClient;
        });

        self::assertSame(['cursor-1', ['a']], $connection->scan('0'));

        $connection->disconnect();

        try {
            $connection->scan('cursor-1');
            self::fail('Expected the failed reconnect to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame(self::REFUSED_MESSAGE, $exception->getMessage());
        }

        self::assertSame(['0', ['b']], $connection->scan('cursor-1'));
        self::assertSame([['cursor-1', '*', 10, null]], $secondClient->callsFor('scan'));
    }

    /**
     * Verify a command after disconnect() on a connection without a connector
     * is refused, rather than answered by the closed client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandAfterDisconnectWithoutConnectorIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->disconnect();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage(self::DISCONNECTED_MESSAGE);

        try {
            $connection->command('get', ['key']);
        } finally {
            self::assertSame(['close'], $client->callOrder());
        }
    }

    /**
     * Verify a pipeline and a transaction after disconnect() run on a client
     * recreated through the connector.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchAfterDisconnectReconnects(): void
    {
        $firstClient  = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('exec', [true]);

        $thirdClient = new ValkeyGlideFake;
        $thirdClient->willReturn('exec', [true]);

        $clients    = [$secondClient, $thirdClient];
        $connection = new ValkeyGlideConnection($firstClient, static function () use (&$clients): \ValkeyGlide {
            $client = array_shift($clients);

            self::assertInstanceOf(\ValkeyGlide::class, $client);

            return $client;
        });

        $connection->disconnect();

        self::assertSame([true], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')));

        $connection->disconnect();

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('b', '2')));
        self::assertSame(['close'], $firstClient->callOrder());
        self::assertSame(['pipeline', 'set', 'exec', 'close'], $secondClient->callOrder());
        self::assertSame(['multi', 'set', 'exec'], $thirdClient->callOrder());
    }

    /**
     * Verify a bare multi() after disconnect() starts its batch on a client
     * recreated through the connector.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bareMultiAfterDisconnectReconnects(): void
    {
        $firstClient  = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('exec', [true]);

        $connection = new ValkeyGlideConnection($firstClient, static fn (): \ValkeyGlide => $secondClient);

        $connection->disconnect();

        $batch = $connection->command('multi');

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);
        self::assertSame([true], $batch->set('a', '1')->exec());
        self::assertSame(['close'], $firstClient->callOrder());
        self::assertSame(['multi', 'set', 'exec'], $secondClient->callOrder());
    }

    /**
     * Verify a batch after disconnect() on a connection without a connector is
     * refused before it opens.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchAfterDisconnectWithoutConnectorIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->disconnect();

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1'));
            self::fail('Expected the disconnected connection to refuse the transaction.');
        } catch (ConnectionException $exception) {
            self::assertSame(self::DISCONNECTED_MESSAGE, $exception->getMessage());
        }

        self::assertFalse($connection->hasOpenBatch());
        self::assertSame(['close'], $client->callOrder());
    }

    /**
     * Provide the events of a pipeline running an immediate command whose
     * listener disconnects, while the pipeline is set aside, and the calls each
     * client then receives.
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function pipelinesDisconnectedByListeners(): iterable
    {
        yield 'flush' => ['pipeline', ['pipeline', 'set', 'exec', 'close'], ['rawcommand', 'pipeline', 'get', 'exec']];

        yield 'immediate command' => ['eval', ['pipeline', 'set', 'exec', 'rawcommand', 'close'], ['pipeline', 'get', 'exec']];
    }

    /**
     * Verify a pipeline whose listener disconnects while the pipeline is set
     * aside for an immediate command resumes on a client recreated through the
     * connector.
     *
     * @param  string  $heard
     * @param  list<string>  $firstCalls
     * @param  list<string>  $secondCalls
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('pipelinesDisconnectedByListeners')]
    #[Test]
    public function pipelineDisconnectedByAListenerResumesOnANewClient(string $heard, array $firstCalls, array $secondCalls): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willReturn('rawcommand', 1);
        $firstClient->willReturn('exec', [true]);

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('rawcommand', 1);
        $secondClient->willReturn('exec', ['v']);

        $connection = new ValkeyGlideConnection($firstClient, static fn (): \ValkeyGlide => $secondClient);
        $dispatcher = new Dispatcher;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $executed) use ($connection, $heard, $firstClient): void {
            if ($executed->command !== $heard || $connection->client() !== $firstClient) {
                return;
            }

            $connection->disconnect();
        });

        $connection->setEventDispatcher($dispatcher);

        self::assertSame([true, 1, 'v'], $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('k', 'v');
            $pipe->eval('return 1', 0);
            $pipe->get('k');
        }));
        self::assertSame($firstCalls, $firstClient->callOrder());
        self::assertSame($secondCalls, $secondClient->callOrder());
    }

    /**
     * Verify a pipeline whose listener disconnects a connection without a
     * connector while the pipeline is set aside ends with the refusal.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineDisconnectedByAListenerWithoutConnectorIsRefused(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rawcommand', 1);

        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $executed) use ($connection): void {
            if ($executed->command !== 'eval') {
                return;
            }

            $connection->disconnect();
        });

        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
                $pipe->eval('return 1', 0);
                $pipe->get('k');
            });
            self::fail('Expected the disconnected connection to refuse the rest of the pipeline.');
        } catch (ConnectionException $exception) {
            self::assertSame(self::DISCONNECTED_MESSAGE, $exception->getMessage());
        }

        self::assertFalse($connection->hasOpenBatch());
        self::assertSame(['rawcommand', 'close'], $client->callOrder());
    }

    /**
     * Verify a WATCH after disconnect() is set on the client the transaction
     * then runs on.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function watchAfterDisconnectGuardsTheTransactionOnTheNewClient(): void
    {
        $firstClient  = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('exec', [true]);

        $connection = new ValkeyGlideConnection($firstClient, static fn (): \ValkeyGlide => $secondClient);

        $connection->command('watch', ['a']);
        $connection->disconnect();
        $connection->command('watch', ['a']);

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('a', '1')));
        self::assertSame(['watch', 'close'], $firstClient->callOrder());
        self::assertSame(['watch', 'multi', 'set', 'exec'], $secondClient->callOrder());
    }

    /**
     * Verify a subscription after disconnect() runs on a client recreated
     * through the connector.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function subscriptionAfterDisconnectReconnects(): void
    {
        $firstClient  = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;
        $secondClient->setSubscriptionPayload('subscribe', ['channel-a', 'payload-a']);

        $connection = new ValkeyGlideConnection($firstClient, static fn (): \ValkeyGlide => $secondClient);

        $connection->disconnect();

        $messages = [];

        $connection->createSubscription('channel-a', static function (mixed $message, mixed $channel) use (&$messages): void {
            $messages[] = [$message, $channel];
        });

        self::assertSame([['payload-a', 'channel-a']], $messages);
        self::assertSame([], $firstClient->callsFor('subscribe'));
        self::assertCount(1, $secondClient->callsFor('subscribe'));
    }

    /**
     * Verify a subscription after disconnect() on a connection without a
     * connector is refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function subscriptionAfterDisconnectWithoutConnectorIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->disconnect();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage(self::DISCONNECTED_MESSAGE);

        try {
            $connection->createSubscription('channel-a', static function (): void {});
        } finally {
            self::assertSame([], $client->callsFor('subscribe'));
        }
    }
}
