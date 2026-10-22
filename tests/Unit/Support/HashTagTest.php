<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Support\HashTag;

/**
 * Hash tag test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(HashTag::class)]
final class HashTagTest extends TestCase
{
    /**
     * Provide keys and whether each contains a hash tag, as Laravel 13.5's
     * `Connection::hasHashTag()` decides.
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function keyProvider(): iterable
    {
        yield 'no braces' => ['queue', false];
        yield 'tag at the start' => ['{queue}', true];
        yield 'tag past the start' => ['app:{queue}:jobs', true];
        yield 'empty tag' => ['{}queue', false];
        yield 'empty tag before a tag' => ['{}{queue}', false];
        yield 'unclosed brace' => ['{queue', false];
        yield 'closing brace before a tag' => ['}{queue}', true];
    }

    /**
     * Verify a key contains a hash tag only between its first opening brace and
     * the next closing brace, when that text is not empty.
     *
     * @param  string  $key
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('keyProvider')]
    #[Test]
    public function isInFindsAHashTag(string $key, bool $expected): void
    {
        self::assertSame($expected, HashTag::isIn($key));
    }
}
