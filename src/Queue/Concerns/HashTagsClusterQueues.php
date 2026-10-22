<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Queue\Concerns;

use Illuminate\Support\Str;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Support\HashTag;

/**
 * Hash-tags a Redis queue's keys on a GLIDE cluster connection before Laravel
 * 13.5, as Laravel does from 13.5.
 *
 * A queue's scripts touch its list and its `:delayed`, `:reserved` and
 * `:notify` keys at once, which a cluster only runs when they share a slot.
 * Before 13.5 every key is built from `getQueue()`, so the name is wrapped in a
 * hash tag there, as 13.5 wraps it, unless the queue's keys already carry one
 * from the name or the connection's prefix. From 13.5 the keys come from
 * `getQueueRedisKey()`, which tags them itself, so the queue is left as it is.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait HashTagsClusterQueues
{
    /**
     * Get the queue or return the default.
     *
     * @param  mixed  $queue
     * @return string
     */
    #[\Override]
    public function getQueue(mixed $queue): string
    {
        $key = parent::getQueue($queue);

        if (method_exists($this, 'getQueueRedisKey')) {
            return $key;
        }

        $connection = $this->getConnection();

        if (!$connection instanceof ValkeyGlideConnection || !$connection->isCluster() || HashTag::isIn($connection->getPrefix() . $key)) {
            return $key;
        }

        return 'queues:{' . Str::after($key, 'queues:') . '}';
    }
}
