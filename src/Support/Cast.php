<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Support;

/**
 * Coerce mixed configuration and command values into normalized scalar types.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Cast
{
    /**
     * Coerce a mixed value into a non-negative integer, or null when it cannot.
     *
     * Accepts integers, floats, numeric strings, and numeric stringables;
     * negative results and non-numeric values resolve to null.
     *
     * @param  mixed  $value
     * @return int|null
     */
    public static function toNonNegativeInt(mixed $value): ?int
    {
        $normalized = match (true) {
            is_int($value)                                               => $value,
            is_float($value)                                             => (int) $value,
            is_string($value)             && is_numeric($value)          => (int) $value,
            $value instanceof \Stringable && is_numeric((string) $value) => (int) (string) $value,
            default                                                      => null,
        };

        return $normalized !== null && $normalized >= 0 ? $normalized : null;
    }

    /**
     * Coerce a mixed value into a non-empty string, or null when it cannot.
     *
     * Accepts non-empty strings, integers, floats, booleans, and stringables;
     * empty strings and other types resolve to null.
     *
     * @param  mixed  $value
     * @return string|null
     */
    public static function toNonEmptyString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value instanceof \Stringable) {
            $normalized = (string) $value;

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
