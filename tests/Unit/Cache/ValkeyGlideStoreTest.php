<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;

/**
 * GLIDE-aware cache store test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideStore::class)]
final class ValkeyGlideStoreTest extends TestCase
{
    /**
     * Verify a cluster connection writes each value with its own SETEX, outside
     * any transaction.
     *
     * @return void
     */
    #[Test]
    public function putManyWritesEachValueOnItsOwnOnACluster(): void
    {
        $client = $this->clusterClient([true, true]);

        self::assertTrue($this->store($client)->putMany(['first' => 'one', 'second' => 2], 60));
        self::assertSame([
            ['setex', ['cache:first', 60, serialize('one')]],
            ['setex', ['cache:second', 60, 2]],
        ], $client->calls);
    }

    /**
     * Verify a cluster putMany fails when any one of its writes fails.
     *
     * @return void
     */
    #[Test]
    public function putManyFailsOnAClusterWhenAnyWriteFails(): void
    {
        $client = $this->clusterClient([true, false, true]);

        self::assertFalse($this->store($client)->putMany(['first' => 'a', 'second' => 'b', 'third' => 'c'], 60));
        self::assertCount(3, $client->calls);
    }

    /**
     * Verify a cluster putMany fails when its first write fails, even though
     * the later ones succeed.
     *
     * @return void
     */
    #[Test]
    public function putManyFailsOnAClusterWhenTheFirstWriteFails(): void
    {
        self::assertFalse($this->store($this->clusterClient([false, true]))->putMany(['first' => 'a', 'second' => 'b'], 60));
    }

    /**
     * Verify a cluster putMany of nothing reports failure, as Laravel's does.
     *
     * @return void
     */
    #[Test]
    public function putManyOfNothingFailsOnACluster(): void
    {
        $client = $this->clusterClient([]);

        self::assertFalse($this->store($client)->putMany([], 60));
        self::assertSame([], $client->calls);
    }

    /**
     * Verify a standalone connection keeps Laravel's transaction.
     *
     * @return void
     */
    #[Test]
    public function putManyKeepsTheTransactionOnAStandaloneConnection(): void
    {
        $client = new class extends \ValkeyGlide {
            /** @var list<string> The commands received, in order. */
            public array $commands = [];

            /**
             * Record each command and answer it.
             *
             * @param  string  $name
             * @param  array<int|string, mixed>  $arguments
             * @return mixed
             */
            #[\Override]
            public function __call(string $name, array $arguments): mixed
            {
                $this->commands[] = $name;

                return true;
            }
        };

        self::assertTrue($this->store($client)->putMany(['first' => 'a', 'second' => 'b'], 60));
        self::assertSame(['multi', 'setex', 'setex', 'exec'], $client->commands);
    }

    /**
     * Build a cluster client answering each SETEX with the given replies, in
     * turn.
     *
     * @param  list<bool>  $replies
     * @return object{calls: list<array{string, array<int|string, mixed>}>}&\ValkeyGlideCluster
     */
    private function clusterClient(array $replies): \ValkeyGlideCluster
    {
        $client = new class extends \ValkeyGlideCluster {
            /** @var list<array{string, array<int|string, mixed>}> The commands received, in order. */
            public array $calls = [];

            /** @var list<bool> The replies to give each SETEX, in turn. */
            public array $replies = [];

            /**
             * Record each command and answer it with the next reply.
             *
             * @param  string  $name
             * @param  array<int|string, mixed>  $arguments
             * @return mixed
             */
            #[\Override]
            public function __call(string $name, array $arguments): mixed
            {
                $this->calls[] = [$name, $arguments];

                return array_shift($this->replies);
            }
        };

        $client->replies = $replies;

        return $client;
    }

    /**
     * Build a store over a connection to the given client.
     *
     * @param  \ValkeyGlide|\ValkeyGlideCluster  $client
     * @return \SineMacula\Valkey\Cache\ValkeyGlideStore
     */
    private function store(\ValkeyGlide|\ValkeyGlideCluster $client): ValkeyGlideStore
    {
        $redis = new readonly class (new ValkeyGlideConnection($client)) implements Factory {
            /**
             * Create a factory answering with the given connection.
             *
             * @param  \Illuminate\Redis\Connections\Connection  $connection
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
        };

        return new ValkeyGlideStore($redis, 'cache:');
    }
}
