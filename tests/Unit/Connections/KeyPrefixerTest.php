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
        yield 'all-key command skips non-scalar entries' => ['app:', 'del', ['a', ['x'], 'c'], ['app:a', ['x'], 'app:c']];
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
     * Verify mgetKeys prefixes every key when a prefix is configured.
     *
     * @return void
     */
    #[Test]
    public function mgetKeysPrefixesEveryKey(): void
    {
        self::assertSame(['app:a', 'app:b'], (new KeyPrefixer('app:'))->mgetKeys(['a', 'b']));
    }

    /**
     * Verify mgetKeys returns the key list unchanged when the prefix is empty.
     *
     * @return void
     */
    #[Test]
    public function mgetKeysLeavesKeysUntouchedWhenPrefixEmpty(): void
    {
        self::assertSame(['a', 'b'], (new KeyPrefixer(''))->mgetKeys(['a', 'b']));
    }
}
