<?php

declare(strict_types = 1);

/**
 * IDE and static-analysis stub for ext-valkey_glide's ValkeyGlideCluster class.
 *
 * Loaded only when the extension class is unavailable.
 *
 * @see https://github.com/valkey-io/valkey-glide-php
 */
if (class_exists(ValkeyGlideCluster::class, false)) {
    return;
}

class ValkeyGlideCluster
{
    /**
     * Create a cluster client and connect via the constructor.
     *
     * All parameters are optional and may be supplied as named arguments.
     * The two styles (positional `seeds` and named `addresses`) must not be
     * mixed. Cluster clients connect during construction; there is no separate
     * `connect()` call.
     *
     * @param  string|null  $name
     * @param  array<int, array{host: string, port: int}>|null  $seeds
     * @param  float|null  $timeout
     * @param  float|null  $read_timeout
     * @param  bool|null  $persistent
     * @param  mixed  $auth
     * @param  resource|array<string, mixed>|null  $context  TLS stream context or options array.
     * @param  array<int, array{host: string, port: int}>|null  $addresses
     * @param  bool|null  $use_tls
     * @param  array<string, mixed>|null  $credentials
     * @param  int|null  $read_from
     * @param  int|null  $request_timeout
     * @param  array<string, mixed>|null  $reconnect_strategy
     * @param  string|null  $client_name
     * @param  int|null  $periodic_checks
     * @param  string|null  $client_az
     * @param  array<string, mixed>|null  $advanced_config
     * @param  bool|null  $lazy_connect
     * @param  int|null  $database_id
     * @param  array<string, mixed>|null  $compression
     * @param  array<string, mixed>|null  $client_side_cache
     * @param  callable|null  $address_resolver
     */
    public function __construct(
        ?string $name = null,
        ?array $seeds = null,
        ?float $timeout = null,
        ?float $read_timeout = null,
        ?bool $persistent = null,
        mixed $auth = null,
        mixed $context = null,
        ?array $addresses = null,
        ?bool $use_tls = null,
        ?array $credentials = null,
        ?int $read_from = null,
        ?int $request_timeout = null,
        ?array $reconnect_strategy = null,
        ?string $client_name = null,
        ?int $periodic_checks = null,
        ?string $client_az = null,
        ?array $advanced_config = null,
        ?bool $lazy_connect = null,
        ?int $database_id = null,
        ?array $compression = null,
        ?array $client_side_cache = null,
        ?callable $address_resolver = null,
    ) {}

    /**
     * Proxy for commands not explicitly declared above.
     *
     * @param  string  $name
     * @param  array<int|string, mixed>  $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed {}

    /**
     * Close the connection.
     *
     * @return bool
     */
    public function close(): bool {}

    /**
     * Get the value of a key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get(string $key): mixed {}

    /**
     * Set the value of a key.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  mixed  $options
     * @return bool|self|string
     */
    public function set(string $key, mixed $value, mixed $options = null): bool|self|string {}

    /**
     * Subscribe to channels.
     *
     * @param  array<int, string>  $channels
     * @param  callable  $callback
     * @return bool
     */
    public function subscribe(array $channels, callable $callback): bool {}

    /**
     * Pattern-subscribe to channels.
     *
     * @param  array<int, string>  $patterns
     * @param  callable  $callback
     * @return bool
     */
    public function psubscribe(array $patterns, callable $callback): bool {}

    /**
     * Run a CLIENT subcommand on a cluster slot.
     *
     * The leading `$route` argument directs the command to the correct node.
     *
     * @param  mixed  $route
     * @param  string  $opt
     * @param  mixed  ...$args
     * @return mixed
     */
    public function client(mixed $route, string $opt, mixed ...$args): mixed {}

    /**
     * Configure a client option.
     *
     * @param  int  $option
     * @param  mixed  $value
     * @return bool
     */
    public function setOption(int $option, mixed $value): bool {}

    /**
     * Execute a raw command on the cluster, routing to the correct node.
     *
     * The `$route` argument selects the target node(s). Accepted forms:
     *
     * - `"randomNode"` - send to a single random node.
     * - `"allPrimaries"` - fan-out to all primary nodes.
     * - `"allNodes"` - fan-out to every node (primaries + replicas).
     * - A key-name string - route to the primary owning that key's hash slot.
     * - `['type' => 'primarySlotKey', 'key' => '<key>']` - explicit key-slot primary.
     * - `['type' => 'routeByAddress', 'host' => '<host>', 'port' => <port>]` - target a specific node.
     *
     * @param  mixed  $route
     * @param  string  $command
     * @param  mixed  ...$args
     * @return mixed
     */
    public function rawcommand(mixed $route, string $command, mixed ...$args): mixed {}
}
