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
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Valkey GLIDE connection test case for the hash and member scan commands the
 * connection answers as Laravel's phpredis connection does: hsetnx, and zscan,
 * hscan and sscan with an options array.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
final class PhpRedisParityValkeyGlideConnectionTest extends TestCase
{
    /** @var string Message for a connection method refused while a batch is active. */
    private const string ACTIVE_BATCH_MESSAGE = '[%s()] cannot be called while a pipeline or transaction is active; %s.';

    /** @var string Message for a command a batch cannot carry. */
    private const string REFUSED_IN_BATCH_MESSAGE = '[%s] cannot be used inside a pipeline or transaction, as GLIDE would refuse it, block on it,'
        . ' or write to a freed iterator.';

    /**
     * Provide GLIDE's HSETNX replies and the integer phpredis gives for each.
     *
     * @return iterable<string, array{mixed, int}>
     */
    public static function hsetnxReplies(): iterable
    {
        yield 'set' => [true, 1];
        yield 'not set' => [false, 0];
    }

    /**
     * Verify hsetnx answers with 1 or 0, as phpredis does through Laravel, for
     * the key it prefixes.
     *
     * @param  mixed  $reply
     * @param  int  $expected
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('hsetnxReplies')]
    #[Test]
    public function hsetnxReturnsAnIntegerForThePrefixedKey(mixed $reply, int $expected): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('hsetnx', $reply);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame($expected, $connection->hsetnx('hash', 'field', 'value'));
        self::assertSame([['app:hash', 'field', 'value']], $client->callsFor('hsetnx'));
    }

    /**
     * Verify hsetnx on the connection is refused while a pipeline or a
     * transaction is active, without buffering anything, as its result is only
     * known once the batch runs.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function hsetnxIsRefusedWhileABatchIsActive(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        foreach ([static fn (): mixed => $connection->pipeline(), static fn (): mixed => $connection->transaction()] as $open) {
            $open();

            try {
                $connection->hsetnx('hash', 'field', 'value');
                self::fail('Expected the call to be refused.');
            } catch (\LogicException $exception) {
                self::assertSame(sprintf(self::ACTIVE_BATCH_MESSAGE, 'hsetnx', 'call hsetnx() on the batch instead'), $exception->getMessage());
            }

            self::assertSame([], $connection->exec());
        }

        self::assertSame([], $client->callOrder());
    }

    /**
     * Verify hsetnx called on the batch joins it, and its result is GLIDE's
     * reply, as phpredis' pipelines return it to Horizon.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function hsetnxOnTheBatchJoinsIt(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('hsetnx', true);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->hsetnx('hash', 'field', 'value');
        });

        self::assertSame([true], $results);
        self::assertSame(['pipeline', 'hsetnx', 'exec'], $client->callOrder());
        self::assertSame([['app:hash', 'field', 'value']], $client->callsFor('hsetnx'));
    }

    /**
     * Provide each member scan with a page in the shape GLIDE answers it, and
     * the page in the shape phpredis gives it, with each score as a float.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function memberScans(): iterable
    {
        $scores = [
            'member:1' => '1.5',
            'member:2' => '-1',
            'member:3' => '3',
            'member:4' => '1e+20',
            'member:5' => '-2.5e-7',
            'member:6' => 'inf',
            'member:7' => '+inf',
            'member:8' => '-inf',
        ];

        $floats = [
            'member:1' => 1.5,
            'member:2' => -1.0,
            'member:3' => 3.0,
            'member:4' => 1.0e20,
            'member:5' => -2.5e-7,
            'member:6' => INF,
            'member:7' => INF,
            'member:8' => -INF,
        ];

        yield 'zscan' => ['zscan', $scores, $floats];
        yield 'hscan' => ['hscan', ['field:1' => 'value:1', 'field:2' => 'inf'], ['field:1' => 'value:1', 'field:2' => 'inf']];
        yield 'sscan' => ['sscan', ['member:1', 'member:2'], ['member:1', 'member:2']];
    }

    /**
     * Verify a member scan returns the next cursor and the page's members for
     * the key it prefixes, sending the match pattern unprefixed and the count
     * as given.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $page
     * @param  array<array-key, mixed>  $expected
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('memberScans')]
    #[Test]
    public function memberScanReturnsTheNextCursorAndMembersOfOnePage(string $method, array $page, array $expected): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan($method, [['17', $page]]);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(['17', $expected], self::memberScan($connection, $method, 'set', '0', ['match' => 'member:*', 'count' => 100]));
        self::assertSame([['app:set', '0', 'member:*', 100]], $client->callsFor($method));
    }

    /**
     * Verify a member scan on a cluster takes the same shape, as GLIDE routes
     * it by its key.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $page
     * @param  array<array-key, mixed>  $expected
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('memberScans')]
    #[Test]
    public function clusterMemberScanReturnsTheNextCursorAndMembersOfOnePage(string $method, array $page, array $expected): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan($method, [['17', $page], ['0', []]]);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(['17', $expected], self::memberScan($connection, $method, 'set', '0', ['match' => 'member:*', 'count' => 100]));
        self::assertSame(['0', []], self::memberScan($connection, $method, 'set', '17', ['match' => 'member:*', 'count' => 100]));
        self::assertSame([['app:set', '0', 'member:*', 100], ['app:set', '17', 'member:*', 100]], $client->callsFor($method));
    }

    /**
     * Provide each member scan with each cursor a caller may start it from.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function memberScanStartCursors(): iterable
    {
        foreach (['zscan', 'hscan', 'sscan'] as $method) {
            yield $method . ' from string zero' => [$method, '0'];
            yield $method . ' from integer zero' => [$method, 0];
            yield $method . ' from null' => [$method, null];
        }
    }

    /**
     * Verify a member scan starts from cursor '0', matching every member ten at
     * a time when no options are given, as phpredis does.
     *
     * @param  string  $method
     * @param  mixed  $cursor
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('memberScanStartCursors')]
    #[Test]
    public function memberScanStartsFromCursorZeroWithPhpRedisDefaults(string $method, mixed $cursor): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['0', []], self::memberScan($connection, $method, 'set', $cursor));
        self::assertSame([['set', '0', '*', 10]], $client->callsFor($method));
    }

    /**
     * Provide each member scan method.
     *
     * @return iterable<string, array{string}>
     */
    public static function memberScanMethods(): iterable
    {
        yield 'zscan' => ['zscan'];
        yield 'hscan' => ['hscan'];
        yield 'sscan' => ['sscan'];
    }

    /**
     * Verify a member scan resumes from an integer cursor sent as its string.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('memberScanMethods')]
    #[Test]
    public function memberScanResumesFromAnIntegerCursor(string $method): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client);

        self::memberScan($connection, $method, 'set', 17, ['match' => 'member:*']);

        self::assertSame([['set', '17', 'member:*', 10]], $client->callsFor($method));
    }

    /**
     * Verify a member scan sends a count of 0 as given, which GLIDE sends
     * without COUNT, as phpredis does.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('memberScanMethods')]
    #[Test]
    public function memberScanSendsACountOfZeroAsGiven(string $method): void
    {
        $client = new ValkeyGlideFake;

        $connection = new ValkeyGlideConnection($client);

        self::memberScan($connection, $method, 'set', '0', ['count' => 0]);

        self::assertSame([['set', '0', '*', 0]], $client->callsFor($method));
    }

    /**
     * Verify a member scan GLIDE answers with false throws, as GLIDE answers a
     * failed scan with false and writes '0' back, which would otherwise read as
     * the empty last page of a completed scan.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('memberScanMethods')]
    #[Test]
    public function memberScanThrowsWhenGlideAnswersFalse(string $method): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan($method, [['0', false]]);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf('Expected array response from %s on [set], received [bool].', $method));

        self::memberScan($connection, $method, 'set', '5');
    }

    /**
     * Provide each member scan with the pages GLIDE answers a scan with, and
     * the members phpredis gives over them.
     *
     * @return iterable<string, array{string, list<array<array-key, mixed>>, array<array-key, mixed>}>
     */
    public static function memberScanLoops(): iterable
    {
        yield 'zscan' => ['zscan', [['a' => '1'], [], ['b' => '2', 'c' => 'inf']], ['a' => 1.0, 'b' => 2.0, 'c' => INF]];
        yield 'hscan' => ['hscan', [['a' => 'x'], [], ['b' => 'y', 'c' => 'z']], ['a' => 'x', 'b' => 'y', 'c' => 'z']];
        yield 'sscan' => ['sscan', [['a'], [], ['b', 'c']], ['a', 'b', 'c']];
    }

    /**
     * Verify a member scan loop written for Laravel's connections, from '0'
     * until the cursor is '0' again, reads every page once, through empty
     * pages, and ends.
     *
     * @param  string  $method
     * @param  list<array<array-key, mixed>>  $pages
     * @param  array<array-key, mixed>  $expected
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('memberScanLoops')]
    #[Test]
    public function memberScanLoopFromCursorZeroReadsEveryPageAndEnds(string $method, array $pages, array $expected): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan($method, [['12', $pages[0]], ['30', $pages[1]], ['0', $pages[2]]]);

        $connection = new ValkeyGlideConnection($client);

        $cursor  = '0';
        $members = [];

        do {
            [$cursor, $page] = self::memberScan($connection, $method, 'set', $cursor, ['match' => '*', 'count' => 1000]);
            $members         = [...$members, ...$page];
        } while ($cursor !== '0');

        self::assertSame($expected, $members);
        self::assertSame(['0', '12', '30'], array_column($client->callsFor($method), 1));
    }

    /**
     * Verify a member scan dispatches a command event carrying the prefixed key
     * and the cursor GLIDE wrote back.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('memberScanMethods')]
    #[Test]
    public function memberScanDispatchesACommandEventWithTheNextCursor(string $method): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan($method, [['42', []]]);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);
        $dispatcher = new Dispatcher;
        $events     = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $connection->setEventDispatcher($dispatcher);
        self::memberScan($connection, $method, 'set', '0', ['match' => 'member:*']);

        self::assertCount(1, $events);
        self::assertSame($method, $events[0]->command);
        self::assertSame(['app:set', '42', 'member:*', 10], $events[0]->parameters);
    }

    /**
     * Verify a member scan is refused inside a pipeline or transaction before
     * anything is sent, as the connection refuses the SCAN family there.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('memberScanMethods')]
    #[Test]
    public function memberScanIsRefusedInsideABatch(string $method): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        foreach ([static fn (): mixed => $connection->pipeline(), static fn (): mixed => $connection->transaction()] as $open) {
            $open();

            try {
                self::memberScan($connection, $method, 'set', '0', ['match' => '*']);
                self::fail('Expected the scan to be refused.');
            } catch (\LogicException $exception) {
                self::assertSame(sprintf(self::REFUSED_IN_BATCH_MESSAGE, strtoupper($method)), $exception->getMessage());
            }

            self::assertSame([], $connection->exec());
        }

        self::assertSame([], $client->callOrder());
    }

    /**
     * Provide each member scan with a page in the shape GLIDE answers it.
     *
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function glideMemberScanPages(): iterable
    {
        foreach (self::memberScans() as $name => [$method, $page]) {
            yield $name => [$method, $page];
        }
    }

    /**
     * Verify GLIDE's own member scan form, through command() with the cursor by
     * reference, still returns the members as GLIDE answers them and writes the
     * cursor back.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $page
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('glideMemberScanPages')]
    #[Test]
    public function memberScanThroughCommandWritesTheCursorBack(string $method, array $page): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan($method, [['17', $page]]);

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        /** @var string $cursor */
        $cursor = '0';

        self::assertSame($page, $connection->command($method, ['set', &$cursor, '*', 100]));
        self::assertSame('17', $cursor);
        self::assertSame([['app:set', '0', '*', 100]], $client->callsFor($method));
    }

    /**
     * Call the named member scan on the connection, leaving the options out
     * when none are given.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $method
     * @param  string  $key
     * @param  mixed  $cursor
     * @param  array<array-key, mixed>|null  $options
     * @return array{0: string, 1: array<array-key, mixed>}
     *
     * @throws \Throwable
     */
    private static function memberScan(ValkeyGlideConnection $connection, string $method, string $key, mixed $cursor, ?array $options = null): array
    {
        if ($options === null) {
            return match ($method) {
                'zscan' => $connection->zscan($key, $cursor),
                'hscan' => $connection->hscan($key, $cursor),
                default => $connection->sscan($key, $cursor),
            };
        }

        return match ($method) {
            'zscan' => $connection->zscan($key, $cursor, $options),
            'hscan' => $connection->hscan($key, $cursor, $options),
            default => $connection->sscan($key, $cursor, $options),
        };
    }
}
