<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Enums\PeriodicChecks;

/**
 * PeriodicChecks enum test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PeriodicChecks::class)]
final class PeriodicChecksTest extends TestCase
{
    /**
     * Verify each enum case value matches the corresponding extension constant.
     *
     * @return void
     */
    #[Test]
    public function enumValuesMatchExtensionPeriodicCheckConstants(): void
    {
        self::assertSame(0, PeriodicChecks::ENABLED_DEFAULT_CONFIGS->value);
        self::assertSame(1, PeriodicChecks::DISABLED->value);
        self::assertSame(\ValkeyGlideCluster::PERIODIC_CHECK_ENABLED_DEFAULT_CONFIGS, PeriodicChecks::ENABLED_DEFAULT_CONFIGS->value);
        self::assertSame(\ValkeyGlideCluster::PERIODIC_CHECK_DISABLED, PeriodicChecks::DISABLED->value);
    }

    /**
     * Provide valid inputs and their expected PeriodicChecks resolution.
     *
     * @return iterable<string, array{0: mixed, 1: \SineMacula\Valkey\Enums\PeriodicChecks}>
     */
    public static function validResolutionProvider(): iterable
    {
        yield 'int 0 resolves to ENABLED_DEFAULT_CONFIGS' => [0, PeriodicChecks::ENABLED_DEFAULT_CONFIGS];
        yield 'int 1 resolves to DISABLED' => [1, PeriodicChecks::DISABLED];

        yield 'numeric string 0 resolves to ENABLED_DEFAULT_CONFIGS' => ['0', PeriodicChecks::ENABLED_DEFAULT_CONFIGS];
        yield 'numeric string 1 resolves to DISABLED' => ['1', PeriodicChecks::DISABLED];

        yield 'lowercase name enabled_default_configs' => ['enabled_default_configs', PeriodicChecks::ENABLED_DEFAULT_CONFIGS];
        yield 'lowercase name enabled' => ['enabled', PeriodicChecks::ENABLED_DEFAULT_CONFIGS];
        yield 'lowercase name disabled' => ['disabled', PeriodicChecks::DISABLED];

        yield 'uppercase name ENABLED_DEFAULT_CONFIGS' => ['ENABLED_DEFAULT_CONFIGS', PeriodicChecks::ENABLED_DEFAULT_CONFIGS];
        yield 'mixed-case name Enabled' => ['Enabled', PeriodicChecks::ENABLED_DEFAULT_CONFIGS];
        yield 'uppercase name DISABLED' => ['DISABLED', PeriodicChecks::DISABLED];

        yield 'name with surrounding whitespace' => ['  disabled  ', PeriodicChecks::DISABLED];
        yield 'name with tabs' => ["\tenabled\t", PeriodicChecks::ENABLED_DEFAULT_CONFIGS];

        yield 'stringable name' => [new \SimpleXMLElement('<root>disabled</root>'), PeriodicChecks::DISABLED];

        yield 'self instance ENABLED_DEFAULT_CONFIGS returns itself' => [PeriodicChecks::ENABLED_DEFAULT_CONFIGS, PeriodicChecks::ENABLED_DEFAULT_CONFIGS];
        yield 'self instance DISABLED returns itself' => [PeriodicChecks::DISABLED, PeriodicChecks::DISABLED];
    }

    /**
     * Verify valid inputs resolve to the expected PeriodicChecks case.
     *
     * @param  mixed  $input
     * @param  \SineMacula\Valkey\Enums\PeriodicChecks  $expected
     * @return void
     */
    #[DataProvider('validResolutionProvider')]
    #[Test]
    public function tryFromMixedResolvesValidInputToExpectedCase(mixed $input, PeriodicChecks $expected): void
    {
        self::assertSame($expected, PeriodicChecks::tryFromMixed($input));
    }

    /**
     * Provide invalid inputs that must resolve to null.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidResolutionProvider(): iterable
    {
        yield 'int -1 out of range' => [-1];
        yield 'int 2 out of range' => [2];
        yield 'manual interval int 500' => [500];

        yield 'numeric string 2 out of range' => ['2'];
        yield 'float string 1.5' => ['1.5'];
        yield 'empty string' => [''];
        yield 'whitespace-only string' => ['   '];

        yield 'partial name enable' => ['enable'];
        yield 'constant name with prefix' => ['PERIODIC_CHECK_DISABLED'];
        yield 'non-matching string foo' => ['foo'];

        yield 'float 1.0' => [1.0];
        yield 'float 0.5' => [0.5];

        yield 'string 1.0' => ['1.0'];
        yield 'string 1e0' => ['1e0'];
        yield 'string 1E0' => ['1E0'];

        yield 'bool true' => [true];
        yield 'bool false' => [false];

        yield 'null' => [null];

        yield 'array' => [[1]];

        yield 'non-stringable object' => [new \stdClass];
    }

    /**
     * Verify invalid inputs resolve to null.
     *
     * @param  mixed  $input
     * @return void
     */
    #[DataProvider('invalidResolutionProvider')]
    #[Test]
    public function tryFromMixedReturnsNullForInvalidInput(mixed $input): void
    {
        self::assertNull(PeriodicChecks::tryFromMixed($input));
    }
}
