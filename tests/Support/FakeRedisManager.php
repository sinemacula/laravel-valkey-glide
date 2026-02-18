<?php

declare(strict_types = 1);

namespace Tests\Support;

use Illuminate\Redis\RedisManager;

final class FakeRedisManager extends RedisManager
{
    /** @var array<string, \Closure(): mixed> */
    public array $extensions = [];

    /**
     * Create a fake Redis manager for service provider tests.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new \stdClass, 'phpredis', []);
    }

    /**
     * @param  mixed  $driver
     * @param  mixed  $callback
     * @return $this
     */
    #[\Override]
    public function extend(mixed $driver, mixed $callback)
    {
        if (!$callback instanceof \Closure) {
            throw new \InvalidArgumentException('Redis extension callback must be a Closure instance.');
        }

        if (!is_string($driver) && !is_int($driver) && !$driver instanceof \Stringable) {
            throw new \InvalidArgumentException('Redis extension driver must be a string-compatible value.');
        }

        $driver_name                    = (string) $driver;
        $this->extensions[$driver_name] = $callback;

        return $this;
    }
}
