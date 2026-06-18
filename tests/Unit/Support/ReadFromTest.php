<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Support\ReadFrom;

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
        self::assertSame(0, ReadFrom::Primary->value);
        self::assertSame(1, ReadFrom::PreferReplica->value);
        self::assertSame(2, ReadFrom::AzAffinity->value);
        self::assertSame(3, ReadFrom::AzAffinityReplicasAndPrimary->value);
    }

    /**
     * Provide valid inputs and their expected ReadFrom resolution.
     *
     * @return iterable<string, array{0: mixed, 1: \SineMacula\Valkey\Support\ReadFrom}>
     */
    public static function validResolutionProvider(): iterable
    {
        yield 'int 0 resolves to Primary' => [0, ReadFrom::Primary];
        yield 'int 1 resolves to PreferReplica' => [1, ReadFrom::PreferReplica];
        yield 'int 2 resolves to AzAffinity' => [2, ReadFrom::AzAffinity];
        yield 'int 3 resolves to AzAffinityReplicasAndPrimary' => [3, ReadFrom::AzAffinityReplicasAndPrimary];

        yield 'numeric string 0 resolves to Primary' => ['0', ReadFrom::Primary];
        yield 'numeric string 1 resolves to PreferReplica' => ['1', ReadFrom::PreferReplica];
        yield 'numeric string 2 resolves to AzAffinity' => ['2', ReadFrom::AzAffinity];
        yield 'numeric string 3 resolves to AzAffinityReplicasAndPrimary' => ['3', ReadFrom::AzAffinityReplicasAndPrimary];

        yield 'lowercase name primary' => ['primary', ReadFrom::Primary];
        yield 'lowercase name prefer_replica' => ['prefer_replica', ReadFrom::PreferReplica];
        yield 'lowercase name az_affinity' => ['az_affinity', ReadFrom::AzAffinity];
        yield 'lowercase name az_affinity_replicas_and_primary' => ['az_affinity_replicas_and_primary', ReadFrom::AzAffinityReplicasAndPrimary];

        yield 'uppercase name PRIMARY' => ['PRIMARY', ReadFrom::Primary];
        yield 'mixed-case name Prefer_Replica' => ['Prefer_Replica', ReadFrom::PreferReplica];
        yield 'uppercase name AZ_AFFINITY' => ['AZ_AFFINITY', ReadFrom::AzAffinity];
        yield 'uppercase name AZ_AFFINITY_REPLICAS_AND_PRIMARY' => ['AZ_AFFINITY_REPLICAS_AND_PRIMARY', ReadFrom::AzAffinityReplicasAndPrimary];

        yield 'name with surrounding whitespace' => ['  primary  ', ReadFrom::Primary];
        yield 'name with tabs' => ["\tprefer_replica\t", ReadFrom::PreferReplica];

        yield 'self instance Primary returns itself' => [ReadFrom::Primary, ReadFrom::Primary];
        yield 'self instance PreferReplica returns itself' => [ReadFrom::PreferReplica, ReadFrom::PreferReplica];
        yield 'self instance AzAffinity returns itself' => [ReadFrom::AzAffinity, ReadFrom::AzAffinity];
        yield 'self instance AzAffinityReplicasAndPrimary returns itself' => [ReadFrom::AzAffinityReplicasAndPrimary, ReadFrom::AzAffinityReplicasAndPrimary];
    }

    /**
     * Verify valid inputs resolve to the expected ReadFrom case.
     *
     * @param  mixed  $input
     * @param  \SineMacula\Valkey\Support\ReadFrom  $expected
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
