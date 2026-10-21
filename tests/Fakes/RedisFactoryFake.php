<?php

declare(strict_types = 1);

namespace Tests\Fakes;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;

/**
 * Redis factory answering every connection name with one connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class RedisFactoryFake implements Factory
{
    /**
     * Create a factory answering with the given connection.
     *
     * @param  \Illuminate\Redis\Connections\Connection  $connection
     * @return void
     */
    public function __construct(

        /** The connection given for every name. */
        private Connection $connection,
    ) {}

    /**
     * Return the connection, whatever its name.
     *
     * @param  string|\UnitEnum|null  $name
     * @return \Illuminate\Redis\Connections\Connection
     */
    #[\Override]
    public function connection($name = null): Connection
    {
        return $this->connection;
    }
}
