<?php

// phpcs:disable PSR12.Files.DeclareStatement.SpaceFoundAfterDirective,PSR12.Files.DeclareStatement.SpaceFoundBeforeDirectiveValue
declare(strict_types = 1);
// phpcs:enable PSR12.Files.DeclareStatement.SpaceFoundAfterDirective,PSR12.Files.DeclareStatement.SpaceFoundBeforeDirectiveValue

namespace SineMacula\Valkey\Support;

/**
 * Normalize Laravel Redis config arrays into Valkey GLIDE connect arguments.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Config
{
    /** @var string Default host value. */
    private const string DEFAULT_HOST = '127.0.0.1';

    /** @var int Default port value. */
    private const int DEFAULT_PORT = 6379;

    /** @var int Default IAM refresh interval in seconds. */
    private const int DEFAULT_IAM_REFRESH_INTERVAL = 300;

    /**
     * Merge Laravel connection config with options and nested option overrides.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function merge(array $config, array $options = []): array
    {
        $nested_options = $config['options'] ?? [];

        if (!is_array($nested_options)) {
            $nested_options = [];
        }

        $config_without_options = $config;
        unset($config_without_options['options']);

        return array_replace($config_without_options, $options, $nested_options);
    }

    /**
     * Build GLIDE connect arguments for a single endpoint.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function connectArguments(array $config): array
    {
        $arguments = [
            'addresses' => self::addresses($config),
        ];

        if (self::usesTls($config) || self::hasIam($config)) {
            $arguments['use_tls'] = true;
        }

        $credentials = self::credentials($config);

        if ($credentials !== null) {
            $arguments['credentials'] = $credentials;
        }

        $database_id = self::databaseId($config['database'] ?? null);

        if ($database_id !== null) {
            $arguments['database_id'] = $database_id;
        }

        $client_name = self::clientName($config['name'] ?? null);

        if ($client_name !== null) {
            $arguments['client_name'] = $client_name;
        }

        return $arguments;
    }

    /**
     * Build GLIDE connect arguments for a cluster-style configuration.
     *
     * @param  array<int|string, mixed>  $cluster_config
     * @param  array<string, mixed>  $base_config
     * @return array<string, mixed>
     */
    public static function clusterConnectArguments(array $cluster_config, array $base_config = []): array
    {
        $seed_addresses = self::clusterAddresses($cluster_config);

        $merged = self::merge($base_config, [
            'addresses' => $seed_addresses,
        ]);

        return self::connectArguments($merged);
    }

    /**
     * Normalize single-connection addresses for GLIDE.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{host: string, port: int}>
     */
    public static function addresses(array $config): array
    {
        $raw_addresses = $config['addresses'] ?? null;

        if (is_array($raw_addresses) && $raw_addresses !== []) {
            $normalized = [];

            foreach ($raw_addresses as $address) {
                if (!is_array($address)) {
                    continue;
                }

                $normalized[] = self::normalizeAddress($address);
            }

            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [self::normalizeAddress($config)];
    }

    /**
     * Normalize cluster node config arrays into GLIDE seed addresses.
     *
     * @param  array<int|string, mixed>  $cluster_config
     * @return array<int, array{host: string, port: int}>
     */
    public static function clusterAddresses(array $cluster_config): array
    {
        $nodes = self::extractClusterNodes($cluster_config);

        if ($nodes === []) {
            return [self::normalizeAddress([])];
        }

        $addresses = [];

        foreach ($nodes as $node) {
            $addresses[] = self::normalizeAddress($node);
        }

        return $addresses;
    }

    /**
     * Determine whether TLS is enabled by config.
     *
     * @param  array<string, mixed>  $config
     * @return bool
     */
    private static function usesTls(array $config): bool
    {
        if (array_key_exists('tls', $config)) {
            return (bool) $config['tls'];
        }

        return ($config['scheme'] ?? null) === 'tls';
    }

    /**
     * Build password, ACL, or IAM credentials for GLIDE.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function credentials(array $config): ?array
    {
        $iam_credentials = self::iamCredentials($config);

        if ($iam_credentials !== null) {
            return $iam_credentials;
        }

        $password = $config['password'] ?? null;

        if (!is_string($password) || $password === '') {
            return null;
        }

        $username = $config['username'] ?? null;

        if (is_string($username) && $username !== '') {
            return [
                'username' => $username,
                'password' => $password,
            ];
        }

        return [
            'password' => $password,
        ];
    }

    /**
     * Determine whether IAM auth config is present.
     *
     * @param  array<string, mixed>  $config
     * @return bool
     */
    private static function hasIam(array $config): bool
    {
        return isset($config['iam']) && is_array($config['iam']);
    }

    /**
     * Build IAM credentials in GLIDE's expected shape.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function iamCredentials(array $config): ?array
    {
        if (!self::hasIam($config)) {
            return null;
        }

        $iam = $config['iam'];

        $username     = self::normalizeString($iam['username'] ?? null);
        $cluster_name = self::normalizeString($iam['cluster_name'] ?? null);
        $region       = self::normalizeString($iam['region'] ?? null);

        if ($username === '' || $cluster_name === '' || $region === '') {
            return null;
        }

        $refresh_interval = self::normalizeNonNegativeInt($iam['refresh_interval'] ?? null);

        if ($refresh_interval === null || $refresh_interval === 0) {
            $refresh_interval = self::DEFAULT_IAM_REFRESH_INTERVAL;
        }

        return [
            'username'  => $username,
            'iamConfig' => [
                'clusterName'            => $cluster_name,
                'region'                 => $region,
                'service'                => 'Elasticache',
                'refreshIntervalSeconds' => $refresh_interval,
            ],
        ];
    }

    /**
     * Normalize a host and port pair.
     *
     * @param  array<string, mixed>  $address
     * @return array{host: string, port: int}
     */
    private static function normalizeAddress(array $address): array
    {
        $host = self::normalizeString($address['host'] ?? null, self::DEFAULT_HOST);
        $port = self::normalizePort($address['port'] ?? null);

        return [
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * Extract nested cluster node definitions from a mixed cluster config.
     *
     * @param  array<int|string, mixed>  $cluster_config
     * @return array<int, array<string, mixed>>
     */
    private static function extractClusterNodes(array $cluster_config): array
    {
        $nodes = [];

        foreach ($cluster_config as $value) {
            if (is_array($value) && self::looksLikeNode($value)) {
                $nodes[] = $value;
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $nested) {
                if (is_array($nested) && self::looksLikeNode($nested)) {
                    $nodes[] = $nested;
                }
            }
        }

        return $nodes;
    }

    /**
     * Check if an array looks like a redis node definition.
     *
     * @param  array<string, mixed>  $value
     * @return bool
     */
    private static function looksLikeNode(array $value): bool
    {
        return array_key_exists('host', $value) || array_key_exists('port', $value);
    }

    /**
     * Normalize a mixed database id into a non-negative integer.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private static function databaseId(mixed $value): ?int
    {
        return self::normalizeNonNegativeInt($value);
    }

    /**
     * Normalize a mixed client name into a non-empty string.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private static function clientName(mixed $value): ?string
    {
        $normalized = trim(self::normalizeString($value));

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * Normalize mixed input to a non-empty string.
     *
     * @param  mixed  $value
     * @param  string  $default
     * @return string
     */
    private static function normalizeString(mixed $value, string $default = ''): string
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

        return $default;
    }

    /**
     * Normalize mixed input to an integer port value.
     *
     * @param  mixed  $value
     * @return int
     */
    private static function normalizePort(mixed $value): int
    {
        $normalized = self::normalizeNonNegativeInt($value);

        if ($normalized === null || $normalized === 0) {
            return self::DEFAULT_PORT;
        }

        return $normalized;
    }

    /**
     * Normalize mixed values into non-negative integers or null.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private static function normalizeNonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            $normalized = (int) $value;

            return $normalized >= 0 ? $normalized : null;
        }

        if (is_string($value) && is_numeric($value)) {
            $normalized = (int) $value;

            return $normalized >= 0 ? $normalized : null;
        }

        if ($value instanceof \Stringable && is_numeric((string) $value)) {
            $normalized = (int) (string) $value;

            return $normalized >= 0 ? $normalized : null;
        }

        return null;
    }
}
