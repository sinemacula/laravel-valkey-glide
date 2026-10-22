<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

/**
 * Convert the scores, members and weights of sorted-set commands to strings.
 *
 * ext-valkey_glide 1.1.2 writes each integer or float score of a ZADD, and each
 * integer or float weight of a ZUNIONSTORE, ZINTERSTORE, ZUNION or ZINTER, or
 * of a ZDIFFSTORE, which it takes weights for though the server does not, to a
 * string it tracks in a buffer of 20 entries, so a call with more than 20 of
 * them overflows the buffer and corrupts the heap
 * (https://github.com/valkey-io/valkey-glide-php/issues/379). It frees its
 * buffers twice when a ZADD member is not a string
 * (https://github.com/valkey-io/valkey-glide-php/issues/380), and it writes a
 * float with six significant digits. A string is sent as it is, so each value
 * is converted first: a number as phpredis sends it, exactly, and a member as
 * phpredis converts it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class SortedSetArguments
{
    /** @var array<string, int> Commands GLIDE takes a list of weights for, by the position of the list; the server refuses weights on ZDIFFSTORE. */
    private const array WEIGHTS_POSITIONS = [
        'ZDIFFSTORE'  => 2,
        'ZINTER'      => 1,
        'ZINTERSTORE' => 2,
        'ZUNION'      => 1,
        'ZUNIONSTORE' => 2,
    ];

    /** @var string Message for a score, member or weight with no string form. */
    private const string UNSUPPORTED_VALUE_MESSAGE = '[%s] cannot send a [%s] as a score, member or weight, as it has no string form'
        . ' and GLIDE would corrupt memory on it.';

    /**
     * Convert a sorted-set command's scores, members and weights to strings.
     *
     * The arguments are read as PHP passes them: those with integer keys by
     * position, in order, whatever the keys, and the others by name, as the
     * weights of `zunionstore($destination, $keys, weights: $weights)` are. A
     * ZADD's positional values after the key, and after its options array when
     * one is given, are taken in score and member pairs; GLIDE takes none of
     * them by name. The arguments of these commands are returned as a new list,
     * followed by the named ones, so a variable the caller passed by reference
     * is left as it is. Other commands are returned as they are.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public function normalize(string $command, array $parameters): array
    {
        $command  = strtoupper($command);
        $position = self::WEIGHTS_POSITIONS[$command] ?? null;

        if ($command !== 'ZADD' && $position === null) {
            return $parameters;
        }

        $values = [];
        $named  = [];

        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $values[] = $value;
            } else {
                $named[$key] = $value;
            }
        }

        if ($position === null) {
            return [...$this->normalizeScoresAndMembers($values), ...$named];
        }

        if (array_key_exists($position, $values)) {
            $values[$position] = $this->normalizeWeights($command, $values[$position]);
        }

        if (array_key_exists('weights', $named)) {
            $named['weights'] = $this->normalizeWeights($command, $named['weights']);
        }

        return [...$values, ...$named];
    }

    /**
     * Convert a ZADD's score and member pairs to strings.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeScoresAndMembers(array $values): array
    {
        $isScore = true;

        for ($index = is_array($values[1] ?? null) ? 2 : 1; array_key_exists($index, $values); $index++) {
            $values[$index] = $isScore ? $this->toScore('ZADD', $values[$index]) : $this->toMember('ZADD', $values[$index]);
            $isScore        = !$isScore;
        }

        return $values;
    }

    /**
     * Convert a list of weights to strings; no list is returned as it is.
     *
     * @param  string  $command
     * @param  mixed  $weights
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeWeights(string $command, mixed $weights): mixed
    {
        return is_array($weights) ? array_map(fn (mixed $weight): string => $this->toScore($command, $weight), $weights) : $weights;
    }

    /**
     * Convert a score or weight to the string phpredis sends for it.
     *
     * A float is written with 17 significant digits and a `.` whatever the
     * locale, as phpredis writes it, so the server reads back the same float,
     * and an infinity as `+inf` or `-inf`. Any other value is converted as a
     * member is.
     *
     * @param  string  $command
     * @param  mixed  $score
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function toScore(string $command, mixed $score): string
    {
        if (!is_float($score)) {
            return $this->toMember($command, $score);
        }

        return match ($score) {
            INF     => '+inf',
            -INF    => '-inf',
            default => sprintf('%.17h', $score),
        };
    }

    /**
     * Convert a member to a string, as phpredis converts it.
     *
     * @param  string  $command
     * @param  mixed  $member
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function toMember(string $command, mixed $member): string
    {
        if ($member === null || is_scalar($member) || $member instanceof \Stringable) {
            return (string) $member;
        }

        throw new \InvalidArgumentException(sprintf(self::UNSUPPORTED_VALUE_MESSAGE, $command, get_debug_type($member)));
    }
}
