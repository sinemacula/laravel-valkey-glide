<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Valkey GLIDE connection test case for the sorted-set calls that take
 * phpredis' and Laravel's forms: an exact ZINCRBY increment, Laravel's zadd()
 * dictionary and option strings, and Laravel's zinterstore() and zunionstore()
 * options array.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
final class SortedSetParityValkeyGlideConnectionTest extends TestCase
{
    /**
     * Verify a ZINCRBY is sent as a ZADD ... INCR of the prefixed key with the
     * increment as a string of 17 significant digits, on a server and on a
     * cluster, and returns the new score.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zincrbyIsSentAsZaddIncrWithTheExactIncrement(): void
    {
        foreach ([new ValkeyGlideFake, new ValkeyGlideClusterFake] as $client) {
            $client->willReturn('zadd', 1.23456789);

            $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

            self::assertSame(1.23456789, $connection->command('zIncrBy', ['z', 1.23456789, 'm']));
            self::assertSame(1.23456789, $connection->command('ZINCRBY', ['z', 2, 7]));
            self::assertSame([], $client->callsFor('zincrby'));
            self::assertSame([['app:z', ['INCR'], '1.2345678899999999', 'm'], ['app:z', ['INCR'], '2', '7']], $client->callsFor('zadd'));
        }
    }

    /**
     * Verify a ZINCRBY GLIDE would refuse an increment of is left to GLIDE.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zincrbyWithANonNumericIncrementIsLeftToGlide(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zincrby', false);

        $connection = new ValkeyGlideConnection($client);

        self::assertFalse($connection->command('zincrby', ['z', 'abc', 'm']));
        self::assertSame([['z', 'abc', 'm']], $client->callsFor('zincrby'));
        self::assertSame([], $client->callsFor('zadd'));
    }

    /**
     * Verify a ZINCRBY in a pipeline or a transaction is buffered as a ZADD ...
     * INCR with the exact increment.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zincrbyInABatchIsBufferedAsZaddIncr(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zadd', 1.5);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame([1.5], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->zincrby('z', 0.1, 'm')));
        self::assertSame([1.5], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->zincrby('z', 1.5, 'm')));
        self::assertSame(['pipeline', 'zadd', 'exec', 'multi', 'zadd', 'exec'], $client->callOrder());
        self::assertSame([['z', ['INCR'], '0.10000000000000001', 'm'], ['z', ['INCR'], '1.5', 'm']], $client->callsFor('zadd'));
    }

    /**
     * Verify listeners hear of a ZINCRBY as the ZADD ... INCR sent for it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zincrbyEventNamesTheZaddSent(): void
    {
        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $connection = new ValkeyGlideConnection(new ValkeyGlideFake);
        $connection->setEventDispatcher($dispatcher);
        $connection->command('zincrby', ['z', 0.5, 'm']);

        self::assertCount(1, $events);
        self::assertSame('zAdd', $events[0]->command);
        self::assertSame(['z', ['INCR'], '0.5', 'm'], $events[0]->parameters);
    }

    /**
     * Verify zadd() takes Laravel's dictionary of scores by member and its
     * option strings, as Laravel's phpredis connection does, and GLIDE's own
     * options array.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zaddTakesLaravelsDictionaryAndOptionStrings(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zadd', 2);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(2, $connection->zadd('z', ['a' => 1, 'b' => 2.5]));
        self::assertSame(2, $connection->zadd('z', 'NX', 'CH', 1, 'a'));
        self::assertSame(2, $connection->zadd('z', 3, 'c'));
        self::assertSame(2, $connection->zadd('z', ['XX'], 4, 'ch'));
        self::assertSame([
            ['app:z', [], '1', 'a', '2.5', 'b'],
            ['app:z', ['NX', 'CH'], '1', 'a'],
            ['app:z', [], '3', 'c'],
            ['app:z', ['XX'], '4', 'ch'],
        ], $client->callsFor('zadd'));
    }

    /**
     * Verify zadd(), zinterstore() and zunionstore() take a key that is not a
     * string, as they did through command(), which prefixes it as a string.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function sortedSetMethodsTakeAKeyThatIsNotAString(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $connection->zadd(123, 1, 'm');
        $connection->zinterstore(7, ['a', 'b']);
        $connection->zunionstore(8, ['a', 'b']);

        self::assertSame([['app:123', [], '1', 'm']], $client->callsFor('zadd'));
        self::assertSame([['app:7', ['app:a', 'app:b'], null, null]], $client->callsFor('zinterstore'));
        self::assertSame([['app:8', ['app:a', 'app:b'], null, null]], $client->callsFor('zunionstore'));
    }

    /**
     * Verify zadd() on the connection inside a bare transaction is buffered in
     * Laravel's form, while a pipeline's zadd() takes the client's own form, as
     * a phpredis pipeline does, so a member named after an option is kept.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function zaddInABatchTakesTheClientsForm(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zadd', 1);

        $connection = new ValkeyGlideConnection($client);

        $connection->multi();
        $connection->zadd('z', ['a' => 1]);

        self::assertSame([1], $connection->exec());
        self::assertSame([1], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->zadd('z', 1, 'nx')));
        self::assertSame([['z', [], '1', 'a'], ['z', '1', 'nx']], $client->callsFor('zadd'));
    }

    /**
     * Verify zinterstore() and zunionstore() take Laravel's options array, as
     * Laravel's phpredis connection does, and GLIDE's weights list and
     * aggregate, by position or by name.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function storeCommandsTakeLaravelsOptions(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zinterstore', 1);
        $client->willReturn('zunionstore', 2);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(1, $connection->zinterstore('d', ['a', 'b'], ['weights' => [2, 0.5], 'aggregate' => 'max']));
        self::assertSame(1, $connection->zinterstore('d', ['a', 'b'], [1, 2], 'MIN'));
        self::assertSame(2, $connection->zunionstore('d', ['a', 'b'], []));
        self::assertSame(2, $connection->zunionstore('d', ['a', 'b'], aggregate: 'MAX'));
        self::assertSame(2, $connection->zunionstore('d', ['a', 'b']));
        self::assertSame([['d', ['a', 'b'], ['2', '0.5'], 'max'], ['d', ['a', 'b'], ['1', '2'], 'MIN']], $client->callsFor('zinterstore'));
        self::assertSame([['d', ['a', 'b'], null, 'sum'], ['d', ['a', 'b'], null, 'MAX'], ['d', ['a', 'b'], null, null]], $client->callsFor('zunionstore'));
    }
}
