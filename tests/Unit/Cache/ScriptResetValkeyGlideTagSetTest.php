<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Cache\ValkeyGlideTagSet;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Fakes\RedisFactoryFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * GLIDE-aware tag set reset test case, on a standalone server, where each chunk
 * of entries is deleted and removed from the tags in one script.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideTagSet::class)]
final class ScriptResetValkeyGlideTagSetTest extends TestCase
{
    /**
     * Verify a reset sends one script per chunk, naming the prefixed entries,
     * then the prefixed tag sets, as its keys and the members as its arguments,
     * and sends no DEL or ZREM of its own.
     *
     * @return void
     */
    #[Test]
    public function resetDeletesAndRemovesEachChunkInOneScript(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'conn:']);
        $commands   = $this->recordCommands($connection);

        $client->willScan('zscan', [['0', ['m1' => '1', 'm2' => '1']], ['0', ['m3' => '-1']]]);

        $this->tagSetOver($connection, ['a', 'b'])->reset();

        self::assertSame(['zscan', 'zscan', 'eval'], $commands->getArrayCopy());
        self::assertSame([[
            'EVAL', self::script(), 5,
            'conn:cache:m1', 'conn:cache:m2', 'conn:cache:m3', 'conn:cache:tag:a:entries', 'conn:cache:tag:b:entries',
            'm1', 'm2', 'm3',
        ]], $client->callsFor('rawcommand'));
        self::assertSame([], $client->callsFor('del'));
        self::assertSame([], $client->callsFor('zrem'));
    }

    /**
     * Verify the script's keys and arguments stay positional when the tag names
     * are keyed, as the connection reads the keys by position.
     *
     * @return void
     */
    #[Test]
    public function resetKeepsTheScriptArgumentsPositionalForKeyedTagNames(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', ['m' => '1']]]);

        $this->tagSet($client, ['first' => 'a', 'second' => 'b'])->reset();

        self::assertSame([[
            'EVAL', self::script(), 3, 'conn:cache:m', 'conn:cache:tag:a:entries', 'conn:cache:tag:b:entries', 'm',
        ]], $client->callsFor('rawcommand'));
    }

    /**
     * Verify a reset sends one script for each thousand entries.
     *
     * @return void
     */
    #[Test]
    public function resetSendsOneScriptPerThousandEntries(): void
    {
        $client  = new ValkeyGlideFake;
        $members = array_map(static fn (int $index): string => 'k' . $index, range(0, 1000));

        $client->willScan('zscan', [
            ['9', array_fill_keys(array_slice($members, 0, 1000), '1')],
            ['0', ['k1000' => '1']],
        ]);

        $this->tagSet($client, ['a'])->reset();

        $first = array_slice($members, 0, 1000);

        self::assertSame([
            ['EVAL', self::script(), 1001, ...array_map(static fn (string $member): string => 'conn:cache:' . $member, $first), 'conn:cache:tag:a:entries', ...$first],
            ['EVAL', self::script(), 2, 'conn:cache:k1000', 'conn:cache:tag:a:entries', 'k1000'],
        ], $client->callsFor('rawcommand'));
    }

    /**
     * Verify a script that deletes nothing, and so returns 0, is not taken for
     * a failure.
     *
     * @return void
     */
    #[Test]
    public function resetTakesAScriptThatDeletedNothingAsDone(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', ['m' => '1']]]);
        $client->willReturn('rawcommand', 0);

        $this->tagSet($client, ['t'])->reset();

        self::assertCount(1, $client->callsFor('rawcommand'));
    }

    /**
     * Verify a failed script throws, naming its first key and how many more it
     * names, and no later chunk is sent.
     *
     * @return void
     */
    #[Test]
    public function resetThrowsWhenTheScriptFails(): void
    {
        $client  = new ValkeyGlideFake;
        $members = array_map(static fn (int $index): string => 'k' . $index, range(0, 1000));

        $client->willScan('zscan', [['0', array_fill_keys($members, '1')]]);
        $client->willReturn('rawcommand', false);

        try {
            $this->tagSet($client, ['a', 'b'])->reset();
            self::fail('A failed script must throw.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Valkey GLIDE failed to run EVAL on [cache:k0] and 1001 more.', $exception->getMessage());
        }

        self::assertCount(1, $client->callsFor('rawcommand'));
    }

    /**
     * Verify a failed script of a single entry and tag names its first key and
     * the one other.
     *
     * @return void
     */
    #[Test]
    public function resetNamesTheScriptsKeysWhenItFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', ['m' => '1']]]);
        $client->willReturn('rawcommand', false);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Valkey GLIDE failed to run EVAL on [cache:m] and 1 more.');

        $this->tagSet($client, ['t'])->reset();
    }

    /**
     * Read the flush script the tag set sends.
     *
     * @return string
     */
    private static function script(): string
    {
        $script = (new \ReflectionClassConstant(ValkeyGlideTagSet::class, 'FLUSH_SCRIPT'))->getValue();

        self::assertIsString($script);

        return $script;
    }

    /**
     * Record the name of every command the connection runs, in order.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return \ArrayObject<int, string>
     */
    private function recordCommands(ValkeyGlideConnection $connection): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $commands */
        $commands = new \ArrayObject;
        $events   = new Dispatcher;

        $events->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($commands): void {
            $commands->append($event->command);
        });

        $connection->setEventDispatcher($events);

        return $commands;
    }

    /**
     * Build a tag set over a standalone GLIDE connection with the `conn:`
     * prefix to the given client.
     *
     * @param  \ValkeyGlide  $client
     * @param  array<array-key, string>  $names
     * @return \SineMacula\Valkey\Cache\ValkeyGlideTagSet
     */
    private function tagSet(\ValkeyGlide $client, array $names): ValkeyGlideTagSet
    {
        return $this->tagSetOver(new ValkeyGlideConnection($client, null, ['prefix' => 'conn:']), $names);
    }

    /**
     * Build a tag set over the given connection, in a store with the `cache:`
     * prefix.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  array<array-key, string>  $names
     * @return \SineMacula\Valkey\Cache\ValkeyGlideTagSet
     */
    private function tagSetOver(ValkeyGlideConnection $connection, array $names): ValkeyGlideTagSet
    {
        return new ValkeyGlideTagSet(new ValkeyGlideStore(new RedisFactoryFake($connection), 'cache:'), $names);
    }
}
