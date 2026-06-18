<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;

/**
 * Laravel Redis connection adapter backed by the Valkey GLIDE client.
 *
 * @mixin \ValkeyGlide
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1448")
 */
final class ValkeyGlideConnection extends Connection
{
    /** @var array<int, string> Commands safe to retry exactly once. */
    private const array IDEMPOTENT_RETRYABLE_COMMANDS = [
        'ECHO',
        'EXISTS',
        'GET',
        'GETBIT',
        'GETRANGE',
        'HGET',
        'HGETALL',
        'HEXISTS',
        'HKEYS',
        'HLEN',
        'MGET',
        'PING',
        'PTTL',
        'RANDOMKEY',
        'SCARD',
        'SISMEMBER',
        'SMEMBERS',
        'STRLEN',
        'TIME',
        'TTL',
        'TYPE',
        'ZCARD',
        'ZRANGE',
        'ZRANGEBYSCORE',
        'ZRANK',
        'ZSCORE',
    ];

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

    /** @var array<int, string> Error fragments treated as transient transport faults. */
    private const array TRANSIENT_ERROR_FRAGMENTS = [
        'connection reset by peer',
        'connection closed',
        'connection lost',
        'protocol error, got',
        'reply-type byte',
        'reply type byte',
        'socket',
        'broken pipe',
        'eof',
        'read error on connection',
        'error while reading',
        'went away',
        'temporarily unavailable',
    ];

    /** @var \ValkeyGlide|\ValkeyGlideCluster Active GLIDE client instance. */
    protected \ValkeyGlide|\ValkeyGlideCluster $glideClient;

    /** @var (\Closure(): (\ValkeyGlide|\ValkeyGlideCluster))|null Reconnection client factory. */
    protected ?\Closure $connector;

    /** @var array<string, mixed> Connection-level configuration. */
    protected array $config;

    /** @var string Key prefix prepended to command key arguments; an empty string disables prefixing. */
    private readonly string $prefix;

    /** @var \Closure(int, int): int Random integer generator callback. */
    private readonly \Closure $randomIntGenerator;

    /** @var \Closure(int): void Sleep callback. */
    private readonly \Closure $sleepCallback;

    /**
     * Create a new Valkey GLIDE Laravel connection wrapper.
     *
     * @param  \ValkeyGlide|\ValkeyGlideCluster  $client
     * @param  (\Closure(): (\ValkeyGlide|\ValkeyGlideCluster))|null  $connector
     * @param  array<string, mixed>  $config
     * @return void
     */
    public function __construct(\ValkeyGlide|\ValkeyGlideCluster $client, ?\Closure $connector = null, array $config = [])
    {
        $this->glideClient        = $client;
        $this->connector          = $connector;
        $this->config             = $config;
        $this->prefix             = $this->resolvePrefix($config['prefix'] ?? null);
        $this->randomIntGenerator = $this->resolveRandomIntGenerator($config['random_int'] ?? null);
        $this->sleepCallback      = $this->resolveSleepCallback($config['sleep'] ?? null);
    }

    /**
     * Get the underlying GLIDE client instance.
     *
     * @return \ValkeyGlide|\ValkeyGlideCluster
     */
    #[\Override]
    public function client(): \ValkeyGlide|\ValkeyGlideCluster
    {
        return $this->glideClient;
    }

    /**
     * Returns the value of the given key.
     *
     * @param  string  $key
     * @return string|null
     *
     * @throws \Throwable
     */
    public function get(string $key): ?string
    {
        /** @var false|string $result */
        $result = $this->command('get', [$key]);

        return $result !== false ? $result : null;
    }

    /**
     * Get the values of all the given keys.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string|null>
     *
     * @throws \Throwable
     * @throws \UnexpectedValueException
     */
    public function mget(array $keys): array
    {
        $results = $this->command('mget', [$this->prefixMgetKeys($keys)]);

        if (!is_array($results)) {
            throw new \UnexpectedValueException(sprintf('Expected array response from mget, received [%s].', get_debug_type($results)));
        }

        return array_map(
            static fn (false|string $value): ?string => $value !== false ? $value : null,
            $results,
        );
    }

    /**
     * Execute a Redis command with one safe retry on transient disconnects.
     *
     * @param  mixed  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    #[\Override]
    public function command(mixed $method, array $parameters = []): mixed
    {
        $normalizedMethod     = $this->normalizeCommandMethod($method);
        $normalizedParameters = $this->normalizeCommandParameters(
            $normalizedMethod,
            $parameters,
        );

        return $this->executeCommandWithRetry(
            $normalizedMethod,
            $normalizedParameters,
        );
    }

    /**
     * Subscribe to channels and normalize callback payload arguments.
     *
     * @param  mixed  $channels
     * @param  \Closure(mixed, mixed): void  $callback
     * @param  mixed  $method
     * @return void
     *
     * @throws \InvalidArgumentException
     *
     * @phpstan-ignore method.childParameterType
     */
    #[\Override]
    public function createSubscription(mixed $channels, \Closure $callback, mixed $method = 'subscribe'): void
    {
        $normalizedChannels = $this->normalizeSubscriptionChannels($channels);
        $normalizedMethod   = $this->normalizeSubscriptionMethod($method);
        $handler            = $this->newMessageHandler($callback);

        match ($normalizedMethod) {
            'subscribe'  => $this->glideClient->subscribe($normalizedChannels, $handler),
            'psubscribe' => $this->glideClient->psubscribe($normalizedChannels, $handler),
            default      => throw new \InvalidArgumentException(sprintf('Unsupported subscription method [%s].', $normalizedMethod)),
        };
    }

    /**
     * Execute a raw command against the underlying GLIDE client.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    public function executeRaw(array $parameters): mixed
    {
        return $this->command('rawcommand', $parameters);
    }

    /**
     * Close the underlying GLIDE connection.
     *
     * @return void
     */
    public function disconnect(): void
    {
        $this->glideClient->close();
    }

    /**
     * Prefix MGET keys explicitly because the command accepts a key list array.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function prefixMgetKeys(array $keys): array
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
     * Execute a normalized command with one retry for transient failures.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    private function executeCommandWithRetry(string $method, array $parameters): mixed
    {
        $attempt = 0;

        while (true) {
            $startedAt = microtime(true);

            try {
                $result = $this->invokeCommand($method, $parameters);
            } catch (\Throwable $exception) {
                if ($this->shouldRetryAttempt($attempt, $method, $exception)) {
                    $attempt++;
                    $this->sleepBeforeRetry();

                    continue;
                }

                $this->dispatchCommandFailedEvent($method, $parameters, $exception);

                throw $exception;
            }

            $this->dispatchCommandExecutedEvent($method, $parameters, $startedAt);

            return $result;
        }
    }

    /**
     * Invoke a command on the underlying GLIDE client.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    private function invokeCommand(string $method, array $parameters): mixed
    {
        $normalizedMethod = strtoupper($method);

        if ($this->shouldInvokeAsRawCommand($normalizedMethod, $parameters)) {
            return $this->invokeAsRawCommand($normalizedMethod, $parameters);
        }

        return call_user_func_array([$this->glideClient, $method], $parameters);
    }

    /**
     * Determine whether a command must run through rawcommand for compatibility.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     */
    private function shouldInvokeAsRawCommand(string $method, array $parameters): bool
    {
        if ($method === 'EVAL' || $method === 'EVALSHA') {
            return true;
        }

        return $method === 'SET' && count($parameters) >= 4;
    }

    /**
     * Execute a command through rawcommand with Redis protocol arguments.
     *
     * Cluster clients require a leading route argument so the command lands on
     * the primary that owns the key's slot. Standalone clients use the existing
     * two-argument form (no route).
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    private function invokeAsRawCommand(string $method, array $parameters): mixed
    {
        $values = array_values($parameters);

        if ($this->glideClient instanceof \ValkeyGlideCluster) {
            return $this->glideClient->rawcommand($this->resolveClusterRoute($method, $values), $method, ...$values);
        }

        return $this->glideClient->rawcommand($method, ...$values);
    }

    /**
     * Resolve the cluster route for a raw command.
     *
     * Keyed write/script commands (EVAL, EVALSHA, phpredis-style SET-with-options)
     * must land on the primary that owns the key's hash slot. Using
     * `primarySlotKey` tells GLIDE to compute the slot from the supplied key and
     * route there, preserving the same per-key semantics as standalone. Fan-out
     * routes (`allPrimaries`, `allNodes`) would execute the write on every shard;
     * `randomNode` would hit an arbitrary slot owner. `randomNode` is the correct
     * fallback only for keyless cases where no slot must be honoured.
     *
     * The parameters array is already prefix-normalised before this method runs,
     * so the key used for routing is identical to the key the command targets.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $values
     * @return array{type: string, key: string}|string
     */
    private function resolveClusterRoute(string $method, array $values): array|string
    {
        $keyIndex = match ($method) {
            'SET' => 0,
            'EVAL', 'EVALSHA' => ($this->normalizeNonNegativeInt($values[1] ?? null) ?? 0) >= 1 ? 2 : null,
            default => null,
        };

        $key = $keyIndex !== null ? ($values[$keyIndex] ?? null) : null;

        return is_scalar($key) || $key instanceof \Stringable
            ? ['type' => 'primarySlotKey', 'key' => (string) $key]
            : 'randomNode';
    }

    /**
     * Determine whether the current attempt should be retried.
     *
     * @param  int  $attempt
     * @param  string  $method
     * @param  \Throwable  $exception
     * @return bool
     */
    private function shouldRetryAttempt(int $attempt, string $method, \Throwable $exception): bool
    {
        if ($attempt > 0) {
            return false;
        }

        if (!$this->shouldRetryCommand($method, $exception)) {
            return false;
        }

        return $this->reconnectClient();
    }

    /**
     * Dispatch a command failed event.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  \Throwable  $exception
     * @return void
     */
    private function dispatchCommandFailedEvent(string $method, array $parameters, \Throwable $exception): void
    {
        $this->events?->dispatch(
            new CommandFailed(
                $method,
                $this->parseParametersForEvent($parameters),
                $exception,
                $this,
            ),
        );
    }

    /**
     * Dispatch a command executed event.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  float  $startedAt
     * @return void
     */
    private function dispatchCommandExecutedEvent(string $method, array $parameters, float $startedAt): void
    {
        $executionTimeMs = round((microtime(true) - $startedAt) * 1000, 2);

        $this->events?->dispatch(
            new CommandExecuted(
                $method,
                $this->parseParametersForEvent($parameters),
                $executionTimeMs,
                $this,
            ),
        );
    }

    /**
     * Wrap subscription callbacks to always receive ($message, $channel).
     *
     * @param  \Closure(mixed, mixed): void  $callback
     * @return \Closure(mixed...): void
     */
    private function newMessageHandler(\Closure $callback): \Closure
    {
        return static function (mixed ...$arguments) use ($callback): void {
            $argumentCount = count($arguments);

            $message = $argumentCount >= 1 ? $arguments[$argumentCount - 1] : null;
            $channel = $argumentCount >= 2 ? $arguments[$argumentCount - 2] : null;

            $callback($message, $channel);
        };
    }

    /**
     * Recreate the client via the configured connector callback.
     *
     * @return bool
     */
    private function reconnectClient(): bool
    {
        if ($this->connector === null) {
            return false;
        }

        $this->glideClient = ($this->connector)();

        return true;
    }

    /**
     * Normalize command parameters and apply configured key prefix when needed.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function normalizeCommandParameters(string $method, array $parameters): array
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
     * Prefix a parameter at a specific index when it can be represented as a key.
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

            if ($prefixedValue !== null) {
                $parameters[$index] = $prefixedValue;
            }
        }

        return $parameters;
    }

    /**
     * Prefix EVAL and EVALSHA key parameters.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function prefixEvalKeys(array $parameters): array
    {
        if (!array_key_exists(1, $parameters)) {
            return $parameters;
        }

        $keyCount = $this->normalizeNonNegativeInt($parameters[1]);

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
     * @param  mixed  $value
     * @return string|null
     */
    private function prefixValue(mixed $value): ?string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        $normalizedValue = (string) $value;

        return $this->prefix . $normalizedValue;
    }

    /**
     * Normalize command method names to non-empty strings.
     *
     * @param  mixed  $method
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeCommandMethod(mixed $method): string
    {
        $normalizedMethod = $this->normalizeNonEmptyStringable($method);

        if ($normalizedMethod !== null) {
            return $normalizedMethod;
        }

        throw new \InvalidArgumentException(sprintf('Unsupported command method type [%s].', get_debug_type($method)));
    }

    /**
     * Normalize subscription method names to non-empty lowercase strings.
     *
     * @param  mixed  $method
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeSubscriptionMethod(mixed $method): string
    {
        $normalizedMethod = $this->normalizeNonEmptyStringable($method);

        if ($normalizedMethod !== null) {
            return strtolower($normalizedMethod);
        }

        throw new \InvalidArgumentException(sprintf('Unsupported subscription method type [%s].', get_debug_type($method)));
    }

    /**
     * Sleep briefly before retry to avoid hot-loop retries.
     *
     * @return void
     */
    private function sleepBeforeRetry(): void
    {
        $baseDelay = $this->normalizeNonNegativeInt($this->config['retry_delay_ms'] ?? null)  ?? 25;
        $maxJitter = $this->normalizeNonNegativeInt($this->config['retry_jitter_ms'] ?? null) ?? 15;

        if ($maxJitter > 0) {
            $randomInt = $this->randomIntGenerator;

            try {
                $baseDelay += $randomInt(0, $maxJitter);
            } catch (\Throwable) {
                $baseDelay = max(0, $baseDelay);
            }
        }

        if ($baseDelay <= 0) {
            return;
        }

        $sleepCallback = $this->sleepCallback;
        $sleepCallback($baseDelay * 1000);
    }

    /**
     * Normalize mixed values into non-negative integers or null.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private function normalizeNonNegativeInt(mixed $value): ?int
    {
        $normalized = null;

        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_float($value)) {
            $normalized = (int) $value;
        } elseif (is_string($value) && is_numeric($value)) {
            $normalized = (int) $value;
        } elseif ($value instanceof \Stringable && is_numeric((string) $value)) {
            $normalized = (int) (string) $value;
        }

        if ($normalized === null || $normalized < 0) {
            return null;
        }

        return $normalized;
    }

    /**
     * Normalize mixed values into non-empty string values when possible.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private function normalizeNonEmptyStringable(mixed $value): ?string
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

    /**
     * Determine if a failed command is safe to retry once.
     *
     * @param  string  $method
     * @param  \Throwable  $exception
     * @return bool
     */
    private function shouldRetryCommand(string $method, \Throwable $exception): bool
    {
        if ($this->connector === null) {
            return false;
        }

        if (!in_array(strtoupper($method), self::IDEMPOTENT_RETRYABLE_COMMANDS, true)) {
            return false;
        }

        return $this->isTransientConnectionError($exception);
    }

    /**
     * Classify whether an exception is a transient transport failure.
     *
     * @param  \Throwable  $exception
     * @return bool
     */
    private function isTransientConnectionError(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach (self::TRANSIENT_ERROR_FRAGMENTS as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize channel input to a non-empty list of channel names.
     *
     * @param  mixed  $channels
     * @return array<int, string>
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeSubscriptionChannels(mixed $channels): array
    {
        if (is_string($channels)) {
            $source = [$channels];
        } elseif (is_array($channels)) {
            $source = $channels;
        } else {
            throw new \InvalidArgumentException(sprintf('Unsupported subscription channel type [%s].', get_debug_type($channels)));
        }

        $normalized = [];

        foreach ($source as $channel) {
            if (!is_scalar($channel) && !$channel instanceof \Stringable) {
                continue;
            }

            $channelName = (string) $channel;

            if ($channelName !== '') {
                $normalized[] = $channelName;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one valid subscription channel is required.');
        }

        return $normalized;
    }

    /**
     * Resolve the configured key prefix.
     *
     * @param  mixed  $prefix
     * @return string
     */
    private function resolvePrefix(mixed $prefix): string
    {
        if (!is_scalar($prefix) && !$prefix instanceof \Stringable) {
            return '';
        }

        return (string) $prefix;
    }

    /**
     * Resolve the configured random integer generator callback.
     *
     * @param  mixed  $randomInt
     * @return \Closure(int, int): int
     */
    private function resolveRandomIntGenerator(mixed $randomInt): \Closure
    {
        if (is_callable($randomInt)) {
            return \Closure::fromCallable($randomInt);
        }

        return static fn (int $min, int $max): int => random_int($min, $max);
    }

    /**
     * Resolve the configured sleep callback.
     *
     * @param  mixed  $sleep
     * @return \Closure(int): void
     */
    private function resolveSleepCallback(mixed $sleep): \Closure
    {
        if (is_callable($sleep)) {
            return \Closure::fromCallable($sleep);
        }

        return static function (int $microseconds): void {
            usleep($microseconds);
        };
    }
}
