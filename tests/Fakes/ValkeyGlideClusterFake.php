<?php

declare(strict_types = 1);

namespace Tests\Fakes;

use Tests\Fakes\Concerns\EmulatesBatchMode;

/**
 * Deterministic fake implementation for cluster connector and connection tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @inheritable
 */
class ValkeyGlideClusterFake extends \ValkeyGlideCluster
{
    use EmulatesBatchMode;

    /** @var int The finished-scan answers a scan method gives past its scripted pages before it fails, so a scan loop that never ends fails fast. */
    private const int MAX_UNSCRIPTED_SCAN_PAGES = 100;

    /** @var array<string, list<array<int|string, mixed>>> Recorded method calls by name. */
    private array $calls = [];

    /** @var array<string, mixed> Configured return values keyed by lowercase method name. */
    private array $returns = [];

    /** @var array<string, \Throwable> Configured exceptions keyed by lowercase method name. */
    private array $exceptions = [];

    /** @var array<string, array<int, mixed>> Subscription callback payloads by method. */
    private array $subscriptionPayloads = [];

    /** @var array<string, list<array{0: string, 1: mixed}>> Scripted scan pages by lowercase method: the cursor written back, then the reply. */
    private array $scanPages = [];

    /** @var array<string, int> The finished-scan answers given past the scripted pages, by lowercase method. */
    private array $unscriptedScanPages = [];

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
        return $this->handleCommand($name, $arguments, null);
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
     * Script the pages a scan method answers with, in turn.
     *
     * Each page is the cursor written back, then the reply. Once the pages run
     * out, the scan answers as GLIDE does for a missing key or a finished scan:
     * cursor '0' and an empty page, up to a bound past which it throws.
     *
     * @param  string  $method
     * @param  list<array{0: string, 1: mixed}>  $pages
     * @return void
     */
    public function willScan(string $method, array $pages): void
    {
        $this->scanPages[strtolower($method)] = $pages;
    }

    /**
     * Record a sorted-set scan and answer with the next scripted page.
     *
     * The cursor is recorded as received and the page's cursor is written back
     * through the reference. An explicit null pattern throws, as with GLIDE.
     *
     * @param  string  $key
     * @param  string|null  $iterator
     * @param  string|null  $pattern
     * @param  int  $count
     *
     * @param-out  string  $iterator
     *
     * @return array<array-key, mixed>|bool|self
     *
     * @throws \TypeError
     */
    #[\Override]
    public function zscan(string $key, ?string &$iterator, ?string $pattern = null, int $count = 0): array|bool|self
    {
        if (func_num_args() >= 3 && $pattern === null) {
            throw new \TypeError('ValkeyGlideCluster::zscan(): Argument #3 ($pattern) must be of type string, null given');
        }

        $this->recordCall('zscan', [$key, $iterator, $pattern, $count]);

        [$iterator, $reply] = $this->nextScanPage('zscan');

        return is_array($reply) || $reply instanceof self ? $reply : false;
    }

    /**
     * Record a set scan and answer with the next scripted page.
     *
     * The cursor is recorded as received and the page's cursor is written back
     * through the reference. An explicit null pattern throws, as with GLIDE.
     *
     * @param  string  $key
     * @param  string|null  $iterator
     * @param  string|null  $pattern
     * @param  int  $count
     *
     * @param-out  string  $iterator
     *
     * @return array<int, mixed>|false
     *
     * @throws \TypeError
     */
    #[\Override]
    public function sscan(string $key, ?string &$iterator, ?string $pattern = null, int $count = 0): array|false
    {
        if (func_num_args() >= 3 && $pattern === null) {
            throw new \TypeError('ValkeyGlideCluster::sscan(): Argument #3 ($pattern) must be of type string, null given');
        }

        $this->recordCall('sscan', [$key, $iterator, $pattern, $count]);

        [$iterator, $reply] = $this->nextScanPage('sscan');

        return is_array($reply) ? array_values($reply) : false;
    }

    /**
     * Record a hash scan and answer with the next scripted page.
     *
     * The cursor is recorded as received and the page's cursor is written back
     * through the reference. An explicit null pattern throws, as with GLIDE.
     *
     * @param  string  $key
     * @param  string|null  $iterator
     * @param  string|null  $pattern
     * @param  int  $count
     *
     * @param-out  string  $iterator
     *
     * @return array<array-key, mixed>|bool
     *
     * @throws \TypeError
     */
    #[\Override]
    public function hscan(string $key, ?string &$iterator, ?string $pattern = null, int $count = 0): array|bool
    {
        if (func_num_args() >= 3 && $pattern === null) {
            throw new \TypeError('ValkeyGlideCluster::hscan(): Argument #3 ($pattern) must be of type string, null given');
        }

        $this->recordCall('hscan', [$key, $iterator, $pattern, $count]);

        [$iterator, $reply] = $this->nextScanPage('hscan');

        return is_array($reply) ? $reply : false;
    }

    /**
     * Record a cluster keyspace scan and answer with the next scripted page.
     *
     * The id the cursor was built with is recorded, and the page's cursor is
     * written as the cursor's next id, as GLIDE does. An explicit null pattern
     * throws, as with GLIDE.
     *
     * @param  \ClusterScanCursor  $iterator
     * @param  string|null  $pattern
     * @param  int  $count
     * @param  string|null  $type
     * @return array<int, string>|bool
     *
     * @throws \TypeError
     */
    #[\Override]
    public function scan(\ClusterScanCursor $iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|bool
    {
        if (func_num_args() >= 2 && $pattern === null) {
            throw new \TypeError('ValkeyGlideCluster::scan(): Argument #2 ($pattern) must be of type string, null given');
        }

        $this->recordCall('scan', [$iterator->getCursor(), $pattern, $count, $type]);

        [$next, $reply] = $this->nextScanPage('scan');

        (new \ReflectionProperty(\ClusterScanCursor::class, 'nextCursor'))->setValue($iterator, $next);

        return is_array($reply) ? $this->stringList($reply) : (bool) $reply;
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

        $result = (bool) $this->resolveBehavior('close', true);

        $this->markClosed();

        return $result;
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
        return $this->handleCommand('get', [$key], null);
    }

    /**
     * Record a set command invocation and apply configured behavior.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  mixed  $options
     * @return bool|self|string
     *
     * @throws \ArgumentCountError
     * @throws \TypeError
     */
    #[\Override]
    public function set(string $key, mixed $value, mixed $options = null): bool|self|string
    {
        $this->assertNativeSetArguments(func_get_args());

        if (is_string($options) || (is_array($options) && $this->refusesSetExpiry($options))) {
            // GLIDE 1.1.2 refuses a string option, and an expiry it cannot read
            // as a positive number, and writes or buffers nothing.
            $this->recordCall('set', [$key, $value, $options]);

            return false;
        }

        $result = $this->handleCommand('set', [$key, $value, $options], true);

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
        return $this->handleCommand('client', [$route, $opt, ...$args], true);
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
        return $this->handleCommand('rawcommand', [$route, $command, ...$args], null);
    }

    /**
     * Enter pipeline mode, as ext-valkey_glide 1.1.2 does.
     *
     * @return bool|self
     */
    #[\Override]
    public function pipeline(): bool|self
    {
        return $this->enterBatch('pipeline', false);
    }

    /**
     * Enter batch mode, as ext-valkey_glide 1.1.2 does.
     *
     * @param  int  $value
     * @return bool|self
     */
    #[\Override]
    public function multi(int $value = 0): bool|self
    {
        return $this->enterBatch('multi', $value === 0);
    }

    /**
     * Send the buffered commands, as ext-valkey_glide 1.1.2 does.
     *
     * @return array<int, mixed>|false
     */
    #[\Override]
    public function exec(): array|false
    {
        return $this->executeBatch();
    }

    /**
     * Drop the buffered commands, as ext-valkey_glide 1.1.2 does.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    #[\Override]
    public function discard(): bool
    {
        return $this->discardBatch();
    }

    /**
     * Record a watch invocation and apply batch mode or configured behavior.
     *
     * The keys are untyped, as GLIDE checks them itself and returns false for
     * an integer first key rather than coerce it.
     *
     * @param  mixed  $key
     * @param  mixed  ...$otherKeys
     * @return bool|self
     */
    #[\Override]
    public function watch(mixed $key, mixed ...$otherKeys): bool|self
    {
        $result = $this->handleCommand('watch', [$key, ...$otherKeys], true);

        return $result instanceof self ? $result : (bool) $result;
    }

    /**
     * Record an unwatch invocation and apply batch mode or configured behavior.
     *
     * @return bool
     */
    #[\Override]
    public function unwatch(): bool
    {
        $result = $this->handleCommand('unwatch', [], true);

        return !$result instanceof self && (bool) $result;
    }

    /**
     * Record a method call for later assertions.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @return void
     *
     * @throws \LogicException
     */
    private function recordCall(string $method, array $arguments): void
    {
        $normalizedMethod = strtolower($method);

        $this->calls[$normalizedMethod] ??= [];
        $this->calls[$normalizedMethod][] = $arguments;
        $this->callOrder[]                = $normalizedMethod;

        $this->assertWithinBatchBudget($normalizedMethod);
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

    /**
     * Take the next scripted page of a scan method, or throw its configured
     * exception.
     *
     * @param  string  $method
     * @return array{0: string, 1: mixed}
     *
     * @throws \LogicException
     */
    private function nextScanPage(string $method): array
    {
        if (array_key_exists($method, $this->exceptions)) {
            throw $this->exceptions[$method];
        }

        $pages = $this->scanPages[$method] ?? [];
        $page  = array_shift($pages);

        $this->scanPages[$method] = $pages;

        if ($page !== null) {
            return $page;
        }

        $unscripted = ($this->unscriptedScanPages[$method] ?? 0) + 1;

        if ($unscripted > self::MAX_UNSCRIPTED_SCAN_PAGES) {
            throw new \LogicException(sprintf('%s was called %d times past its scripted pages; the scan loop does not end.', $method, $unscripted - 1));
        }

        $this->unscriptedScanPages[$method] = $unscripted;

        return ['0', []];
    }

    /**
     * Narrow a scripted keyspace scan reply to the list of keys GLIDE returns.
     *
     * @param  array<array-key, mixed>  $reply
     * @return array<int, string>
     */
    private function stringList(array $reply): array
    {
        return array_values(array_map(static fn (mixed $key): string => is_scalar($key) ? (string) $key : '', $reply));
    }
}
