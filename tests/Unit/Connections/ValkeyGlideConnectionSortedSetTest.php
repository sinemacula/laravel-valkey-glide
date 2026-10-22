<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Valkey GLIDE connection test case for the scores, members and weights of
 * sorted-set commands, which reach GLIDE as strings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
final class ValkeyGlideConnectionSortedSetTest extends TestCase
{
    /** @var int The score and member pairs of a ZADD, past the 20 numeric scores GLIDE can track. */
    private const int PAIRS = 25;

    /** @var string Message for a score, member or weight with no string form. */
    private const string UNSUPPORTED_VALUE_MESSAGE = '[%s] cannot send a [%s] as a score, member or weight, as it has no string form'
        . ' and GLIDE would corrupt memory on it.';

    /**
     * Verify zadd() sends its numeric scores and members as strings, on a
     * server and on a cluster, so GLIDE tracks none of them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zaddSendsScoresAndMembersAsStrings(): void
    {
        foreach ([new ValkeyGlideFake, new ValkeyGlideClusterFake] as $client) {
            $client->willReturn('zadd', self::PAIRS);

            $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

            self::assertSame(self::PAIRS, $connection->__call('zadd', ['z', ...self::numericPairs()]));

            $client->willReturn('zadd', 7.1);

            self::assertSame(7.1, $connection->command('zAdd', ['z', ['NX', 'INCR'], 0.1, 7]));
            self::assertSame([['app:z', ...self::stringPairs()], ['app:z', ['NX', 'INCR'], '0.10000000000000001', '7']], $client->callsFor('zadd'));
        }
    }

    /**
     * Verify a ZADD added to a pipeline or a transaction is buffered with its
     * scores and members as strings.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zaddInABatchIsBufferedWithStrings(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zadd', self::PAIRS);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame([self::PAIRS], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->zadd('z', ...self::numericPairs())));
        self::assertSame([self::PAIRS], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->zadd('z', ['GT'], 1.5, 2)));
        self::assertSame(['pipeline', 'zadd', 'exec', 'multi', 'zadd', 'exec'], $client->callOrder());
        self::assertSame([['z', ...self::stringPairs()], ['z', ['GT'], '1.5', '2']], $client->callsFor('zadd'));
    }

    /**
     * Verify the weights of ZUNIONSTORE, ZINTERSTORE, ZUNION and ZINTER are
     * sent as strings.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function weightsAreSentAsStrings(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        $connection->command('zunionstore', ['d', ['a', 'b'], [1, 2.5], 'SUM']);
        $connection->command('zinterstore', ['d', ['a', 'b'], [2, INF]]);
        $connection->command('zunion', [['a', 'b'], [3, 0.1]]);
        $connection->command('zinter', [['a', 'b'], [4, -INF]]);

        self::assertSame([['d', ['a', 'b'], ['1', '2.5'], 'SUM']], $client->callsFor('zunionstore'));
        self::assertSame([['d', ['a', 'b'], ['2', '+inf']]], $client->callsFor('zinterstore'));
        self::assertSame([[['a', 'b'], ['3', '0.10000000000000001']]], $client->callsFor('zunion'));
        self::assertSame([[['a', 'b'], ['4', '-inf']]], $client->callsFor('zinter'));
    }

    /**
     * Verify listeners hear of a ZADD with the strings sent for it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zaddEventCarriesTheStringsSent(): void
    {
        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);
        $connection->setEventDispatcher($dispatcher);
        $connection->command('zadd', ['z', 1792345678, 42]);

        self::assertCount(1, $events);
        self::assertSame(['z', '1792345678', '42'], $events[0]->parameters);
    }

    /**
     * Verify a score, member or weight with no string form is refused before
     * anything is sent, outside a batch and inside one, and that a refused call
     * leaves a batch without a slot, so the batch carries on.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function valueWithNoStringFormIsRefusedBeforeAnythingIsSent(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);
        $dispatcher = new Dispatcher;
        $failures   = 0;

        $dispatcher->listen(CommandFailed::class, static function () use (&$failures): void {
            $failures++;
        });

        $connection->setEventDispatcher($dispatcher);

        $exception = self::exceptionOf(static fn (): mixed => $connection->command('zadd', ['z', 1, ['m']]));

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame(sprintf(self::UNSUPPORTED_VALUE_MESSAGE, 'ZADD', 'array'), $exception->getMessage());

        $exception = self::exceptionOf(static fn (): mixed => $connection->command('zunionstore', ['d', ['a'], [new \stdClass]]));

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame(sprintf(self::UNSUPPORTED_VALUE_MESSAGE, 'ZUNIONSTORE', \stdClass::class), $exception->getMessage());
        self::assertSame([], $client->callOrder());
        self::assertSame(0, $failures);

        $batch     = $connection->pipeline();
        $exception = self::exceptionOf(static fn (): mixed => $batch->zadd('z', 1, new \stdClass));

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame([true], $batch->set('a', '1')->exec());
        self::assertSame(['pipeline', 'set', 'exec'], $client->callOrder());
        self::assertSame([], $client->callsFor('zadd'));
    }

    /**
     * Build score and member pairs with integer and float scores and integer
     * members.
     *
     * @return list<float|int>
     */
    private static function numericPairs(): array
    {
        $pairs = [];

        for ($i = 0; $i < self::PAIRS; $i++) {
            $pairs[] = $i % 2 === 0 ? $i : $i + 0.25;
            $pairs[] = $i;
        }

        return $pairs;
    }

    /**
     * Build the strings sent for the numeric score and member pairs.
     *
     * @return list<string>
     */
    private static function stringPairs(): array
    {
        $pairs = [];

        for ($i = 0; $i < self::PAIRS; $i++) {
            $pairs[] = $i % 2 === 0 ? (string) $i : $i . '.25';
            $pairs[] = (string) $i;
        }

        return $pairs;
    }

    /**
     * Run a call and return what it throws, if anything.
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
}
