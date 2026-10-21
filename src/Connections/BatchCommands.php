<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

/**
 * Classify and reshape commands issued while a pipeline or transaction is
 * active.
 *
 * GLIDE only buffers a command in batch mode when its implementation checks for
 * it. The classes below follow ext-valkey_glide 1.1.2: scripts, raw commands,
 * pub/sub and search commands, and the client-local methods run immediately and
 * return their real result, and a cluster batch refuses the keyless commands
 * that take a route. Whether a command is buffered is decided by these classes
 * rather than read from what GLIDE returns, because some immediate commands
 * still return the client, a buffered `unwatch()` returns null, and some calls
 * return the client without being buffered or sent at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class BatchCommands
{
    /** @var list<string> Commands refused while a batch is active, as they would block, misbehave or corrupt memory. */
    private const array REFUSED_COMMANDS = [
        'HSCAN',
        'PSUBSCRIBE',
        'PUNSUBSCRIBE',
        'SCAN',
        'SELECT',
        'SSCAN',
        'SSUBSCRIBE',
        'SUBSCRIBE',
        'UNSUBSCRIBE',
        'ZSCAN',
    ];

    /** @var list<string> Commands GLIDE sends immediately instead of buffering. */
    private const array IMMEDIATE_COMMANDS = [
        'CLEARCONNECTIONPASSWORD',
        'EVAL',
        'EVALSHA',
        'EVALSHA_RO',
        'EVAL_RO',
        'PUBLISH',
        'PUBSUB',
        'RAWCOMMAND',
        'REFRESHIAMTOKEN',
        'UPDATECONNECTIONPASSWORD',
    ];

    /** @var list<string> Prefixes of the command families GLIDE sends immediately instead of buffering. */
    private const array IMMEDIATE_COMMAND_PREFIXES = [
        'FT',
        'FUNCTION',
        'SCRIPT',
    ];

    /** @var list<string> Client-local methods, which GLIDE runs immediately without reaching the server. */
    private const array LOCAL_COMMANDS = [
        'GETOPTION',
        'GETOTELSAMPLEPERCENTAGE',
        'GETSTATISTICS',
        'REGISTERPHPREDISALIASES',
        'SETOPTION',
        'SETOTELSAMPLEPERCENTAGE',
    ];

    /** @var list<string> Prefixes of the client-local method families, which GLIDE runs immediately without reaching the server. */
    private const array LOCAL_COMMAND_PREFIXES = [
        'GETCACHE',
    ];

    /** @var list<string> Keyless commands a cluster batch refuses, which therefore run immediately. */
    private const array CLUSTER_IMMEDIATE_COMMANDS = [
        'CLIENT',
        'DBSIZE',
        'ECHO',
        'FLUSHALL',
        'FLUSHDB',
        'INFO',
        'PING',
        'RANDOMKEY',
        'TIME',
    ];

    /** @var list<string> Commands whose single key-list argument GLIDE runs immediately unless spread. */
    private const array KEY_LIST_COMMANDS = [
        'DEL',
        'UNLINK',
    ];

    /** @var array<string, int> Commands GLIDE neither buffers nor sends with fewer parameters than this, though it returns the client. */
    private const array MINIMUM_BUFFERED_ARITY = [
        'ZMSCORE' => 2,
        'ZREM'    => 2,
    ];

    /** @var list<string> Commands GLIDE neither buffers nor sends with an empty key list, though it returns the client. */
    private const array NON_EMPTY_KEY_LIST_COMMANDS = [
        'ZINTER',
    ];

    /** @var list<string> phpredis-style SET options that take a value. */
    private const array SET_VALUE_OPTIONS = [
        'EX',
        'EXAT',
        'PX',
        'PXAT',
    ];

    /** @var list<string> phpredis-style SET options that stand alone. */
    private const array SET_FLAG_OPTIONS = [
        'GET',
        'KEEPTTL',
        'NX',
        'XX',
    ];

    /**
     * Determine whether a command is refused while a batch is active.
     *
     * The scan family stores a reference to the caller's iterator and writes
     * through it at `exec()`, after the variable may be gone; the subscribe
     * family blocks; and GLIDE refuses `SELECT` in batch mode.
     *
     * @param  string  $command
     * @return bool
     */
    public function isRefused(string $command): bool
    {
        return in_array(strtoupper($command), self::REFUSED_COMMANDS, true);
    }

    /**
     * Determine whether GLIDE runs a command immediately, outside the batch.
     *
     * @param  string  $command
     * @param  bool  $cluster
     * @return bool
     */
    public function runsImmediately(string $command, bool $cluster): bool
    {
        $command = strtoupper($command);

        return $this->runsLocally($command)
            || $this->isListed($command, self::IMMEDIATE_COMMANDS, self::IMMEDIATE_COMMAND_PREFIXES)
            || ($cluster && in_array($command, self::CLUSTER_IMMEDIATE_COMMANDS, true));
    }

    /**
     * Determine whether a command is one of the client's own methods, which
     * GLIDE runs immediately without reaching the server.
     *
     * @param  string  $command
     * @return bool
     */
    public function runsLocally(string $command): bool
    {
        return $this->isListed(strtoupper($command), self::LOCAL_COMMANDS, self::LOCAL_COMMAND_PREFIXES);
    }

    /**
     * Determine whether GLIDE would report a command buffered without buffering
     * it.
     *
     * In batch mode, `zRem()` and `zMscore()` given only a key, and `zinter()`
     * given an empty key list, return the client but queue nothing, so counting
     * them would leave a slot that the batch's results never fill.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     */
    public function isUnbuffered(string $command, array $parameters): bool
    {
        $command = strtoupper($command);

        if (in_array($command, self::NON_EMPTY_KEY_LIST_COMMANDS, true)) {
            return (array_values($parameters)[0] ?? null) === [];
        }

        $minimum = self::MINIMUM_BUFFERED_ARITY[$command] ?? null;

        return $minimum !== null && count($parameters) < $minimum;
    }

    /**
     * Reshape command parameters into the form GLIDE buffers.
     *
     * A single key list, as in `del(['a', 'b'])`, is spread into variadic keys,
     * and phpredis-style SET options, as in `set('k', 'v', 'EX', 60, 'NX')` or
     * `set('k', 'v', 'NX')`, become GLIDE's options array, as in
     * `set('k', 'v', ['EX' => 60, 'NX'])`, as GLIDE buffers no SET whose
     * options are a string; the value is then sent as the string a raw command
     * would send. SET options that cannot be converted, or are already GLIDE's
     * own, such as an integer expiry or an options array, are left as they are.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    public function rewrite(string $command, array $parameters): array
    {
        $command = strtoupper($command);

        if (in_array($command, self::KEY_LIST_COMMANDS, true)) {
            return $this->spreadKeyList($parameters);
        }

        if ($command === 'SET' && count($parameters) >= 3) {
            return $this->convertSetOptions(array_values($parameters)) ?? $parameters;
        }

        return $parameters;
    }

    /**
     * Spread a single key-list argument into variadic key arguments.
     *
     * The spread keys are sent as strings, as GLIDE refuses an integer first
     * key in the variadic form, though the key list accepts one. An empty key
     * list is kept, so GLIDE refuses it as it does outside a batch, rather than
     * receive no keys at all.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    public function spreadKeyList(array $parameters): array
    {
        $values = array_values($parameters);
        $keys   = $values[0] ?? null;

        if (count($values) === 1 && is_array($keys) && $keys !== []) {
            return array_map(
                static fn (mixed $key): mixed => is_scalar($key) || $key instanceof \Stringable ? (string) $key : $key,
                array_values($keys),
            );
        }

        return $parameters;
    }

    /**
     * Determine whether a command is listed, by name or by family prefix.
     *
     * @param  string  $command
     * @param  list<string>  $commands
     * @param  list<string>  $prefixes
     * @return bool
     */
    private function isListed(string $command, array $commands, array $prefixes): bool
    {
        if (in_array($command, $commands, true)) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($command, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert phpredis-style SET options to GLIDE's options array.
     *
     * GLIDE only buffers an expiry given as a positive integer, so the digits
     * of one, which Redis accepts, are cast. Returns null, leaving the command
     * for the server to judge, as it is outside a batch, when an option is not
     * recognised, when an expiry is neither, such as a float or `'7.5'`, which
     * GLIDE would truncate and Redis rejects, when two different expiries are
     * given, of which GLIDE would keep one while Redis rejects both, or when
     * the value cannot be sent as a string.
     *
     * @param  array<int, mixed>  $values
     * @return array{mixed, string, array<int|string, mixed>}|null
     */
    private function convertSetOptions(array $values): ?array
    {
        $value = $this->resolveSetValue($values[1]);

        if ($value === null) {
            return null;
        }

        $options = [];
        $tokens  = array_slice($values, 2);

        while ($tokens !== []) {
            $token  = array_shift($tokens);
            $option = is_string($token) ? strtoupper($token) : '';

            if (in_array($option, self::SET_FLAG_OPTIONS, true)) {
                $options[] = $option;

                continue;
            }

            $expiry = $this->resolveExpiry(array_shift($tokens));

            if (!in_array($option, self::SET_VALUE_OPTIONS, true) || $expiry === null || $this->hasOtherExpiry($options, $option)) {
                return null;
            }

            $options[$option] = $expiry;
        }

        return [$values[0], $value, $options];
    }

    /**
     * Resolve a SET value as the string a raw command sends for it.
     *
     * Outside a batch these SET calls go through rawcommand, which sends the
     * value with PHP's string conversion, while GLIDE's own `set()` stores
     * `false` as `'0'`, writes floats in another notation, and refuses a
     * Stringable. Converting the value first keeps what a batch stores the same
     * as a direct call; a value with no string form is left to rawcommand.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private function resolveSetValue(mixed $value): ?string
    {
        return $value === null || is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }

    /**
     * Determine whether the options hold an expiry other than the given one.
     *
     * @param  array<int|string, mixed>  $options
     * @param  string  $option
     * @return bool
     */
    private function hasOtherExpiry(array $options, string $option): bool
    {
        foreach (self::SET_VALUE_OPTIONS as $expiry) {
            if ($expiry !== $option && array_key_exists($expiry, $options)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a SET expiry given as a positive integer, or as its digits alone,
     * as Redis reads them.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private function resolveExpiry(mixed $value): ?int
    {
        if (is_string($value) && (string) (int) $value === $value) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }
}
