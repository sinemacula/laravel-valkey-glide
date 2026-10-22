<?php

declare(strict_types = 1);

namespace Tests\Fakes;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;

/**
 * Redis factory that hands out one connection, recording the names it is asked
 * for.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class RecordingRedisFactoryFake implements Factory
{
    /** @var list<mixed> The connection names asked for, in order. */
    public array $names = [];

    /**
     * Create a factory over the connection.
     *
     * @param  \Illuminate\Redis\Connections\Connection  $connection
     * @return void
     */
    public function __construct(

        /** The connection handed out for every name. */
        private readonly Connection $connection,
    ) {}

    /**
     * Record the name and hand out the connection.
     *
     * @param  mixed  $name
     * @return \Illuminate\Redis\Connections\Connection
     */
    #[\Override]
    public function connection(mixed $name = null): Connection
    {
        $this->names[] = $name;

        return $this->connection;
    }
}
