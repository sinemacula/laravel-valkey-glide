<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Cache\RedisTaggedCache;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Cache\ValkeyGlideTagSet;
use SineMacula\Valkey\Connections\ClusterRouter;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;
use Tests\Fakes\LaravelConnectionFake;
use Tests\Fakes\RedisFactoryFake;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

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
     * Verify a cluster putMany over keys in different hash slots writes each
     * one directly, never opening a batch that the connection would refuse as a
     * cross-slot transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function putManyNeverOpensABatchOnACluster(): void
    {
        $router = new ClusterRouter;

        self::assertNotSame($router->slot('cache:first'), $router->slot('cache:second'));

        $client = new ValkeyGlideClusterFake;
        $client->willReturn('setex', true);

        self::assertTrue($this->store($client)->putMany(['first' => 'a', 'second' => 'b'], 60));
        self::assertSame(['setex', 'setex'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a standalone connection keeps Laravel's transaction, buffering
     * each prefixed SETEX in one MULTI that a single EXEC sends.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function putManyKeepsTheTransactionOnAStandaloneConnection(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('setex', true);

        self::assertTrue($this->store($client)->putMany(['first' => 'one', 'second' => 2], 60));
        self::assertSame(['multi', 'setex', 'setex', 'exec'], $client->callOrder());
        self::assertSame([['cache:first', 60, serialize('one')], ['cache:second', 60, 2]], $client->callsFor('setex'));
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify a standalone putMany of nothing reports failure, as Laravel's
     * does, without leaving the client stuck in batch mode.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function putManyOfNothingFailsOnAStandaloneConnectionWithoutWedgingTheClient(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('setex', true);

        $store = $this->store($client);

        self::assertFalse($store->putMany([], 60));
        self::assertSame([], $client->callOrder());
        self::assertFalse($client->isBatching());

        self::assertTrue($store->put('after', 'value', 60));
        self::assertSame(['setex'], $client->callOrder());
    }

    /**
     * Verify a standalone putMany still reports success when one write fails
     * inside the transaction, as Laravel's store ignores the results EXEC
     * returns.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function putManyIgnoresAFailedWriteInsideAStandaloneTransaction(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', [true, false]);

        self::assertTrue($this->store($client)->putMany(['first' => 'a', 'second' => 'b'], 60));
        self::assertSame(['multi', 'setex', 'setex', 'exec'], $client->callOrder());
    }

    /**
     * Verify a standalone transaction GLIDE fails as a whole is raised, rather
     * than reported as written.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function putManyThrowsOnAStandaloneConnectionWhenTheTransactionFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('exec', false);

        try {
            $this->store($client)->putMany(['first' => 'a', 'second' => 'b'], 60);

            self::fail('Expected the failed transaction to be raised.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(ConnectionException::class, $exception);
            self::assertStringContainsString('may have been committed', $exception->getMessage());
        }

        self::assertSame(['multi', 'setex', 'setex', 'exec', 'discard'], $client->callOrder());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify tags() gives the GLIDE-aware tagged cache for a list of names.
     *
     * @return void
     */
    #[Test]
    public function tagsReturnsAGlideTaggedCacheForAListOfNames(): void
    {
        $cache = $this->store(new ValkeyGlideFake)->tags(['a', 'b']);

        self::assertInstanceOf(RedisTaggedCache::class, $cache);
        self::assertInstanceOf(ValkeyGlideTagSet::class, $cache->getTags());
        self::assertSame(['a', 'b'], $cache->getTags()->getNames());
    }

    /**
     * Verify tags() accepts the names as separate arguments.
     *
     * @return void
     */
    #[Test]
    public function tagsAcceptsVariadicNames(): void
    {
        self::assertSame(['a', 'b'], $this->store(new ValkeyGlideFake)->tags('a', 'b')->getTags()->getNames());
    }

    /**
     * Verify tags() does not resolve the connection, so no connection is made
     * until a tag operation runs.
     *
     * @return void
     */
    #[Test]
    public function tagsDoesNotResolveTheConnection(): void
    {
        $redis = new class implements Factory {
            /**
             * Fail the test when a connection is resolved.
             *
             * @param  string|\UnitEnum|null  $name
             * @return \Illuminate\Redis\Connections\Connection
             *
             * @throws \LogicException
             */
            #[\Override]
            public function connection($name = null): Connection
            {
                throw new \LogicException('The connection must not be resolved.');
            }
        };

        self::assertSame(['a'], (new ValkeyGlideStore($redis, 'cache:'))->tags(['a'])->getTags()->getNames());
    }

    /**
     * Verify flushStaleTags() scans a standalone server for the tags' sorted
     * sets from cursor '0' until GLIDE writes '0' back, and prunes each one.
     *
     * @return void
     */
    #[Test]
    public function flushStaleTagsScansAStandaloneServerForEveryTagSet(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [
            ['17', ['conn:cache:tag:a:entries', 'conn:cache:tag:b:entries']],
            ['0', ['conn:cache:tag:c:entries']],
        ]);

        $this->store($client, 'conn:')->flushStaleTags();

        self::assertSame([
            ['0', 'conn:cache:tag:*:entries', 1000, 'zset'],
            ['17', 'conn:cache:tag:*:entries', 1000, 'zset'],
        ], $client->callsFor('scan'));
        self::assertSame(
            ['conn:cache:tag:a:entries', 'conn:cache:tag:b:entries', 'conn:cache:tag:c:entries'],
            array_column($client->callsFor('zremrangebyscore'), 0),
        );
    }

    /**
     * Provide each topology with each kind of batch.
     *
     * @return iterable<string, array{bool, bool}>
     */
    public static function batchesOnEachTopology(): iterable
    {
        yield 'standalone pipeline' => [false, false];
        yield 'standalone transaction' => [false, true];
        yield 'cluster pipeline' => [true, false];
        yield 'cluster transaction' => [true, true];
    }

    /**
     * Verify flushStaleTags() inside a pipeline or transaction on the store's
     * connection is refused before any SCAN reaches the client, on a cluster
     * too, where GLIDE would run the SCAN at once, outside the batch.
     *
     * @param  bool  $cluster
     * @param  bool  $atomic
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('batchesOnEachTopology')]
    #[Test]
    public function flushStaleTagsInsideABatchIsRefusedBeforeScanning(bool $cluster, bool $atomic): void
    {
        $client = $cluster ? new ValkeyGlideClusterFake : new ValkeyGlideFake;
        $client->willScan('scan', [['0', ['cache:tag:a:entries']]]);

        $store      = $this->store($client);
        $connection = $store->connection();

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);

        $flush = static function () use ($store): void {
            $store->flushStaleTags();
        };

        try {
            $atomic ? $connection->transaction($flush) : $connection->pipeline($flush);

            self::fail('Expected the SCAN inside the batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringStartsWith('[SCAN] cannot be used inside a pipeline or transaction, as GLIDE would', $exception->getMessage());
        }

        self::assertSame([], $client->callsFor('scan'));
        self::assertSame([], $client->callsFor('zremrangebyscore'));
        self::assertFalse($connection->hasOpenBatch());
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify the glob characters of both prefixes are escaped in the SCAN
     * pattern, while the tag name is still read from the key.
     *
     * @return void
     */
    #[Test]
    public function flushStaleTagsEscapesGlobCharactersInThePrefixes(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [['0', ['a*b?[c]\x:tag:t:entries']]]);

        $this->store($client, 'a*b?[c]\\', 'x:')->flushStaleTags();

        self::assertSame([['0', 'a\*b\?\[c\]\\\x:tag:*:entries', 1000, 'zset']], $client->callsFor('scan'));
        self::assertSame(['a*b?[c]\x:tag:t:entries'], array_column($client->callsFor('zremrangebyscore'), 0));
    }

    /**
     * Verify the SCAN pattern is the bare tag pattern when neither prefix is
     * set.
     *
     * @return void
     */
    #[Test]
    public function flushStaleTagsWithoutAnyPrefix(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [['0', ['tag:t:entries']]]);

        $this->store($client, '', '')->flushStaleTags();

        self::assertSame([['0', 'tag:*:entries', 1000, 'zset']], $client->callsFor('scan'));
        self::assertSame(['tag:t:entries'], array_column($client->callsFor('zremrangebyscore'), 0));
    }

    /**
     * Verify a failed standalone scan throws at once.
     *
     * @return void
     */
    #[Test]
    public function flushStaleTagsThrowsWhenAStandaloneScanFails(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [['0', false]]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected array response from scan on [conn:cache:tag:*:entries], received [bool].');

        try {
            $this->store($client, 'conn:')->flushStaleTags();
        } finally {
            self::assertCount(1, $client->callsFor('scan'));
        }
    }

    /**
     * Verify the chunk size is the COUNT hint of each scan, defaulting to a
     * thousand when it is not a number.
     *
     * @return void
     */
    #[Test]
    public function currentTagsUsesTheChunkSizeAsTheScanCount(): void
    {
        $client      = new ValkeyGlideFake;
        $store       = $this->store($client, 'conn:');
        $currentTags = new \ReflectionMethod($store, 'currentTags');
        $sizedTags   = $currentTags->invoke($store, '500');
        $defaultTags = $currentTags->invoke($store, 'x');

        self::assertInstanceOf(LazyCollection::class, $sizedTags);
        self::assertInstanceOf(LazyCollection::class, $defaultTags);

        $sizedTags->all();
        $defaultTags->all();

        self::assertSame([500, 1000], array_column($client->callsFor('scan'), 2));
    }

    /**
     * Verify any other connection runs Laravel's scan and prune.
     *
     * @return void
     */
    #[Test]
    public function flushStaleTagsRunsLaravelsOverOtherConnections(): void
    {
        $connection = new LaravelConnectionFake;
        $connection->willReturn('scan', ['0', ['cache:tag:a:entries']]);

        (new ValkeyGlideStore(new RedisFactoryFake($connection), 'cache:'))->flushStaleTags();

        self::assertSame(['scan', ['0', ['match' => 'cache:tag:*:entries', 'count' => 1000]]], $connection->commands[0]);
        self::assertSame('pipeline', $connection->commands[1][0]);
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
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return \SineMacula\Valkey\Cache\ValkeyGlideStore
     */
    private function store(\ValkeyGlide|\ValkeyGlideCluster $client, string $connectionPrefix = '', string $cachePrefix = 'cache:'): ValkeyGlideStore
    {
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => $connectionPrefix]);

        return new ValkeyGlideStore(new RedisFactoryFake($connection), $cachePrefix);
    }
}
