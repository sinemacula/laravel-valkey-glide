<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

/**
 * No-op Valkey GLIDE client used to isolate connection wrapper overhead.
 *
 * Every command returns immediately so the connection benchmarks measure only
 * the method normalization and key-prefix rewriting, not client or network
 * cost.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class BenchClient extends \ValkeyGlide
{
    /**
     * Return immediately for any dynamically dispatched command.
     *
     * @param  string  $name
     * @param  array<int|string, mixed>  $arguments
     * @return mixed
     */
    #[\Override]
    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }

    /**
     * Return immediately for GET.
     *
     * @param  string  $key
     * @return mixed
     */
    #[\Override]
    public function get(string $key): mixed
    {
        return null;
    }

    /**
     * Return immediately for SET.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  mixed  $options
     * @return bool|self|string
     */
    #[\Override]
    public function set(string $key, mixed $value, mixed $options = null): bool|self|string
    {
        return true;
    }

    /**
     * Return immediately for raw commands.
     *
     * @param  string  $command
     * @param  mixed  ...$args
     * @return mixed
     */
    #[\Override]
    public function rawcommand(string $command, mixed ...$args): mixed
    {
        return null;
    }
}
