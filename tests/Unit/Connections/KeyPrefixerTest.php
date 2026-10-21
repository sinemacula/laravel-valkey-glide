<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\KeyPrefixer;

/**
 * Key prefixer test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(KeyPrefixer::class)]
final class KeyPrefixerTest extends TestCase
{
    /**
     * Provide command shapes and the expected prefixed parameters.
     *
     * @return iterable<string, array{0: string, 1: string, 2: array<array-key, mixed>, 3: array<array-key, mixed>}>
     */
    public static function applyProvider(): iterable
    {
        yield 'empty prefix leaves parameters untouched' => ['', 'get', ['user:1'], ['user:1']];
        yield 'empty parameter list is returned as-is' => ['app:', 'get', [], []];
        yield 'single-key command prefixes index zero' => ['app:', 'get', ['user:1'], ['app:user:1']];
        yield 'method name is upper-cased before routing' => ['app:', 'GeT', ['user:1'], ['app:user:1']];
        yield 'integer key is cast then prefixed' => ['app:', 'get', [42], ['app:42']];
        yield 'boolean key is cast then prefixed' => ['app:', 'get', [true], ['app:1']];
        yield 'non-scalar single key is left untouched' => ['app:', 'get', [['nested']], [['nested']]];
        yield 'all-key command prefixes every key' => ['app:', 'del', ['a', 'b', 'c'], ['app:a', 'app:b', 'app:c']];
        yield 'all-key command skips non-scalar entries' => ['app:', 'del', ['a', null, 'c'], ['app:a', null, 'app:c']];
        yield 'del key list prefixes every listed key' => ['app:', 'del', [['a', 'b']], [['app:a', 'app:b']]];
        yield 'unlink key list prefixes every listed key' => ['app:', 'unlink', [['a', 'b']], [['app:a', 'app:b']]];
        yield 'key list skips non-scalar entries' => ['app:', 'del', [['a', null]], [['app:a', null]]];
        yield 'key list beside variadic keys prefixes both' => ['app:', 'del', [['a'], 'b'], [['app:a'], 'app:b']];
        yield 'mget key list prefixes every listed key' => ['app:', 'mget', [['a', 'b']], [['app:a', 'app:b']]];
        yield 'exists prefixes every variadic key' => ['app:', 'exists', ['a', 'b'], ['app:a', 'app:b']];
        yield 'exists key list prefixes every listed key' => ['app:', 'exists', [['a', 'b']], [['app:a', 'app:b']]];
        yield 'watch prefixes every variadic key' => ['app:', 'watch', ['a', 'b'], ['app:a', 'app:b']];
        yield 'watch key list prefixes every listed key' => ['app:', 'watch', [['a', 'b']], [['app:a', 'app:b']]];
        yield 'touch key list prefixes every listed key' => ['app:', 'touch', [['a', 'b']], [['app:a', 'app:b']]];
        yield 'touch prefixes every key' => ['app:', 'touch', ['a', 'b'], ['app:a', 'app:b']];
        yield 'sinter key list prefixes every listed key' => ['app:', 'sInter', [['a', 'b']], [['app:a', 'app:b']]];
        yield 'sunion does not prefix a list argument' => ['app:', 'sunion', [['a']], [['a']]];
        yield 'key list after a variadic key prefixes both' => ['app:', 'del', ['b', ['a']], ['app:b', ['app:a']]];
        yield 'hmset prefixes its hash key' => ['app:', 'hmset', ['job:1', ['status' => 'pending']], ['app:job:1', ['status' => 'pending']]];
        yield 'zremrangebyscore prefixes its key' => ['app:', 'zRemRangeByScore', ['recent', 0, 10], ['app:recent', 0, 10]];
        yield 'zremrangebyrank prefixes its key' => ['app:', 'zremrangebyrank', ['metrics', 0, -10], ['app:metrics', 0, -10]];
        yield 'zrevrangebyscore prefixes its key' => ['app:', 'zRevRangeByScore', ['supervisors', '+inf', 0], ['app:supervisors', '+inf', 0]];
        yield 'zrevrank prefixes its key' => ['app:', 'zrevrank', ['board', 'member'], ['app:board', 'member']];
        yield 'psetex prefixes its key' => ['app:', 'psetex', ['key', 1000, 'value'], ['app:key', 1000, 'value']];
        yield 'mset prefixes the keys of its map' => ['app:', 'mset', [['a' => '1', 7 => '2']], [['app:a' => '1', 'app:7' => '2']]];
        yield 'msetnx prefixes the keys of its map' => ['app:', 'MSETNX', [['a' => '1']], [['app:a' => '1']]];
        yield 'mset without a map is left untouched' => ['app:', 'mset', ['a', '1'], ['a', '1']];
        yield 'mset keeps arguments after its map' => ['app:', 'mset', [['a' => '1'], 'extra'], [['app:a' => '1'], 'extra']];
        yield 'scan match pattern is left untouched' => ['app:', 'scan', [0, 'MATCH', 'user:*'], [0, 'MATCH', 'user:*']];
        yield 'double-key command prefixes both keys' => ['app:', 'rename', ['old', 'new'], ['app:old', 'app:new']];
        yield 'double-key command tolerates missing second key' => ['app:', 'rename', ['old'], ['app:old']];
        yield 'bitop prefixes the destination and source keys' => ['app:', 'bitop', ['AND', 'dest', 'a', 'b'], ['AND', 'app:dest', 'app:a', 'app:b']];
        yield 'bitop with only an operation is left untouched' => ['app:', 'bitop', ['NOT'], ['NOT']];
        yield 'unknown command is left untouched' => ['app:', 'ping', ['payload'], ['payload']];
        yield 'eval prefixes one declared key' => ['app:', 'eval', ['return 1', 1, 'queue', 'arg'], ['return 1', 1, 'app:queue', 'arg']];
        yield 'eval prefixes multiple declared keys' => ['app:', 'eval', ['return 1', 2, 'k1', 'k2', 'arg'], ['return 1', 2, 'app:k1', 'app:k2', 'arg']];
        yield 'eval with zero keys is left untouched' => ['app:', 'eval', ['return 1', 0, 'arg'], ['return 1', 0, 'arg']];
        yield 'eval with missing key count is untouched' => ['app:', 'eval', ['return 1'], ['return 1']];
        yield 'eval with non-numeric key count untouched' => ['app:', 'eval', ['return 1', 'nope', 'k1'], ['return 1', 'nope', 'k1']];
        yield 'evalsha prefixes declared keys' => ['app:', 'evalsha', ['sha1', 1, 'queue'], ['sha1', 1, 'app:queue']];
        yield 'tag pruning zremrangebyscore prefixes its key' => ['app:', 'zremrangebyscore', ['t', '0', '9'], ['app:t', '0', '9']];
        yield 'zremrangebyrank used by tags prefixes its key' => ['app:', 'zremrangebyrank', ['t', 0, -1], ['app:t', 0, -1]];
        yield 'zrevrangebyscore single key is prefixed' => ['app:', 'zrevrangebyscore', ['t', '+inf', '-inf'], ['app:t', '+inf', '-inf']];
        yield 'zrevrank single key is prefixed' => ['app:', 'zrevrank', ['t', 'member'], ['app:t', 'member']];
        yield 'zscan prefixes only its key' => ['app:', 'zscan', ['t', '0', '*', 10], ['app:t', '0', '*', 10]];
        yield 'sscan prefixes only its key' => ['app:', 'sscan', ['t', '0', '*', 10], ['app:t', '0', '*', 10]];
        yield 'hscan prefixes only its key' => ['app:', 'hscan', ['t', '0', '*', 10], ['app:t', '0', '*', 10]];
        yield 'scan leaves its cursor and pattern untouched' => ['app:', 'scan', ['0', 'p:*', 10], ['0', 'p:*', 10]];
    }

    /**
     * Verify apply routes each command family to the correct prefixing
     * strategy.
     *
     * @param  string  $prefix
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $expected
     * @return void
     */
    #[DataProvider('applyProvider')]
    #[Test]
    public function applyPrefixesParametersForEachCommandFamily(string $prefix, string $method, array $parameters, array $expected): void
    {
        self::assertSame($expected, (new KeyPrefixer($prefix))->apply($method, $parameters));
    }

    /**
     * Verify a member scan's cursor stays a reference once its key is prefixed,
     * so the cursor GLIDE writes back reaches the caller.
     *
     * @return void
     */
    #[Test]
    public function applyKeepsTheScanCursorAReference(): void
    {
        /** @var string $cursor */
        $cursor = '0';

        $out    = (new KeyPrefixer('app:'))->apply('zscan', ['t', &$cursor, '*', 10]);
        $out[1] = '42';

        self::assertSame('42', $cursor);
        self::assertSame('app:t', $out[0]);
    }

    /**
     * Verify the configured prefix is returned as given, empty included.
     *
     * @return void
     */
    #[Test]
    public function prefixReturnsTheConfiguredPrefix(): void
    {
        self::assertSame('app:', (new KeyPrefixer('app:'))->prefix());
        self::assertSame('', (new KeyPrefixer(''))->prefix());
    }

    /**
     * Provide every command whose key is its first argument.
     *
     * @return iterable<string, array{string}>
     */
    public static function singleKeyCommands(): iterable
    {
        yield 'append' => ['append'];
        yield 'decr' => ['decr'];
        yield 'decrby' => ['decrby'];
        yield 'dump' => ['dump'];
        yield 'expire' => ['expire'];
        yield 'expireat' => ['expireat'];
        yield 'get' => ['get'];
        yield 'getbit' => ['getbit'];
        yield 'getdel' => ['getdel'];
        yield 'getex' => ['getex'];
        yield 'getrange' => ['getrange'];
        yield 'getset' => ['getset'];
        yield 'hdel' => ['hdel'];
        yield 'hexists' => ['hexists'];
        yield 'hget' => ['hget'];
        yield 'hgetall' => ['hgetall'];
        yield 'hincrby' => ['hincrby'];
        yield 'hincrbyfloat' => ['hincrbyfloat'];
        yield 'hkeys' => ['hkeys'];
        yield 'hlen' => ['hlen'];
        yield 'hmget' => ['hmget'];
        yield 'hmset' => ['hmset'];
        yield 'hscan' => ['hscan'];
        yield 'hset' => ['hset'];
        yield 'hsetnx' => ['hsetnx'];
        yield 'hstrlen' => ['hstrlen'];
        yield 'hvals' => ['hvals'];
        yield 'incr' => ['incr'];
        yield 'incrby' => ['incrby'];
        yield 'incrbyfloat' => ['incrbyfloat'];
        yield 'lindex' => ['lindex'];
        yield 'linsert' => ['linsert'];
        yield 'llen' => ['llen'];
        yield 'lpop' => ['lpop'];
        yield 'lpos' => ['lpos'];
        yield 'lpush' => ['lpush'];
        yield 'lpushx' => ['lpushx'];
        yield 'lrange' => ['lrange'];
        yield 'lrem' => ['lrem'];
        yield 'lset' => ['lset'];
        yield 'ltrim' => ['ltrim'];
        yield 'move' => ['move'];
        yield 'persist' => ['persist'];
        yield 'pexpire' => ['pexpire'];
        yield 'pexpireat' => ['pexpireat'];
        yield 'psetex' => ['psetex'];
        yield 'pttl' => ['pttl'];
        yield 'rpop' => ['rpop'];
        yield 'rpush' => ['rpush'];
        yield 'rpushx' => ['rpushx'];
        yield 'sadd' => ['sadd'];
        yield 'scard' => ['scard'];
        yield 'set' => ['set'];
        yield 'setbit' => ['setbit'];
        yield 'setex' => ['setex'];
        yield 'setnx' => ['setnx'];
        yield 'setrange' => ['setrange'];
        yield 'sismember' => ['sismember'];
        yield 'smembers' => ['smembers'];
        yield 'spop' => ['spop'];
        yield 'srem' => ['srem'];
        yield 'sscan' => ['sscan'];
        yield 'strlen' => ['strlen'];
        yield 'ttl' => ['ttl'];
        yield 'type' => ['type'];
        yield 'zadd' => ['zadd'];
        yield 'zcard' => ['zcard'];
        yield 'zcount' => ['zcount'];
        yield 'zincrby' => ['zincrby'];
        yield 'zrange' => ['zrange'];
        yield 'zrangebyscore' => ['zrangebyscore'];
        yield 'zrank' => ['zrank'];
        yield 'zrem' => ['zrem'];
        yield 'zremrangebyrank' => ['zremrangebyrank'];
        yield 'zremrangebyscore' => ['zremrangebyscore'];
        yield 'zrevrangebyscore' => ['zrevrangebyscore'];
        yield 'zrevrank' => ['zrevrank'];
        yield 'zscan' => ['zscan'];
        yield 'zscore' => ['zscore'];
    }

    /**
     * Verify every single-key command prefixes its first argument only.
     *
     * @param  string  $method
     * @return void
     */
    #[DataProvider('singleKeyCommands')]
    #[Test]
    public function singleKeyCommandsPrefixTheirFirstArgumentOnly(string $method): void
    {
        $prefixer = new KeyPrefixer('app:');

        self::assertSame(['app:k', 'x'], $prefixer->apply($method, ['k', 'x']));
        self::assertSame(['k'], $prefixer->keys($method, ['k', 'x']));
    }

    /**
     * Provide every command whose arguments are all keys.
     *
     * @return iterable<string, array{string}>
     */
    public static function allKeyCommands(): iterable
    {
        yield 'sdiff' => ['sdiff'];
        yield 'sunion' => ['sunion'];
    }

    /**
     * Verify every all-key command prefixes each argument, but not the keys of
     * a list argument.
     *
     * @param  string  $method
     * @return void
     */
    #[DataProvider('allKeyCommands')]
    #[Test]
    public function allKeyCommandsPrefixEveryArgument(string $method): void
    {
        $prefixer = new KeyPrefixer('app:');

        self::assertSame(['app:a', 'app:b'], $prefixer->apply($method, ['a', 'b']));
        self::assertSame([['a']], $prefixer->apply($method, [['a']]));
        self::assertSame(['a', 'b'], $prefixer->keys($method, ['a', 'b']));
    }

    /**
     * Provide every command whose keys may also come as a list argument.
     *
     * @return iterable<string, array{string}>
     */
    public static function keyListCommands(): iterable
    {
        yield 'del' => ['del'];
        yield 'exists' => ['exists'];
        yield 'mget' => ['mget'];
        yield 'sinter' => ['sinter'];
        yield 'touch' => ['touch'];
        yield 'unlink' => ['unlink'];
        yield 'watch' => ['watch'];
    }

    /**
     * Verify every key-list command prefixes each argument and each key of a
     * list argument.
     *
     * @param  string  $method
     * @return void
     */
    #[DataProvider('keyListCommands')]
    #[Test]
    public function keyListCommandsPrefixEveryKey(string $method): void
    {
        $prefixer = new KeyPrefixer('app:');

        self::assertSame(['app:a', 'app:b'], $prefixer->apply($method, ['a', 'b']));
        self::assertSame([['app:a', 'app:b']], $prefixer->apply($method, [['a', 'b']]));
        self::assertSame(['a', 'b'], $prefixer->keys($method, [['a', 'b']]));
    }

    /**
     * Provide every command whose keys are its first two arguments.
     *
     * @return iterable<string, array{string}>
     */
    public static function doubleKeyCommands(): iterable
    {
        yield 'brpoplpush' => ['brpoplpush'];
        yield 'copy' => ['copy'];
        yield 'rename' => ['rename'];
        yield 'renamenx' => ['renamenx'];
        yield 'rpoplpush' => ['rpoplpush'];
        yield 'smove' => ['smove'];
    }

    /**
     * Verify every double-key command prefixes its first two arguments only.
     *
     * @param  string  $method
     * @return void
     */
    #[DataProvider('doubleKeyCommands')]
    #[Test]
    public function doubleKeyCommandsPrefixTheirFirstTwoArguments(string $method): void
    {
        $prefixer = new KeyPrefixer('app:');

        self::assertSame(['app:a', 'app:b', 'x'], $prefixer->apply($method, ['a', 'b', 'x']));
        self::assertSame(['a', 'b'], $prefixer->keys($method, ['a', 'b', 'x']));
    }

    /**
     * Verify apply prefixes stringable keys via their string representation.
     *
     * @return void
     */
    #[Test]
    public function applyPrefixesStringableKeys(): void
    {
        $key = new \SimpleXMLElement('<root>node</root>');

        self::assertSame(['app:node'], (new KeyPrefixer('app:'))->apply('get', [$key]));
    }

    /**
     * Provide command shapes and the keys they name.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, list<string>|null}>
     */
    public static function keysProvider(): iterable
    {
        yield 'single-key command' => ['get', ['user:1'], ['user:1']];
        yield 'single-key command ignores its other arguments' => ['set', ['k', 'v', ['NX']], ['k']];
        yield 'single-key command without arguments' => ['get', [], []];
        yield 'method name is upper-cased before matching' => ['zRemRangeByScore', ['z', 0, 1], ['z']];
        yield 'named parameters' => ['get', ['key' => 'user:1'], ['user:1']];
        yield 'integer key is cast to a string' => ['get', [42], ['42']];
        yield 'stringable key is cast to a string' => ['get', [new \SimpleXMLElement('<root>node</root>')], ['node']];
        yield 'non-scalar key is skipped' => ['get', [['nested']], []];
        yield 'double-key command' => ['rename', ['old', 'new', 'extra'], ['old', 'new']];
        yield 'all-key command' => ['unlink', ['a', 'b', 'c'], ['a', 'b', 'c']];
        yield 'all-key command skips non-scalar entries' => ['unlink', [null, 'a'], ['a']];
        yield 'all-key command without a key list' => ['sdiff', ['a', 'b'], ['a', 'b']];
        yield 'all-key command ignores a list argument' => ['sunion', [['a'], 'b'], ['b']];
        yield 'touch key list' => ['touch', [['a', 'b']], ['a', 'b']];
        yield 'sinter key list' => ['sinter', [['a', 'b']], ['a', 'b']];
        yield 'key list' => ['del', [['a', 'b']], ['a', 'b']];
        yield 'named key list' => ['del', ['keys' => ['a', 'b']], ['a', 'b']];
        yield 'empty key list' => ['del', [[]], []];
        yield 'key list beside variadic keys is unknown' => ['exists', [['a', 'b'], 'c'], null];
        yield 'key list after a variadic key is unknown' => ['del', ['a', ['b', 'c']], null];
        yield 'two key lists are unknown' => ['unlink', [['a'], ['b']], null];
        yield 'list inside a key list is unknown' => ['touch', [[['a'], 'b']], null];
        yield 'key list with string keys' => ['mget', [['x' => 'a', 'y' => 'b']], ['a', 'b']];
        yield 'key list skips non-scalar entries' => ['watch', [['a', null, new \stdClass]], ['a']];
        yield 'variadic keys skip a non-scalar entry' => ['watch', ['a', new \stdClass], ['a']];
        yield 'bitop names its destination and sources' => ['bitop', ['AND', 'dest', 'a', 'b'], ['dest', 'a', 'b']];
        yield 'mset names the keys of its map' => ['mset', [['a' => '1', 'b' => '2']], ['a', 'b']];
        yield 'msetnx names the keys of its map' => ['msetnx', [['a' => '1', 7 => '2']], ['a', '7']];
        yield 'mset without a map is unknown' => ['mset', ['a', '1'], null];
        yield 'mset with a named map' => ['mset', ['map' => ['a' => '1']], ['a']];
        yield 'script keys are unknown' => ['eval', ['return 1', 1, 'k'], null];
        yield 'unknown command' => ['zinterstore', ['dest', ['a', 'b']], null];
    }

    /**
     * Verify keys reads the keys from each command family's key positions.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  list<string>|null  $expected
     * @return void
     */
    #[DataProvider('keysProvider')]
    #[Test]
    public function keysResolvesTheKeysOfEachCommandFamily(string $method, array $parameters, ?array $expected): void
    {
        self::assertSame($expected, (new KeyPrefixer(''))->keys($method, $parameters));
    }

    /**
     * Provide key-list command shapes and whether GLIDE refuses to take them as
     * keys.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, bool}>
     */
    public static function unsupportedKeyListProvider(): iterable
    {
        yield 'variadic keys' => ['del', ['a', 'b'], false];
        yield 'single key list' => ['del', [['a', 'b']], false];
        yield 'empty key list' => ['unlink', [[]], false];
        yield 'key list after a variadic key' => ['del', ['a', ['b', 'c']], true];
        yield 'key list before a variadic key' => ['exists', [['a'], 'b'], true];
        yield 'two key lists' => ['unlink', [['a'], ['b']], true];
        yield 'list inside a key list' => ['touch', [[['a'], ['b']]], true];
        yield 'mixed mget' => ['mget', [['a'], 'b'], true];
        yield 'mixed sinter' => ['sInter', ['a', ['b']], true];
        yield 'mixed watch' => ['watch', ['a', ['b']], true];
        yield 'method name is upper-cased before matching' => ['Del', ['a', ['b']], true];
        yield 'command that is not a key-list command' => ['sunion', [['a'], 'b'], false];
        yield 'command whose keys are unknown' => ['eval', ['return 1', 1, 'k'], false];
    }

    /**
     * Verify a key list beside other arguments, or inside another key list, is
     * reported as a form GLIDE does not take as keys, whatever the prefix.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('unsupportedKeyListProvider')]
    #[Test]
    public function hasUnsupportedKeyListReportsKeyListsGlideDoesNotTake(string $method, array $parameters, bool $expected): void
    {
        self::assertSame($expected, (new KeyPrefixer(''))->hasUnsupportedKeyList($method, $parameters));
        self::assertSame($expected, (new KeyPrefixer('app:'))->hasUnsupportedKeyList($method, $parameters));
    }

    /**
     * Verify keys reads the keys as given rather than prefixing them.
     *
     * @return void
     */
    #[Test]
    public function keysReadsTheKeysAsGiven(): void
    {
        $prefixer = new KeyPrefixer('app:');

        self::assertSame(['app:a', 'app:b'], $prefixer->keys('del', $prefixer->apply('del', [['a', 'b']])));
    }

    /**
     * Verify mgetKeys(), kept for compatibility, prefixes every key when a
     * prefix is configured.
     *
     * @return void
     */
    #[Test]
    public function mgetKeysPrefixesEveryKey(): void
    {
        // @phpstan-ignore method.deprecated (the deprecated method stays covered until it is removed)
        self::assertSame(['app:a', 'app:b'], (new KeyPrefixer('app:'))->mgetKeys(['a', 'b']));
    }

    /**
     * Verify mgetKeys(), kept for compatibility, returns the key list unchanged
     * when the prefix is empty.
     *
     * @return void
     */
    #[Test]
    public function mgetKeysLeavesKeysUntouchedWhenPrefixEmpty(): void
    {
        // @phpstan-ignore method.deprecated (the deprecated method stays covered until it is removed)
        self::assertSame(['a', 'b'], (new KeyPrefixer(''))->mgetKeys(['a', 'b']));
    }
}
