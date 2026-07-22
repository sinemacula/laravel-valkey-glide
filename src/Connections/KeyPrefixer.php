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
final class KeyPrefixer
{
    /** @var array<int, string> Commands with a single key argument at index 0. */
    private const array SINGLE_KEY_COMMANDS = [
        'APPEND',
        'DECR',
        'DECRBY',
        'DEL',
        'DUMP',
        'EXISTS',
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
        'MGET',
        'MOVE',
        'PERSIST',
        'PEXPIRE',
        'PEXPIREAT',
        'PTTL',
        'RPOP',
        'RPUSH',
        'RPUSHX',
        'SADD',
        'SCARD',
        'SDIFF',
        'SET',
        'SETBIT',
        'SETEX',
        'SETNX',
        'SETRANGE',
        'SISMEMBER',
        'SMEMBERS',
        'SPOP',
        'SREM',
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
        'ZSCORE',
    ];

    /** @var array<int, string> Commands where every argument is a key. */
    private const array ALL_KEY_COMMANDS = [
        'DEL',
        'MGET',
        'MSET',
        'MSETNX',
        'SDIFF',
        'SINTER',
        'SUNION',
        'TOUCH',
        'UNLINK',
    ];

    /** @var array<int, string> Commands with key arguments at indexes 0 and 1. */
    private const array DOUBLE_KEY_COMMANDS = [
        'BITOP',
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
        private readonly string $prefix,
    ) {}

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
            in_array($normalizedMethod, self::ALL_KEY_COMMANDS, true)    => $this->prefixAllParameters($parameters),
            in_array($normalizedMethod, self::DOUBLE_KEY_COMMANDS, true) => $this->prefixParameterAt(
                $this->prefixParameterAt($parameters, 0),
                1,
            ),
            in_array($normalizedMethod, self::SINGLE_KEY_COMMANDS, true) => $this->prefixParameterAt($parameters, 0),
            default                                                      => $parameters,
        };
    }

    /**
     * Prefix MGET keys explicitly because the command accepts a key list array.
     *
     * Returns the key list unchanged when the prefix is empty.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
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
