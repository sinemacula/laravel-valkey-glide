<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Queue;

use Illuminate\Queue\RedisQueue;
use SineMacula\Valkey\Queue\Concerns\HashTagsClusterQueues;

/**
 * Laravel's Redis queue, with its keys hash-tagged on a GLIDE cluster
 * connection before Laravel 13.5, as Laravel does from 13.5.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValkeyGlideQueue extends RedisQueue
{
    use HashTagsClusterQueues;
}
