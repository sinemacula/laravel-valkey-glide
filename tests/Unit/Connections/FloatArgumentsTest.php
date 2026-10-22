<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\FloatArguments;

/**
 * Float argument converter test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FloatArguments::class)]
final class FloatArgumentsTest extends TestCase
{
    /**
     * Provide floats and the string phpredis sends for each, as a number it
     * takes as a float.
     *
     * @return iterable<string, array{float, string}>
     */
    public static function doubleProvider(): iterable
    {
        yield 'a short float' => [1.5, '1.5'];
        yield 'a float with more than six digits' => [1.23456789, '1.2345678899999999'];
        yield 'a float with no exact decimal form' => [0.1, '0.10000000000000001'];
        yield 'a large float' => [1e25, '1.0000000000000001e+25'];
        yield 'infinity' => [INF, '+inf'];
        yield 'negative infinity' => [-INF, '-inf'];
    }

    /**
     * Verify double() writes a float as phpredis sends a number it takes as a
     * float, and an infinity as the server reads it.
     *
     * @param  float  $value
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('doubleProvider')]
    #[Test]
    public function doubleWritesAFloatAsPhpRedisSendsIt(float $value, string $expected): void
    {
        self::assertSame($expected, FloatArguments::double($value));
    }

    /**
     * Provide commands, their parameters, and the parameters sent to GLIDE for
     * their values, members, fields and score bounds.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function valuesProvider(): iterable
    {
        yield 'a SET value' => ['set', ['k', 1.23456789], ['k', '1.23456789']];
        yield 'only the SET value' => ['SET', ['k', 19.999999, 1.5, ['NX']], ['k', '19.999999', 1.5, ['NX']]];
        yield 'a large SET value as PHP converts it' => ['set', ['k', 1e20], ['k', '1.0E+20']];
        yield 'HSET fields and values' => ['hSet', ['h', 'f', 1.23456789, 2.5, 'v'], ['h', 'f', '1.23456789', '2.5', 'v']];
        yield 'HSET values in an array' => ['hset', ['h', ['f' => 1.23456789, 'g' => 2]], ['h', ['f' => '1.23456789', 'g' => 2]]];
        yield 'HMSET values' => ['hMset', ['h', ['f' => 1.23456789, 'g' => 'v']], ['h', ['f' => '1.23456789', 'g' => 'v']]];
        yield 'HMGET fields' => ['hmget', ['h', [1.23456789, 'f']], ['h', ['1.23456789', 'f']]];
        yield 'HDEL fields' => ['hdel', ['h', 1.23456789, 'f'], ['h', '1.23456789', 'f']];
        yield 'LPUSH values' => ['lpush', ['l', 1.23456789, 'a', 7], ['l', '1.23456789', 'a', 7]];
        yield 'RPUSH values, and those in an array' => ['rPush', ['l', [1.5, 1.23456789], 0.25], ['l', ['1.5', '1.23456789'], '0.25']];
        yield 'an LPUSHX value' => ['lpushx', ['l', 1.23456789], ['l', '1.23456789']];
        yield 'an RPUSHX value' => ['rpushx', ['l', 1.23456789], ['l', '1.23456789']];
        yield 'ZREM members' => ['zRem', ['z', 1.23456789, 2.5], ['z', '1.23456789', '2.5']];
        yield 'ZMSCORE members' => ['zmscore', ['z', 1.23456789], ['z', '1.23456789']];
        yield 'ZRANGE bounds, not the options' => [
            'zRange',
            ['z', 1.23456789, INF, ['byscore' => true, 'limit' => [0.5, 1]]],
            ['z', '1.23456789', 'INF', ['byscore' => true, 'limit' => [0.5, 1]]],
        ];
        yield 'ZRANGEBYSCORE bounds' => ['zrangebyscore', ['z', 1.23456789, '+inf', ['withscores' => true]], ['z', '1.23456789', '+inf', ['withscores' => true]]];
        yield 'ZREVRANGEBYSCORE bounds' => ['zrevrangebyscore', ['z', '+inf', 1.23456789], ['z', '+inf', '1.23456789']];
        yield 'ZRANGESTORE bounds, not the source' => ['zrangestore', ['d', 's', 1.23456789, 2.5, ['byscore' => true]], ['d', 's', '1.23456789', '2.5', ['byscore' => true]]];
        yield 'GEOADD coordinates and members' => ['geoadd', ['g', 13.361389338970184, 38.1155563954963, 1.5, ['CH']], ['g', '13.36138933897', '38.115556395496', '1.5', ['CH']]];
        yield 'GEOPOS members' => ['geopos', ['g', 1.23456789], ['g', '1.23456789']];
        yield 'GEOHASH members' => ['geohash', ['g', 1.23456789, 'm'], ['g', '1.23456789', 'm']];
        yield 'values are taken in call order whatever their keys' => ['set', [4 => 'k', 9 => 1.23456789], [4 => 'k', 9 => '1.23456789']];
        yield 'named parameters are kept and not counted' => ['set', ['key' => 'k', 0 => 'k', 1 => 1.5, 2 => 2.5], ['key' => 'k', 0 => 'k', 1 => '1.5', 2 => 2.5]];
        yield 'the key is kept' => ['lpush', [1.5, 2.5], [1.5, '2.5']];
        yield 'commands GLIDE writes exactly are kept' => ['setex', ['k', 60, 1.23456789], ['k', 60, 1.23456789]];
        yield 'other commands are kept' => ['zadd', ['z', 1.5, 'm'], ['z', 1.5, 'm']];
    }

    /**
     * Verify normalize sends the float values, members, fields and score bounds
     * GLIDE would write with too few digits as the strings phpredis sends.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $expected
     * @return void
     */
    #[DataProvider('valuesProvider')]
    #[Test]
    public function normalizeSendsFloatValuesAsPhpRedisSendsThem(string $command, array $parameters, array $expected): void
    {
        self::assertSame($expected, (new FloatArguments)->normalize($command, $parameters));
    }

    /**
     * Verify the values are converted into a new list, so the caller's
     * variables are left as they are.
     *
     * @return void
     */
    #[Test]
    public function normalizeLeavesReferencedValuesAsTheyAre(): void
    {
        $value     = 1.23456789;
        $values    = [2.5];
        $increment = 1.23456789;

        self::assertSame(['k', '1.23456789'], (new FloatArguments)->normalize('set', ['k', &$value]));
        self::assertSame(['l', ['2.5']], (new FloatArguments)->normalize('rpush', ['l', &$values]));
        self::assertSame(['k', '1.2345678899999999'], (new FloatArguments)->normalize('incrbyfloat', ['k', &$increment]));
        self::assertSame(1.23456789, $value);
        self::assertSame([2.5], $values);
        self::assertSame(1.23456789, $increment);
    }

    /**
     * Provide increment commands, their parameters, and the parameters sent.
     *
     * @return iterable<string, array{string, array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function incrementProvider(): iterable
    {
        yield 'an INCRBYFLOAT increment with more than six digits' => ['incrbyfloat', ['k', 1.23456789], ['k', '1.2345678899999999']];
        yield 'an HINCRBYFLOAT increment with more than six digits' => ['hIncrByFloat', ['h', 'f', 1.23456789], ['h', 'f', '1.2345678899999999']];
        yield 'an integer increment GLIDE writes with an exponent' => ['incrbyfloat', ['k', 1234567], ['k', '1234567']];
        yield 'a numeric string increment GLIDE writes with too few digits' => ['INCRBYFLOAT', ['k', '19.999999'], ['k', '19.999998999999999']];
        yield 'an increment GLIDE writes as the same float' => ['incrbyfloat', ['k', 0.1], ['k', 0.1]];
        yield 'a numeric string increment GLIDE writes as the same float' => ['hincrbyfloat', ['h', 'f', '2.5'], ['h', 'f', '2.5']];
        yield 'an infinite increment' => ['incrbyfloat', ['k', INF], ['k', INF]];
        yield 'a non-numeric increment' => ['incrbyfloat', ['k', '1.23456789 apples'], ['k', '1.23456789 apples']];
        yield 'a missing increment' => ['hincrbyfloat', ['h', 1.23456789], ['h', 1.23456789]];
        yield 'arguments that are not a list' => ['incrbyfloat', [1 => '19.999999', 2 => 1.5], [1 => '19.999999', 2 => 1.5]];
        yield 'other commands' => ['incrby', ['k', 1.23456789], ['k', 1.23456789]];
    }

    /**
     * Verify normalize writes an increment GLIDE would not write as the same
     * float with phpredis' 17 digits, and leaves the others to GLIDE.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $expected
     * @return void
     */
    #[DataProvider('incrementProvider')]
    #[Test]
    public function normalizeWritesInexactIncrementsAsPhpRedisSendsThem(string $command, array $parameters, array $expected): void
    {
        $arguments = new FloatArguments;

        self::assertSame($expected, $arguments->normalize($command, $parameters));
        self::assertSame($expected !== $parameters, $arguments->isInexactIncrement($command, $parameters));
    }
}
