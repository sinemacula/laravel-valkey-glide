<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ClusterRouter;

/**
 * Cluster router test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ClusterRouter::class)]
final class ClusterRouterTest extends TestCase
{
    /**
     * Provide raw command parameters and the route they resolve to.
     *
     * @return iterable<string, array{array<array-key, mixed>, array{type: string, key: string}|string}>
     */
    public static function routeProvider(): iterable
    {
        yield 'first key' => [['GET', 'user:1'], self::slot('user:1')];
        yield 'lower-case command' => [['unlink', 'k'], self::slot('k')];
        yield 'named parameters' => [['command' => 'GET', 'key' => 'user:1'], self::slot('user:1')];
        yield 'multi-key command routes by its first key' => [['UNLINK', '{t}a', '{t}b'], self::slot('{t}a')];
        yield 'multi-key command across slots still routes whole by its first key' => [['MGET', 'a', 'b', 'c'], self::slot('a')];
        yield 'mset routes by its first key' => [['MSET', 'a', '1', 'b', '2'], self::slot('a')];
        yield 'multi-byte utf-8 key' => [['GET', 'user:é'], self::slot('user:é')];
        yield 'invalid utf-8 key goes to a random node' => [['GET', "\xff"], 'randomNode'];
        yield 'invalid utf-8 after a valid prefix goes to a random node' => [['GET', "user:\xc3"], 'randomNode'];
        yield 'integer key' => [['GET', 42], self::slot('42')];
        yield 'stringable key' => [['GET', new \SimpleXMLElement('<root>node</root>')], self::slot('node')];
        yield 'empty key' => [['GET', ''], 'randomNode'];
        yield 'null key' => [['GET', null], 'randomNode'];
        yield 'array key' => [['GET', ['k']], 'randomNode'];
        yield 'no arguments' => [['LASTSAVE'], 'randomNode'];
        yield 'no parameters' => [[], 'randomNode'];
        yield 'non-string command' => [[42, 'k'], self::slot('k')];
        yield 'bitop routes by its destination key' => [['BITOP', 'AND', '{t}dest', '{t}a'], self::slot('{t}dest')];
        yield 'object routes by the key after its subcommand' => [['OBJECT', 'ENCODING', 'k'], self::slot('k')];
        yield 'migrate routes by its third argument' => [['MIGRATE', '10.0.0.1', '6379', 'k', '0', '1000'], self::slot('k')];
        yield 'eval routes by its first declared key' => [['EVAL', 'return 1', 2, 'k1', 'k2'], self::slot('k1')];
        yield 'evalsha with a numeric-string key count' => [['EVALSHA', 'sha', '1', 'k'], self::slot('k')];
        yield 'eval without keys' => [['EVAL', 'return 1', 0, 'arg'], 'randomNode'];
        yield 'eval with a non-numeric key count' => [['EVAL', 'return 1', 'nope', 'arg'], 'randomNode'];
        yield 'fcall routes by its first declared key' => [['FCALL', 'fn', 1, 'k'], self::slot('k')];
        yield 'blmpop routes by the key after its count' => [['BLMPOP', 0, 1, 'list', 'LEFT'], self::slot('list')];
        yield 'lmpop routes by the key after its count' => [['LMPOP', 1, 'list', 'LEFT'], self::slot('list')];
        yield 'xread routes by the first stream' => [['XREAD', 'COUNT', 1, 'streams', 's1', 's2', '0', '0'], self::slot('s1')];
        yield 'xreadgroup routes by the first stream' => [['XREADGROUP', 'GROUP', 'g', 'c', 'STREAMS', 's1', '>'], self::slot('s1')];
        yield 'xread without streams' => [['XREAD', 'COUNT', 1], 'randomNode'];
        yield 'script load runs on every node' => [['SCRIPT', 'load', 'return 1'], 'allNodes'];
        yield 'config set runs on every node' => [['CONFIG', 'SET', 'maxmemory-policy', 'allkeys-lru'], 'allNodes'];
        yield 'function load runs on every primary' => [['FUNCTION', 'LOAD', 'code'], 'allPrimaries'];
        yield 'dbsize runs on every primary' => [['DBSIZE'], 'allPrimaries'];
        yield 'keys runs on every primary' => [['keys', 'user:*'], 'allPrimaries'];
        yield 'flushdb with a flag runs on every primary' => [['FLUSHDB', 'ASYNC'], 'allPrimaries'];
        yield 'keyless command goes to a random node' => [['ECHO', 'hello'], 'randomNode'];
        yield 'keyless subcommand goes to a random node' => [['CONFIG', 'GET', 'maxmemory'], 'randomNode'];
        yield 'cluster subcommand with a key-shaped argument goes to a random node' => [['CLUSTER', 'KEYSLOT', 'user:1'], 'randomNode'];
        yield 'container without a subcommand' => [['SCRIPT'], 'randomNode'];
    }

    /**
     * Verify route resolves the expected route for each command family.
     *
     * @param  array<array-key, mixed>  $parameters
     * @param  array{type: string, key: string}|string  $expected
     * @return void
     */
    #[DataProvider('routeProvider')]
    #[Test]
    public function routeResolvesExpectedRoute(array $parameters, array|string $expected): void
    {
        self::assertSame($expected, (new ClusterRouter)->route($parameters));
    }

    /**
     * Provide key values and the route they resolve to.
     *
     * @return iterable<string, array{mixed, array{type: string, key: string}|string}>
     */
    public static function routeKeyProvider(): iterable
    {
        yield 'string key' => ['user:1', self::slot('user:1')];
        yield 'integer key' => [7, self::slot('7')];
        yield 'multi-byte utf-8 key' => ['user:é', self::slot('user:é')];
        yield 'invalid utf-8 key' => ["\xff", 'randomNode'];
        yield 'empty key' => ['', 'randomNode'];
        yield 'null key' => [null, 'randomNode'];
        yield 'array key' => [['k'], 'randomNode'];
    }

    /**
     * Verify routeKey routes to the key's slot only when GLIDE can carry the
     * key in a slot-key route.
     *
     * @param  mixed  $key
     * @param  array{type: string, key: string}|string  $expected
     * @return void
     */
    #[DataProvider('routeKeyProvider')]
    #[Test]
    public function routeKeyFallsBackToRandomNodeForUnroutableKeys(mixed $key, array|string $expected): void
    {
        self::assertSame($expected, (new ClusterRouter)->routeKey($key));
    }

    /**
     * Verify a cursor SCAN is refused rather than routed to a single node.
     *
     * @return void
     */
    #[Test]
    public function routeRefusesScan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ClusterScanCursor');

        (new ClusterRouter)->route(['scan', '0']);
    }

    /**
     * Provide raw CLUSTER commands that GLIDE routes by the slot number in
     * their second argument.
     *
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function slotCommandProvider(): iterable
    {
        yield 'countkeysinslot' => [['CLUSTER', 'COUNTKEYSINSLOT', 7000], 'CLUSTER COUNTKEYSINSLOT'];
        yield 'getkeysinslot' => [['cluster', 'getkeysinslot', '7000', 10], 'CLUSTER GETKEYSINSLOT'];
        yield 'addslots' => [['CLUSTER', 'ADDSLOTS', 1, 2], 'CLUSTER ADDSLOTS'];
        yield 'delslots' => [['CLUSTER', 'DELSLOTS', 1], 'CLUSTER DELSLOTS'];
        yield 'delslotsrange' => [['CLUSTER', 'DELSLOTSRANGE', 1, 10], 'CLUSTER DELSLOTSRANGE'];
        yield 'setslot' => [['CLUSTER', 'SETSLOT', 1, 'STABLE'], 'CLUSTER SETSLOT'];
    }

    /**
     * Verify a slot command is refused rather than run on a node that may not
     * own the slot.
     *
     * @param  array<array-key, mixed>  $parameters
     * @param  string  $command
     * @return void
     */
    #[DataProvider('slotCommandProvider')]
    #[Test]
    public function routeRefusesSlotCommands(array $parameters, string $command): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A raw ' . $command . ' must reach the primary that owns its slot');

        (new ClusterRouter)->route($parameters);
    }

    /**
     * Provide keys and the hash slots a Valkey cluster's CLUSTER KEYSLOT
     * reports for them.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function slotProvider(): iterable
    {
        yield 'redis specification check value' => ['123456789', 12739];
        yield 'plain key' => ['a', 15495];
        yield 'another plain key' => ['foo', 12182];
        yield 'empty key' => ['', 0];
        yield 'hash tag' => ['{user1000}.following', 3443];
        yield 'same hash tag' => ['{user1000}.followers', 3443];
        yield 'hash tag inside the key' => ['x{a}y', 15495];
        yield 'hash tag after a closing brace' => ['}{a}', 15495];
        yield 'hash tag inside a prefix' => ['ip:{abc}:k', 7638];
        yield 'horizon cluster prefix' => ['{horizon:}job:1', 14165];
        yield 'empty hash tag hashes the whole key' => ['foo{}{bar}', 8363];
        yield 'bare empty hash tag' => ['{}', 15257];
        yield 'empty hash tag at the start' => ['{}a', 10875];
        yield 'hash tag ends at the first closing brace' => ['foo{{bar}}zap', 4015];
        yield 'only the first hash tag counts' => ['foo{bar}{zap}', 5061];
        yield 'unclosed hash tag hashes the whole key' => ['{a', 10276];
        yield 'closing brace before the opening one' => ['a}b{', 6027];
        yield 'binary key' => ["\xff\x00x", 12540];
        yield 'multi-byte utf-8 key' => ['user:é', 1676];
    }

    /**
     * Verify slot matches the cluster's own CRC16 key slot, hash tags included.
     *
     * @param  string  $key
     * @param  int  $expected
     * @return void
     */
    #[DataProvider('slotProvider')]
    #[Test]
    public function slotMatchesTheClusterKeySlot(string $key, int $expected): void
    {
        self::assertSame($expected, (new ClusterRouter)->slot($key));
    }

    /**
     * Build the slot-key route for a key.
     *
     * @param  string  $key
     * @return array{type: string, key: string}
     */
    private static function slot(string $key): array
    {
        return ['type' => 'primarySlotKey', 'key' => $key];
    }
}
