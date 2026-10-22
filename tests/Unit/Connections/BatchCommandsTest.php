<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\BatchCommands;

/**
 * Batch command classifier test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(BatchCommands::class)]
final class BatchCommandsTest extends TestCase
{
    /**
     * Provide commands and whether a batch refuses them.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'scan' => ['scan', true];
        yield 'hscan' => ['hscan', true];
        yield 'sscan' => ['sscan', true];
        yield 'zscan' => ['zScan', true];
        yield 'select' => ['select', true];
        yield 'subscribe' => ['subscribe', true];
        yield 'psubscribe' => ['psubscribe', true];
        yield 'ssubscribe' => ['ssubscribe', true];
        yield 'unsubscribe' => ['unsubscribe', true];
        yield 'punsubscribe' => ['PUNSUBSCRIBE', true];
        yield 'buffered command' => ['set', false];
        yield 'immediate command' => ['eval', false];
    }

    /**
     * Verify isRefused flags the commands a batch cannot carry.
     *
     * @param  string  $command
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('refusedProvider')]
    #[Test]
    public function isRefusedFlagsCommandsABatchCannotCarry(string $command, bool $expected): void
    {
        self::assertSame($expected, (new BatchCommands)->isRefused($command));
    }

    /**
     * Provide commands, the client type, and whether GLIDE runs them
     * immediately in batch mode.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function immediateProvider(): iterable
    {
        yield 'eval' => ['eval', false, true];
        yield 'evalsha' => ['evalSha', false, true];
        yield 'eval_ro' => ['eval_ro', false, true];
        yield 'evalsha_ro' => ['evalsha_ro', false, true];
        yield 'rawcommand' => ['rawCommand', false, true];
        yield 'publish' => ['publish', false, true];
        yield 'pubsub' => ['pubsub', false, true];
        yield 'getoption' => ['getOption', false, true];
        yield 'setoption' => ['setOption', false, true];
        yield 'getstatistics' => ['getStatistics', false, true];
        yield 'password update' => ['updateConnectionPassword', false, true];
        yield 'password clear' => ['clearConnectionPassword', false, true];
        yield 'iam refresh' => ['refreshIamToken', false, true];
        yield 'otel sample percentage read' => ['getOtelSamplePercentage', false, true];
        yield 'otel sample percentage write' => ['setOtelSamplePercentage', false, true];
        yield 'phpredis alias registration' => ['registerPHPRedisAliases', false, true];
        yield 'last error read' => ['getLastError', false, true];
        yield 'last error clear' => ['clearLastError', false, true];
        yield 'script family' => ['scriptLoad', false, true];
        yield 'script' => ['script', false, true];
        yield 'function family' => ['functionLoad', false, true];
        yield 'search family' => ['ftSearch', false, true];
        yield 'cache statistics family' => ['getCacheHitRate', false, true];
        yield 'fcall buffers' => ['fcall', false, false];
        yield 'set buffers' => ['set', false, false];
        yield 'standalone ping buffers' => ['ping', false, false];
        yield 'cluster ping' => ['ping', true, true];
        yield 'cluster echo' => ['echo', true, true];
        yield 'cluster info' => ['info', true, true];
        yield 'cluster dbsize' => ['dbSize', true, true];
        yield 'cluster time' => ['time', true, true];
        yield 'cluster randomkey' => ['randomKey', true, true];
        yield 'cluster flushdb' => ['flushDB', true, true];
        yield 'cluster flushall' => ['flushAll', true, true];
        yield 'cluster client' => ['client', true, true];
        yield 'cluster set buffers' => ['set', true, false];
        yield 'cluster eval' => ['eval', true, true];
    }

    /**
     * Verify runsImmediately flags the commands GLIDE does not buffer.
     *
     * @param  string  $command
     * @param  bool  $cluster
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('immediateProvider')]
    #[Test]
    public function runsImmediatelyFlagsCommandsGlideDoesNotBuffer(string $command, bool $cluster, bool $expected): void
    {
        self::assertSame($expected, (new BatchCommands)->runsImmediately($command, $cluster));
    }

    /**
     * Verify an empty key list is kept rather than spread into no keys at all,
     * so GLIDE refuses it as it does outside a batch.
     *
     * @return void
     */
    #[Test]
    public function emptyKeyListIsNotSpread(): void
    {
        $commands = new BatchCommands;

        self::assertSame([[]], $commands->spreadKeyList([[]]));
        self::assertSame([[]], $commands->rewrite('unlink', [[]]));
    }

    /**
     * Provide commands and whether they are client-local methods.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function localProvider(): iterable
    {
        yield 'getoption' => ['getOption', true];
        yield 'setoption' => ['SETOPTION', true];
        yield 'getstatistics' => ['getStatistics', true];
        yield 'otel sample percentage read' => ['getOtelSamplePercentage', true];
        yield 'otel sample percentage write' => ['setOtelSamplePercentage', true];
        yield 'phpredis alias registration' => ['registerPHPRedisAliases', true];
        yield 'last error read' => ['getLastError', true];
        yield 'last error clear' => ['clearLastError', true];
        yield 'cache statistics family' => ['getCacheHitRate', true];
        yield 'eval reaches the server' => ['eval', false];
        yield 'password update reaches the server' => ['updateConnectionPassword', false];
        yield 'script family reaches the server' => ['scriptLoad', false];
        yield 'buffered command' => ['get', false];
    }

    /**
     * Verify runsLocally flags the client's own methods only.
     *
     * @param  string  $command
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('localProvider')]
    #[Test]
    public function runsLocallyFlagsTheClientsOwnMethods(string $command, bool $expected): void
    {
        self::assertSame($expected, (new BatchCommands)->runsLocally($command));
    }

    /**
     * Provide commands with their parameters and the buffered form.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function rewriteProvider(): iterable
    {
        yield 'del key list is spread' => ['del', [['a', 'b']], ['a', 'b']];
        yield 'del integer key list is spread as strings' => ['del', [[1, 2]], ['1', '2']];
        yield 'unlink key list is spread' => ['UNLINK', [['a', 'b']], ['a', 'b']];
        yield 'del string-keyed key list is spread' => ['del', [['x' => 'a', 'y' => 'b']], ['a', 'b']];
        yield 'del named key list is spread' => ['del', ['keys' => ['a']], ['a']];
        yield 'del variadic keys are kept' => ['del', ['a', 'b'], ['a', 'b']];
        yield 'del empty key list is kept' => ['del', [[]], [[]]];
        yield 'unlink empty key list is kept' => ['unlink', [[]], [[]]];
        yield 'del key list beside other keys is kept' => ['del', [['a'], 'b'], [['a'], 'b']];
        yield 'mget key list is kept' => ['mget', [['a', 'b']], [['a', 'b']]];
        yield 'set expiry and flag' => ['set', ['k', 'v', 'EX', 60, 'NX'], ['k', 'v', ['EX' => 60, 'NX']]];
        yield 'set lower-case options' => ['SET', ['k', 'v', 'nx', 'px', 500], ['k', 'v', ['NX', 'PX' => 500]]];
        yield 'set numeric string expiry is cast' => ['set', ['k', 'v', 'EX', '60'], ['k', 'v', ['EX' => 60]]];
        yield 'set float expiry is left' => ['set', ['k', 'v', 'PX', 1500.9], ['k', 'v', 'PX', 1500.9]];
        yield 'set whole float expiry is left' => ['set', ['k', 'v', 'EX', 60.0], ['k', 'v', 'EX', 60.0]];
        yield 'set decimal string expiry is left' => ['set', ['k', 'v', 'EX', '7.5'], ['k', 'v', 'EX', '7.5']];
        yield 'set exponent string expiry is left' => ['set', ['k', 'v', 'EX', '1e3'], ['k', 'v', 'EX', '1e3']];
        yield 'set padded string expiry is left' => ['set', ['k', 'v', 'EX', ' 60'], ['k', 'v', 'EX', ' 60']];
        yield 'set leading zero string expiry is left' => ['set', ['k', 'v', 'EX', '060'], ['k', 'v', 'EX', '060']];
        yield 'set signed string expiry is left' => ['set', ['k', 'v', 'EX', '+60'], ['k', 'v', 'EX', '+60']];
        yield 'set overflowing string expiry is left' => ['set', ['k', 'v', 'EX', '99999999999999999999'], ['k', 'v', 'EX', '99999999999999999999']];
        yield 'set boolean expiry is left' => ['set', ['k', 'v', 'EX', true], ['k', 'v', 'EX', true]];
        yield 'set zero string expiry is left' => ['set', ['k', 'v', 'EX', '0'], ['k', 'v', 'EX', '0']];
        yield 'set negative string expiry is left' => ['set', ['k', 'v', 'EX', '-5'], ['k', 'v', 'EX', '-5']];
        yield 'set float unix expiry is left' => ['set', ['k', 'v', 'EXAT', 1700000000.5], ['k', 'v', 'EXAT', 1700000000.5]];
        yield 'set one second expiry' => ['set', ['k', 'v', 'EX', 1], ['k', 'v', ['EX' => 1]]];
        yield 'set one second string expiry' => ['set', ['k', 'v', 'EX', '1'], ['k', 'v', ['EX' => 1]]];
        yield 'set unix expiry' => ['set', ['k', 'v', 'EXAT', 1700000000], ['k', 'v', ['EXAT' => 1700000000]]];
        yield 'set unix millisecond expiry' => ['set', ['k', 'v', 'PXAT', 1700000000000], ['k', 'v', ['PXAT' => 1700000000000]]];
        yield 'set flags' => ['set', ['k', 'v', 'XX', 'KEEPTTL', 'GET'], ['k', 'v', ['XX', 'KEEPTTL', 'GET']]];
        yield 'set named parameters' => ['set', ['key' => 'k', 'value' => 'v', 'ex' => 'EX', 'ttl' => 5], ['k', 'v', ['EX' => 5]]];
        yield 'set zero expiry is left' => ['set', ['k', 'v', 'EX', 0], ['k', 'v', 'EX', 0]];
        yield 'set negative expiry is left' => ['set', ['k', 'v', 'EX', -1], ['k', 'v', 'EX', -1]];
        yield 'set non-numeric expiry is left' => ['set', ['k', 'v', 'EX', 'soon'], ['k', 'v', 'EX', 'soon']];
        yield 'set expiry without a value is left' => ['set', ['k', 'v', 'NX', 'EX'], ['k', 'v', 'NX', 'EX']];
        yield 'set unknown option is left' => ['set', ['k', 'v', 'FOREVER', 1], ['k', 'v', 'FOREVER', 1]];
        yield 'set non-string option is left' => ['set', ['k', 'v', 10, 'NX'], ['k', 'v', 10, 'NX']];
        yield 'set with an options array is kept' => ['set', ['k', 'v', ['NX']], ['k', 'v', ['NX']]];
        yield 'set with a ttl is kept' => ['set', ['k', 'v', 60], ['k', 'v', 60]];
        yield 'set single flag' => ['set', ['k', 'v', 'NX'], ['k', 'v', ['NX']]];
        yield 'set single lower-case flag' => ['set', ['k', 'v', 'keepttl'], ['k', 'v', ['KEEPTTL']]];
        yield 'set single get flag' => ['set', ['k', 'v', 'GET'], ['k', 'v', ['GET']]];
        yield 'set single named flag' => ['set', ['key' => 'k', 'value' => 'v', 'options' => 'XX'], ['k', 'v', ['XX']]];
        yield 'set single flag with a non-string value' => ['set', ['k', 1, 'NX'], ['k', '1', ['NX']]];
        yield 'set single unknown string option is left' => ['set', ['k', 'v', 'FOREVER'], ['k', 'v', 'FOREVER']];
        yield 'set single expiry option without a value is left' => ['set', ['k', 'v', 'EX'], ['k', 'v', 'EX']];
        yield 'set with a null option is kept' => ['set', ['k', 'v', null], ['k', 'v', null]];
        yield 'set the same expiry twice keeps the last' => ['set', ['k', 'v', 'EX', 60, 'EX', 120], ['k', 'v', ['EX' => 120]]];
        yield 'set two expiries are left' => ['set', ['k', 'v', 'EX', 60, 'PX', 100000], ['k', 'v', 'EX', 60, 'PX', 100000]];
        yield 'set two unix expiries are left' => ['set', ['k', 'v', 'PX', 5000, 'EXAT', 1700000000], ['k', 'v', 'PX', 5000, 'EXAT', 1700000000]];
        yield 'set two lower-case expiries around a flag are left' => ['set', ['k', 'v', 'pxat', 1700000000000, 'NX', 'ex', 60], ['k', 'v', 'pxat', 1700000000000, 'NX', 'ex', 60]];
        yield 'set false value is sent as a raw command sends it' => ['set', ['k', false, 'EX', 60], ['k', '', ['EX' => 60]]];
        yield 'set true value is sent as a raw command sends it' => ['set', ['k', true, 'NX'], ['k', '1', ['NX']]];
        yield 'set null value is sent as a raw command sends it' => ['set', ['k', null, 'NX'], ['k', '', ['NX']]];
        yield 'set large float value is sent as a raw command sends it' => ['set', ['k', 1e20, 'EX', 60], ['k', '1.0E+20', ['EX' => 60]]];
        yield 'set stringable value is sent as a raw command sends it' => ['set', ['k', new \SimpleXMLElement('<root>node</root>'), 'NX'], ['k', 'node', ['NX']]];
        yield 'set array value is left' => ['set', ['k', ['a'], 'NX'], ['k', ['a'], 'NX']];
        yield 'set value of an options array call is kept' => ['set', ['k', false, ['NX']], ['k', false, ['NX']]];
        yield 'set without options is kept' => ['set', ['k', 'v'], ['k', 'v']];
        yield 'other command is kept' => ['zadd', ['z', 1, 'm'], ['z', 1, 'm']];
        yield 'other command with set-like options is kept' => ['zadd', ['z', 'v', 'NX', 'XX'], ['z', 'v', 'NX', 'XX']];
    }

    /**
     * Verify rewrite reshapes commands into the form GLIDE buffers.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $expected
     * @return void
     */
    #[DataProvider('rewriteProvider')]
    #[Test]
    public function rewriteReshapesCommandsIntoTheFormGlideBuffers(string $command, array $parameters, array $expected): void
    {
        self::assertSame($expected, (new BatchCommands)->rewrite($command, $parameters));
    }

    /**
     * Provide commands with their parameters and whether GLIDE would leave them
     * unbuffered while returning the client.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, bool}>
     */
    public static function unbufferedProvider(): iterable
    {
        yield 'zrem without a member' => ['zrem', ['z'], true];
        yield 'upper-case zrem without a member' => ['ZREM', ['z'], true];
        yield 'zrem without arguments' => ['zrem', [], true];
        yield 'zrem with a member' => ['zrem', ['z', 'm'], false];
        yield 'zrem with members' => ['zrem', ['z', 'm', 'n'], false];
        yield 'zmscore without a member' => ['zMscore', ['z'], true];
        yield 'zmscore with a member' => ['zmscore', ['z', 'm'], false];
        yield 'zinter with an empty key list' => ['zinter', [[]], true];
        yield 'upper-case zinter with an empty key list and options' => ['ZINTER', [[], null, ['withscores' => true]], true];
        yield 'zinter with a named empty key list' => ['zInter', ['keys' => []], true];
        yield 'zinter with keys' => ['zinter', [['a']], false];
        yield 'zinter without arguments' => ['zinter', [], false];
        yield 'zinter with a key that is not a list' => ['zinter', ['a'], false];
        yield 'other command with an empty key list' => ['mget', [[]], false];
        yield 'other command with one argument' => ['get', ['k'], false];
        yield 'other command without arguments' => ['ping', [], false];
    }

    /**
     * Verify isUnbuffered flags the calls GLIDE reports as buffered without
     * buffering them.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('unbufferedProvider')]
    #[Test]
    public function isUnbufferedFlagsCallsGlideSilentlyDrops(string $command, array $parameters, bool $expected): void
    {
        self::assertSame($expected, (new BatchCommands)->isUnbuffered($command, $parameters));
    }

    /**
     * Provide parameters and their spread form.
     *
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function spreadKeyListProvider(): iterable
    {
        yield 'key list' => [[['a', 'b']], ['a', 'b']];
        yield 'variadic keys' => [['a', 'b'], ['a', 'b']];
        yield 'single key' => [['a'], ['a']];
        yield 'no keys' => [[], []];
        yield 'empty key list' => [[[]], [[]]];
        yield 'named empty key list' => [['keys' => []], ['keys' => []]];
        yield 'key list beside other keys' => [[['a'], 'b'], [['a'], 'b']];
        yield 'integer keys become strings' => [[[1, 2]], ['1', '2']];
        yield 'scalar and stringable keys become strings' => [[[1.5, true, new \SimpleXMLElement('<root>node</root>')]], ['1.5', '1', 'node']];
        yield 'keys that are not scalar are kept' => [[[null, ['a']]], [null, ['a']]];
    }

    /**
     * Verify spreadKeyList spreads a single key-list argument only.
     *
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $expected
     * @return void
     */
    #[DataProvider('spreadKeyListProvider')]
    #[Test]
    public function spreadKeyListSpreadsASingleKeyListOnly(array $parameters, array $expected): void
    {
        self::assertSame($expected, (new BatchCommands)->spreadKeyList($parameters));
    }
}
