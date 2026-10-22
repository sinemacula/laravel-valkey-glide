<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

/**
 * Convert the float arguments GLIDE would write with too few digits.
 *
 * ext-valkey_glide 1.1.2 writes a float argument with six significant digits,
 * or a list value with six decimals, so 1.23456789 reaches the server as
 * 1.23457, or as 1.234568. GLIDE sends a string as it is, so the floats among
 * the values, members, fields and score bounds it takes as any type become the
 * strings PHP converts them to, as phpredis sends them. GLIDE takes the
 * increment of INCRBYFLOAT and HINCRBYFLOAT as a float whatever is passed, so
 * one it would not write as the same float is sent through the raw command,
 * with 17 significant digits, as phpredis writes it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class FloatArguments
{
    /** @var array<string, array{int, int|null}> Commands GLIDE writes float values, members, fields or bounds of with too few digits, by the positions of the first and last such arguments; null for all that follow. */
    private const array VALUE_POSITIONS = [
        'GEOADD'           => [1, null],
        'GEOHASH'          => [1, null],
        'GEOPOS'           => [1, null],
        'HDEL'             => [1, null],
        'HMGET'            => [1, 1],
        'HMSET'            => [1, 1],
        'HSET'             => [1, null],
        'LPUSH'            => [1, null],
        'LPUSHX'           => [1, null],
        'RPUSH'            => [1, null],
        'RPUSHX'           => [1, null],
        'SET'              => [1, 1],
        'ZMSCORE'          => [1, null],
        'ZRANGE'           => [1, 2],
        'ZRANGEBYSCORE'    => [1, 2],
        'ZRANGESTORE'      => [2, 3],
        'ZREM'             => [1, null],
        'ZREVRANGEBYSCORE' => [1, 2],
    ];

    /** @var array<string, int> Commands whose increment GLIDE takes as a float, by the position of the increment. */
    private const array INCREMENT_POSITIONS = [
        'HINCRBYFLOAT' => 2,
        'INCRBYFLOAT'  => 1,
    ];

    /**
     * Write a float as phpredis sends a number it takes as a float.
     *
     * The float is written with 17 significant digits and a `.` whatever the
     * locale, so the server reads back the same float, and an infinity as
     * `+inf` or `-inf`.
     *
     * @param  float  $value
     * @return string
     */
    public static function double(float $value): string
    {
        return match ($value) {
            INF     => '+inf',
            -INF    => '-inf',
            default => sprintf('%.17h', $value),
        };
    }

    /**
     * Convert a command's float arguments to the form sent to GLIDE.
     *
     * The floats among the values, members, fields and score bounds, and those
     * one level inside an array there, as in `hset($key, ['field' => 1.5])`,
     * become the strings PHP converts them to. The arguments are read by
     * position, those with integer keys in order. An inexact increment is
     * written by double(). A new array is returned, so a variable the caller
     * passed by reference is left as it is.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    public function normalize(string $command, array $parameters): array
    {
        $command = strtoupper($command);

        if (array_key_exists($command, self::VALUE_POSITIONS)) {
            return $this->normalizeValues($parameters, ...self::VALUE_POSITIONS[$command]);
        }

        $increment = $this->inexactIncrement($command, $parameters);

        return $increment !== null ? array_replace($parameters, [self::INCREMENT_POSITIONS[$command] => self::double($increment)]) : $parameters;
    }

    /**
     * Determine whether a command is an INCRBYFLOAT or HINCRBYFLOAT whose
     * increment GLIDE would not write as the same float, and so must be sent
     * through the raw command.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     */
    public function isInexactIncrement(string $command, array $parameters): bool
    {
        return $this->inexactIncrement(strtoupper($command), $parameters) !== null;
    }

    /**
     * Resolve the numeric increment GLIDE would not write as the same float, if
     * the command has one.
     *
     * Arguments that are not a list, and infinities, which the server refuses
     * either way, are left to GLIDE.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @return float|null
     */
    private function inexactIncrement(string $command, array $parameters): ?float
    {
        $position  = self::INCREMENT_POSITIONS[$command] ?? null;
        $increment = $position !== null && array_is_list($parameters) ? ($parameters[$position] ?? null) : null;

        if (!is_numeric($increment)) {
            return null;
        }

        $value = (float) $increment;

        return is_finite($value) && (float) sprintf('%.6h', $value) !== $value ? $value : null;
    }

    /**
     * Convert the floats among the arguments in the given positions.
     *
     * @param  array<array-key, mixed>  $parameters
     * @param  int  $first
     * @param  int|null  $last
     * @return array<array-key, mixed>
     */
    private function normalizeValues(array $parameters, int $first, ?int $last): array
    {
        $normalized = [];
        $position   = 0;

        foreach ($parameters as $key => $value) {
            if (is_int($key) && $position >= $first && ($last === null || $position <= $last)) {
                $value = is_array($value) ? array_map($this->toValue(...), $value) : $this->toValue($value);
            }

            $normalized[$key] = $value;
            $position += is_int($key) ? 1 : 0;
        }

        return $normalized;
    }

    /**
     * Convert a float value to the string PHP converts it to, as phpredis sends
     * it.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function toValue(mixed $value): mixed
    {
        return is_float($value) ? (string) $value : $value;
    }
}
