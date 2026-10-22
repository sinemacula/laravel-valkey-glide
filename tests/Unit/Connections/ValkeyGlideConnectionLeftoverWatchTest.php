<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Valkey GLIDE connection test case for a WATCH left over from a transaction
 * that ended while its UNWATCH failed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
final class ValkeyGlideConnectionLeftoverWatchTest extends TestCase
{
    /** @var string Shared transient failure message for retry assertions. */
    private const string TRANSIENT_RESET_MESSAGE = 'connection reset by peer';

    /** @var string Message for an unwatched transaction whose exec() failed. */
    private const string TRANSACTION_FAILED_MESSAGE = 'The transaction failed and its outcome is unknown; it may have been committed.';

    /** @var string Message for a cluster transaction whose keys span hash slots. */
    private const string CROSS_SLOT_MESSAGE = 'A cluster transaction must keep every key in one hash slot, but [%s] key [%s] is in slot [%d], not slot [%d];'
        . ' use a hash tag, such as {user:1}, so they share one.';

    /**
     * Verify the UNWATCH that failed when a transaction ended is sent again
     * before the next command, which then no longer sees the keys as watched:
     * the next transaction that fails throws as an unwatched one.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function leftoverWatchIsUnwatchedBeforeTheNextCommand(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $this->leaveWatchBehind($connection, $client, 'a');

        $client->willReturn('unwatch', true);
        $client->willReturn('get', 'value');

        self::assertSame('value', $connection->command('get', ['k']));
        self::assertSame(['unwatch', 'get'], array_slice($client->callOrder(), -2));

        self::assertSame('value', $connection->command('get', ['k']));
        self::assertCount(2, $client->callsFor('unwatch'));

        $client->willReturn('exec', false);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage(self::TRANSACTION_FAILED_MESSAGE);

        $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('b', '1'));
    }

    /**
     * Verify a leftover UNWATCH that fails again is sent once before each
     * command until it succeeds, and the commands still run.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failingLeftoverUnwatchIsSentOncePerCommandUntilItSucceeds(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $this->leaveWatchBehind($connection, $client, 'a');

        $client->willReturn('get', 'value');

        self::assertSame('value', $connection->command('get', ['k']));
        self::assertSame('value', $connection->command('get', ['k']));
        self::assertSame(['unwatch', 'get', 'unwatch', 'get'], array_slice($client->callOrder(), -4));

        $client->willReturn('unwatch', true);

        self::assertSame('value', $connection->command('get', ['k']));
        self::assertSame('value', $connection->command('get', ['k']));
        self::assertSame(['unwatch', 'get', 'get'], array_slice($client->callOrder(), -3));
    }

    /**
     * Verify an UNWATCH that throws as a transaction ends leaves the keys over
     * too, and that a leftover UNWATCH that throws does not fail the command,
     * which runs, and is sent again before the next one.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function throwingLeftoverUnwatchIsSentAgainWithoutFailingTheCommand(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('watch', ['a']);
        $client->willThrow('unwatch', new \RuntimeException('unwatch failed'));

        try {
            $connection->transaction(static function (): void {
                throw new \DomainException('callback failed');
            });
            self::fail('Expected the callback exception to be rethrown.');
        } catch (\DomainException $exception) {
            self::assertSame('callback failed', $exception->getMessage());
        }

        $client->willReturn('get', 'value');

        self::assertSame('value', $connection->command('get', ['k']));
        self::assertSame('value', $connection->command('get', ['k']));
        self::assertSame(['unwatch', 'get', 'unwatch', 'get'], array_slice($client->callOrder(), -4));
    }

    /**
     * Verify a leftover WATCH does not hold the retry back, as it guards no
     * transaction, and the fresh client holds no WATCH, so it is forgotten.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function leftoverWatchDoesNotHoldTheRetryBack(): void
    {
        $firstClient  = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'value');

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        $this->leaveWatchBehind($connection, $firstClient, 'a');

        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        self::assertSame('value', $connection->command('get', ['k']));
        self::assertSame($secondClient, $connection->client());
        self::assertSame(['unwatch', 'get', 'close'], array_slice($firstClient->callOrder(), -3));

        self::assertSame('value', $connection->command('get', ['k']));
        self::assertSame(['get', 'get'], $secondClient->callOrder());
    }

    /**
     * Verify a retry that keeps the same client keeps the leftover WATCH, as
     * the client may still hold it, so it is sent again before the next
     * command.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function retryOnTheSameClientKeepsTheLeftoverWatch(): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection(
            $client,
            static fn (): \ValkeyGlide => $client,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        $this->leaveWatchBehind($connection, $client, 'a');

        $client->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        try {
            $connection->command('get', ['k']);
            self::fail('Expected the transport error to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame(self::TRANSIENT_RESET_MESSAGE, $exception->getMessage());
        }

        self::assertTrue($connection->command('set', ['k', 'v']));
        self::assertSame(['unwatch', 'get', 'get', 'unwatch', 'set'], array_slice($client->callOrder(), -5));
    }

    /**
     * Verify a watch() after a leftover WATCH is unwatched first tracks only
     * the keys it watches, so a cluster transaction is bound to their slot
     * alone.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function watchAfterAnUnwatchedLeftoverTracksOnlyItsOwnKeys(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        $this->leaveWatchBehind($connection, $client, '{a}k');

        $client->willReturn('unwatch', true);

        self::assertTrue($connection->command('watch', ['{b}k']));
        self::assertSame(['unwatch', 'watch'], array_slice($client->callOrder(), -2));
        self::assertSame([[], []], $client->callsFor('unwatch'));

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{b}x', '1')));
    }

    /**
     * Verify a watch() made while the leftover WATCH cannot be unwatched makes
     * every tracked key the caller's own: it is not unwatched before the next
     * command, the retry stays off, and a transaction it aborts returns false.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function watchWhileTheLeftoverCannotBeUnwatchedKeepsEveryKeyWatched(): void
    {
        $firstClient  = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection(
            $firstClient,
            static fn (): \ValkeyGlide => $secondClient,
            [
                'retry_delay_ms'  => 0,
                'retry_jitter_ms' => 0,
            ],
        );

        $this->leaveWatchBehind($connection, $firstClient, 'a');

        self::assertTrue($connection->command('watch', ['b']));
        self::assertSame(['unwatch', 'watch'], array_slice($firstClient->callOrder(), -2));

        $firstClient->willThrow('get', new \RuntimeException(self::TRANSIENT_RESET_MESSAGE));

        try {
            $connection->command('get', ['k']);
            self::fail('Expected the transport error to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame(self::TRANSIENT_RESET_MESSAGE, $exception->getMessage());
        }

        self::assertSame(['watch', 'get'], array_slice($firstClient->callOrder(), -2));
        self::assertSame($firstClient, $connection->client());

        $firstClient->willReturn('exec', false);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('b', '1')));
    }

    /**
     * Verify a watch() made while the leftover WATCH cannot be unwatched keeps
     * the leftover keys beside its own, so a cluster transaction is refused
     * when they do not share a slot, before anything is sent.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function watchWhileTheLeftoverCannotBeUnwatchedKeepsTheLeftoverKeys(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        $this->leaveWatchBehind($connection, $client, '{a}k');

        self::assertTrue($connection->command('watch', ['{b}k']));

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{b}x', '1'));
            self::fail('Expected the transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame(sprintf(self::CROSS_SLOT_MESSAGE, 'WATCH', '{b}k', 3300, 15495), $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('exec'));
    }

    /**
     * Verify a watch() that GLIDE refuses leaves the leftover WATCH as it is,
     * to be sent again before the next command.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function refusedWatchKeepsTheLeftoverWatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $this->leaveWatchBehind($connection, $client, 'a');

        $client->willReturn('watch', false);

        self::assertFalse($connection->command('watch', ['b']));

        $client->willReturn('unwatch', true);

        self::assertTrue($connection->command('set', ['k', 'v']));
        self::assertSame(['unwatch', 'watch', 'unwatch', 'set'], array_slice($client->callOrder(), -4));
    }

    /**
     * Verify an unwatch() called while a WATCH is left over sends a single
     * UNWATCH, which forgets the keys once it succeeds, and keeps them left
     * over while it fails.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function unwatchWhileAWatchIsLeftOverSendsOneUnwatch(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $this->leaveWatchBehind($connection, $client, 'a');

        self::assertFalse($connection->command('unwatch'));
        self::assertTrue($connection->command('set', ['k', 'v']));
        self::assertSame(['discard', 'unwatch', 'unwatch', 'unwatch', 'set'], array_slice($client->callOrder(), -5));

        $client->willReturn('unwatch', true);

        self::assertTrue($connection->command('unwatch'));
        self::assertTrue($connection->command('set', ['k', 'v']));
        self::assertSame(['set', 'unwatch', 'set'], array_slice($client->callOrder(), -3));
    }

    /**
     * Verify a pipeline opened while a WATCH is left over sends the UNWATCH
     * before it, never into the batch, and sends no UNWATCH of its own.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function batchOpenedWhileAWatchIsLeftOverUnwatchesItFirst(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $this->leaveWatchBehind($connection, $client, 'a');

        $client->willReturn('unwatch', true);

        self::assertSame([true, true], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('k', '1')->set('l', '2')));
        self::assertSame(['unwatch', 'pipeline', 'set', 'set', 'exec'], array_slice($client->callOrder(), -5));
        self::assertCount(2, $client->callsFor('unwatch'));
    }

    /**
     * Verify a bare multi() opened while a WATCH is left over sends the UNWATCH
     * first, once, and the transaction then runs unwatched.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function bareMultiWhileAWatchIsLeftOverUnwatchesItOnce(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $this->leaveWatchBehind($connection, $client, 'a');

        $client->willReturn('unwatch', true);

        $connection->command('multi');
        $connection->command('set', ['k', '1']);

        self::assertSame([true], $connection->exec());
        self::assertSame(['unwatch', 'multi', 'set', 'exec'], array_slice($client->callOrder(), -4));
        self::assertCount(2, $client->callsFor('unwatch'));
    }

    /**
     * Verify a subscription made while a WATCH is left over sends the UNWATCH
     * first.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function subscriptionWhileAWatchIsLeftOverUnwatchesItFirst(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $this->leaveWatchBehind($connection, $client, 'a');

        $client->willReturn('unwatch', true);

        $connection->subscribe(['channel'], static function (): void {});

        self::assertSame(['unwatch', 'subscribe'], array_slice($client->callOrder(), -2));
        self::assertCount(2, $client->callsFor('unwatch'));
    }

    /**
     * Verify a watch() after an unwatch() that failed sends the UNWATCH again
     * first and then tracks only its own keys, so a cluster transaction is
     * bound to their slot alone.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function watchAfterAFailedUnwatchTracksOnlyItsOwnKeys(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command('watch', ['{a}k']));

        $client->willReturn('unwatch', false);

        self::assertFalse($connection->command('unwatch'));

        $client->willReturn('unwatch', true);

        self::assertTrue($connection->command('watch', ['{b}k']));
        self::assertSame(['watch', 'unwatch', 'unwatch', 'watch'], $client->callOrder());
        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->set('{b}x', '1')));
    }

    /**
     * Verify an unwatch() that fails while nothing is watched leaves nothing
     * over to unwatch before the next command.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function failedUnwatchWithNothingWatchedLeavesNothingOver(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $client->willReturn('unwatch', false);

        self::assertFalse($connection->command('unwatch'));
        self::assertTrue($connection->command('set', ['k', 'v']));
        self::assertSame(['unwatch', 'set'], $client->callOrder());
    }

    /**
     * Verify disconnect() forgets a leftover WATCH, as the server drops it with
     * the connection, so the client the next command reconnects with is sent no
     * UNWATCH.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function disconnectForgetsTheLeftoverWatch(): void
    {
        $client       = new ValkeyGlideFake;
        $secondClient = new ValkeyGlideFake;
        $connection   = new ValkeyGlideConnection($client, static fn (): \ValkeyGlide => $secondClient);

        $this->leaveWatchBehind($connection, $client, 'a');

        $connection->disconnect();
        $connection->command('get', ['k']);

        self::assertSame(['unwatch', 'close'], array_slice($client->callOrder(), -2));
        self::assertSame(['get'], $secondClient->callOrder());
    }

    /**
     * Watch a key, then end a transaction whose UNWATCH fails, leaving the
     * WATCH over; GLIDE's unwatch() keeps failing until told otherwise.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  \Tests\Fakes\ValkeyGlideClusterFake|\Tests\Fakes\ValkeyGlideFake  $client
     * @param  string  $key
     * @return void
     *
     * @throws \Throwable
     */
    private function leaveWatchBehind(ValkeyGlideConnection $connection, ValkeyGlideClusterFake|ValkeyGlideFake $client, string $key): void
    {
        self::assertTrue($connection->command('watch', [$key]));

        $client->willReturn('unwatch', false);

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx) use ($key): void {
                $tx->set($key, '1');

                throw new \DomainException('callback failed');
            });
            self::fail('Expected the callback exception to be rethrown.');
        } catch (\DomainException $exception) {
            self::assertSame('callback failed', $exception->getMessage());
        }

        self::assertSame(['discard', 'unwatch'], array_slice($client->callOrder(), -2));
    }
}
