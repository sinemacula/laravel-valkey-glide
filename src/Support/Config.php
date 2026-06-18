<?php

declare(strict_types = 1);

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
        $nestedOptions = $config['options'] ?? [];

        if (!is_array($nestedOptions)) {
            $nestedOptions = [];
        }

        $configWithoutOptions = $config;
        unset($configWithoutOptions['options']);

        return array_replace($configWithoutOptions, $options, $nestedOptions);
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

        $databaseId = self::databaseId($config['database'] ?? null);

        if ($databaseId !== null) {
            $arguments['database_id'] = $databaseId;
        }

        $clientName = self::clientName($config['name'] ?? null);

        if ($clientName !== null) {
            $arguments['client_name'] = $clientName;
        }

        return $arguments;
    }

    /**
     * Build GLIDE connect arguments for a cluster-style configuration.
     *
     * @param  array<int|string, mixed>  $clusterConfig
     * @param  array<string, mixed>  $baseConfig
     * @return array<string, mixed>
     */
    public static function clusterConnectArguments(array $clusterConfig, array $baseConfig = []): array
    {
        $seedAddresses = self::clusterAddresses($clusterConfig);

        $merged = self::merge($baseConfig, [
            'addresses' => $seedAddresses,
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
        $rawAddresses = $config['addresses'] ?? null;

        if (is_array($rawAddresses) && $rawAddresses !== []) {
            $normalized = [];

            foreach ($rawAddresses as $address) {
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
     * @param  array<int|string, mixed>  $clusterConfig
     * @return array<int, array{host: string, port: int}>
     */
    public static function clusterAddresses(array $clusterConfig): array
    {
        $nodes = self::extractClusterNodes($clusterConfig);

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
        $iamCredentials = self::iamCredentials($config);

        if ($iamCredentials !== null) {
            return $iamCredentials;
        }

        $password = $config['password'] ?? null;

        if (!is_string($password) || $password === '') {
            return null;
        }

        $username    = $config['username'] ?? null;
        $credentials = ['password' => $password];

        if (is_string($username) && $username !== '') {
            $credentials['username'] = $username;
        }

        return $credentials;
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

        $username    = self::normalizeString($iam['username'] ?? null);
        $clusterName = self::normalizeString($iam['cluster_name'] ?? null);
        $region      = self::normalizeString($iam['region'] ?? null);

        if ($username === '' || $clusterName === '' || $region === '') {
            return null;
        }

        $refreshInterval = self::normalizeNonNegativeInt($iam['refresh_interval'] ?? null);

        if ($refreshInterval === null || $refreshInterval === 0) {
            $refreshInterval = self::DEFAULT_IAM_REFRESH_INTERVAL;
        }

        return [
            'username'  => $username,
            'iamConfig' => [
                'clusterName'            => $clusterName,
                'region'                 => $region,
                'service'                => 'Elasticache',
                'refreshIntervalSeconds' => $refreshInterval,
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
     * @param  array<int|string, mixed>  $clusterConfig
     * @return array<int, array<string, mixed>>
     */
    private static function extractClusterNodes(array $clusterConfig): array
    {
        $nodes = [];

        foreach ($clusterConfig as $value) {
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
        $normalized = match (true) {
            is_int($value)                                               => $value,
            is_float($value)                                             => (int) $value,
            is_string($value)             && is_numeric($value)          => (int) $value,
            $value instanceof \Stringable && is_numeric((string) $value) => (int) (string) $value,
            default                                                      => null,
        };

        return $normalized !== null && $normalized >= 0 ? $normalized : null;
    }
}
