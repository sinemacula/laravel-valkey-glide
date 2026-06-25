<?php

declare(strict_types = 1);

namespace Tests\Fakes;

/**
 * Deterministic fake implementation for cluster connector and connection tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ValkeyGlideClusterFake extends \ValkeyGlideCluster
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
    #[\Override]
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
     * Record a close invocation and apply configured behavior.
     *
     * @return bool
     *
     * @imperative
     */
    #[\Override]
    public function close(): bool
    {
        $this->recordCall('close', []);

        return (bool) $this->resolveBehavior('close', true);
    }

    /**
     * Record a get command invocation and apply configured behavior.
     *
     * @param  string  $key
     * @return mixed
     */
    #[\Override]
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
    #[\Override]
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
     * Record subscribe invocation, execute callback, and apply behavior.
     *
     * @param  array<int, string>  $channels
     * @param  callable  $callback
     *
     * @phpstan-param callable(): mixed $callback
     *
     * @return bool
     *
     * @imperative
     */
    #[\Override]
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
     *
     * @imperative
     */
    #[\Override]
    public function psubscribe(array $patterns, callable $callback): bool
    {
        $this->recordCall('psubscribe', [$patterns]);

        $payload = $this->subscriptionPayloads['psubscribe'] ?? ['ignored', $patterns[0] ?? null, 'message'];
        $callback(...$payload);

        return (bool) $this->resolveBehavior('psubscribe', true);
    }

    /**
     * Record a CLIENT command invocation and apply configured behavior.
     *
     * The leading `$route` argument is recorded as the first element so tests
     * can assert which node was targeted.
     *
     * @param  mixed  $route
     * @param  string  $opt
     * @param  mixed  ...$args
     * @return mixed
     */
    #[\Override]
    public function client(mixed $route, string $opt, mixed ...$args): mixed
    {
        $this->recordCall('client', [$route, $opt, ...$args]);

        return $this->resolveBehavior('client', true);
    }

    /**
     * Record a raw cluster command invocation and apply configured behavior.
     *
     * The call is recorded as `[$route, $command, ...$args]` so tests can
     * assert both the routing decision and the command arguments.
     *
     * @param  mixed  $route
     * @param  string  $command
     * @param  mixed  ...$args
     * @return mixed
     */
    #[\Override]
    public function rawcommand(mixed $route, string $command, mixed ...$args): mixed
    {
        $this->recordCall('rawcommand', [$route, $command, ...$args]);

        return $this->resolveBehavior('rawcommand', null);
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
        $normalizedMethod = strtolower($method);

        $this->calls[$normalizedMethod] ??= [];
        $this->calls[$normalizedMethod][] = $arguments;
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
        $normalizedMethod = strtolower($method);

        if (array_key_exists($normalizedMethod, $this->exceptions)) {
            throw $this->exceptions[$normalizedMethod];
        }

        if (array_key_exists($normalizedMethod, $this->returns)) {
            return $this->returns[$normalizedMethod];
        }

        return $default;
    }
}
