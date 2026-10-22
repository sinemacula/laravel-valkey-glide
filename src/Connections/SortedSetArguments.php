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
 * phpredis converts it. It also writes a ZINCRBY increment with six significant
 * digits, from the float it takes, so the increment is sent as a ZADD ... INCR
 * score instead. Laravel's phpredis connection takes forms of zadd(),
 * zinterstore() and zunionstore() that GLIDE does not, which are read here as
 * Laravel reads them.
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

    /** @var list<string> The ZADD options Laravel's phpredis connection takes as strings, among the first three values after the key. */
    private const array ZADD_OPTIONS = ['nx', 'xx', 'ch', 'incr', 'gt', 'lt', 'NX', 'XX', 'CH', 'INCR', 'GT', 'LT'];

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
     * Send a ZINCRBY as the ZADD ... INCR it is equivalent to.
     *
     * GLIDE's zIncrBy() takes the increment as a float and writes it with six
     * significant digits, so 1.23456789 would be added as 1.23457. A ZINCRBY
     * given its key, a numeric increment and its member, in that order, is
     * returned as a ZADD ... INCR instead, whose score normalize() then sends
     * exactly; it adds the increment, creating the member if it is missing, and
     * returns the new score as a float, as ZINCRBY does. The increment is read
     * as a float first, as phpredis reads it. Any other command, and a ZINCRBY
     * given other arguments, is returned as it is, for GLIDE to take or refuse.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @return array{string, array<array-key, mixed>}
     */
    public function rewriteIncrement(string $command, array $parameters): array
    {
        if (strtoupper($command) !== 'ZINCRBY' || !array_is_list($parameters) || count($parameters) !== 3 || !is_numeric($parameters[1])) {
            return [$command, $parameters];
        }

        [$key, $increment, $member] = $parameters;

        return ['zAdd', [$key, ['INCR'], (float) $increment, $member]];
    }

    /**
     * Read the arguments of a zadd() after its key as Laravel's phpredis
     * connection reads them.
     *
     * A trailing array of scores keyed by member, as in `zadd($key, ['a' =>
     * 1])`, is taken as score and member pairs, and the option strings among
     * the first three values, as in `zadd($key, 'NX', 1, 'a')`, are gathered
     * into the options array that leads the arguments, which is empty when
     * there are none. Arguments that already lead with an options array, as
     * GLIDE takes them, are kept as they are, after the pairs of a trailing
     * array; Laravel would read that array as a score.
     *
     * @param  array<array-key, mixed>  $dictionary
     * @return list<mixed>
     */
    public function zaddArguments(array $dictionary): array
    {
        $values = array_values($dictionary);
        $scores = end($values);

        if (is_array($scores)) {
            array_pop($values);

            foreach ($scores as $member => $score) {
                array_push($values, $score, $member);
            }
        }

        if (is_array($values[0] ?? null)) {
            return $values;
        }

        $options = [];

        foreach (array_slice($values, 0, 3) as $index => $value) {
            if (!in_array($value, self::ZADD_OPTIONS, true)) {
                continue;
            }

            $options[] = $value;

            unset($values[$index]);
        }

        return [$options, ...$values];
    }

    /**
     * Read the weights and aggregate of a zinterstore() or zunionstore() as
     * Laravel's phpredis connection reads them.
     *
     * Laravel takes an options array in place of the weights, as in
     * `zunionstore($destination, $keys, ['weights' => [2, 1], 'aggregate' =>
     * 'max'])`, and sends SUM when it names no aggregate; GLIDE would send the
     * array as the weights. An array with a key that is not an integer, or an
     * empty one, which GLIDE would send as an empty WEIGHTS, is read as those
     * options, falling back to an aggregate passed after it. Weights keyed by
     * integers, which GLIDE and phpredis take in order whatever the keys, as
     * from array_filter(), are kept, and so is anything else.
     *
     * @param  mixed  $weights
     * @param  mixed  $aggregate
     * @return array{mixed, mixed}
     */
    public function storeArguments(mixed $weights, mixed $aggregate): array
    {
        if (!is_array($weights) || ($weights !== [] && array_filter(array_keys($weights), is_string(...)) === [])) {
            return [$weights, $aggregate];
        }

        return [$weights['weights'] ?? null, $weights['aggregate'] ?? $aggregate ?? 'sum'];
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
     * A float is written as FloatArguments::double() writes it, with 17
     * significant digits, so the server reads back the same float. Any other
     * value is converted as a member is.
     *
     * @param  string  $command
     * @param  mixed  $score
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function toScore(string $command, mixed $score): string
    {
        return is_float($score) ? FloatArguments::double($score) : $this->toMember($command, $score);
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
