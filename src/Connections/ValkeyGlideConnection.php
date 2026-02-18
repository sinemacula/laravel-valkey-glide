<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

use Illuminate\Contracts\Redis\Connection as ConnectionContract;
use Illuminate\Redis\Connections\Connection;

/**
 * Laravel Redis connection adapter backed by the Valkey GLIDE client.
 *
 * This wrapper keeps Laravel's Redis connection contract while adding a narrow
 * retry path for idempotent read commands on transient transport failures.
 *
 * @mixin \ValkeyGlide
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValkeyGlideConnection extends Connection implements ConnectionContract
{
    /** @var array<int, string> */
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

    /** @var array<int, string> */
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
        'readonly',
        'temporarily unavailable',
    ];

    /** @var \ValkeyGlide */
    protected \ValkeyGlide $glideClient;

    /** @var (callable(): \ValkeyGlide)|null */
    protected $connector;

    /** @var array<string, mixed> */
    protected array $config;

    /** @var callable(int, int): int */
    private $randomIntGenerator;

    /** @var callable(int): void */
    private $sleepCallback;

    /**
     * Create a new Valkey GLIDE Laravel connection wrapper.
     *
     * @param  \ValkeyGlide  $client
     * @param  (callable(): \ValkeyGlide)|null  $connector
     * @param  array<string, mixed>  $config
     * @return void
     */
    public function __construct(\ValkeyGlide $client, ?callable $connector = null, array $config = [])
    {
        $this->glideClient        = $client;
        $this->connector          = $connector;
        $this->config             = $config;
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
     * @param  mixed|string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    #[\Override]
    public function command($method, array $parameters = [])
    {
        $normalized_method = $this->normalizeCommandMethod($method);

        try {
            return $this->runClientCommand($normalized_method, $parameters);
        } catch (\Throwable $exception) {

            if (!$this->shouldRetryCommand($method, $exception) || !$this->reconnectClient()) {
                throw $exception;
            }

            $this->sleepBeforeRetry();

            return $this->runClientCommand($normalized_method, $parameters);
        }
    }

    /**
     * Subscribe to channels and normalize message callback arguments.
     *
     * @param  array<array-key, mixed>|string  $channels
     * @param  mixed  $callback
     * @param  mixed|string  $method
     * @return void
     */
    #[\Override]
    public function createSubscription($channels, mixed $callback, $method = 'subscribe'): void
    {
        if (!$callback instanceof \Closure) {
            throw new \InvalidArgumentException(sprintf('Unsupported subscription callback type [%s].', get_debug_type($callback)));
        }

        if (!is_string($method)) {
            throw new \InvalidArgumentException(sprintf('Unsupported subscription method type [%s].', get_debug_type($method)));
        }

        $channels = $this->normalizeSubscriptionChannels($channels);
        $handler  = $this->newMessageHandler($callback);

        match (strtolower($method)) {
            'subscribe'  => $this->glideClient->subscribe($channels, $handler),
            'psubscribe' => $this->glideClient->psubscribe($channels, $handler),
            default      => throw new \InvalidArgumentException(sprintf('Unsupported subscription method [%s].', $method)),
        };
    }

    /**
     * Execute a raw command against the underlying GLIDE client.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
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
     * Wrap a subscription callback to receive ($message, $channel) regardless
     * of how many arguments the underlying driver passes.
     *
     * @param  \Closure  $callback
     * @return \Closure
     */
    private function newMessageHandler(\Closure $callback): \Closure
    {
        return static function (mixed ...$arguments) use ($callback): void {
            $message = $arguments[array_key_last($arguments)]     ?? null;
            $channel = $arguments[array_key_last($arguments) - 1] ?? null;

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

        $client            = ($this->connector)();
        $this->glideClient = $client;

        return true;
    }

    /**
     * Execute a command against the GLIDE client using dynamic dispatch.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     */
    private function runClientCommand(string $method, array $parameters): mixed
    {
        return call_user_func_array([$this->glideClient, $method], $parameters);
    }

    /**
     * Normalize the command method name to a non-empty string.
     *
     * @param  mixed  $method
     * @return string
     */
    private function normalizeCommandMethod(mixed $method): string
    {
        if (is_string($method) && $method !== '') {
            return $method;
        }

        if ((is_int($method) || is_float($method) || is_bool($method) || $method instanceof \Stringable) && (string) $method !== '') {
            return (string) $method;
        }

        throw new \InvalidArgumentException(sprintf('Unsupported command method type [%s].', get_debug_type($method)));
    }

    /**
     * Sleep briefly before retry to avoid hot-loop retries.
     *
     * @return void
     */
    private function sleepBeforeRetry(): void
    {
        $delay_milliseconds = $this->retryDelayMilliseconds();

        if ($delay_milliseconds <= 0) {
            return;
        }

        $sleep_callback = $this->sleepCallback;
        $sleep_callback($delay_milliseconds * 1000);
    }

    /**
     * Resolve retry delay from config with optional jitter.
     *
     * @return int
     */
    private function retryDelayMilliseconds(): int
    {
        $base_delay = $this->normalizeIntConfig($this->config['retry_delay_ms'] ?? null, 25);
        $max_jitter = $this->normalizeIntConfig($this->config['retry_jitter_ms'] ?? null, 15);

        if ($max_jitter === 0) {
            return $base_delay;
        }

        $random_int = $this->randomIntGenerator;

        try {
            return $base_delay + $random_int(0, $max_jitter);
        } catch (\Throwable) {
            return $base_delay;
        }
    }

    /**
     * Normalize mixed config values into non-negative integers.
     *
     * @param  mixed  $value
     * @param  int  $default
     * @return int
     */
    private function normalizeIntConfig(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_float($value)) {
            return max(0, (int) $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return max(0, $default);
    }

    /**
     * Determine if the failed command is safe to retry once.
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
     * Classify whether the exception represents a transient connection issue.
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
     * @param  array<array-key, mixed>|string  $channels
     * @return array<int, string>
     */
    private function normalizeSubscriptionChannels(array|string $channels): array
    {
        $source     = is_array($channels) ? $channels : [$channels];
        $normalized = [];

        foreach ($source as $channel) {

            if (is_string($channel)) {

                if ($channel !== '') {
                    $normalized[] = $channel;
                }

                continue;
            }

            if (is_int($channel) || is_float($channel) || is_bool($channel) || $channel instanceof \Stringable) {

                $channel_name = (string) $channel;

                if ($channel_name !== '') {
                    $normalized[] = $channel_name;
                }
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one valid subscription channel is required.');
        }

        return $normalized;
    }

    /**
     * Resolve the configured random integer generator callback.
     *
     * @param  mixed  $random_int
     * @return callable(int, int): int
     */
    private function resolveRandomIntGenerator(mixed $random_int): callable
    {
        if (is_callable($random_int)) {
            return $random_int;
        }

        return static fn (int $min, int $max): int => random_int($min, $max);
    }

    /**
     * Resolve the configured sleep callback.
     *
     * @param  mixed  $sleep
     * @return callable(int): void
     */
    private function resolveSleepCallback(mixed $sleep): callable
    {
        if (is_callable($sleep)) {
            return $sleep;
        }

        return static function (int $microseconds): void {
            usleep($microseconds);
        };
    }
}
