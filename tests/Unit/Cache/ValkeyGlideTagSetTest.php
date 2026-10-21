<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Cache\ValkeyGlideTagSet;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Fakes\LaravelConnectionFake;
use Tests\Fakes\RedisFactoryFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * GLIDE-aware tag set test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideTagSet::class)]
final class ValkeyGlideTagSetTest extends TestCase
{
    /** @var int The timestamp of the frozen test time, 2026-01-01 00:00:00 UTC. */
    private const int NOW = 1767225600;

    /**
     * Freeze the time the tag scores are computed from.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-01 00:00:00');
    }

    /**
     * Release the frozen time.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Verify a tag's sorted set is scanned from cursor '0', with each cursor
     * GLIDE writes back, until it writes '0' back.
     *
     * @return void
     */
    #[Test]
    public function entriesScansEachTagSetFromCursorZeroUntilItReturnsToZero(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['17', ['a' => '1']], ['0', ['b' => '-1']]]);

        self::assertSame(['a', 'b'], $this->tagSet($client, ['t'])->entries()->all());
        self::assertSame([
            ['conn:cache:tag:t:entries', '0', '*', 1000],
            ['conn:cache:tag:t:entries', '17', '*', 1000],
        ], $client->callsFor('zscan'));
    }

    /**
     * Verify every tag's sorted set is scanned, each from cursor '0', in the
     * order of the tags.
     *
     * @return void
     */
    #[Test]
    public function entriesScansEveryTag(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['5', ['x' => '1']], ['0', ['y' => '1']], ['0', ['z' => '1']]]);

        self::assertSame(['x', 'y', 'z'], $this->tagSet($client, ['a', 'b'])->entries()->all());
        self::assertSame([
            ['conn:cache:tag:a:entries', '0', '*', 1000],
            ['conn:cache:tag:a:entries', '5', '*', 1000],
            ['conn:cache:tag:b:entries', '0', '*', 1000],
        ], $client->callsFor('zscan'));
    }

    /**
     * Verify an empty page before the end of the scan does not end it.
     *
     * @return void
     */
    #[Test]
    public function entriesContinuesThroughEmptyPages(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['128', []], ['0', ['x' => '1']]]);

        self::assertSame(['x'], $this->tagSet($client, ['t'])->entries()->all());
        self::assertCount(2, $client->callsFor('zscan'));
    }

    /**
     * Verify members PHP turned into integer array keys come back as strings.
     *
     * @return void
     */
    #[Test]
    public function entriesCastsNumericMembersToStrings(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', [123 => '1', '0042' => '2']]]);

        self::assertSame(['123', '0042'], $this->tagSet($client, ['t'])->entries()->all());
    }

    /**
     * Verify no scan runs until the entries are iterated.
     *
     * @return void
     */
    #[Test]
    public function entriesIsLazy(): void
    {
        $client  = new ValkeyGlideFake;
        $entries = $this->tagSet($client, ['t'])->entries();
        $before  = count($client->callsFor('zscan'));

        $entries->all();

        self::assertSame([0, 1], [$before, count($client->callsFor('zscan'))]);
    }

    /**
     * Verify a failed scan throws at once instead of ending or repeating the
     * scan.
     *
     * @return void
     */
    #[Test]
    public function entriesThrowsWhenTheScanFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', false]]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected array response from zscan on [cache:tag:t:entries], received [bool].');

        try {
            $this->tagSet($client, ['t'])->entries()->all();
        } finally {
            self::assertCount(1, $client->callsFor('zscan'));
        }
    }

    /**
     * Verify a scan answered with the client object, as a batch would, throws.
     *
     * @return void
     */
    #[Test]
    public function entriesThrowsWhenTheScanAnswersWithTheClient(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['17', $client]]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf('Expected array response from zscan on [cache:tag:t:entries], received [%s].', ValkeyGlideFake::class));

        $this->tagSet($client, ['t'])->entries()->all();
    }

    /**
     * Verify any other connection runs Laravel's phpredis-style scan.
     *
     * @return void
     */
    #[Test]
    public function entriesRunsLaravelsScanOverOtherConnections(): void
    {
        $connection = new LaravelConnectionFake;
        $connection->willReturn('zscan', ['0', ['m' => 1]]);

        self::assertSame(['m'], $this->tagSetOver($connection, ['t'])->entries()->all());
        self::assertSame([
            ['zscan', ['cache:tag:t:entries', '0', ['match' => '*', 'count' => 1000]]],
        ], $connection->commands);
    }

    /**
     * Verify an entry is recorded in every tag's sorted set with its expiry as
     * the score.
     *
     * @return void
     */
    #[Test]
    public function addEntryRecordsTheExpiryScore(): void
    {
        $client = new ValkeyGlideFake;

        $this->tagSet($client, ['a', 'b'])->addEntry('item', 60);

        self::assertSame([
            ['conn:cache:tag:a:entries', self::NOW + 60, 'item'],
            ['conn:cache:tag:b:entries', self::NOW + 60, 'item'],
        ], $client->callsFor('zadd'));
    }

    /**
     * Verify an entry stored forever is recorded with score -1.
     *
     * @return void
     */
    #[Test]
    public function addEntryRecordsForeverEntriesWithScoreMinusOne(): void
    {
        $client = new ValkeyGlideFake;

        $this->tagSet($client, ['t'])->addEntry('item');

        self::assertSame([['conn:cache:tag:t:entries', -1, 'item']], $client->callsFor('zadd'));
    }

    /**
     * Verify an update condition is sent as GLIDE's options array, which GLIDE
     * honours, rather than the string it ignores.
     *
     * @return void
     */
    #[Test]
    public function addEntryPassesUpdateWhenAsGlideOptions(): void
    {
        $client = new ValkeyGlideFake;

        $this->tagSet($client, ['t'])->addEntry('item', updateWhen: 'NX');

        self::assertSame([['conn:cache:tag:t:entries', ['NX'], -1, 'item']], $client->callsFor('zadd'));
    }

    /**
     * Verify a failed ZADD throws rather than leave the entry untracked.
     *
     * @return void
     */
    #[Test]
    public function addEntryThrowsWhenZaddFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zadd', false);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Valkey GLIDE failed to run ZADD on [cache:tag:t:entries].');

        $this->tagSet($client, ['t'])->addEntry('item');
    }

    /**
     * Verify any other connection runs Laravel's ZADD, string option included.
     *
     * @return void
     */
    #[Test]
    public function addEntryRunsLaravelsOverOtherConnections(): void
    {
        $connection = new LaravelConnectionFake;

        $this->tagSetOver($connection, ['t'])->addEntry('item', updateWhen: 'NX');

        self::assertSame([['zadd', ['cache:tag:t:entries', 'NX', -1, 'item']]], $connection->commands);
    }

    /**
     * Verify each tag's expired entries are removed with its own command, with
     * string bounds from 0 to now.
     *
     * @return void
     */
    #[Test]
    public function flushStaleEntriesRemovesExpiredMembersOfEachTag(): void
    {
        $client = new ValkeyGlideFake;

        $this->tagSet($client, ['a', 'b'])->flushStaleEntries();

        self::assertSame([
            ['conn:cache:tag:a:entries', '0', (string) self::NOW],
            ['conn:cache:tag:b:entries', '0', (string) self::NOW],
        ], $client->callsFor('zremrangebyscore'));
    }

    /**
     * Verify a failed removal throws at once.
     *
     * @return void
     */
    #[Test]
    public function flushStaleEntriesThrowsWhenTheRemovalFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('zremrangebyscore', false);

        try {
            $this->tagSet($client, ['a', 'b'])->flushStaleEntries();
            self::fail('A failed removal must throw.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Valkey GLIDE failed to run ZREMRANGEBYSCORE on [cache:tag:a:entries].', $exception->getMessage());
        }

        self::assertCount(1, $client->callsFor('zremrangebyscore'));
    }

    /**
     * Verify any other connection runs Laravel's pipelined removal.
     *
     * @return void
     */
    #[Test]
    public function flushStaleEntriesRunsLaravelsOverOtherConnections(): void
    {
        $connection = new LaravelConnectionFake;

        $this->tagSetOver($connection, ['t'])->flushStaleEntries();

        self::assertSame('pipeline', $connection->commands[0][0] ?? null);

        $callback = $connection->commands[0][1][0] ?? null;

        self::assertInstanceOf(\Closure::class, $callback);

        $callback($connection);

        self::assertSame(['zremrangebyscore', ['cache:tag:t:entries', 0, self::NOW]], $connection->commands[1] ?? null);
        self::assertCount(2, $connection->commands);
    }

    /**
     * Verify a reset deletes the entries, then removes them from every tag's
     * sorted set, and leaves the sorted sets themselves to the server.
     *
     * @return void
     */
    #[Test]
    public function resetDeletesEntriesThenRemovesThemFromEachTag(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'conn:']);
        $commands   = $this->recordCommands($connection);

        $client->willScan('zscan', [['0', ['m1' => '1', 'm2' => '1']], ['0', ['m3' => '-1']]]);

        $this->tagSetOver($connection, ['a', 'b'])->reset();

        self::assertSame(['zscan', 'zscan', 'del', 'zrem', 'zrem'], array_column($commands->getArrayCopy(), 0));
        self::assertSame([['conn:cache:m1', 'conn:cache:m2', 'conn:cache:m3']], $client->callsFor('del'));
        self::assertSame([
            ['conn:cache:tag:a:entries', 'm1', 'm2', 'm3'],
            ['conn:cache:tag:b:entries', 'm1', 'm2', 'm3'],
        ], $client->callsFor('zrem'));
    }

    /**
     * Verify a reset deletes a thousand entries at a time.
     *
     * @return void
     */
    #[Test]
    public function resetDeletesInChunksOfAThousand(): void
    {
        $client  = new ValkeyGlideFake;
        $members = array_map(static fn (int $index): string => 'k' . $index, range(0, 1000));

        $client->willScan('zscan', [
            ['9', array_fill_keys(array_slice($members, 0, 1000), '1')],
            ['0', ['k1000' => '1']],
        ]);

        $this->tagSet($client, ['a', 'b'])->reset();

        $deletes = $client->callsFor('del');

        self::assertCount(2, $deletes);
        self::assertSame(array_map(static fn (string $member): string => 'conn:cache:' . $member, array_slice($members, 0, 1000)), $deletes[0]);
        self::assertSame(['conn:cache:k1000'], $deletes[1]);
        self::assertSame([
            ['conn:cache:tag:a:entries', ...array_slice($members, 0, 1000)],
            ['conn:cache:tag:b:entries', ...array_slice($members, 0, 1000)],
            ['conn:cache:tag:a:entries', 'k1000'],
            ['conn:cache:tag:b:entries', 'k1000'],
        ], $client->callsFor('zrem'));
    }

    /**
     * Verify a reset of tags without entries sends no write.
     *
     * @return void
     */
    #[Test]
    public function resetDoesNothingForEmptyTagSets(): void
    {
        $client = new ValkeyGlideFake;

        $this->tagSet($client, ['a', 'b'])->reset();

        self::assertCount(2, $client->callsFor('zscan'));
        self::assertSame([], $client->callsFor('del'));
        self::assertSame([], $client->callsFor('zrem'));
    }

    /**
     * Verify a failed DEL throws before any entry is removed from the tags, so
     * the entries stay tracked and a retry deletes them.
     *
     * @return void
     */
    #[Test]
    public function resetThrowsBeforeRemovingMembersWhenDeleteFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', ['m1' => '1', 'm2' => '1', 'm3' => '1']]]);
        $client->willReturn('del', false);

        try {
            $this->tagSet($client, ['t'])->reset();
            self::fail('A failed delete must throw.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Valkey GLIDE failed to run DEL on [cache:m1] and 2 more.', $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('zrem'));
    }

    /**
     * Verify a failed DEL of a single entry names only that entry.
     *
     * @return void
     */
    #[Test]
    public function resetNamesTheOnlyEntryWhenItsDeleteFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', ['m1' => '1']]]);
        $client->willReturn('del', false);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Valkey GLIDE failed to run DEL on [cache:m1].');

        $this->tagSet($client, ['t'])->reset();
    }

    /**
     * Verify a failed ZREM throws.
     *
     * @return void
     */
    #[Test]
    public function resetThrowsWhenRemovingMembersFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', ['m1' => '1', 'm2' => '1']]]);
        $client->willReturn('zrem', false);

        try {
            $this->tagSet($client, ['a', 'b'])->reset();
            self::fail('A failed removal must throw.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Valkey GLIDE failed to run ZREM on [cache:tag:a:entries].', $exception->getMessage());
        }

        self::assertCount(1, $client->callsFor('zrem'));
    }

    /**
     * Verify any other connection runs Laravel's reset, which forgets each
     * tag's sorted set.
     *
     * @return void
     */
    #[Test]
    public function resetRunsLaravelsOverOtherConnections(): void
    {
        $connection = new LaravelConnectionFake;

        $this->tagSetOver($connection, ['a', 'b'])->reset();

        self::assertSame([
            ['del', ['cache:tag:a:entries']],
            ['del', ['cache:tag:b:entries']],
        ], $connection->commands);
    }

    /**
     * Record the name and parameters of every command the connection runs, in
     * order.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return \ArrayObject<int, array{string, array<array-key, mixed>}>
     */
    private function recordCommands(ValkeyGlideConnection $connection): \ArrayObject
    {
        /** @var \ArrayObject<int, array{string, array<array-key, mixed>}> $commands */
        $commands = new \ArrayObject;
        $events   = new Dispatcher;

        $events->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($commands): void {
            $commands->append([$event->command, $event->parameters]);
        });

        $connection->setEventDispatcher($events);

        return $commands;
    }

    /**
     * Build a tag set over a GLIDE connection with the `conn:` prefix to the
     * given client.
     *
     * @param  \ValkeyGlide|\ValkeyGlideCluster  $client
     * @param  array<int, string>  $names
     * @return \SineMacula\Valkey\Cache\ValkeyGlideTagSet
     */
    private function tagSet(\ValkeyGlide|\ValkeyGlideCluster $client, array $names): ValkeyGlideTagSet
    {
        return $this->tagSetOver(new ValkeyGlideConnection($client, null, ['prefix' => 'conn:']), $names);
    }

    /**
     * Build a tag set over the given connection, in a store with the `cache:`
     * prefix.
     *
     * @param  \Illuminate\Redis\Connections\Connection  $connection
     * @param  array<int, string>  $names
     * @return \SineMacula\Valkey\Cache\ValkeyGlideTagSet
     */
    private function tagSetOver(Connection $connection, array $names): ValkeyGlideTagSet
    {
        return new ValkeyGlideTagSet(new ValkeyGlideStore(new RedisFactoryFake($connection), 'cache:'), $names);
    }
}
