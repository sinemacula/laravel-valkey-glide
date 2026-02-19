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

    /** @var \ValkeyGlide Active GLIDE client instance. */
    protected \ValkeyGlide $glideClient;

    /** @var (\Closure(): \ValkeyGlide)|null Reconnection client factory. */
    protected ?\Closure $connector;

    /** @var array<string, mixed> Connection-level configuration. */
    protected array $config;

    /** @var string Optional key prefix applied for command compatibility. */
    private string $prefix;

    /** @var \Closure(int, int): int Random integer generator callback. */
    private \Closure $randomIntGenerator;

    /** @var \Closure(int): void Sleep callback. */
    private \Closure $sleepCallback;

    /**
     * Create a new Valkey GLIDE Laravel connection wrapper.
     *
     * @param  \ValkeyGlide  $client
     * @param  (\Closure(): \ValkeyGlide)|null  $connector
     * @param  array<string, mixed>  $config
     * @return void
     */
    public function __construct(\ValkeyGlide $client, ?\Closure $connector = null, array $config = [])
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
     * @return \ValkeyGlide
     */
    #[\Override]
    public function client(): \ValkeyGlide
    {
        return $this->glideClient;
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
        $normalized_method     = $this->normalizeCommandMethod($method);
        $normalized_parameters = $this->normalizeCommandParameters(
            $normalized_method,
            $parameters,
        );

        return $this->executeCommandWithRetry(
            $normalized_method,
            $normalized_parameters,
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
     * @phpstan-ignore method.childParameterType
     */
    #[\Override]
    public function createSubscription(mixed $channels, \Closure $callback, mixed $method = 'subscribe'): void
    {
        $normalized_channels = $this->normalizeSubscriptionChannels($channels);
        $normalized_method   = $this->normalizeSubscriptionMethod($method);
        $handler             = $this->newMessageHandler($callback);

        match ($normalized_method) {
            'subscribe'  => $this->glideClient->subscribe($normalized_channels, $handler),
            'psubscribe' => $this->glideClient->psubscribe($normalized_channels, $handler),
            default      => throw new \InvalidArgumentException(sprintf('Unsupported subscription method [%s].', $normalized_method)),
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
            $started_at = microtime(true);

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

            $this->dispatchCommandExecutedEvent($method, $parameters, $started_at);

            return $result;
        }
    }

    /**
     * Invoke a command on the underlying GLIDE client.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     */
    private function invokeCommand(string $method, array $parameters): mixed
    {
        return call_user_func_array([$this->glideClient, $method], $parameters);
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
     * @param  float  $started_at
     * @return void
     */
    private function dispatchCommandExecutedEvent(string $method, array $parameters, float $started_at): void
    {
        $execution_time_ms = round((microtime(true) - $started_at) * 1000, 2);

        $this->events?->dispatch(
            new CommandExecuted(
                $method,
                $this->parseParametersForEvent($parameters),
                $execution_time_ms,
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
            $argument_count = count($arguments);

            $message = $argument_count >= 1 ? $arguments[$argument_count - 1] : null;
            $channel = $argument_count >= 2 ? $arguments[$argument_count - 2] : null;

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

        $normalized_method = strtoupper($method);

        if ($normalized_method === 'EVAL' || $normalized_method === 'EVALSHA') {
            return $this->prefixEvalKeys($parameters);
        }

        return match (true) {
            in_array($normalized_method, self::ALL_KEY_COMMANDS, true)    => $this->prefixAllParameters($parameters),
            in_array($normalized_method, self::DOUBLE_KEY_COMMANDS, true) => $this->prefixParameterAt(
                $this->prefixParameterAt($parameters, 0),
                1,
            ),
            in_array($normalized_method, self::SINGLE_KEY_COMMANDS, true) => $this->prefixParameterAt($parameters, 0),
            default                                                       => $parameters,
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

        $prefixed_value = $this->prefixValue($parameters[$index]);

        if ($prefixed_value === null) {
            return $parameters;
        }

        $parameters[$index] = $prefixed_value;

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
            $prefixed_value = $this->prefixValue($parameter);

            if ($prefixed_value !== null) {
                $parameters[$index] = $prefixed_value;
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

        $key_count = $this->normalizeNonNegativeInt($parameters[1]);

        if ($key_count === null || $key_count <= 0) {
            return $parameters;
        }

        for ($offset = 0; $offset < $key_count; $offset++) {
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

        $normalized_value = (string) $value;

        return $this->prefix . $normalized_value;
    }

    /**
     * Normalize command method names to non-empty strings.
     *
     * @param  mixed  $method
     * @return string
     */
    private function normalizeCommandMethod(mixed $method): string
    {
        $normalized_method = $this->normalizeNonEmptyStringable($method);

        if ($normalized_method !== null) {
            return $normalized_method;
        }

        throw new \InvalidArgumentException(sprintf('Unsupported command method type [%s].', get_debug_type($method)));
    }

    /**
     * Normalize subscription method names to non-empty lowercase strings.
     *
     * @param  mixed  $method
     * @return string
     */
    private function normalizeSubscriptionMethod(mixed $method): string
    {
        $normalized_method = $this->normalizeNonEmptyStringable($method);

        if ($normalized_method !== null) {
            return strtolower($normalized_method);
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
        $base_delay = $this->normalizeNonNegativeInt($this->config['retry_delay_ms'] ?? null)  ?? 25;
        $max_jitter = $this->normalizeNonNegativeInt($this->config['retry_jitter_ms'] ?? null) ?? 15;

        if ($max_jitter > 0) {
            $random_int = $this->randomIntGenerator;

            try {
                $base_delay += $random_int(0, $max_jitter);
            } catch (\Throwable) {
                $base_delay = max(0, $base_delay);
            }
        }

        if ($base_delay <= 0) {
            return;
        }

        $sleep_callback = $this->sleepCallback;
        $sleep_callback($base_delay * 1000);
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

            $channel_name = (string) $channel;

            if ($channel_name !== '') {
                $normalized[] = $channel_name;
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
     * @param  mixed  $random_int
     * @return \Closure(int, int): int
     */
    private function resolveRandomIntGenerator(mixed $random_int): \Closure
    {
        if (is_callable($random_int)) {
            return \Closure::fromCallable($random_int);
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
