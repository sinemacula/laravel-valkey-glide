<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Queue;

use Laravel\Horizon\RedisQueue;
use SineMacula\Valkey\Queue\Concerns\HashTagsClusterQueues;

/**
 * Horizon's Redis queue, with its keys hash-tagged on a GLIDE cluster
 * connection before Laravel 13.5, as Laravel does from 13.5.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValkeyGlideHorizonQueue extends RedisQueue
{
    use HashTagsClusterQueues;
}
