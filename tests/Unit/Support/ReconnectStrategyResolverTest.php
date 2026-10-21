<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Support\ReconnectStrategyResolver;

/**
 * ReconnectStrategyResolver test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ReconnectStrategyResolver::class)]
final class ReconnectStrategyResolverTest extends TestCase
{
    /** @var int Longest steady reconnect delay the resolver accepts, in milliseconds. */
    private const int LONGEST_DELAY = 3600000;

    /**
     * Verify resolve returns null when no reconnect strategy is configured.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenStrategyIsAbsent(): void
    {
        self::assertNull(ReconnectStrategyResolver::resolve(null));
    }

    /**
     * Verify resolve treats an empty strategy as not configured.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenStrategyIsEmpty(): void
    {
        self::assertNull(ReconnectStrategyResolver::resolve([]));
    }

    /**
     * Verify resolve passes every supported key through unchanged.
     *
     * @return void
     */
    #[Test]
    public function resolvePassesEverySupportedKeyThrough(): void
    {
        $strategy = [
            'num_of_retries' => 8,
            'factor'         => 250,
            'exponent_base'  => 3,
            'jitter_percent' => 15,
        ];

        self::assertSame($strategy, ReconnectStrategyResolver::resolve($strategy));
    }

    /**
     * Verify resolve forwards only the configured keys so the extension applies
     * its own defaults to the rest.
     *
     * @return void
     */
    #[Test]
    public function resolveForwardsOnlyConfiguredKeys(): void
    {
        self::assertSame(['factor' => 500], ReconnectStrategyResolver::resolve(['factor' => 500]));
    }

    /**
     * Verify resolve casts digit-only strings, as read from the environment.
     *
     * @return void
     */
    #[Test]
    public function resolveCastsDigitStringsToIntegers(): void
    {
        self::assertSame(
            ['num_of_retries' => 8, 'jitter_percent' => 0],
            ReconnectStrategyResolver::resolve(['num_of_retries' => '8', 'jitter_percent' => '0']),
        );
    }

    /**
     * Provide strategies whose settings sit exactly on an accepted bound.
     *
     * @return iterable<string, array{array<string, int>}>
     */
    public static function boundaryStrategies(): iterable
    {
        yield 'minimum retries' => [['num_of_retries' => 1]];
        yield 'maximum retries' => [['num_of_retries' => 100, 'exponent_base' => 1]];
        yield 'minimum factor' => [['factor' => 1]];
        yield 'maximum factor' => [['factor' => self::LONGEST_DELAY, 'exponent_base' => 1]];
        yield 'minimum exponent base' => [['exponent_base' => 1]];
        yield 'maximum exponent base' => [['exponent_base' => self::LONGEST_DELAY, 'factor' => 1, 'num_of_retries' => 1]];
        yield 'minimum jitter' => [['jitter_percent' => 0]];
        yield 'maximum jitter' => [['jitter_percent' => 100]];
    }

    /**
     * Verify resolve accepts settings on their inclusive bounds.
     *
     * @param  array<string, int>  $strategy
     * @return void
     */
    #[DataProvider('boundaryStrategies')]
    #[Test]
    public function resolveAcceptsSettingsOnTheirBounds(array $strategy): void
    {
        self::assertSame($strategy, ReconnectStrategyResolver::resolve($strategy));
    }

    /**
     * Provide settings outside their bounds, with the expected range.
     *
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function outOfRangeSettings(): iterable
    {
        yield 'zero retries' => ['num_of_retries', 0, 'from 1 to 100'];
        yield 'retries above maximum' => ['num_of_retries', 101, 'from 1 to 100'];
        yield 'zero factor' => ['factor', 0, 'from 1 to 3600000'];
        yield 'factor above maximum' => ['factor', self::LONGEST_DELAY + 1, 'from 1 to 3600000'];
        yield 'zero exponent base' => ['exponent_base', 0, 'from 1 to 3600000'];
        yield 'exponent base above maximum' => ['exponent_base', self::LONGEST_DELAY + 1, 'from 1 to 3600000'];
        yield 'negative jitter' => ['jitter_percent', -1, 'from 0 to 100'];
        yield 'jitter above maximum' => ['jitter_percent', 101, 'from 0 to 100'];
        yield 'digit string above maximum' => ['num_of_retries', '101', 'from 1 to 100'];
        yield 'digit string above the extension integer' => ['factor', '2147483648', 'from 1 to 3600000'];
    }

    /**
     * Verify resolve rejects settings outside their bounds.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  string  $range
     * @return void
     */
    #[DataProvider('outOfRangeSettings')]
    #[Test]
    public function resolveRejectsSettingsOutsideTheirBounds(string $key, mixed $value, string $range): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('The [reconnect_strategy.%s] option must be an integer %s.', $key, $range));

        ReconnectStrategyResolver::resolve([$key => $value]);
    }

    /**
     * Provide strategies whose steady delay is exactly the ceiling, including
     * through the extension's defaults for the keys left out.
     *
     * @return iterable<string, array{array<string, int>}>
     */
    public static function strategiesAtTheDelayCeiling(): iterable
    {
        yield 'every key configured' => [['num_of_retries' => 4, 'factor' => 225000, 'exponent_base' => 2]];
        yield 'default retries and base' => [['factor' => 112500]];
        yield 'default factor' => [['num_of_retries' => 1, 'exponent_base' => 36000]];
        yield 'default exponent base' => [['num_of_retries' => 1, 'factor' => 1800000]];
    }

    /**
     * Verify resolve accepts a steady delay of exactly the ceiling.
     *
     * @param  array<string, int>  $strategy
     * @return void
     */
    #[DataProvider('strategiesAtTheDelayCeiling')]
    #[Test]
    public function resolveAcceptsDelaysUpToTheCeiling(array $strategy): void
    {
        self::assertSame($strategy, ReconnectStrategyResolver::resolve($strategy));
    }

    /**
     * Provide strategies whose steady delay exceeds the ceiling although each
     * setting is within its own bounds.
     *
     * @return iterable<string, array{array<string, int>}>
     */
    public static function strategiesAboveTheDelayCeiling(): iterable
    {
        yield 'every key configured' => [['num_of_retries' => 4, 'factor' => 225001, 'exponent_base' => 2]];
        yield 'default retries and base' => [['factor' => 112501]];
        yield 'default factor' => [['num_of_retries' => 1, 'exponent_base' => 36001]];
        yield 'default exponent base' => [['num_of_retries' => 1, 'factor' => 1800001]];
        yield 'years between attempts' => [['num_of_retries' => 8, 'factor' => 1000, 'exponent_base' => 10]];
        yield 'maximum retries at the default base' => [['num_of_retries' => 100]];
        yield 'maximum exponent base at the default retries' => [['exponent_base' => self::LONGEST_DELAY]];
    }

    /**
     * Verify resolve rejects a steady delay above the ceiling, which GLIDE
     * would otherwise wait out between every reconnect attempt.
     *
     * @param  array<string, int>  $strategy
     * @return void
     */
    #[DataProvider('strategiesAboveTheDelayCeiling')]
    #[Test]
    public function resolveRejectsDelaysAboveTheCeiling(array $strategy): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The [reconnect_strategy] delay of factor * exponent_base^num_of_retries must not exceed 3600000ms.');

        ReconnectStrategyResolver::resolve($strategy);
    }

    /**
     * Provide setting values that are not integers.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function nonIntegerSettings(): iterable
    {
        yield 'float' => [2.5];
        yield 'integral float' => [5.0];
        yield 'boolean' => [true];
        yield 'null' => [null];
        yield 'negative string' => ['-1'];
        yield 'decimal string' => ['2.5'];
        yield 'padded string' => [' 5'];
        yield 'trailing newline string' => ["5\n"];
        yield 'word' => ['five'];
        yield 'empty string' => [''];
        yield 'array' => [[5]];
    }

    /**
     * Verify resolve rejects setting values that are not integers.
     *
     * @param  mixed  $value
     * @return void
     */
    #[DataProvider('nonIntegerSettings')]
    #[Test]
    public function resolveRejectsNonIntegerSettings(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The [reconnect_strategy.num_of_retries] option must be an integer from 1 to 100.');

        ReconnectStrategyResolver::resolve(['num_of_retries' => $value]);
    }

    /**
     * Verify resolve rejects the core's number_of_retries spelling, which the
     * extension would silently ignore.
     *
     * @return void
     */
    #[Test]
    public function resolveRejectsUnsupportedKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported [reconnect_strategy] key [number_of_retries]; expected one of [num_of_retries, factor, exponent_base, jitter_percent].');

        ReconnectStrategyResolver::resolve(['number_of_retries' => 8]);
    }

    /**
     * Verify resolve rejects a list in place of a keyed strategy.
     *
     * @return void
     */
    #[Test]
    public function resolveRejectsListStrategies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported [reconnect_strategy] key [0]');

        ReconnectStrategyResolver::resolve([8, 250]);
    }

    /**
     * Verify resolve rejects a strategy that is not an array.
     *
     * @return void
     */
    #[Test]
    public function resolveRejectsNonArrayStrategies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The [reconnect_strategy] option must be an array, received [string].');

        ReconnectStrategyResolver::resolve('exponential');
    }
}
