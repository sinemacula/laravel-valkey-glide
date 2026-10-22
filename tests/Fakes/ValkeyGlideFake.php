<?php

declare(strict_types = 1);

namespace Tests\Fakes;

use Tests\Fakes\Concerns\EmulatesBatchMode;

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
     * @return array<array-key, mixed>|false|self
     *
     * @throws \TypeError
     */
    #[\Override]
    public function zscan(string $key, ?string &$iterator, ?string $pattern = null, int $count = 0): array|false|self
    {
        if (func_num_args() >= 3 && $pattern === null) {
            throw new \TypeError('ValkeyGlide::zscan(): Argument #3 ($pattern) must be of type string, null given');
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
            throw new \TypeError('ValkeyGlide::sscan(): Argument #3 ($pattern) must be of type string, null given');
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
     * @return array<array-key, mixed>|bool|self
     *
     * @throws \TypeError
     */
    #[\Override]
    public function hscan(string $key, ?string &$iterator, ?string $pattern = null, int $count = 0): array|bool|self
    {
        if (func_num_args() >= 3 && $pattern === null) {
            throw new \TypeError('ValkeyGlide::hscan(): Argument #3 ($pattern) must be of type string, null given');
        }

        $this->recordCall('hscan', [$key, $iterator, $pattern, $count]);

        [$iterator, $reply] = $this->nextScanPage('hscan');

        return is_array($reply) || $reply instanceof self ? $reply : false;
    }

    /**
     * Record a keyspace scan and answer with the next scripted page.
     *
     * The cursor is recorded as received and the page's cursor is written back
     * through the reference. An explicit null pattern throws, as with GLIDE.
     *
     * @param  string|null  $iterator
     * @param  string|null  $pattern
     * @param  int  $count
     * @param  string|null  $type
     *
     * @param-out  string  $iterator
     *
     * @return array<int, string>|false
     *
     * @throws \TypeError
     */
    #[\Override]
    public function scan(?string &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        if (func_num_args() >= 2 && $pattern === null) {
            throw new \TypeError('ValkeyGlide::scan(): Argument #2 ($pattern) must be of type string, null given');
        }

        $this->recordCall('scan', [$iterator, $pattern, $count, $type]);

        [$iterator, $reply] = $this->nextScanPage('scan');

        return is_array($reply) ? $this->stringList($reply) : false;
    }

    // phpcs:disable Squiz.NamingConventions.ValidVariableName.NotCamelCaps
    /**
     * Record a connect invocation and apply configured behavior.
     *
     * The parameter names are intentionally snake_case to mirror the
     * ext-valkey_glide signature: the connector spreads the snake_case
     * connect-argument array as named arguments, so these names are a fixed API
     * contract and must not be renamed to camelCase.
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
     *
     * @imperative
     */
    #[\Override]
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

    // phpcs:enable Squiz.NamingConventions.ValidVariableName.NotCamelCaps

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
     * Record a raw command invocation and apply configured behavior.
     *
     * @param  string  $command
     * @param  mixed  ...$args
     * @return mixed
     */
    #[\Override]
    public function rawcommand(string $command, mixed ...$args): mixed
    {
        return $this->handleCommand('rawcommand', [$command, ...$args], null);
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
     * Record an mget command invocation and apply configured behavior.
     *
     * @param  array<int, string>  $keys
     * @return array<int, false|string>|false|self
     *
     * @throws \UnexpectedValueException
     */
    public function mget(array $keys): array|false|self
    {
        $result = $this->handleCommand('mget', [$keys], false);

        if ($result === false || $result instanceof self) {
            return $result;
        }

        if (!is_array($result)) {
            throw new \UnexpectedValueException(sprintf('Configured mget behavior must return array|false|self; received [%s].', get_debug_type($result)));
        }

        $normalizedResult = [];

        foreach ($result as $value) {

            if ($value !== false && !is_string($value)) {
                throw new \UnexpectedValueException(sprintf('Configured mget array entries must be string|false; received [%s].', get_debug_type($value)));
            }

            $normalizedResult[] = $value;
        }

        return $normalizedResult;
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
     * Record a CLIENT command invocation and apply configured behavior.
     *
     * @param  string  $opt
     * @param  mixed  ...$args
     * @return mixed
     */
    #[\Override]
    public function client(string $opt, mixed ...$args): mixed
    {
        return $this->handleCommand('client', [$opt, ...$args], true);
    }

    /**
     * Report the PHPRedis aliases as registered.
     *
     * Like the other client-local methods, this is static in ext-valkey_glide
     * 1.1.2, so it runs at once whatever batch mode the client is in.
     *
     * @return bool
     *
     * @imperative
     */
    #[\Override]
    public static function registerPHPRedisAliases(): bool
    {
        return true;
    }

    /**
     * Accept an OpenTelemetry sampling percentage, as a static method of
     * ext-valkey_glide 1.1.2 that runs at once.
     *
     * @param  int  $percentage
     * @return void
     */
    #[\Override]
    public static function setOtelSamplePercentage(int $percentage): void
    {
        // Nothing is sampled by the fake.
    }

    /**
     * Report a fixed OpenTelemetry sampling percentage, as a static method of
     * ext-valkey_glide 1.1.2 that runs at once.
     *
     * @return int
     */
    #[\Override]
    public static function getOtelSamplePercentage(): int
    {
        return 25;
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
    public function multi(int $value = self::MULTI): bool|self
    {
        return $this->enterBatch('multi', $value === self::MULTI);
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
