<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Support;

use SineMacula\Valkey\Enums\PeriodicChecks;
use SineMacula\Valkey\Enums\ReadFrom;

/**
 * Normalize Laravel Redis config arrays into Valkey GLIDE connect arguments.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Config
{
    /** @var int The connection timeout, in milliseconds, when the config sets none: glide-core's own default. */
    private const int DEFAULT_CONNECTION_TIMEOUT = 2000;

    /**
     * Merge Laravel connection config with options and nested option overrides.
     *
     * The connection's nested `options` override the given (shared) options,
     * which override the connection's own keys. A `prefix` set on the
     * connection itself, even an empty one, overrides both, as Laravel's
     * phpredis connector applies it; a null prefix counts as unset.
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

        if (isset($config['prefix'])) {
            $nestedOptions['prefix'] = $config['prefix'];
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
     *
     * @throws \InvalidArgumentException
     */
    public static function connectArguments(#[\SensitiveParameter] array $config): array
    {
        $arguments = [
            'addresses' => AddressResolver::addresses($config),
        ];

        if (self::usesTls($config) || CredentialResolver::hasIam($config)) {
            $arguments['use_tls'] = true;
        }

        $credentials = CredentialResolver::resolve($config);

        if ($credentials !== null) {
            $arguments['credentials'] = $credentials;
        }

        return array_merge($arguments, self::optionalArguments($config));
    }

    /**
     * Build GLIDE connect arguments for a cluster-style configuration.
     *
     * @param  array<int|string, mixed>  $clusterConfig
     * @param  array<string, mixed>  $baseConfig
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function clusterConnectArguments(array $clusterConfig, array $baseConfig = []): array
    {
        $seedAddresses = AddressResolver::clusterAddresses($clusterConfig);

        $merged = self::merge($baseConfig, [
            'addresses' => $seedAddresses,
        ]);

        return self::clusterClientArguments($merged);
    }

    /**
     * Build GLIDE cluster constructor arguments from a resolved config.
     *
     * Extends connectArguments() with the options only the cluster client
     * accepts; the standalone connect() has no periodic_checks parameter.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function clusterClientArguments(#[\SensitiveParameter] array $config): array
    {
        $arguments      = self::connectArguments($config);
        $periodicChecks = self::periodicChecks($config['periodic_checks'] ?? null);

        if ($periodicChecks !== null) {
            $arguments['periodic_checks'] = $periodicChecks;
        }

        return $arguments;
    }

    /**
     * Normalize single-connection addresses for GLIDE.
     *
     * Delegates to AddressResolver; retained as a public API entry point.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{host: string, port: int}>
     */
    public static function addresses(array $config): array
    {
        return AddressResolver::addresses($config);
    }

    /**
     * Normalize cluster node config arrays into GLIDE seed addresses.
     *
     * Delegates to AddressResolver; retained as a public API entry point.
     *
     * @param  array<int|string, mixed>  $clusterConfig
     * @return array<int, array{host: string, port: int}>
     */
    public static function clusterAddresses(array $clusterConfig): array
    {
        return AddressResolver::clusterAddresses($clusterConfig);
    }

    /**
     * Resolve the optional, value-dependent GLIDE connect arguments.
     *
     * Each entry is included only when its resolver returns a non-null value,
     * preserving the original key order. A zero database_id and a false
     * lazy_connect are kept; only null is treated as absent.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private static function optionalArguments(#[\SensitiveParameter] array $config): array
    {
        $resolved = [
            'database_id'        => self::databaseId($config['database'] ?? null),
            'client_name'        => self::clientName($config['name'] ?? null),
            'read_from'          => self::readFrom($config['read_from'] ?? null),
            'client_az'          => self::clientAz($config['client_az'] ?? null),
            'context'            => self::context($config['context'] ?? null),
            'request_timeout'    => self::requestTimeout($config['timeout'] ?? null),
            'advanced_config'    => self::advancedConfig($config['connection_timeout'] ?? null),
            'reconnect_strategy' => ReconnectStrategyResolver::resolve($config['reconnect_strategy'] ?? null),
            'lazy_connect'       => self::shouldConnectLazily($config['lazy_connect'] ?? null),
        ];

        return array_filter($resolved, static fn (mixed $value): bool => $value !== null);
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
     * Normalize a mixed database id into a non-negative integer.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private static function databaseId(mixed $value): ?int
    {
        return Cast::toNonNegativeInt($value);
    }

    /**
     * Normalize a mixed client name into a non-empty string.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private static function clientName(mixed $value): ?string
    {
        $normalized = trim(Cast::toNonEmptyString($value) ?? '');

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * Resolve a mixed read-from value to its GLIDE integer constant.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private static function readFrom(mixed $value): ?int
    {
        return ReadFrom::tryFromMixed($value)?->value;
    }

    /**
     * Resolve a mixed periodic-checks value to its GLIDE integer constant.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private static function periodicChecks(mixed $value): ?int
    {
        return PeriodicChecks::tryFromMixed($value)?->value;
    }

    /**
     * Normalize a mixed availability-zone value into a non-empty string.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private static function clientAz(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Normalize a mixed context value for GLIDE passthrough.
     *
     * Accepts a non-empty array or a PHP resource; all other values return
     * null.
     *
     * @param  mixed  $value
     * @return array<array-key, mixed>|resource|null
     */
    private static function context(mixed $value): mixed
    {
        if (is_array($value) && $value !== []) {
            return $value;
        }

        if (is_resource($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Convert a seconds value to GLIDE milliseconds for request_timeout.
     *
     * Accepts int, float, or numeric string greater than zero; all other values
     * return null. The value is treated as seconds and converted to
     * milliseconds.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private static function requestTimeout(mixed $value): ?int
    {
        return self::secondsToMilliseconds($value);
    }

    /**
     * Build the advanced_config array from a connection_timeout value in
     * seconds.
     *
     * When the value is absent or not a positive numeric, glide-core's own two
     * second default is used rather than the PHP extension's 250ms, which is
     * too short for a connection that resolves IAM credentials and opens TLS.
     *
     * @param  mixed  $value
     * @return array<string, int>
     */
    private static function advancedConfig(mixed $value): array
    {
        return ['connection_timeout' => self::secondsToMilliseconds($value) ?? self::DEFAULT_CONNECTION_TIMEOUT];
    }

    /**
     * Normalize a mixed lazy_connect flag into a boolean.
     *
     * Accepts booleans and the integer or string forms filter_var() reads as
     * booleans, so environment values work; null means not configured.
     *
     * @param  mixed  $value
     * @return bool|null
     *
     * @throws \InvalidArgumentException
     */
    private static function shouldConnectLazily(mixed $value): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        $flag = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;

        if ($flag === null) {
            throw new \InvalidArgumentException(sprintf('The [lazy_connect] option must be a boolean, received [%s].', get_debug_type($value)));
        }

        return $flag;
    }

    /**
     * Convert a seconds value to an integer millisecond count.
     *
     * Accepts int, float, or numeric string; returns null for non-numeric or
     * non-positive values.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private static function secondsToMilliseconds(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $seconds = (float) $value;

        if ($seconds <= 0) {
            return null;
        }

        return (int) round($seconds * 1000);
    }
}
