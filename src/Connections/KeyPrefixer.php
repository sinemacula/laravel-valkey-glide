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
        'BITCOUNT',
        'BITPOS',
        'DECR',
        'DECRBY',
        'DUMP',
        'EXPIRE',
        'EXPIREAT',
        'EXPIRETIME',
        'GEOADD',
        'GEODIST',
        'GEOHASH',
        'GEOPOS',
        'GEOSEARCH',
        'GET',
        'GETBIT',
        'GETDEL',
        'GETEX',
        'GETRANGE',
        'GETSET',
        'HDEL',
        'HEXISTS',
        'HEXPIRE',
        'HEXPIREAT',
        'HEXPIRETIME',
        'HGET',
        'HGETALL',
        'HGETEX',
        'HINCRBY',
        'HINCRBYFLOAT',
        'HKEYS',
        'HLEN',
        'HMGET',
        'HMSET',
        'HPERSIST',
        'HPEXPIRE',
        'HPEXPIREAT',
        'HPEXPIRETIME',
        'HPSETEX',
        'HPTTL',
        'HRANDFIELD',
        'HSCAN',
        'HSET',
        'HSETEX',
        'HSETNX',
        'HSTRLEN',
        'HTTL',
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
        'PEXPIRETIME',
        'PFADD',
        'PSETEX',
        'PTTL',
        'RESTORE',
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
        'SMISMEMBER',
        'SORT',
        'SORT_RO',
        'SPOP',
        'SRANDMEMBER',
        'SREM',
        'SSCAN',
        'STRLEN',
        'TTL',
        'TYPE',
        'XACK',
        'XADD',
        'XAUTOCLAIM',
        'XCLAIM',
        'XDEL',
        'XLEN',
        'XPENDING',
        'XRANGE',
        'XREVRANGE',
        'XTRIM',
        'ZADD',
        'ZCARD',
        'ZCOUNT',
        'ZINCRBY',
        'ZLEXCOUNT',
        'ZMSCORE',
        'ZPOPMAX',
        'ZPOPMIN',
        'ZRANDMEMBER',
        'ZRANGE',
        'ZRANGEBYLEX',
        'ZRANGEBYSCORE',
        'ZRANK',
        'ZREM',
        'ZREMRANGEBYLEX',
        'ZREMRANGEBYRANK',
        'ZREMRANGEBYSCORE',
        'ZREVRANGEBYSCORE',
        'ZREVRANK',
        'ZSCAN',
        'ZSCORE',
    ];

    /** @var array<int, string> Commands where every argument is a key and none may be a list of keys, which GLIDE would send as the key `Array` or refuse. */
    private const array ALL_KEY_COMMANDS = [
        'SDIFF',
        'SDIFFSTORE',
        'SUNION',
        'SUNIONSTORE',
    ];

    /** @var array<int, string> Commands where every argument is a key, or a list of keys. */
    private const array KEY_LIST_COMMANDS = [
        'DEL',
        'EXISTS',
        'MGET',
        'PFCOUNT',
        'SINTER',
        'SINTERSTORE',
        'TOUCH',
        'UNLINK',
        'WATCH',
    ];

    /** @var array<string, int> Commands that take their keys as the keys of a key-value map, by the map's index. */
    private const array KEY_MAP_COMMANDS = [
        'MSET'       => 0,
        'MSETNX'     => 0,
        'XREAD'      => 0,
        'XREADGROUP' => 2,
    ];

    /** @var array<int, string> Commands that take a list of keys at index 0. */
    private const array KEY_ARRAY_FIRST_COMMANDS = [
        'LMPOP',
        'SINTERCARD',
        'ZDIFF',
        'ZINTER',
        'ZINTERCARD',
        'ZMPOP',
        'ZUNION',
    ];

    /** @var array<int, string> Commands with a destination key at index 0, before their list of keys. */
    private const array DESTINATION_KEY_COMMANDS = [
        'PFMERGE',
        'ZDIFFSTORE',
        'ZINTERSTORE',
        'ZUNIONSTORE',
    ];

    /** @var array<int, string> Commands that take a list of keys at index 1. */
    private const array KEY_ARRAY_SECOND_COMMANDS = [...self::DESTINATION_KEY_COMMANDS, 'BLMPOP', 'BZMPOP', 'FCALL', 'FCALL_RO'];

    /** @var array<int, string> Blocking pops, which take a list of keys, or keys as separate arguments, then a timeout. */
    private const array BLOCKING_POP_COMMANDS = [
        'BLPOP',
        'BRPOP',
        'BZPOPMAX',
        'BZPOPMIN',
    ];

    /** @var array<int, string> Commands with key arguments at indexes 0 and 1. */
    private const array DOUBLE_KEY_COMMANDS = [
        'BLMOVE',
        'BRPOPLPUSH',
        'COPY',
        'GEOSEARCHSTORE',
        'LCS',
        'LMOVE',
        'RENAME',
        'RENAMENX',
        'RPOPLPUSH',
        'SMOVE',
        'ZRANGESTORE',
    ];

    /** @var array<int, string> Commands with a single key argument at index 1, after their subcommand. */
    private const array SUBCOMMAND_KEY_COMMANDS = [
        'OBJECT',
        'XGROUP',
        'XINFO',
    ];

    /** @var array<int, string> Scripts, whose keys are counted: EVAL and EVALSHA in Laravel's argument shape, the read-only ones in phpredis'. */
    private const array SCRIPT_COMMANDS = ['EVAL', 'EVALSHA', 'EVALSHA_RO', 'EVAL_RO'];

    /** @var array<int, string> Commands whose keys are refused when given a list in a form GLIDE does not take as keys. */
    private const array KEY_LIST_CHECKED_COMMANDS = [
        ...self::KEY_LIST_COMMANDS,
        ...self::ALL_KEY_COMMANDS,
        ...self::KEY_ARRAY_FIRST_COMMANDS,
        ...self::KEY_ARRAY_SECOND_COMMANDS,
        ...self::BLOCKING_POP_COMMANDS,
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

        if (in_array($normalizedMethod, self::SCRIPT_COMMANDS, true)) {
            return $this->prefixEvalKeys($normalizedMethod, $parameters);
        }

        return match (true) {
            $normalizedMethod === 'BITOP'                    => $this->prefixBitopKeys($parameters),
            isset(self::KEY_MAP_COMMANDS[$normalizedMethod]) => $this->prefixKeyMap($parameters, self::KEY_MAP_COMMANDS[$normalizedMethod]),
            in_array($normalizedMethod, self::KEY_ARRAY_FIRST_COMMANDS, true),
            in_array($normalizedMethod, self::KEY_ARRAY_SECOND_COMMANDS, true),
            in_array($normalizedMethod, self::BLOCKING_POP_COMMANDS, true) => $this->prefixKeyArray($normalizedMethod, $parameters),
            in_array($normalizedMethod, self::KEY_LIST_COMMANDS, true)     => $this->prefixAllParameters($this->prefixKeyLists($parameters)),
            in_array($normalizedMethod, self::ALL_KEY_COMMANDS, true)      => $this->prefixAllParameters($parameters),
            $normalizedMethod === 'KEYS'                                   => $this->prefixParametersAt($parameters, 0),
            default                                                        => $this->prefixParametersAt($parameters, ...$this->resolveKeyIndexes($normalizedMethod)),
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
     * Determine whether a command was given a key list in a form GLIDE does not
     * take as keys.
     *
     * A key-list command takes a key list only as its single argument, and an
     * all-key command, such as `SUNIONSTORE`, takes none. A list beside other
     * arguments is sent as the key `Array`, or the arguments after it are
     * dropped, and a list inside a key list, of any command, is sent as the key
     * `Array`, so the command would reach keys outside the prefix, and a store
     * command would replace its destination with the result.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     */
    public function hasUnsupportedKeyList(string $method, array $parameters): bool
    {
        return in_array(strtoupper($method), self::KEY_LIST_CHECKED_COMMANDS, true)
            && $this->keys($method, $parameters) === null;
    }

    /**
     * Resolve the keys a command's parameters name, from the same key positions
     * the prefix is applied to.
     *
     * The keys are read as given, so parameters that were already prefixed
     * yield the prefixed keys. A key-value map, as `MSET` and `XREAD` take,
     * yields its keys, an all-key command its arguments, a key-list command its
     * variadic keys or its single key list, a key-array command its destination
     * key, if any, and its list of keys, and a blocking pop its list of keys,
     * or the arguments before its timeout. Returns null when the command's key
     * positions are unknown, which includes scripts, and a key list in a form
     * GLIDE does not take as keys.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return list<string>|null
     */
    public function keys(string $method, array $parameters): ?array
    {
        $normalizedMethod = strtoupper($method);
        $values           = array_values($parameters);

        $indexes = $this->resolveKeyIndexes($normalizedMethod);

        return match (true) {
            $normalizedMethod === 'BITOP'                    => $this->normalizeKeys(array_slice($values, 1)),
            isset(self::KEY_MAP_COMMANDS[$normalizedMethod]) => $this->resolveKeyMapKeys($values, self::KEY_MAP_COMMANDS[$normalizedMethod]),
            in_array($normalizedMethod, self::KEY_ARRAY_FIRST_COMMANDS, true),
            in_array($normalizedMethod, self::KEY_ARRAY_SECOND_COMMANDS, true),
            in_array($normalizedMethod, self::BLOCKING_POP_COMMANDS, true) => $this->resolveKeyArrayKeys($normalizedMethod, $values),
            in_array($normalizedMethod, self::KEY_LIST_COMMANDS, true)     => $this->resolveKeyListKeys($values),
            in_array($normalizedMethod, self::ALL_KEY_COMMANDS, true)      => $this->resolveListedKeys($values),
            $indexes !== []                                                => $this->normalizeKeys(array_intersect_key($values, array_flip($indexes))),
            default                                                        => null,
        };
    }

    /**
     * Resolve the indexes of the keys of a command whose keys are at fixed
     * positions; empty when they are not.
     *
     * @param  string  $command
     * @return list<int>
     */
    private function resolveKeyIndexes(string $command): array
    {
        return match (true) {
            in_array($command, self::DOUBLE_KEY_COMMANDS, true)     => [0, 1],
            in_array($command, self::SUBCOMMAND_KEY_COMMANDS, true) => [1],
            in_array($command, self::SINGLE_KEY_COMMANDS, true)     => [0],
            default                                                 => [],
        };
    }

    /**
     * Prefix the parameters at the given indexes when they can be represented
     * as keys.
     *
     * @param  array<array-key, mixed>  $parameters
     * @param  int  ...$indexes
     * @return array<array-key, mixed>
     */
    private function prefixParametersAt(array $parameters, int ...$indexes): array
    {
        foreach ($indexes as $index) {
            if (!array_key_exists($index, $parameters)) {
                continue;
            }

            $prefixedValue = $this->prefixValue($parameters[$index]);

            if ($prefixedValue === null) {
                continue;
            }

            $parameters[$index] = $prefixedValue;
        }

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
     * Prefix the keys of the key-value map at the given index, as `MSET` and
     * `MSETNX` take their keys, in `mset(['a' => 1, 'b' => 2])`, and `XREAD`
     * and `XREADGROUP` their streams, in `xread(['a' => '0'])`.
     *
     * Variadic key-value arguments are left as they are, as GLIDE takes only
     * the map.
     *
     * @param  array<array-key, mixed>  $parameters
     * @param  int  $index
     * @return array<array-key, mixed>
     */
    private function prefixKeyMap(array $parameters, int $index): array
    {
        $map = $parameters[$index] ?? null;

        if (!is_array($map)) {
            return $parameters;
        }

        $prefixed = [];

        foreach ($map as $key => $value) {
            $prefixed[$this->prefix . $key] = $value;
        }

        $parameters[$index] = $prefixed;

        return $parameters;
    }

    /**
     * Prefix EVAL and EVALSHA key parameters, and those of their read-only
     * forms.
     *
     * Reads the key count from index 1 and prefixes that many parameters from
     * index 2. EVAL_RO and EVALSHA_RO take phpredis' shape instead, a list of
     * keys and arguments then the key count, so that many leading entries of
     * the list at index 1 are prefixed. Nothing is prefixed when the key count
     * is missing, non-numeric, or zero.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function prefixEvalKeys(string $command, array $parameters): array
    {
        if ($command !== 'EVAL_RO' && $command !== 'EVALSHA_RO') {
            $keyCount = Cast::toNonNegativeInt($parameters[1] ?? null) ?? 0;

            return array_replace($parameters, $this->prefixAllParameters(array_slice($parameters, 2, $keyCount, true)));
        }

        $arguments = $parameters[1] ?? null;

        if (is_array($arguments)) {
            $keyCount      = Cast::toNonNegativeInt($parameters[2] ?? null) ?? 0;
            $parameters[1] = array_replace($arguments, $this->prefixAllParameters(array_slice($arguments, 0, $keyCount, true)));
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
     * Prefix the list of keys a key-array command takes, as in
     * `zunionstore('destination', ['a', 'b'])`, and its destination key, if it
     * has one.
     *
     * A blocking pop takes its keys as a list at index 0, as in
     * `blpop(['a', 'b'], 5)`, or as every argument before its timeout, as in
     * `bzpopmin('a', 'b', 5)`, the union then restoring the timeout.
     *
     * @param  string  $command
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function prefixKeyArray(string $command, array $parameters): array
    {
        $index = in_array($command, self::KEY_ARRAY_SECOND_COMMANDS, true) ? 1 : 0;

        if (in_array($command, self::DESTINATION_KEY_COMMANDS, true)) {
            $parameters = $this->prefixParametersAt($parameters, 0);
        }

        if (is_array($parameters[$index] ?? null)) {
            $parameters[$index] = $this->prefixAllParameters($parameters[$index]);
        } elseif (in_array($command, self::BLOCKING_POP_COMMANDS, true)) {
            $parameters = $this->prefixAllParameters(array_slice($parameters, 0, -1, true)) + $parameters;
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
        return $this->resolveListedKeys(count($values) === 1 && is_array($values[0]) ? $values[0] : $values);
    }

    /**
     * Resolve the keys of a key-array command: its destination key, if it has
     * one, and its list of keys; or of a blocking pop: its list of keys, when
     * the list is followed by its timeout alone, or every argument before its
     * timeout.
     *
     * Returns null when a list is given inside the list of keys, or among a
     * blocking pop's keys, as in `bzpopmin(['a'], 'b', 5)`, as GLIDE does not
     * take it as keys.
     *
     * @param  string  $command
     * @param  array<int, mixed>  $values
     * @return list<string>|null
     */
    private function resolveKeyArrayKeys(string $command, array $values): ?array
    {
        $list = $values[in_array($command, self::KEY_ARRAY_SECOND_COMMANDS, true) ? 1 : 0] ?? null;

        if (in_array($command, self::BLOCKING_POP_COMMANDS, true) && (!is_array($list) || count($values) > 2)) {
            return $this->resolveListedKeys(array_slice($values, 0, -1));
        }

        $keys = $this->resolveListedKeys(is_array($list) ? $list : []);

        if ($keys === null || !in_array($command, self::DESTINATION_KEY_COMMANDS, true)) {
            return $keys;
        }

        return [...$this->normalizeKeys(array_slice($values, 0, 1)), ...$keys];
    }

    /**
     * Resolve the keys of the key-value map at the given index, as `MSET`,
     * `MSETNX`, `XREAD` and `XREADGROUP` take it.
     *
     * @param  array<int, mixed>  $values
     * @param  int  $index
     * @return list<string>|null
     */
    private function resolveKeyMapKeys(array $values, int $index): ?array
    {
        return is_array($values[$index] ?? null) ? $this->normalizeKeys(array_keys($values[$index])) : null;
    }

    /**
     * Resolve the keys of a list of keys, or null when it holds a list, which
     * GLIDE would send as the key `Array`.
     *
     * @param  array<array-key, mixed>  $keys
     * @return list<string>|null
     */
    private function resolveListedKeys(array $keys): ?array
    {
        foreach ($keys as $key) {
            if (is_array($key)) {
                return null;
            }
        }

        return $this->normalizeKeys($keys);
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
