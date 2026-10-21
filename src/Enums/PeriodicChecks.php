<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Enums;

/**
 * Periodic topology check enum mirroring the Valkey GLIDE extension
 * ValkeyGlideCluster::PERIODIC_CHECK_* constants. Provides a typed resolver
 * that accepts a setting name, an int, or a numeric string, and returns null
 * for anything unresolvable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum PeriodicChecks: int
{
    case ENABLED_DEFAULT_CONFIGS = 0;
    case DISABLED                = 1;

    /**
     * Resolve a mixed value to a PeriodicChecks case, or null when
     * unresolvable.
     *
     * Resolution order:
     *   1. A PeriodicChecks instance is returned as-is.
     *   2. An int is passed to tryFrom() - null when outside 0-1.
     *   3. A string or Stringable is trimmed, then:
     *      - if numeric and integral, cast to int and passed to tryFrom();
     *      - else matched case-insensitively against snake-case setting names.
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
     * Resolve a trimmed string to a PeriodicChecks case, or null when
     * unresolvable.
     *
     * Integral numeric strings are cast and passed to tryFrom(); otherwise the
     * value is matched case-insensitively against the snake-case setting names.
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
     * Return a lookup map from lowercased snake-case setting names to cases.
     *
     * @return array<string, self>
     */
    private static function nameMap(): array
    {
        return [
            'enabled_default_configs' => self::ENABLED_DEFAULT_CONFIGS,
            'enabled'                 => self::ENABLED_DEFAULT_CONFIGS,
            'disabled'                => self::DISABLED,
        ];
    }
}
