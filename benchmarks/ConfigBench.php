<?php

declare(strict_types = 1);

namespace Benchmarks;

use PhpBench\Attributes as Bench;
use SineMacula\Valkey\Support\Config;

/**
 * Benchmarks for connection configuration normalization.
 *
 * Config translates Laravel Redis configuration into GLIDE connect arguments on
 * every connection establishment and reconnect. These benchmarks track the cost
 * of that normalization for single-node and cluster shapes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('microseconds')]
final class ConfigBench
{
    /** @var array<string, mixed> Representative single-node connection config. */
    private const array SINGLE_NODE_CONFIG = [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'username' => 'cache-user',
        'password' => 'secret',
        'database' => 3,
        'name'     => 'worker',
        'options'  => ['prefix' => 'app:'],
    ];

    /** @var array<int, array<string, mixed>> Representative cluster seed nodes. */
    private const array CLUSTER_CONFIG = [
        ['host' => 'node-1', 'port' => 6379],
        ['host' => 'node-2', 'port' => 6380],
        ['host' => 'node-3', 'port' => 6381],
    ];

    /**
     * Benchmark single-endpoint connect-argument building.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchConnectArguments(): void
    {
        Config::connectArguments(Config::merge(self::SINGLE_NODE_CONFIG));
    }

    /**
     * Benchmark cluster seed-address connect-argument building.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchClusterConnectArguments(): void
    {
        Config::clusterConnectArguments(self::CLUSTER_CONFIG);
    }
}
