<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Cache;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\RedisTaggedCache;
use Illuminate\Cache\TaggedCache;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;

/**
 * Laravel's Redis tagged cache, with a tag flush that works on GLIDE
 * connections.
 *
 * Laravel's `RedisTaggedCache::flush()` reads the connection prefix from the
 * phpredis or Predis client, and fails for any other connection, then deletes
 * the entries with a Lua script that cannot run across the hash slots of a
 * cluster. Over a GLIDE connection the flush is left to the tag set instead,
 * which runs a script of its own on a standalone server and none on a
 * cluster; over any other connection Laravel's own flush runs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValkeyGlideTaggedCache extends RedisTaggedCache
{
    /**
     * Create a new tagged cache over the given store and GLIDE-aware tag set.
     *
     * The tag set is narrowed to the GLIDE-aware one, whose reset() the flush
     * relies on, and the store is kept as a Redis store to read its connection,
     * so the constructor is not a plain pass-through.
     *
     * @param  \Illuminate\Cache\RedisStore  $redisStore
     * @param  \SineMacula\Valkey\Cache\ValkeyGlideTagSet  $tags
     * @return void
     *
     * @SuppressWarnings("php:S1185")
     */
    public function __construct(

        /** The Redis store the tagged cache writes through. */
        private readonly RedisStore $redisStore,

        // The GLIDE-aware tag set the flush resets.
        ValkeyGlideTagSet $tags,
    ) {
        parent::__construct($redisStore, $tags);
    }

    /**
     * Remove all items from the cache.
     *
     * Over a GLIDE connection this runs TaggedCache::flush(), which dispatches
     * CacheFlushing and CacheFlushed exactly when the running Laravel version
     * does and resets the tag set in between; ValkeyGlideTagSet::reset() then
     * deletes the tags' entries, in one script per chunk on a standalone server
     * and without a cross-slot command on a cluster.
     * Any other connection runs Laravel's RedisTaggedCache::flush().
     *
     * @return bool
     *
     * @throws \UnexpectedValueException
     *
     * @phpstan-ignore throws.unusedType
     */
    #[\Override]
    public function flush(): bool
    {
        if (!$this->redisStore->connection() instanceof ValkeyGlideConnection) {
            return parent::flush();
        }

        return TaggedCache::flush();
    }
}
