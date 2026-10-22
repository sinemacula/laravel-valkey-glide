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
     * Provide commands and parameters, and the command and parameters sent for
     * them.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, array{string, array<array-key, mixed>}}>
     */
    public static function rewriteIncrementProvider(): iterable
    {
        yield 'a float increment' => ['zincrby', ['z', 1.23456789, 'm'], ['zAdd', ['z', ['INCR'], 1.23456789, 'm']]];
        yield 'an integer increment, as a float' => ['zIncrBy', ['z', -3, 7], ['zAdd', ['z', ['INCR'], -3.0, 7]]];
        yield 'a numeric string increment, as a float' => ['ZINCRBY', ['z', ' 1.5e3', 'm'], ['zAdd', ['z', ['INCR'], 1500.0, 'm']]];
        yield 'an infinite increment' => ['zincrby', ['z', -INF, 'm'], ['zAdd', ['z', ['INCR'], -INF, 'm']]];
        yield 'a non-numeric increment is kept' => ['zincrby', ['z', 'abc', 'm'], ['zincrby', ['z', 'abc', 'm']]];
        yield 'a boolean increment is kept' => ['zincrby', ['z', true, 'm'], ['zincrby', ['z', true, 'm']]];
        yield 'too few arguments are kept' => ['zincrby', ['z', 1.5], ['zincrby', ['z', 1.5]]];
        yield 'too many arguments are kept' => ['zincrby', ['z', 1.5, 'm', 'n'], ['zincrby', ['z', 1.5, 'm', 'n']]];
        yield 'other commands are kept' => ['hincrbyfloat', ['h', 1.5, 'f'], ['hincrbyfloat', ['h', 1.5, 'f']]];
        yield 'named arguments are kept' => ['zincrby', ['z', 'value' => 1.5, 'member' => 'm'], ['zincrby', ['z', 'value' => 1.5, 'member' => 'm']]];
    }

    /**
     * Verify rewriteIncrement sends a ZINCRBY with a numeric increment as the
     * ZADD ... INCR it is equivalent to, and leaves everything else as it is.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @param  array{string, array<array-key, mixed>}  $expected
     * @return void
     */
    #[DataProvider('rewriteIncrementProvider')]
    #[Test]
    public function rewriteIncrementSendsANumericIncrementAsZaddIncr(string $command, array $parameters, array $expected): void
    {
        self::assertSame($expected, (new SortedSetArguments)->rewriteIncrement($command, $parameters));
    }

    /**
     * Verify the increment rewritten as a ZADD ... INCR score is sent with 17
     * significant digits, where GLIDE's zIncrBy() would write six.
     *
     * @return void
     */
    #[Test]
    public function rewrittenIncrementIsSentExactly(): void
    {
        $arguments = new SortedSetArguments;

        [$command, $parameters] = $arguments->rewriteIncrement('zincrby', ['z', 1.23456789, 'm']);

        self::assertSame(['z', ['INCR'], '1.2345678899999999', 'm'], $arguments->normalize($command, $parameters));
    }

    /**
     * Provide the arguments of a zadd() after its key, and the arguments sent
     * for them.
     *
     * @return iterable<string, array{array<array-key, mixed>, list<mixed>}>
     */
    public static function zaddArgumentsProvider(): iterable
    {
        yield 'score and member pairs' => [[1, 'a', 2.5, 'b'], [[], 1, 'a', 2.5, 'b']];
        yield 'a dictionary of scores by member' => [[['a' => 1, 'b' => 2.5, 7 => 3]], [[], 1, 'a', 2.5, 'b', 3, 7]];
        yield 'pairs, then a dictionary' => [[1, 'a', ['b' => 2]], [[], 1, 'a', 2, 'b']];
        yield 'an option string' => [['NX', 1, 'a'], [['NX'], 1, 'a']];
        yield 'option strings in either case' => [['xx', 'CH', 1, 'a'], [['xx', 'CH'], 1, 'a']];
        yield 'the increment option' => [['INCR', 0.5, 'a'], [['INCR'], 0.5, 'a']];
        yield 'three option strings' => [['gt', 'ch', 'INCR', 1, 'a'], [['gt', 'ch', 'INCR'], 1, 'a']];
        yield 'option strings, then a dictionary' => [['LT', 'CH', ['a' => 4]], [['LT', 'CH'], 4, 'a']];
        yield 'an option string among the first three values' => [[1, 'xx', 'a'], [['xx'], 1, 'a']];
        yield 'an option string after the first three values is a member' => [[1, 'a', 2, 'nx'], [[], 1, 'a', 2, 'nx']];
        yield 'an option string in mixed case is a member' => [[1, 'Nx'], [[], 1, 'Nx']];
        yield 'a GLIDE options array is kept' => [[['NX', 'CH'], 1, 'a'], [['NX', 'CH'], 1, 'a']];
        yield 'a GLIDE options array, then a dictionary' => [[['XX'], ['a' => 1]], [['XX'], 1, 'a']];
        yield 'a GLIDE options array keeps a member named after an option' => [[['XX'], 1, 'ch'], [['XX'], 1, 'ch']];
        yield 'values are taken in call order whatever their keys' => [[3 => 'NX', 'score' => 1, 9 => 'a'], [['NX'], 1, 'a']];
        yield 'no values' => [[], [[]]];
    }

    /**
     * Verify zaddArguments reads a zadd() as Laravel's phpredis connection
     * reads it, and keeps GLIDE's own options array.
     *
     * @param  array<array-key, mixed>  $dictionary
     * @param  list<mixed>  $expected
     * @return void
     */
    #[DataProvider('zaddArgumentsProvider')]
    #[Test]
    public function zaddArgumentsReadsLaravelsForms(array $dictionary, array $expected): void
    {
        self::assertSame($expected, (new SortedSetArguments)->zaddArguments($dictionary));
    }

    /**
     * Provide the weights and aggregate of a ZINTERSTORE or ZUNIONSTORE, and
     * the weights and aggregate sent for them.
     *
     * @return iterable<string, array{mixed, mixed, array{mixed, mixed}}>
     */
    public static function storeArgumentsProvider(): iterable
    {
        yield 'Laravel options' => [['weights' => [2, 1], 'aggregate' => 'max'], null, [[2, 1], 'max']];
        yield 'Laravel options without an aggregate' => [['weights' => [2, 1]], null, [[2, 1], 'sum']];
        yield 'Laravel options without weights' => [['aggregate' => 'MIN'], null, [null, 'MIN']];
        yield 'Laravel options with other keys only' => [['AGGREGATE' => 'MIN'], null, [null, 'sum']];
        yield 'Laravel options name the aggregate before a later one' => [['aggregate' => 'MIN'], 'MAX', [null, 'MIN']];
        yield 'an aggregate after Laravel options that name none' => [['weights' => [1, 2]], 'MAX', [[1, 2], 'MAX']];
        yield 'empty options' => [[], null, [null, 'sum']];
        yield 'empty options, then an aggregate' => [[], 'MAX', [null, 'MAX']];
        yield 'a weights list is kept' => [[2, 1], 'MAX', [[2, 1], 'MAX']];
        yield 'a weights list without an aggregate is kept' => [[2, 1], null, [[2, 1], null]];
        yield 'weights keyed by integers out of order are kept' => [[3 => 2, 1 => 1], 'MAX', [[3 => 2, 1 => 1], 'MAX']];
        yield 'Laravel options beside integer keys' => [[0 => 2, 'aggregate' => 'max'], null, [null, 'max']];
        yield 'no weights are kept' => [null, 'MIN', [null, 'MIN']];
        yield 'nothing is kept' => [null, null, [null, null]];
    }

    /**
     * Verify storeArguments reads Laravel's options array as Laravel's phpredis
     * connection reads it, and keeps GLIDE's weights list and aggregate.
     *
     * @param  mixed  $weights
     * @param  mixed  $aggregate
     * @param  array{mixed, mixed}  $expected
     * @return void
     */
    #[DataProvider('storeArgumentsProvider')]
    #[Test]
    public function storeArgumentsReadsLaravelsOptions(mixed $weights, mixed $aggregate, array $expected): void
    {
        self::assertSame($expected, (new SortedSetArguments)->storeArguments($weights, $aggregate));
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
