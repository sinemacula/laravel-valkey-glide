<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Queue\Connectors;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Queue\Connectors\RedisConnector;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Arr;
use SineMacula\Valkey\Queue\ValkeyGlideHorizonQueue;
use SineMacula\Valkey\Queue\ValkeyGlideQueue;

/**
 * Laravel's Redis queue connector, connecting the GLIDE-aware queue, or
 * Horizon's when Horizon is registered.
 *
 * The package's service provider registers it before Laravel 13.5.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ValkeyGlideQueueConnector extends RedisConnector
{
    /**
     * Create a new Redis queue connector instance.
     *
     * @param  \Illuminate\Contracts\Redis\Factory  $redis
     * @param  bool  $horizon
     * @return void
     */
    public function __construct(

        // The Redis factory the queue connects through.
        Redis $redis,

        /** Whether to connect Horizon's queue, as Horizon's connector does. */
        private readonly bool $horizon,
    ) {
        parent::__construct($redis);
    }

    /**
     * Establish a queue connection, as Laravel's connector does, or as
     * Horizon's does, which keeps a null retry after and takes no migration
     * batch size.
     *
     * @param  array<array-key, mixed>  $config
     * @return \Illuminate\Queue\RedisQueue
     */
    #[\Override]
    public function connect(array $config): RedisQueue
    {
        if ($this->horizon) {
            return new ValkeyGlideHorizonQueue(
                $this->redis,
                $config['queue'],
                Arr::get($config, 'connection', $this->connection),
                Arr::get($config, 'retry_after', 60),
                Arr::get($config, 'block_for'),
                Arr::get($config, 'after_commit'),
            );
        }

        return new ValkeyGlideQueue(
            $this->redis,
            $config['queue'],
            $config['connection']           ?? $this->connection,
            $config['retry_after']          ?? 60,
            $config['block_for']            ?? null,
            $config['after_commit']         ?? null,
            $config['migration_batch_size'] ?? -1,
        );
    }
}
