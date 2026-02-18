<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Stubs;

/**
 * Minimal IDE/static-analysis stub for the Valkey GLIDE extension.
 */
class ValkeyGlide extends Redis
{
    public const IAM_CONFIG_CLUSTER_NAME     = 'clusterName';
    public const IAM_CONFIG_REFRESH_INTERVAL = 'refreshIntervalSeconds';
    public const IAM_CONFIG_REGION           = 'region';
    public const IAM_CONFIG_SERVICE          = 'service';
    public const IAM_SERVICE_ELASTICACHE     = 'Elasticache';

    /**
     * Create a new stubbed GLIDE client instance.
     *
     * @return void
     */
    public function __construct()
    {
        self::touchArguments();
    }

    /**
     * Dynamically proxy unknown methods for static analysis compatibility.
     *
     * @param  string  $name
     * @param  array<int|string, mixed>  $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        self::touchArguments($name, $arguments);

        return null;
    }

    /**
     * Connect to a Valkey endpoint.
     *
     * @param  mixed  ...$arguments
     * @return bool
     */
    public function connect(mixed ...$arguments): bool
    {
        self::touchArguments(...$arguments);

        return true;
    }

    /**
     * Run a CLIENT command.
     *
     * @param  string  $opt
     * @param  mixed  ...$args
     * @return mixed
     */
    public function client(string $opt, mixed ...$args): mixed
    {
        self::touchArguments($opt, ...$args);

        return true;
    }

    /**
     * Close the connection.
     *
     * @return bool
     */
    public function close(): bool
    {
        self::touchArguments();

        return true;
    }

    /**
     * Select the logical database.
     *
     * @param  int  $db
     * @return bool|self
     */
    public function select(int $db): bool|self
    {
        self::touchArguments($db);

        return true;
    }

    /**
     * Execute a raw command.
     *
     * @param  string  $command
     * @param  mixed  ...$args
     * @return mixed
     */
    public function rawcommand(string $command, mixed ...$args): mixed
    {
        self::touchArguments($command, ...$args);

        return null;
    }

    /**
     * Configure a client option.
     *
     * @param  int  $option
     * @param  mixed  $value
     * @return bool
     */
    public function setOption(int $option, mixed $value): bool
    {
        self::touchArguments($option, $value);

        return true;
    }

    /**
     * Subscribe to channels.
     *
     * @param  array<int, string>  $channels
     * @param  callable  $cb
     *
     * @phpstan-param callable(mixed ...$arguments): void $cb
     *
     * @return bool
     */
    public function subscribe(array $channels, callable $cb): bool
    {
        self::touchArguments($channels, $cb);

        return true;
    }

    /**
     * Pattern-subscribe to channels.
     *
     * @param  array<int, string>  $patterns
     * @param  callable  $cb
     *
     * @phpstan-param callable(mixed ...$arguments): void $cb
     *
     * @return bool
     */
    public function psubscribe(array $patterns, callable $cb): bool
    {
        self::touchArguments($patterns, $cb);

        return true;
    }

    /**
     * Get a key value.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get(string $key): mixed
    {
        self::touchArguments($key);

        return false;
    }

    /**
     * Set a key value.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  mixed  $options
     * @return bool|self|string
     */
    public function set(string $key, mixed $value, mixed $options = null): bool|self|string
    {
        self::touchArguments($key, $value, $options);

        return true;
    }

    /**
     * Consume values so static analysis sees argument usage.
     *
     * @param  mixed  ...$arguments
     * @return int
     */
    private static function touchArguments(mixed ...$arguments): int
    {
        return count($arguments);
    }
}
