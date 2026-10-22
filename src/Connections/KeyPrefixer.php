<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

use SineMacula\Valkey\Support\Cast;

/**
 * Apply a configured key prefix to Redis command parameters.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class KeyPrefixer
{
    /** @var array<int, string> Commands with a single key argument at index 0; a member scan's by-reference cursor at index 1 is left as it is. */
    private const array SINGLE_KEY_COMMANDS = [
        'APPEND',
        'DECR',
        'DECRBY',
        'DUMP',
        'EXPIRE',
        'EXPIREAT',
        'GET',
        'GETBIT',
        'GETDEL',
        'GETEX',
        'GETRANGE',
        'GETSET',
        'HDEL',
        'HEXISTS',
        'HGET',
        'HGETALL',
        'HINCRBY',
        'HINCRBYFLOAT',
        'HKEYS',
        'HLEN',
        'HMGET',
        'HMSET',
        'HSCAN',
        'HSET',
        'HSETNX',
        'HSTRLEN',
        'HVALS',
        'INCR',
        'INCRBY',
        'INCRBYFLOAT',
        'LINDEX',
        'LINSERT',
        'LLEN',
        'LPOP',
        'LPOS',
        'LPUSH',
        'LPUSHX',
        'LRANGE',
        'LREM',
        'LSET',
        'LTRIM',
        'MOVE',
        'PERSIST',
        'PEXPIRE',
        'PEXPIREAT',
        'PSETEX',
        'PTTL',
        'RPOP',
        'RPUSH',
        'RPUSHX',
        'SADD',
        'SCARD',
        'SET',
        'SETBIT',
        'SETEX',
        'SETNX',
        'SETRANGE',
        'SISMEMBER',
        'SMEMBERS',
        'SPOP',
        'SREM',
        'SSCAN',
        'STRLEN',
        'TTL',
        'TYPE',
        'ZADD',
        'ZCARD',
        'ZCOUNT',
        'ZINCRBY',
        'ZRANGE',
        'ZRANGEBYSCORE',
        'ZRANK',
        'ZREM',
        'ZREMRANGEBYRANK',
        'ZREMRANGEBYSCORE',
        'ZREVRANGEBYSCORE',
        'ZREVRANK',
        'ZSCAN',
        'ZSCORE',
    ];

    /** @var array<int, string> Commands whose first argument takes the prefix: the single-key commands, and KEYS, whose pattern phpredis and Predis prefix. */
    private const array FIRST_ARGUMENT_COMMANDS = [...self::SINGLE_KEY_COMMANDS, 'KEYS'];

    /** @var array<int, string> Commands where every argument is a key; the key-list and key-map commands below are matched first. */
    private const array ALL_KEY_COMMANDS = [
        'SDIFF',
        'SUNION',
    ];

    /** @var array<int, string> Commands where every argument is a key, or a list of keys. */
    private const array KEY_LIST_COMMANDS = [
        'DEL',
        'EXISTS',
        'MGET',
        'SINTER',
        'TOUCH',
        'UNLINK',
        'WATCH',
    ];

    /** @var array<int, string> Commands that take their keys as the keys of a key-value map. */
    private const array KEY_MAP_COMMANDS = [
        'MSET',
        'MSETNX',
    ];

    /** @var array<int, string> Commands with key arguments at indexes 0 and 1. */
    private const array DOUBLE_KEY_COMMANDS = [
        'BRPOPLPUSH',
        'COPY',
        'RENAME',
        'RENAMENX',
        'RPOPLPUSH',
        'SMOVE',
    ];

    /**
     * Create a new key prefixer for the given prefix string.
     *
     * An empty prefix string disables all prefixing operations.
     *
     * @param  string  $prefix
     * @return void
     */
    public function __construct(

        /** The key prefix applied to every outgoing Redis command. */
        private string $prefix,
    ) {}

    /**
     * Get the configured key prefix; empty when prefixing is disabled.
     *
     * @return string
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Apply the configured prefix to command parameters.
     *
     * Normalizes the method name to uppercase and routes to the appropriate
     * prefixing strategy based on the command type. Returns the parameters
     * unchanged when the prefix is empty or the parameter list is empty.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    public function apply(string $method, array $parameters): array
    {
        if ($this->prefix === '' || $parameters === []) {
            return $parameters;
        }

        $normalizedMethod = strtoupper($method);

        if ($normalizedMethod === 'EVAL' || $normalizedMethod === 'EVALSHA') {
            return $this->prefixEvalKeys($parameters);
        }

        return match (true) {
            $normalizedMethod === 'BITOP'                                => $this->prefixBitopKeys($parameters),
            in_array($normalizedMethod, self::KEY_MAP_COMMANDS, true)    => $this->prefixKeyMap($parameters),
            in_array($normalizedMethod, self::KEY_LIST_COMMANDS, true)   => $this->prefixAllParameters($this->prefixKeyLists($parameters)),
            in_array($normalizedMethod, self::ALL_KEY_COMMANDS, true)    => $this->prefixAllParameters($parameters),
            in_array($normalizedMethod, self::DOUBLE_KEY_COMMANDS, true) => $this->prefixParameterAt(
                $this->prefixParameterAt($parameters, 0),
                1,
            ),
            in_array($normalizedMethod, self::FIRST_ARGUMENT_COMMANDS, true) => $this->prefixParameterAt($parameters, 0),
            default                                                          => $parameters,
        };
    }

    /**
     * Prefix MGET keys explicitly because the command accepts a key list array.
     *
     * Returns the key list unchanged when the prefix is empty. This is kept for
     * compatibility; `apply('mget', [$keys])` does the same, as it does for
     * every key-list command.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     *
     * @deprecated 1.3.0 use apply('mget', [$keys]) instead, and do not pass
     *     the result of this method to the connection's command(), which now
     *     prefixes MGET key lists itself; this will be removed in the next
     *     major release
     */
    public function mgetKeys(array $keys): array
    {
        if ($this->prefix === '') {
            return $keys;
        }

        foreach ($keys as $index => $key) {
            $keys[$index] = $this->prefix . $key;
        }

        return $keys;
    }

    /**
     * Determine whether a key-list command was given a key list in a form GLIDE
     * does not take as keys.
     *
     * GLIDE takes a key list only as the command's single argument. A list
     * beside other arguments is sent as the key `Array`, or the arguments after
     * it are dropped, and a list inside the key list is sent as the key
     * `Array`, so the command would reach keys outside the prefix.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     */
    public function hasUnsupportedKeyList(string $method, array $parameters): bool
    {
        return in_array(strtoupper($method), self::KEY_LIST_COMMANDS, true)
            && $this->keys($method, $parameters) === null;
    }

    /**
     * Resolve the keys a command's parameters name, from the same key positions
     * the prefix is applied to.
     *
     * The keys are read as given, so parameters that were already prefixed
     * yield the prefixed keys. An `MSET` or `MSETNX` key-value map yields its
     * keys, and a key-list command its variadic keys or its single key list.
     * Returns null when the command's key positions are unknown, which includes
     * scripts, whose keys follow a key count, and a key list in a form GLIDE
     * does not take as keys.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return list<string>|null
     */
    public function keys(string $method, array $parameters): ?array
    {
        $normalizedMethod = strtoupper($method);
        $values           = array_values($parameters);

        return match (true) {
            $normalizedMethod === 'BITOP'                                => $this->normalizeKeys(array_slice($values, 1)),
            in_array($normalizedMethod, self::KEY_MAP_COMMANDS, true)    => $this->resolveKeyMapKeys($values),
            in_array($normalizedMethod, self::KEY_LIST_COMMANDS, true)   => $this->resolveKeyListKeys($values),
            in_array($normalizedMethod, self::ALL_KEY_COMMANDS, true)    => $this->normalizeKeys($values),
            in_array($normalizedMethod, self::DOUBLE_KEY_COMMANDS, true) => $this->normalizeKeys(array_slice($values, 0, 2)),
            in_array($normalizedMethod, self::SINGLE_KEY_COMMANDS, true) => $this->normalizeKeys(array_slice($values, 0, 1)),
            default                                                      => null,
        };
    }

    /**
     * Prefix a parameter at a specific index when it can be represented as a
     * key.
     *
     * @param  array<array-key, mixed>  $parameters
     * @param  int  $index
     * @return array<array-key, mixed>
     */
    private function prefixParameterAt(array $parameters, int $index): array
    {
        if (!array_key_exists($index, $parameters)) {
            return $parameters;
        }

        $prefixedValue = $this->prefixValue($parameters[$index]);

        if ($prefixedValue === null) {
            return $parameters;
        }

        $parameters[$index] = $prefixedValue;

        return $parameters;
    }

    /**
     * Prefix every parameter value that can be represented as a key.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function prefixAllParameters(array $parameters): array
    {
        foreach ($parameters as $index => $parameter) {
            $prefixedValue = $this->prefixValue($parameter);

            if ($prefixedValue === null) {
                continue;
            }

            $parameters[$index] = $prefixedValue;
        }

        return $parameters;
    }

    /**
     * Prefix the keys of the key-value map an `MSET` or `MSETNX` takes, as in
     * `mset(['a' => 1, 'b' => 2])`.
     *
     * Variadic key-value arguments are left as they are, as GLIDE takes only
     * the map.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function prefixKeyMap(array $parameters): array
    {
        $map = $parameters[0] ?? null;

        if (!is_array($map)) {
            return $parameters;
        }

        $prefixed = [];

        foreach ($map as $key => $value) {
            $prefixed[$this->prefix . $key] = $value;
        }

        $parameters[0] = $prefixed;

        return $parameters;
    }

    /**
     * Prefix EVAL and EVALSHA key parameters.
     *
     * Reads the key count from index 1 and prefixes the subsequent numkeys
     * parameters starting at index 2. Returns the parameters unchanged when the
     * key count is missing, non-numeric, or zero.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function prefixEvalKeys(array $parameters): array
    {
        if (!array_key_exists(1, $parameters)) {
            return $parameters;
        }

        $keyCount = Cast::toNonNegativeInt($parameters[1]);

        if ($keyCount === null || $keyCount <= 0) {
            return $parameters;
        }

        for ($offset = 0; $offset < $keyCount; $offset++) {
            $parameters = $this->prefixParameterAt($parameters, $offset + 2);
        }

        return $parameters;
    }

    /**
     * Prefix BITOP key parameters.
     *
     * Every argument is prefixed, then the operation at index 0 is restored, as
     * the union keeps its left-hand entries.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function prefixBitopKeys(array $parameters): array
    {
        return array_slice($parameters, 0, 1, true) + $this->prefixAllParameters($parameters);
    }

    /**
     * Prefix every key of the list arguments a key-list command accepts in
     * place of its variadic keys, such as `del(['a', 'b'])`.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function prefixKeyLists(array $parameters): array
    {
        foreach ($parameters as $index => $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $parameters[$index] = $this->prefixAllParameters($parameter);
        }

        return $parameters;
    }

    /**
     * Resolve the keys of a key-list command: its variadic keys, or the keys of
     * its single key list.
     *
     * Returns null when a list is given beside other arguments, or inside the
     * key list, as GLIDE takes neither as keys.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>|null
     */
    private function resolveKeyListKeys(array $values): ?array
    {
        $keys = count($values) === 1 && is_array($values[0]) ? $values[0] : $values;

        foreach ($keys as $key) {
            if (is_array($key)) {
                return null;
            }
        }

        return $this->normalizeKeys($keys);
    }

    /**
     * Resolve the keys of the key-value map an `MSET` or `MSETNX` takes.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>|null
     */
    private function resolveKeyMapKeys(array $values): ?array
    {
        return is_array($values[0] ?? null) ? $this->normalizeKeys(array_keys($values[0])) : null;
    }

    /**
     * Normalize key values to strings, skipping values that cannot be keys.
     *
     * @param  array<array-key, mixed>  $keys
     * @return list<string>
     */
    private function normalizeKeys(array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            if (!is_scalar($key) && !$key instanceof \Stringable) {
                continue;
            }

            $normalized[] = (string) $key;
        }

        return $normalized;
    }

    /**
     * Prefix a key-like value when possible.
     *
     * Returns null for non-scalar, non-stringable values to signal that the
     * parameter should be left unchanged at its original index.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private function prefixValue(mixed $value): ?string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        return $this->prefix . (string) $value;
    }
}
