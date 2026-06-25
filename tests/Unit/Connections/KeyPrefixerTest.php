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
        yield 'unknown command is left untouched' => ['app:', 'ping', ['payload'], ['payload']];
        yield 'eval prefixes one declared key' => ['app:', 'eval', ['return 1', 1, 'queue', 'arg'], ['return 1', 1, 'app:queue', 'arg']];
        yield 'eval prefixes multiple declared keys' => ['app:', 'eval', ['return 1', 2, 'k1', 'k2', 'arg'], ['return 1', 2, 'app:k1', 'app:k2', 'arg']];
        yield 'eval with zero keys is left untouched' => ['app:', 'eval', ['return 1', 0, 'arg'], ['return 1', 0, 'arg']];
        yield 'eval with missing key count is untouched' => ['app:', 'eval', ['return 1'], ['return 1']];
        yield 'eval with non-numeric key count untouched' => ['app:', 'eval', ['return 1', 'nope', 'k1'], ['return 1', 'nope', 'k1']];
        yield 'evalsha prefixes declared keys' => ['app:', 'evalsha', ['sha1', 1, 'queue'], ['sha1', 1, 'app:queue']];
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
