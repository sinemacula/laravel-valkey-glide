<?php

declare(strict_types = 1);

namespace Tests\Fakes;

use Illuminate\Redis\Connections\Connection;

/**
 * Stand-in for Laravel's Redis queue before 13.5, which builds every key from
 * `getQueue()` and has no `getQueueRedisKey()`, so a queue trait can be tested
 * over it on any Laravel version.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class RedisQueueBefore135Fake
{
    /**
     * Create a queue over the given connection.
     *
     * @param  \Illuminate\Redis\Connections\Connection  $connection
     * @return void
     */
    public function __construct(

        /** The connection the queue runs on. */
        private readonly Connection $connection,
    ) {}

    /**
     * Get the queue or return the default, as Laravel's Redis queue does.
     *
     * @param  mixed  $queue
     * @return string
     */
    public function getQueue(mixed $queue): string
    {
        return 'queues:' . (is_string($queue) && $queue !== '' ? $queue : 'default');
    }

    /**
     * Get the connection for the queue.
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
