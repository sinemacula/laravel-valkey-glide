<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\SortedSetArguments;

/**
 * Sorted-set argument converter test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SortedSetArguments::class)]
final class SortedSetArgumentsTest extends TestCase
{
    /** @var string Message for a score, member or weight with no string form. */
    private const string UNSUPPORTED_VALUE_MESSAGE = '[%s] cannot send a [%s] as a score, member or weight, as it has no string form'
        . ' and GLIDE would corrupt memory on it.';

    /**
     * Provide commands, their parameters, and the parameters sent to GLIDE.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function normalizeProvider(): iterable
    {
        yield 'integer scores' => ['zadd', ['z', 1, 'a', -2, 'b', PHP_INT_MAX, 'c'], ['z', '1', 'a', '-2', 'b', '9223372036854775807', 'c']];
        yield 'float scores, exactly' => [
            'zadd',
            ['z', 0.1 + 0.2, 'a', 1234567.5, 'b', 1.5e-7, 'c', 2.0, 'd'],
            ['z', '0.30000000000000004', 'a', '1234567.5', 'b', '1.4999999999999999e-7', 'c', '2', 'd'],
        ];
        yield 'infinite scores' => ['zadd', ['z', INF, 'a', -INF, 'b'], ['z', '+inf', 'a', '-inf', 'b']];
        yield 'string scores are kept' => ['zadd', ['z', '1.5', 'a', '-inf', 'b', '(1', 'c'], ['z', '1.5', 'a', '-inf', 'b', '(1', 'c']];
        $stringable = new class implements \Stringable {
            /**
             * Get the member's string form.
             *
             * @return string
             */
            #[\Override]
            public function __toString(): string
            {
                return 'member';
            }
        };

        yield 'members as phpredis converts them' => [
            'zadd',
            ['z', 1, 123, 2, 0.1 + 0.2, 3, true, 4, null, 5, $stringable],
            ['z', '1', '123', '2', '0.3', '3', '1', '4', '', '5', 'member'],
        ];
        yield 'an options array is kept' => ['zadd', ['z', ['NX', 'CH'], 1, 'a', 0.5, 2], ['z', ['NX', 'CH'], '1', 'a', '0.5', '2']];
        yield 'the increment form' => ['zAdd', ['z', ['INCR'], 0.1, 7], ['z', ['INCR'], '0.10000000000000001', '7']];
        yield 'an unpaired value is taken by its position' => ['ZADD', ['z', 'NX', 1, 2.5], ['z', 'NX', '1', '2.5']];
        yield 'values are taken in call order whatever their keys' => ['zadd', [4 => 'z', 5 => 1, 6 => 123], ['z', '1', '123']];
        yield 'named parameters are kept, after the positional ones' => ['zadd', ['z', 'score_or_options' => 1.5], ['z', 'score_or_options' => 1.5]];
        yield 'zunionstore weights' => ['zunionstore', ['d', ['a', 'b', 'c'], [1, 0.1, '2'], 'SUM'], ['d', ['a', 'b', 'c'], ['1', '0.10000000000000001', '2'], 'SUM']];
        yield 'zinterstore weights' => ['zInterStore', ['d', ['a', 'b'], [2, INF]], ['d', ['a', 'b'], ['2', '+inf']]];
        yield 'zunion weights' => ['zUnion', [['a', 'b'], [3, 1.5], ['withscores' => true]], [['a', 'b'], ['3', '1.5'], ['withscores' => true]]];
        yield 'zdiffstore weights, which GLIDE parses though ZDIFFSTORE takes none' => ['zDiffStore', ['d', ['a', 'b'], [1, 2]], ['d', ['a', 'b'], ['1', '2']]];
        yield 'zinter weights' => ['zinter', [['a', 'b'], [4, -INF]], [['a', 'b'], ['4', '-inf']]];
        yield 'named weights' => [
            'zunionstore',
            ['d', ['a', 'b'], 'weights' => [1, 0.5], 'aggregate' => 'MIN'],
            ['d', ['a', 'b'], 'weights' => ['1', '0.5'], 'aggregate' => 'MIN'],
        ];
        yield 'weights in call order whatever their keys' => ['zinter', [3 => ['a', 'b'], 7 => [5, 6]], [['a', 'b'], ['5', '6']]];
        yield 'no weights are kept' => ['zunionstore', ['d', ['a', 'b'], null, 'MAX'], ['d', ['a', 'b'], null, 'MAX']];
        yield 'no named weights are kept' => ['zunion', [['a', 'b'], 'weights' => null], [['a', 'b'], 'weights' => null]];
        yield 'weights left out are not added' => ['zunionstore', ['d', ['a', 'b']], ['d', ['a', 'b']]];
        yield 'a weights index that is not a list is kept' => ['zunion', [['a', 'b'], 1.5], [['a', 'b'], 1.5]];
        yield 'other sorted-set commands are kept' => ['zrem', ['z', 1, 2.5], ['z', 1, 2.5]];
        yield 'other commands are kept' => ['set', ['k', 1.5], ['k', 1.5]];
    }

    /**
     * Verify normalize sends scores, members and weights as strings, and leaves
     * everything else as it is.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $expected
     * @return void
     */
    #[DataProvider('normalizeProvider')]
    #[Test]
    public function normalizeSendsScoresMembersAndWeightsAsStrings(string $command, array $parameters, array $expected): void
    {
        self::assertSame($expected, (new SortedSetArguments)->normalize($command, $parameters));
    }

    /**
     * Verify the values are converted into a new list, so a referenced value is
     * converted by its own position and the caller's variables are left as they
     * are, even when a later value is refused.
     *
     * @return void
     */
    #[Test]
    public function normalizeLeavesReferencedValuesAsTheyAre(): void
    {
        $value      = 0.1 + 0.2;
        $weights    = [1, 2.5];
        $parameters = ['z', &$value, &$value];
        $refused    = ['z', &$value, 'a', 2, []];
        $weighted   = ['d', ['a', 'b'], &$weights];

        self::assertSame(['z', '0.30000000000000004', '0.3'], (new SortedSetArguments)->normalize('zadd', $parameters));
        self::assertSame(['d', ['a', 'b'], ['1', '2.5']], (new SortedSetArguments)->normalize('zunionstore', $weighted));

        try {
            (new SortedSetArguments)->normalize('zadd', $refused);
            self::fail('Expected the array member to be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0.1 + 0.2, $value);
        }

        self::assertSame(0.1 + 0.2, $value);
        self::assertSame([1, 2.5], $weights);
    }

    /**
     * Verify every float score written reads back as the same float.
     *
     * @return void
     */
    #[Test]
    public function normalizeWritesFloatScoresThatReadBackExactly(): void
    {
        $scores = [0.1, 1 / 3, -2.718281828459045, 1e-300, 5e-324, PHP_FLOAT_MAX, 1e25, 1792345678.123];

        foreach ($scores as $score) {
            $sent = (new SortedSetArguments)->normalize('zadd', ['z', $score, 'm'])[1];

            self::assertIsString($sent);
            self::assertSame($score, (float) $sent);
        }
    }

    /**
     * Verify a float score is written with a `.` in a locale whose decimal
     * separator is a comma.
     *
     * @return void
     */
    #[Test]
    public function normalizeWritesFloatScoresWhateverTheLocale(): void
    {
        $locale = setlocale(LC_NUMERIC, '0');

        if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR') === false) {
            self::markTestSkipped('No locale with a comma decimal separator is installed.');
        }

        try {
            self::assertSame(['z', '0.5', 'm'], (new SortedSetArguments)->normalize('zadd', ['z', 0.5, 'm']));
        } finally {
            setlocale(LC_NUMERIC, $locale === false ? 'C' : $locale);
        }
    }

    /**
     * Provide commands and parameters holding a value with no string form.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, string}>
     */
    public static function unsupportedValueProvider(): iterable
    {
        yield 'array member' => ['zadd', ['z', 1, ['m']], 'array'];
        yield 'object member' => ['zAdd', ['z', ['NX'], 1, new \stdClass], \stdClass::class];
        yield 'array score after the first pair' => ['zadd', ['z', 1, 'a', ['NX'], 'b'], 'array'];
        yield 'object weight' => ['zunionstore', ['d', ['a'], [new \stdClass]], \stdClass::class];
        yield 'array weight' => ['zinter', [['a'], [[1]]], 'array'];
    }

    /**
     * Verify a score, member or weight with no string form is refused.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @param  string  $type
     * @return void
     */
    #[DataProvider('unsupportedValueProvider')]
    #[Test]
    public function normalizeRefusesAValueWithNoStringForm(string $command, array $parameters, string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(self::UNSUPPORTED_VALUE_MESSAGE, strtoupper($command), $type));

        (new SortedSetArguments)->normalize($command, $parameters);
    }
}
