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
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ValkeyGlideQueueConnector extends RedisConnector
{
    /** @var string Message for a queue option of the wrong type. */
    private const string INVALID_OPTION_MESSAGE = 'The [%s] queue option must be %s, received [%s].';

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
     *
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function connect(array $config): RedisQueue
    {
        if ($this->horizon) {
            return new ValkeyGlideHorizonQueue(
                $this->redis,
                self::queueName($config['queue'] ?? null),
                self::connectionName(Arr::get($config, 'connection', $this->connection)),
                self::optionalInteger('retry_after', Arr::get($config, 'retry_after', 60)),
                self::optionalInteger('block_for', Arr::get($config, 'block_for')),
                self::shouldDispatchAfterCommit(Arr::get($config, 'after_commit')),
            );
        }

        return new ValkeyGlideQueue(
            $this->redis,
            self::queueName($config['queue'] ?? null),
            self::connectionName($config['connection'] ?? $this->connection),
            self::integer('retry_after', $config['retry_after'] ?? null, 60),
            self::optionalInteger('block_for', $config['block_for'] ?? null),
            self::shouldDispatchAfterCommit($config['after_commit'] ?? null),
            self::integer('migration_batch_size', $config['migration_batch_size'] ?? null, -1),
        );
    }

    /**
     * Get the queue's default queue, which the queue option names.
     *
     * @param  mixed  $value
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private static function queueName(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new \InvalidArgumentException(sprintf(self::INVALID_OPTION_MESSAGE, 'queue', 'a string', get_debug_type($value)));
    }

    /**
     * Get the Redis connection the queue runs on, or null for the default.
     *
     * @param  mixed  $value
     * @return string|null
     *
     * @throws \InvalidArgumentException
     */
    private static function connectionName(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new \InvalidArgumentException(sprintf(self::INVALID_OPTION_MESSAGE, 'connection', 'a string', get_debug_type($value)));
    }

    /**
     * Get an integer option, or its default when it is not set.
     *
     * @param  string  $option
     * @param  mixed  $value
     * @param  int  $default
     * @return int
     *
     * @throws \InvalidArgumentException
     */
    private static function integer(string $option, mixed $value, int $default): int
    {
        return self::optionalInteger($option, $value) ?? $default;
    }

    /**
     * Get an integer option, from an integer or the string an environment
     * variable gives, or null when it is not set.
     *
     * @param  string  $option
     * @param  mixed  $value
     * @return int|null
     *
     * @throws \InvalidArgumentException
     */
    private static function optionalInteger(string $option, mixed $value): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        $integer = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) : null;

        if ($integer === null) {
            throw new \InvalidArgumentException(sprintf(self::INVALID_OPTION_MESSAGE, $option, 'an integer', get_debug_type($value)));
        }

        return $integer;
    }

    /**
     * Get whether the queue dispatches its jobs after database transactions
     * commit, from a boolean or the integer or string an environment variable
     * gives; an unset option is false, as the queue reads it.
     *
     * @param  mixed  $value
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    private static function shouldDispatchAfterCommit(mixed $value): bool
    {
        if ($value === null || is_bool($value)) {
            return $value ?? false;
        }

        $flag = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;

        if ($flag === null) {
            throw new \InvalidArgumentException(sprintf(self::INVALID_OPTION_MESSAGE, 'after_commit', 'a boolean', get_debug_type($value)));
        }

        return $flag;
    }
}
