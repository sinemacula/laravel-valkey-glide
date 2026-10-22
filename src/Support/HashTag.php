<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Support;

/**
 * Redis Cluster hash tags.
 *
 * A cluster hashes a key by the text between its first `{` and the next `}`,
 * when that text is not empty, so keys that share a hash tag share a slot.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class HashTag
{
    /**
     * Determine if the key contains a hash tag, as Laravel 13.5's
     * `Connection::hasHashTag()` does.
     *
     * @param  string  $key
     * @return bool
     */
    public static function isIn(string $key): bool
    {
        return preg_match('/^[^{]*\{[^}]+\}/', $key) === 1;
    }
}
