<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Support\Cast;

/**
 * Cast test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Cast::class)]
final class CastTest extends TestCase
{
    /**
     * Provide values that resolve to a non-negative integer.
     *
     * @return iterable<string, array{0: mixed, 1: int}>
     */
    public static function nonNegativeIntProvider(): iterable
    {
        yield 'integer' => [4, 4];
        yield 'zero' => [0, 0];
        yield 'float truncates' => [4.7, 4];
        yield 'numeric string' => ['7', 7];
        yield 'zero string' => ['0', 0];
        yield 'numeric stringable' => [new \SimpleXMLElement('<root>9</root>'), 9];
    }

    /**
     * Verify supported inputs resolve to their non-negative integer form.
     *
     * @param  mixed  $value
     * @param  int  $expected
     * @return void
     */
    #[DataProvider('nonNegativeIntProvider')]
    #[Test]
    public function toNonNegativeIntResolvesSupportedValues(mixed $value, int $expected): void
    {
        self::assertSame($expected, Cast::toNonNegativeInt($value));
    }

    /**
     * Provide values that cannot resolve to a non-negative integer.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidNonNegativeIntProvider(): iterable
    {
        yield 'negative integer' => [-1];
        yield 'negative string' => ['-5'];
        yield 'non-numeric string' => ['not-numeric'];
        yield 'non-numeric stringable' => [new \SimpleXMLElement('<root>not-a-number</root>')];
        yield 'boolean' => [true];
        yield 'null' => [null];
        yield 'array' => [[1, 2]];
    }

    /**
     * Verify unsupported inputs resolve to null.
     *
     * @param  mixed  $value
     * @return void
     */
    #[DataProvider('invalidNonNegativeIntProvider')]
    #[Test]
    public function toNonNegativeIntReturnsNullForUnsupportedValues(mixed $value): void
    {
        self::assertNull(Cast::toNonNegativeInt($value));
    }

    /**
     * Provide values that resolve to a non-empty string.
     *
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function nonEmptyStringProvider(): iterable
    {
        yield 'non-empty string' => ['worker', 'worker'];
        yield 'integer' => [42, '42'];
        yield 'float' => [1.5, '1.5'];
        yield 'boolean true' => [true, '1'];
        yield 'stringable' => [new \SimpleXMLElement('<root>node</root>'), 'node'];
    }

    /**
     * Verify supported inputs resolve to their non-empty string form.
     *
     * @param  mixed  $value
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('nonEmptyStringProvider')]
    #[Test]
    public function toNonEmptyStringResolvesSupportedValues(mixed $value, string $expected): void
    {
        self::assertSame($expected, Cast::toNonEmptyString($value));
    }

    /**
     * Provide values that cannot resolve to a non-empty string.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidNonEmptyStringProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'boolean false' => [false];
        yield 'null' => [null];
        yield 'array' => [['a']];
    }

    /**
     * Verify unsupported inputs resolve to null.
     *
     * @param  mixed  $value
     * @return void
     */
    #[DataProvider('invalidNonEmptyStringProvider')]
    #[Test]
    public function toNonEmptyStringReturnsNullForUnsupportedValues(mixed $value): void
    {
        self::assertNull(Cast::toNonEmptyString($value));
    }
}
