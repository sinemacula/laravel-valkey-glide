<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Support;

/**
 * Normalize Laravel Redis config arrays into GLIDE host/port address shapes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AddressResolver
{
    /** @var string Default host value. */
    private const string DEFAULT_HOST = '127.0.0.1';

    /** @var int Default port value. */
    private const int DEFAULT_PORT = 6379;

    /**
     * Normalize single-connection addresses for GLIDE.
     *
     * When the config contains a non-empty array under the 'addresses' key,
     * each array element is normalized; non-array elements are skipped. Falls
     * back to a single endpoint derived from the top-level config when the
     * list is absent, empty, or entirely non-array.
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
     * Traverses the cluster config array extracting host/port node definitions
     * from both flat and nested shapes. Falls back to the default address when
     * no valid nodes are found.
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
     * Normalize a host and port pair into the GLIDE address shape.
     *
     * @param  array<string, mixed>  $address
     * @return array{host: string, port: int}
     */
    private static function normalizeAddress(array $address): array
    {
        $host = Cast::toNonEmptyString($address['host'] ?? null) ?? self::DEFAULT_HOST;
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
     * Determine whether the array shape resembles a host/port node definition.
     *
     * @param  array<string, mixed>  $value
     * @return bool
     */
    private static function looksLikeNode(array $value): bool
    {
        return array_key_exists('host', $value) || array_key_exists('port', $value);
    }

    /**
     * Normalize mixed input to an integer port value.
     *
     * @param  mixed  $value
     * @return int
     */
    private static function normalizePort(mixed $value): int
    {
        $normalized = Cast::toNonNegativeInt($value);

        if ($normalized === null || $normalized === 0) {
            return self::DEFAULT_PORT;
        }

        return $normalized;
    }
}
