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
        yield 'keys prefixes its match pattern' => ['app:', 'keys', ['queues:*'], ['app:queues:*']];
        yield 'double-key command prefixes both keys' => ['app:', 'rename', ['old', 'new'], ['app:old', 'app:new']];
        yield 'double-key command tolerates missing second key' => ['app:', 'rename', ['old'], ['app:old']];
        yield 'double-key command tolerates missing first key' => ['app:', 'rename', [1 => 'new'], [1 => 'app:new']];
        yield 'double-key command skips a non-scalar first key' => ['app:', 'rename', [['old'], 'new'], [['old'], 'app:new']];
        yield 'bitop prefixes the destination and source keys' => ['app:', 'bitop', ['AND', 'dest', 'a', 'b'], ['AND', 'app:dest', 'app:a', 'app:b']];
        yield 'bitop with only an operation is left untouched' => ['app:', 'bitop', ['NOT'], ['NOT']];
        yield 'unknown command is left untouched' => ['app:', 'ping', ['payload'], ['payload']];
        yield 'eval prefixes one declared key' => ['app:', 'eval', ['return 1', 1, 'queue', 'arg'], ['return 1', 1, 'app:queue', 'arg']];
        yield 'eval prefixes multiple declared keys' => ['app:', 'eval', ['return 1', 2, 'k1', 'k2', 'arg'], ['return 1', 2, 'app:k1', 'app:k2', 'arg']];
        yield 'eval with zero keys is left untouched' => ['app:', 'eval', ['return 1', 0, 'arg'], ['return 1', 0, 'arg']];
        yield 'eval with missing key count is untouched' => ['app:', 'eval', ['return 1'], ['return 1']];
        yield 'eval with non-numeric key count untouched' => ['app:', 'eval', ['return 1', 'nope', 'k1', 'k2'], ['return 1', 'nope', 'k1', 'k2']];
        yield 'evalsha prefixes declared keys' => ['app:', 'evalsha', ['sha1', 1, 'queue'], ['sha1', 1, 'app:queue']];
        yield 'tag pruning zremrangebyscore prefixes its key' => ['app:', 'zremrangebyscore', ['t', '0', '9'], ['app:t', '0', '9']];
        yield 'zremrangebyrank used by tags prefixes its key' => ['app:', 'zremrangebyrank', ['t', 0, -1], ['app:t', 0, -1]];
        yield 'zrevrangebyscore single key is prefixed' => ['app:', 'zrevrangebyscore', ['t', '+inf', '-inf'], ['app:t', '+inf', '-inf']];
        yield 'zrevrank single key is prefixed' => ['app:', 'zrevrank', ['t', 'member'], ['app:t', 'member']];
        yield 'zscan prefixes only its key' => ['app:', 'zscan', ['t', '0', '*', 10], ['app:t', '0', '*', 10]];
        yield 'sscan prefixes only its key' => ['app:', 'sscan', ['t', '0', '*', 10], ['app:t', '0', '*', 10]];
        yield 'hscan prefixes only its key' => ['app:', 'hscan', ['t', '0', '*', 10], ['app:t', '0', '*', 10]];
        yield 'scan leaves its cursor and pattern untouched' => ['app:', 'scan', ['0', 'p:*', 10], ['0', 'p:*', 10]];
        yield 'zunionstore prefixes its destination and keys only' => ['app:', 'zunionstore', ['d', ['a', 'b'], [1, 2], 'SUM'], ['app:d', ['app:a', 'app:b'], [1, 2], 'SUM']];
        yield 'key array command without a list keeps the argument' => ['app:', 'zunionstore', ['d', 'a'], ['app:d', 'a']];
        yield 'key array keeps the keys of its list' => ['app:', 'zunion', [['x' => 'a', 'y' => 'b']], [['x' => 'app:a', 'y' => 'app:b']]];
        yield 'key array skips non-scalar entries' => ['app:', 'zinter', [['a', null]], [['app:a', null]]];
        yield 'blocking pop with only a timeout is untouched' => ['app:', 'blpop', [5], [5]];
        yield 'blocking pop keeps its parameter keys' => ['app:', 'bzpopmin', [1 => 'a', 2 => 'b', 3 => 5], [1 => 'app:a', 2 => 'app:b', 3 => 5]];
        yield 'xread prefixes its stream keys' => ['app:', 'xread', [['s1' => '0', 's2' => '$'], 10], [['app:s1' => '0', 'app:s2' => '$'], 10]];
        yield 'xreadgroup prefixes its stream keys' => ['app:', 'xReadGroup', ['g', 'c', ['s' => '>'], 1], ['g', 'c', ['app:s' => '>'], 1]];
        yield 'xreadgroup without a stream map is untouched' => ['app:', 'xreadgroup', ['g', 'c'], ['g', 'c']];
        yield 'xinfo without a key is untouched' => ['app:', 'xinfo', ['HELP'], ['HELP']];
        yield 'sort leaves its store key and patterns' => ['app:', 'sort', ['l', ['by' => 'w_*', 'store' => 'd']], ['app:l', ['by' => 'w_*', 'store' => 'd']]];
        yield 'evalsha_ro prefixes its declared keys' => ['app:', 'evalsha_ro', ['h', [1 => 'k', 2 => 'v'], '1'], ['h', [1 => 'app:k', 2 => 'v'], '1']];
        yield 'eval_ro with zero keys is untouched' => ['app:', 'eval_ro', ['return 1', ['k'], 0], ['return 1', ['k'], 0]];
        yield 'eval_ro with non-numeric key count untouched' => ['app:', 'eval_ro', ['return 1', ['k', 'v'], 'x'], ['return 1', ['k', 'v'], 'x']];
        yield 'eval_ro without an argument list is untouched' => ['app:', 'eval_ro', ['return 1', 'k', 1], ['return 1', 'k', 1]];
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
        yield 'bitcount' => ['bitcount'];
        yield 'bitpos' => ['bitpos'];
        yield 'decr' => ['decr'];
        yield 'decrby' => ['decrby'];
        yield 'dump' => ['dump'];
        yield 'expire' => ['expire'];
        yield 'expireat' => ['expireat'];
        yield 'expiretime' => ['expiretime'];
        yield 'geoadd' => ['geoadd'];
        yield 'geodist' => ['geodist'];
        yield 'geohash' => ['geohash'];
        yield 'geopos' => ['geopos'];
        yield 'geosearch' => ['geosearch'];
        yield 'get' => ['get'];
        yield 'getbit' => ['getbit'];
        yield 'getdel' => ['getdel'];
        yield 'getex' => ['getex'];
        yield 'getrange' => ['getrange'];
        yield 'getset' => ['getset'];
        yield 'hdel' => ['hdel'];
        yield 'hexists' => ['hexists'];
        yield 'hexpire' => ['hexpire'];
        yield 'hexpireat' => ['hexpireat'];
        yield 'hexpiretime' => ['hexpiretime'];
        yield 'hget' => ['hget'];
        yield 'hgetall' => ['hgetall'];
        yield 'hgetex' => ['hgetex'];
        yield 'hincrby' => ['hincrby'];
        yield 'hincrbyfloat' => ['hincrbyfloat'];
        yield 'hkeys' => ['hkeys'];
        yield 'hlen' => ['hlen'];
        yield 'hmget' => ['hmget'];
        yield 'hmset' => ['hmset'];
        yield 'hpersist' => ['hpersist'];
        yield 'hpexpire' => ['hpexpire'];
        yield 'hpexpireat' => ['hpexpireat'];
        yield 'hpexpiretime' => ['hpexpiretime'];
        yield 'hpsetex' => ['hpsetex'];
        yield 'hpttl' => ['hpttl'];
        yield 'hrandfield' => ['hrandfield'];
        yield 'hscan' => ['hscan'];
        yield 'hset' => ['hset'];
        yield 'hsetex' => ['hsetex'];
        yield 'hsetnx' => ['hsetnx'];
        yield 'hstrlen' => ['hstrlen'];
        yield 'httl' => ['httl'];
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
        yield 'pexpiretime' => ['pexpiretime'];
        yield 'pfadd' => ['pfadd'];
        yield 'psetex' => ['psetex'];
        yield 'pttl' => ['pttl'];
        yield 'restore' => ['restore'];
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
        yield 'smismember' => ['smismember'];
        yield 'sort' => ['sort'];
        yield 'sort_ro' => ['sort_ro'];
        yield 'spop' => ['spop'];
        yield 'srandmember' => ['srandmember'];
        yield 'srem' => ['srem'];
        yield 'sscan' => ['sscan'];
        yield 'strlen' => ['strlen'];
        yield 'ttl' => ['ttl'];
        yield 'type' => ['type'];
        yield 'xack' => ['xack'];
        yield 'xadd' => ['xadd'];
        yield 'xautoclaim' => ['xautoclaim'];
        yield 'xclaim' => ['xclaim'];
        yield 'xdel' => ['xdel'];
        yield 'xlen' => ['xlen'];
        yield 'xpending' => ['xpending'];
        yield 'xrange' => ['xrange'];
        yield 'xrevrange' => ['xrevrange'];
        yield 'xtrim' => ['xtrim'];
        yield 'zadd' => ['zadd'];
        yield 'zcard' => ['zcard'];
        yield 'zcount' => ['zcount'];
        yield 'zincrby' => ['zincrby'];
        yield 'zlexcount' => ['zlexcount'];
        yield 'zmscore' => ['zmscore'];
        yield 'zpopmax' => ['zpopmax'];
        yield 'zpopmin' => ['zpopmin'];
        yield 'zrandmember' => ['zrandmember'];
        yield 'zrange' => ['zrange'];
        yield 'zrangebylex' => ['zrangebylex'];
        yield 'zrangebyscore' => ['zrangebyscore'];
        yield 'zrank' => ['zrank'];
        yield 'zrem' => ['zrem'];
        yield 'zremrangebylex' => ['zremrangebylex'];
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
        yield 'sdiffstore' => ['sdiffstore'];
        yield 'sunion' => ['sunion'];
        yield 'sunionstore' => ['sunionstore'];
    }

    /**
     * Verify every all-key command prefixes each argument, and reports a list
     * argument, alone or beside other keys, as a form GLIDE does not take as
     * keys, as it would send it as the key `Array` or refuse it.
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
        self::assertFalse($prefixer->hasUnsupportedKeyList($method, ['a', 'b']));

        foreach ([[['a', 'b']], ['d', ['a', 'b']], ['d', 'a', ['b']], [['d'], 'a']] as $parameters) {
            self::assertNull($prefixer->keys($method, $parameters));
            self::assertTrue($prefixer->hasUnsupportedKeyList($method, $parameters));
        }
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
        yield 'pfcount' => ['pfcount'];
        yield 'sinter' => ['sinter'];
        yield 'sinterstore' => ['sinterstore'];
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
        yield 'blmove' => ['blmove'];
        yield 'brpoplpush' => ['brpoplpush'];
        yield 'copy' => ['copy'];
        yield 'geosearchstore' => ['geosearchstore'];
        yield 'lcs' => ['lcs'];
        yield 'lmove' => ['lmove'];
        yield 'rename' => ['rename'];
        yield 'renamenx' => ['renamenx'];
        yield 'rpoplpush' => ['rpoplpush'];
        yield 'smove' => ['smove'];
        yield 'zrangestore' => ['zrangestore'];
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
     * Provide every command whose key follows its subcommand.
     *
     * @return iterable<string, array{string}>
     */
    public static function subcommandKeyCommands(): iterable
    {
        yield 'object' => ['object'];
        yield 'xgroup' => ['xgroup'];
        yield 'xinfo' => ['xinfo'];
    }

    /**
     * Verify every subcommand-key command prefixes its second argument only.
     *
     * @param  string  $method
     * @return void
     */
    #[DataProvider('subcommandKeyCommands')]
    #[Test]
    public function subcommandKeyCommandsPrefixTheirSecondArgumentOnly(string $method): void
    {
        $prefixer = new KeyPrefixer('app:');

        self::assertSame(['CREATE', 'app:k', 'x'], $prefixer->apply($method, ['CREATE', 'k', 'x']));
        self::assertSame(['k'], $prefixer->keys($method, ['CREATE', 'k', 'x']));
    }

    /**
     * Provide every command that takes a list of keys, with the list's index
     * and whether a destination key comes before it.
     *
     * @return iterable<string, array{string, int, bool}>
     */
    public static function keyArrayCommands(): iterable
    {
        yield 'blmpop' => ['blmpop', 1, false];
        yield 'bzmpop' => ['bzmpop', 1, false];
        yield 'fcall' => ['fcall', 1, false];
        yield 'fcall_ro' => ['fcall_ro', 1, false];
        yield 'lmpop' => ['lmpop', 0, false];
        yield 'pfmerge' => ['pfmerge', 1, true];
        yield 'sintercard' => ['sintercard', 0, false];
        yield 'zdiff' => ['zdiff', 0, false];
        yield 'zdiffstore' => ['zdiffstore', 1, true];
        yield 'zinter' => ['zinter', 0, false];
        yield 'zintercard' => ['zintercard', 0, false];
        yield 'zinterstore' => ['zinterstore', 1, true];
        yield 'zmpop' => ['zmpop', 0, false];
        yield 'zunion' => ['zunion', 0, false];
        yield 'zunionstore' => ['zunionstore', 1, true];
    }

    /**
     * Verify every key-array command prefixes each key of its list, and its
     * destination key, if it has one, and nothing else.
     *
     * @param  string  $method
     * @param  int  $index
     * @param  bool  $destination
     * @return void
     */
    #[DataProvider('keyArrayCommands')]
    #[Test]
    public function keyArrayCommandsPrefixTheirListOfKeys(string $method, int $index, bool $destination): void
    {
        $prefixer   = new KeyPrefixer('app:');
        $parameters = $index === 0 ? [['a', 'b'], 'x'] : ['first', ['a', 'b'], 'x'];
        $expected   = $index === 0 ? [['app:a', 'app:b'], 'x'] : [$destination ? 'app:first' : 'first', ['app:a', 'app:b'], 'x'];
        $keys       = $destination ? ['first', 'a', 'b'] : ['a', 'b'];

        self::assertSame($expected, $prefixer->apply($method, $parameters));
        self::assertSame($keys, $prefixer->keys($method, $parameters));
    }

    /**
     * Provide every blocking pop.
     *
     * @return iterable<string, array{string}>
     */
    public static function blockingPopCommands(): iterable
    {
        yield 'blpop' => ['blpop'];
        yield 'brpop' => ['brpop'];
        yield 'bzpopmax' => ['bzpopmax'];
        yield 'bzpopmin' => ['bzpopmin'];
    }

    /**
     * Verify every blocking pop prefixes its list of keys, or every argument
     * before its timeout, and not the timeout.
     *
     * @param  string  $method
     * @return void
     */
    #[DataProvider('blockingPopCommands')]
    #[Test]
    public function blockingPopsPrefixTheirKeysButNotTheirTimeout(string $method): void
    {
        $prefixer = new KeyPrefixer('app:');

        self::assertSame([['app:a', 'app:b'], 5], $prefixer->apply($method, [['a', 'b'], 5]));
        self::assertSame(['app:a', 'app:b', 5], $prefixer->apply($method, ['a', 'b', 5]));
        self::assertSame(['app:a', 5], $prefixer->apply($method, ['a', 5]));
        self::assertSame(['a', 'b'], $prefixer->keys($method, ['a', 'b', 5]));
        self::assertSame(['a', 'b'], $prefixer->keys($method, [['a', 'b'], 5]));
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
        yield 'list among the keys of an all-key command is unknown' => ['sunion', [['a'], 'b'], null];
        yield 'all-key command skips a non-scalar key' => ['sdiffstore', ['d', null, 'a'], ['d', 'a']];
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
        yield 'read-only script keys are unknown' => ['eval_ro', ['return 1', ['k'], 1], null];
        yield 'key array command names its destination and keys' => ['zinterstore', ['dest', ['a', 'b']], ['dest', 'a', 'b']];
        yield 'key array command without a destination' => ['bzmpop', [1.0, ['a', 'b'], 'MIN'], ['a', 'b']];
        yield 'key array command without a list' => ['zunionstore', ['dest', 'a'], ['dest']];
        yield 'key array command skips a destination that is not a key' => ['zunionstore', [null, ['a']], ['a']];
        yield 'key array with string keys' => ['zunion', [['x' => 'a', 'y' => 'b']], ['a', 'b']];
        yield 'list inside a key array is unknown' => ['zunion', [[['a'], 'b']], null];
        yield 'list inside a blocking pop key list is unknown' => ['brpop', [[['a']], 5], null];
        yield 'list among blocking pop keys is unknown' => ['bzpopmax', ['a', ['b'], 5], null];
        yield 'blocking pop list before other keys is unknown' => ['bzpopmin', [['a'], 'b', 5], null];
        yield 'blocking pop key list without a timeout' => ['bzpopmin', [['a', 'b']], ['a', 'b']];
        yield 'xread names its streams' => ['xread', [['s1' => '0', 's2' => '0']], ['s1', 's2']];
        yield 'xreadgroup names its streams' => ['xreadgroup', ['g', 'c', ['s' => '>']], ['s']];
        yield 'xreadgroup without a stream map is unknown' => ['xreadgroup', ['g', 'c'], null];
        yield 'subcommand key' => ['object', ['encoding', 'k', 'x'], ['k']];
        yield 'unknown command' => ['jsonset', ['doc', '$', '1'], null];
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
        yield 'command that takes no key list' => ['get', [['a']], false];
        yield 'command whose keys are unknown' => ['eval', ['return 1', 1, 'k'], false];
        yield 'mixed sinterstore' => ['sinterstore', ['d', ['a']], true];
        yield 'single sinterstore key list' => ['sinterstore', [['d', 'a']], false];
        yield 'key array' => ['zunionstore', ['d', ['a', 'b']], false];
        yield 'key array that is not a list' => ['zinter', ['a'], false];
        yield 'list inside a key array' => ['zunionstore', ['d', [['a'], 'b']], true];
        yield 'blocking pop key list' => ['bzpopmin', [['a'], 5], false];
        yield 'list inside a blocking pop key list' => ['blpop', [[['a']], 5], true];
        yield 'list among blocking pop keys' => ['bzpopmin', ['a', ['b'], 5], true];
        yield 'blocking pop list before other keys' => ['bzPopMax', [['a'], 'b', 5], true];
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
