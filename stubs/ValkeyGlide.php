<?php

declare(strict_types = 1);

/**
 * IDE and static-analysis stub for ext-valkey_glide's ValkeyGlide class.
 *
 * Loaded only when the extension class is unavailable.
 *
 * @see https://github.com/valkey-io/valkey-glide-php
 */
if (class_exists(ValkeyGlide::class, false)) {
    return;
}

class ValkeyGlide
{
    /** Data type constants. */
    public const int NOT_FOUND = 0;
    public const int STRING    = 1;
    public const int SET       = 2;
    public const int LIST      = 3;
    public const int ZSET      = 4;
    public const int HASH      = 5;
    public const int STREAM    = 6;

    /** Transaction mode constants. */
    public const int MULTI    = 0;
    public const int PIPELINE = 1;

    /** Read strategy constants. */
    public const int READ_FROM_PRIMARY                          = 0;
    public const int READ_FROM_PREFER_REPLICA                   = 1;
    public const int READ_FROM_AZ_AFFINITY                      = 2;
    public const int READ_FROM_AZ_AFFINITY_REPLICAS_AND_PRIMARY = 3;

    /** Condition constants. */
    public const string CONDITION_NX = 'NX';
    public const string CONDITION_XX = 'XX';

    /** Time unit constants. */
    public const string TIME_UNIT_SECONDS                = 'EX';
    public const string TIME_UNIT_MILLISECONDS           = 'PX';
    public const string TIME_UNIT_TIMESTAMP_SECONDS      = 'EXAT';
    public const string TIME_UNIT_TIMESTAMP_MILLISECONDS = 'PXAT';

    /** Copy constants. */
    public const string COPY_REPLACE = 'REPLACE';
    public const string COPY_DB      = 'DB';

    /** List position constants. */
    public const string BEFORE = 'before';
    public const string AFTER  = 'after';
    public const string LEFT   = 'left';
    public const string RIGHT  = 'right';

    /** IAM configuration key constants. */
    public const string IAM_CONFIG_CLUSTER_NAME     = 'clusterName';
    public const string IAM_CONFIG_REGION           = 'region';
    public const string IAM_CONFIG_SERVICE          = 'service';
    public const string IAM_CONFIG_REFRESH_INTERVAL = 'refreshIntervalSeconds';

    /** IAM service constants. */
    public const string IAM_SERVICE_ELASTICACHE = 'Elasticache';
    public const string IAM_SERVICE_MEMORYDB    = 'MemoryDB';

    /**
     * Constructor.
     */
    public function __construct() {}

    /**
     * Proxy for commands not explicitly declared above.
     *
     * @param  string  $name
     * @param  array<int|string, mixed>  $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed {}

    /**
     * Register class aliases for PHPRedis compatibility.
     *
     * @return bool
     */
    public static function registerPHPRedisAliases(): bool {}

    /**
     * Set the OpenTelemetry sampling percentage.
     *
     * @param  int  $percentage
     * @return void
     */
    public static function setOtelSamplePercentage(int $percentage): void {}

    /**
     * Get the current OpenTelemetry sampling percentage.
     *
     * @return int|null
     */
    public static function getOtelSamplePercentage(): ?int {}

    /**
     * Connect to a Valkey endpoint.
     *
     * Parameters 1-6 provide PHPRedis-compatible positional style. Parameters
     * 7+ are ValkeyGlide-native named parameters. The two styles must not be
     * mixed.
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
     * @param  array<string, mixed>|resource|null  $context
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
    ): bool {}

    /**
     * Close the connection.
     *
     * @return bool
     */
    public function close(): bool {}

    /**
     * Select the logical database.
     *
     * @param  int  $db
     * @return bool|self
     */
    public function select(int $db): bool|self {}

    /**
     * Run a CLIENT subcommand.
     *
     * @param  string  $opt
     * @param  mixed  ...$args
     * @return mixed
     */
    public function client(string $opt, mixed ...$args): mixed {}

    /**
     * Configure a client option.
     *
     * @param  int  $option
     * @param  mixed  $value
     * @return bool
     */
    public function setOption(int $option, mixed $value): bool {}

    /**
     * Execute a raw command.
     *
     * @param  string  $command
     * @param  mixed  ...$args
     * @return mixed
     */
    public function rawcommand(string $command, mixed ...$args): mixed {}

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
}
