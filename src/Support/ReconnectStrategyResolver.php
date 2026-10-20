<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Support;

/**
 * Validate a Laravel reconnect_strategy block into GLIDE's backoff shape.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ReconnectStrategyResolver
{
    /** @var int Longest steady reconnect delay accepted, in milliseconds (one hour). */
    private const int LONGEST_DELAY = 3600000;

    /** @var array<string, array{int, int}> Inclusive bounds for each supported key. */
    private const array BOUNDS = [
        'num_of_retries' => [1, 100],
        'factor'         => [1, self::LONGEST_DELAY],
        'exponent_base'  => [1, self::LONGEST_DELAY],
        'jitter_percent' => [0, 100],
    ];

    /** @var array<string, int> The extension's defaults for the keys that set the delay. */
    private const array DEFAULTS = [
        'num_of_retries' => 5,
        'factor'         => 100,
        'exponent_base'  => 2,
    ];

    /**
     * Resolve the reconnect strategy, or null when none is configured.
     *
     * Keys left out fall back to GLIDE's defaults inside the extension. Unknown
     * keys are rejected because the extension ignores them silently.
     *
     * @param  mixed  $value
     * @return array<string, int>|null
     *
     * @throws \InvalidArgumentException
     */
    public static function resolve(mixed $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('The [reconnect_strategy] option must be an array, received [%s].', get_debug_type($value)));
        }

        $strategy = [];

        foreach ($value as $key => $setting) {
            if (!array_key_exists($key, self::BOUNDS)) {
                throw new \InvalidArgumentException(sprintf('Unsupported [reconnect_strategy] key [%s]; expected one of [%s].', $key, implode(', ', array_keys(self::BOUNDS))));
            }

            $strategy[$key] = self::setting($key, $setting);
        }

        self::assertDelayIsBounded($strategy);

        return $strategy;
    }

    /**
     * Reject a strategy whose steady reconnect delay exceeds the ceiling.
     *
     * Once a connection drops, GLIDE works through the increasing delays and
     * then retries forever at factor * exponent_base^num_of_retries
     * milliseconds. It saturates that delay rather than failing on overflow, so
     * a large product leaves the connection effectively never reconnecting.
     *
     * @param  array<string, int>  $strategy
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private static function assertDelayIsBounded(array $strategy): void
    {
        ['num_of_retries' => $retries, 'factor' => $factor, 'exponent_base' => $base] = $strategy + self::DEFAULTS;

        if ($factor * $base ** $retries > self::LONGEST_DELAY) {
            throw new \InvalidArgumentException(sprintf('The [reconnect_strategy] delay of factor * exponent_base^num_of_retries must not exceed %dms.', self::LONGEST_DELAY));
        }
    }

    /**
     * Validate a single reconnect strategy setting against its bounds.
     *
     * Integers and digit-only strings (as read from the environment) are
     * accepted; the extension drops anything that is not an integer. The bounds
     * protect GLIDE's backoff: zero retries underflows it, a jitter above 100
     * percent turns its delay multiplier negative, which panics, and GLIDE
     * steps through every retry synchronously each time it starts reconnecting.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return int
     *
     * @throws \InvalidArgumentException
     */
    private static function setting(string $key, mixed $value): int
    {
        [$minimum, $maximum] = self::BOUNDS[$key];

        $setting = match (true) {
            is_int($value)                                            => $value,
            is_string($value) && preg_match('/^\d+$/D', $value) === 1 => (int) $value,
            default                                                   => null,
        };

        if ($setting === null || $setting < $minimum || $setting > $maximum) {
            throw new \InvalidArgumentException(sprintf('The [reconnect_strategy.%s] option must be an integer from %d to %d.', $key, $minimum, $maximum));
        }

        return $setting;
    }
}
