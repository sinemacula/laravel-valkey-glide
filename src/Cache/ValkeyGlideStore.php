<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Cache;

use Illuminate\Cache\RedisStore;

/**
 * Laravel's Redis cache store, made safe for GLIDE cluster connections.
 *
 * `RedisStore::putMany()` writes through MULTI/EXEC unless its connection is
 * one of Laravel's own cluster connections. A GLIDE cluster connection is not,
 * so keys hashing to different slots fail the transaction with `CROSSSLOT`
 * while `putMany()` still reports success. On a cluster each value is written
 * on its own instead, as Laravel does for its cluster connections.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValkeyGlideStore extends RedisStore
{
    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param  array<array-key, mixed>  $values
     * @param  int  $seconds
     * @return bool
     */
    #[\Override]
    public function putMany(array $values, mixed $seconds)
    {
        if (!$this->connection()->client() instanceof \ValkeyGlideCluster) {
            return parent::putMany($values, $seconds);
        }

        $manyResult = null;

        foreach ($values as $key => $value) {
            $result = $this->put((string) $key, $value, $seconds);

            $manyResult = $manyResult === null ? $result : $result && $manyResult;
        }

        return $manyResult ?: false;
    }
}
