<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Give Laravel Horizon its cluster connection on a valkey-glide cluster before
 * Laravel 13.5.
 *
 * Horizon 5.46 configures a cluster as its own `horizon` cluster connection,
 * with its prefix hash-tagged into one slot, only where Laravel's
 * Connection::hasHashTag() exists, which Laravel 13.5 added. Before that it
 * connects to the cluster's first node as a standalone server, where every key
 * that another node owns fails with MOVED.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class HorizonClusterConnection
{
    /**
     * Replace Horizon's first-node fallback for the valkey-glide cluster that
     * `horizon.use` names with the configuration Horizon writes from Laravel
     * 13.5.
     *
     * The fallback is matched exactly as Horizon writes it: the cluster's first
     * node, with `horizon.prefix` in its options. Any other configuration is
     * left as it is.
     *
     * @param  \Illuminate\Contracts\Config\Repository  $config
     * @return void
     */
    public static function configure(Repository $config): void
    {
        $use    = $config->get('horizon.use', 'default');
        $prefix = $config->get('horizon.prefix');

        if ($config->get('database.redis.client') !== 'valkey-glide' || !is_string($use) || !is_string($prefix)) {
            return;
        }

        $cluster = $config->get('database.redis.clusters.' . $use);

        if (!is_array($cluster) || !is_array($cluster[0] ?? null) || $config->get('database.redis.horizon') !== self::withPrefix($cluster[0], $prefix)) {
            return;
        }

        $prefix = HashTag::isIn($prefix) ? $prefix : '{' . $prefix . '}';

        $redis = $config->get('database.redis');

        unset($redis['horizon']);

        $config->set([
            'database.redis'                  => $redis,
            'database.redis.clusters.horizon' => self::withPrefix($cluster, $prefix),
            'horizon.prefix'                  => $prefix,
        ]);
    }

    /**
     * Set the prefix in the given connection's options, as Horizon does.
     *
     * @param  array<array-key, mixed>  $connection
     * @param  string  $prefix
     * @return array<array-key, mixed>
     */
    private static function withPrefix(array $connection, string $prefix): array
    {
        $connection['options']['prefix'] = $prefix;

        return $connection;
    }
}
