<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Support;

use Illuminate\Support\Arr;

final class Config
{
    private const string DEFAULT_HOST              = '127.0.0.1';
    private const int DEFAULT_PORT                 = 6379;
    private const int DEFAULT_IAM_REFRESH_INTERVAL = 300;

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function merge(array $config, array $options = []): array
    {
        $connectionOptions = Arr::pull($config, 'options', []);

        if (!is_array($connectionOptions)) {
            $connectionOptions = [];
        }

        return [...$config, ...$options, ...$connectionOptions];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{host: string, port: int}>
     */
    public static function addresses(array $config): array
    {
        $rawAddresses = $config['addresses'] ?? null;

        if (is_array($rawAddresses) && $rawAddresses !== []) {
            $addresses = array_map(
                static fn (array $address): array => self::normalizeAddress($address),
                array_values(array_filter($rawAddresses, static fn (mixed $value): bool => is_array($value))),
            );

            if ($addresses !== []) {
                return $addresses;
            }
        }

        return [self::normalizeAddress($config)];
    }

    /**
     * @param  array<int, mixed>  $clusterConfig
     * @return array<int, array{host: string, port: int}>
     */
    public static function clusterAddresses(array $clusterConfig): array
    {
        $addresses = [];

        foreach ($clusterConfig as $node) {
            if (!is_array($node)) {
                continue;
            }

            $addresses[] = self::normalizeAddress($node);
        }

        if ($addresses !== []) {
            return $addresses;
        }

        return [self::normalizeAddress([])];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, array{host: string, port: int}>|null  $addresses
     * @return array<string, mixed>
     */
    public static function connectArguments(array $config, ?array $addresses = null): array
    {
        $arguments = ['addresses' => $addresses ?? self::addresses($config)];

        if (self::usesTls($config) || isset($config['iam'])) {
            $arguments['use_tls'] = true;
        }

        $credentials = self::credentials($config);

        if ($credentials !== null) {
            $arguments['credentials'] = $credentials;
        }

        return $arguments;
    }

    /**
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

        $username = $config['username'] ?? null;

        if (is_string($username) && $username !== '') {
            return [
                'username' => $username,
                'password' => $password,
            ];
        }

        return ['password' => $password];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function iamCredentials(array $config): ?array
    {
        if (!isset($config['iam']) || !is_array($config['iam'])) {
            return null;
        }

        $iam         = $config['iam'];
        $username    = trim(self::normalizeString($iam['username'] ?? null));
        $clusterName = trim(self::normalizeString($iam['cluster_name'] ?? null));
        $region      = trim(self::normalizeString($iam['region'] ?? null));

        if ($username === '' || $clusterName === '' || $region === '') {
            return null;
        }

        $refreshInterval = (int) ($iam['refresh_interval'] ?? self::DEFAULT_IAM_REFRESH_INTERVAL);
        $refreshInterval = $refreshInterval > 0 ? $refreshInterval : self::DEFAULT_IAM_REFRESH_INTERVAL;

        return [
            'username'  => $username,
            'iamConfig' => [
                self::glideConstant('IAM_CONFIG_CLUSTER_NAME', 'cluster_name')         => $clusterName,
                self::glideConstant('IAM_CONFIG_REGION', 'region')                     => $region,
                self::glideConstant('IAM_CONFIG_SERVICE', 'service')                   => self::glideConstant('IAM_SERVICE_ELASTICACHE', 'elasticache'),
                self::glideConstant('IAM_CONFIG_REFRESH_INTERVAL', 'refresh_interval') => $refreshInterval,
            ],
        ];
    }

    /**
     * Resolve a GLIDE extension constant to a string key value.
     *
     * @param  string  $name
     * @param  string  $default
     * @return string
     */
    private static function glideConstant(string $name, string $default): string
    {
        $constantName = "ValkeyGlide::{$name}";

        if (!defined($constantName)) {
            return $default;
        }

        $value = constant($constantName);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Normalize mixed host input to a non-empty string.
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
     * Normalize mixed port input to an integer.
     *
     * @param  mixed  $value
     * @return int
     */
    private static function normalizePort(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return self::DEFAULT_PORT;
    }
}
