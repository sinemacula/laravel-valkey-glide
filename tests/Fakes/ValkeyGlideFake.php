<?php

declare(strict_types = 1);

namespace Tests\Fakes;

/**
 * Deterministic fake implementation for connector and connection tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ValkeyGlideFake extends \ValkeyGlide
{
    /** @var array<string, list<array<int|string, mixed>>> Recorded method calls by name. */
    private array $calls = [];

    /** @var array<string, mixed> Configured return values keyed by lowercase method name. */
    private array $returns = [];

    /** @var array<string, \Throwable> Configured exceptions keyed by lowercase method name. */
    private array $exceptions = [];

    /** @var array<string, array<int, mixed>> Subscription callback payloads by method. */
    private array $subscriptionPayloads = [];

    /**
     * Record dynamic command invocations and apply configured behavior.
     *
     * @param  string  $name
     * @param  array<int|string, mixed>  $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        $this->recordCall($name, $arguments);

        return $this->resolveBehavior($name, null);
    }

    /**
     * Configure a return value for a fake method.
     *
     * @param  string  $method
     * @param  mixed  $value
     * @return void
     */
    public function willReturn(string $method, mixed $value): void
    {
        $this->returns[strtolower($method)] = $value;
    }

    /**
     * Configure an exception for a fake method.
     *
     * @param  string  $method
     * @param  \Throwable  $exception
     * @return void
     */
    public function willThrow(string $method, \Throwable $exception): void
    {
        $this->exceptions[strtolower($method)] = $exception;
    }

    /**
     * Configure payload arguments passed to subscription callbacks.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $payload
     * @return void
     */
    public function setSubscriptionPayload(string $method, array $payload): void
    {
        $this->subscriptionPayloads[strtolower($method)] = $payload;
    }

    /**
     * Return recorded calls for the given method.
     *
     * @param  string  $method
     * @return list<array<int|string, mixed>>
     */
    public function callsFor(string $method): array
    {
        return $this->calls[strtolower($method)] ?? [];
    }

    /**
     * Record a connect invocation and apply configured behavior.
     *
     * @param  string|null  $host
     * @param  int|null  $port
     * @param  float|null  $timeout
     * @param  string|null  $persistent_id
     * @param  int|null  $retry_interval
     * @param  float|null  $read_timeout
     * @param  array<int, array{host: string, port: int}>|null  $addresses
     * @param  bool|null  $use_tls
     * @param  array<string, mixed>|null  $credentials
     * @param  int|null  $read_from
     * @param  int|null  $request_timeout
     * @param  array<string, mixed>|null  $reconnect_strategy
     * @param  int|null  $database_id
     * @param  string|null  $client_name
     * @param  string|null  $client_az
     * @param  array<string, mixed>|null  $advanced_config
     * @param  bool|null  $lazy_connect
     * @param  mixed  $context
     * @return bool
     */
    public function connect(
        ?string $host = null,
        ?int $port = null,
        ?float $timeout = null,
        ?string $persistent_id = null,
        ?int $retry_interval = null,
        ?float $read_timeout = null,
        ?array $addresses = null,
        ?bool $use_tls = null,
        ?array $credentials = null,
        ?int $read_from = null,
        ?int $request_timeout = null,
        ?array $reconnect_strategy = null,
        ?int $database_id = null,
        ?string $client_name = null,
        ?string $client_az = null,
        ?array $advanced_config = null,
        ?bool $lazy_connect = null,
        mixed $context = null,
    ): bool {
        $this->recordCall('connect', [
            'host'               => $host,
            'port'               => $port,
            'timeout'            => $timeout,
            'persistent_id'      => $persistent_id,
            'retry_interval'     => $retry_interval,
            'read_timeout'       => $read_timeout,
            'addresses'          => $addresses,
            'use_tls'            => $use_tls,
            'credentials'        => $credentials,
            'read_from'          => $read_from,
            'request_timeout'    => $request_timeout,
            'reconnect_strategy' => $reconnect_strategy,
            'database_id'        => $database_id,
            'client_name'        => $client_name,
            'client_az'          => $client_az,
            'advanced_config'    => $advanced_config,
            'lazy_connect'       => $lazy_connect,
            'context'            => $context,
        ]);

        return (bool) $this->resolveBehavior('connect', true);
    }

    /**
     * Record a close invocation and apply configured behavior.
     *
     * @return bool
     */
    public function close(): bool
    {
        $this->recordCall('close', []);

        return (bool) $this->resolveBehavior('close', true);
    }

    /**
     * Record a raw command invocation and apply configured behavior.
     *
     * @param  string  $command
     * @param  mixed  ...$args
     * @return mixed
     */
    public function rawcommand(string $command, mixed ...$args): mixed
    {
        $this->recordCall('rawcommand', [$command, ...$args]);

        return $this->resolveBehavior('rawcommand', null);
    }

    /**
     * Record a get command invocation and apply configured behavior.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get(string $key): mixed
    {
        $this->recordCall('get', [$key]);

        return $this->resolveBehavior('get', null);
    }

    /**
     * Record a set command invocation and apply configured behavior.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  mixed  $options
     * @return bool|self|string
     */
    public function set(string $key, mixed $value, mixed $options = null): bool|self|string
    {
        $this->recordCall('set', [$key, $value, $options]);

        $result = $this->resolveBehavior('set', true);

        if (is_string($result) || is_bool($result) || $result instanceof self) {
            return $result;
        }

        return true;
    }

    /**
     * Record a CLIENT command invocation and apply configured behavior.
     *
     * @param  string  $opt
     * @param  mixed  ...$args
     * @return mixed
     */
    public function client(string $opt, mixed ...$args): mixed
    {
        $this->recordCall('client', [$opt, ...$args]);

        return $this->resolveBehavior('client', true);
    }

    /**
     * Record subscribe invocation, execute callback, and apply behavior.
     *
     * @param  array<int, string>  $channels
     * @param  callable  $callback
     *
     * @phpstan-param callable(): mixed $callback
     *
     * @return bool
     */
    public function subscribe(array $channels, callable $callback): bool
    {
        $this->recordCall('subscribe', [$channels]);

        $payload = $this->subscriptionPayloads['subscribe'] ?? ['ignored', $channels[0] ?? null, 'message'];
        $callback(...$payload);

        return (bool) $this->resolveBehavior('subscribe', true);
    }

    /**
     * Record psubscribe invocation, execute callback, and apply behavior.
     *
     * @param  array<int, string>  $patterns
     * @param  callable  $callback
     *
     * @phpstan-param callable(): mixed $callback
     *
     * @return bool
     */
    public function psubscribe(array $patterns, callable $callback): bool
    {
        $this->recordCall('psubscribe', [$patterns]);

        $payload = $this->subscriptionPayloads['psubscribe'] ?? ['ignored', $patterns[0] ?? null, 'message'];
        $callback(...$payload);

        return (bool) $this->resolveBehavior('psubscribe', true);
    }

    /**
     * Resolve configured behavior for a fake method.
     *
     * @param  string  $method
     * @param  mixed  $default
     * @return mixed
     *
     * @throws \Throwable
     */
    private function resolveBehavior(string $method, mixed $default): mixed
    {
        $normalized_method = strtolower($method);

        if (array_key_exists($normalized_method, $this->exceptions)) {
            throw $this->exceptions[$normalized_method];
        }

        if (array_key_exists($normalized_method, $this->returns)) {
            return $this->returns[$normalized_method];
        }

        return $default;
    }

    /**
     * Record a method call for later assertions.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @return void
     */
    private function recordCall(string $method, array $arguments): void
    {
        $normalized_method = strtolower($method);

        $this->calls[$normalized_method] ??= [];
        $this->calls[$normalized_method][] = $arguments;
    }
}
