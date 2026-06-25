<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Enums;

/**
 * Read strategy enum mirroring the Valkey GLIDE extension READ_FROM_*
 * constants. Provides a typed resolver that accepts a strategy name, an int,
 * or a numeric string, and returns null for anything unresolvable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum ReadFrom: int
{
    case PRIMARY                          = 0;
    case PREFER_REPLICA                   = 1;
    case AZ_AFFINITY                      = 2;
    case AZ_AFFINITY_REPLICAS_AND_PRIMARY = 3;

    /**
     * Resolve a mixed value to a ReadFrom case, or null when unresolvable.
     *
     * Resolution order:
     *   1. A ReadFrom instance is returned as-is.
     *   2. An int is passed to tryFrom() - null when outside 0-3.
     *   3. A string or Stringable is trimmed, then:
     *      - if numeric and integral, cast to int and passed to tryFrom();
     *      - else matched case-insensitively against snake-case strategy names.
     *   4. Anything else (bool, float, array, null, non-stringable object)
     *      returns null.
     *
     * @param  mixed  $value
     * @return self|null
     */
    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value)) {
            return self::tryFrom($value);
        }

        return is_string($value) || $value instanceof \Stringable
            ? self::resolveString(trim((string) $value))
            : null;
    }

    /**
     * Resolve a trimmed string to a ReadFrom case, or null when unresolvable.
     *
     * Integral numeric strings are cast and passed to tryFrom(); otherwise the
     * value is matched case-insensitively against the snake-case strategy
     * names.
     *
     * @param  string  $value
     * @return self|null
     */
    private static function resolveString(string $value): ?self
    {
        if (is_numeric($value) && !str_contains($value, '.') && !str_contains($value, 'e') && !str_contains($value, 'E')) {
            return self::tryFrom((int) $value);
        }

        return self::nameMap()[strtolower($value)] ?? null;
    }

    /**
     * Return a lookup map from lowercased snake-case strategy names to cases.
     *
     * @return array<string, self>
     */
    private static function nameMap(): array
    {
        return [
            'primary'                          => self::PRIMARY,
            'prefer_replica'                   => self::PREFER_REPLICA,
            'az_affinity'                      => self::AZ_AFFINITY,
            'az_affinity_replicas_and_primary' => self::AZ_AFFINITY_REPLICAS_AND_PRIMARY,
        ];
    }
}
