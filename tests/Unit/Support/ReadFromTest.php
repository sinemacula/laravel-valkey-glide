<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Enums\ReadFrom;

/**
 * ReadFrom enum test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ReadFrom::class)]
final class ReadFromTest extends TestCase
{
    /**
     * Verify each enum case value matches the corresponding extension constant.
     *
     * @return void
     */
    #[Test]
    public function enumValuesMatchExtensionReadFromConstants(): void
    {
        self::assertSame(0, ReadFrom::PRIMARY->value);
        self::assertSame(1, ReadFrom::PREFER_REPLICA->value);
        self::assertSame(2, ReadFrom::AZ_AFFINITY->value);
        self::assertSame(3, ReadFrom::AZ_AFFINITY_REPLICAS_AND_PRIMARY->value);
    }

    /**
     * Provide valid inputs and their expected ReadFrom resolution.
     *
     * @return iterable<string, array{0: mixed, 1: \SineMacula\Valkey\Enums\ReadFrom}>
     */
    public static function validResolutionProvider(): iterable
    {
        yield 'int 0 resolves to PRIMARY' => [0, ReadFrom::PRIMARY];
        yield 'int 1 resolves to PREFER_REPLICA' => [1, ReadFrom::PREFER_REPLICA];
        yield 'int 2 resolves to AZ_AFFINITY' => [2, ReadFrom::AZ_AFFINITY];
        yield 'int 3 resolves to AZ_AFFINITY_REPLICAS_AND_PRIMARY' => [3, ReadFrom::AZ_AFFINITY_REPLICAS_AND_PRIMARY];

        yield 'numeric string 0 resolves to PRIMARY' => ['0', ReadFrom::PRIMARY];
        yield 'numeric string 1 resolves to PREFER_REPLICA' => ['1', ReadFrom::PREFER_REPLICA];
        yield 'numeric string 2 resolves to AZ_AFFINITY' => ['2', ReadFrom::AZ_AFFINITY];
        yield 'numeric string 3 resolves to AZ_AFFINITY_REPLICAS_AND_PRIMARY' => ['3', ReadFrom::AZ_AFFINITY_REPLICAS_AND_PRIMARY];

        yield 'lowercase name primary' => ['primary', ReadFrom::PRIMARY];
        yield 'lowercase name prefer_replica' => ['prefer_replica', ReadFrom::PREFER_REPLICA];
        yield 'lowercase name az_affinity' => ['az_affinity', ReadFrom::AZ_AFFINITY];
        yield 'lowercase name az_affinity_replicas_and_primary' => ['az_affinity_replicas_and_primary', ReadFrom::AZ_AFFINITY_REPLICAS_AND_PRIMARY];

        yield 'uppercase name PRIMARY' => ['PRIMARY', ReadFrom::PRIMARY];
        yield 'mixed-case name Prefer_Replica' => ['Prefer_Replica', ReadFrom::PREFER_REPLICA];
        yield 'uppercase name AZ_AFFINITY' => ['AZ_AFFINITY', ReadFrom::AZ_AFFINITY];
        yield 'uppercase name AZ_AFFINITY_REPLICAS_AND_PRIMARY' => ['AZ_AFFINITY_REPLICAS_AND_PRIMARY', ReadFrom::AZ_AFFINITY_REPLICAS_AND_PRIMARY];

        yield 'name with surrounding whitespace' => ['  primary  ', ReadFrom::PRIMARY];
        yield 'name with tabs' => ["\tprefer_replica\t", ReadFrom::PREFER_REPLICA];

        yield 'self instance PRIMARY returns itself' => [ReadFrom::PRIMARY, ReadFrom::PRIMARY];
        yield 'self instance PREFER_REPLICA returns itself' => [ReadFrom::PREFER_REPLICA, ReadFrom::PREFER_REPLICA];
        yield 'self instance AZ_AFFINITY returns itself' => [ReadFrom::AZ_AFFINITY, ReadFrom::AZ_AFFINITY];
        yield 'self instance AZ_AFFINITY_REPLICAS_AND_PRIMARY returns itself' => [ReadFrom::AZ_AFFINITY_REPLICAS_AND_PRIMARY, ReadFrom::AZ_AFFINITY_REPLICAS_AND_PRIMARY];
    }

    /**
     * Verify valid inputs resolve to the expected ReadFrom case.
     *
     * @param  mixed  $input
     * @param  \SineMacula\Valkey\Enums\ReadFrom  $expected
     * @return void
     */
    #[DataProvider('validResolutionProvider')]
    #[Test]
    public function tryFromMixedResolvesValidInputToExpectedCase(mixed $input, ReadFrom $expected): void
    {
        self::assertSame($expected, ReadFrom::tryFromMixed($input));
    }

    /**
     * Provide invalid inputs that must resolve to null.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidResolutionProvider(): iterable
    {
        yield 'int -1 out of range' => [-1];
        yield 'int 4 out of range' => [4];

        yield 'numeric string 4 out of range' => ['4'];
        yield 'float string 1.5' => ['1.5'];
        yield 'empty string' => [''];
        yield 'whitespace-only string' => ['   '];

        yield 'partial name replica' => ['replica'];
        yield 'non-matching string foo' => ['foo'];

        yield 'float 1.0' => [1.0];
        yield 'float 0.5' => [0.5];

        yield 'string 1.0' => ['1.0'];
        yield 'string 1e1' => ['1e1'];

        yield 'bool true' => [true];
        yield 'bool false' => [false];

        yield 'null' => [null];

        yield 'array' => [[0]];

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
        self::assertNull(ReadFrom::tryFromMixed($input));
    }
}
