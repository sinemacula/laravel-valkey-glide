<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Composer\InstalledVersions;
use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\CacheFlushing;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Cache\ValkeyGlideTaggedCache;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Fakes\PhpRedisClientFake;
use Tests\Fakes\RedisFactoryFake;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * GLIDE-aware tagged cache test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideTaggedCache::class)]
final class ValkeyGlideTaggedCacheTest extends TestCase
{
    /**
     * Verify a flush on a standalone server deletes the tags' entries with DEL
     * and ZREM, without a script.
     *
     * @return void
     */
    #[Test]
    public function flushDeletesTheTagsEntriesWithoutAScript(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', ['m' => '1']]]);

        self::assertTrue($this->taggedCache($client, ['t'])->flush());
        self::assertCount(1, $client->callsFor('zscan'));
        self::assertSame([['cache:m']], $client->callsFor('del'));
        self::assertSame([['cache:tag:t:entries', 'm']], $client->callsFor('zrem'));
        self::assertSame([], $client->callsFor('rawcommand'));
        self::assertSame([], $client->callsFor('eval'));
    }

    /**
     * Verify a flush on a cluster deletes the tags' entries the same way,
     * without a script.
     *
     * @return void
     */
    #[Test]
    public function flushDeletesTheTagsEntriesWithoutAScriptOnACluster(): void
    {
        $client = new ValkeyGlideClusterFake;
        $client->willScan('zscan', [['0', ['m' => '1']]]);

        self::assertTrue($this->taggedCache($client, ['t'])->flush());
        self::assertCount(1, $client->callsFor('zscan'));
        self::assertSame([['cache:m']], $client->callsFor('del'));
        self::assertSame([['cache:tag:t:entries', 'm']], $client->callsFor('zrem'));
        self::assertSame([], $client->callsFor('rawcommand'));
        self::assertSame([], $client->callsFor('eval'));
    }

    /**
     * Verify a flush dispatches CacheFlushing, then CacheFlushed, carrying the
     * tags.
     *
     * @return void
     */
    #[Test]
    public function flushDispatchesFlushingThenFlushedEvents(): void
    {
        if (!class_exists(CacheFlushing::class)) {
            self::markTestSkipped('This Laravel version dispatches no cache flush events.');
        }

        $cache  = $this->taggedCache(new ValkeyGlideFake, ['a', 'b']);
        $events = $this->recordFlushEvents($cache);

        self::assertTrue($cache->flush());
        self::assertSame([CacheFlushing::class, CacheFlushed::class], array_map(get_class(...), $events->getArrayCopy()));

        foreach ($events as $event) {
            self::assertNull($event->storeName);
            self::assertSame(['a', 'b'], $event->tags);
        }
    }

    /**
     * Verify any other connection runs Laravel's flush, shown here over a
     * phpredis cluster connection: Laravel scans the tag's sorted set with its
     * phpredis options, deletes the entries, then deletes the whole sorted set,
     * where the GLIDE flush would remove the entries from it with ZREM.
     *
     * @return void
     */
    #[Test]
    public function flushRunsLaravelsOverOtherConnections(): void
    {
        $version = InstalledVersions::getVersion('laravel/framework') ?? '';

        if (version_compare($version, '12.30.0', '>=') && version_compare($version, '12.32.0', '<')) {
            self::markTestSkipped('This Laravel version\'s tagged flush reads the prefix from a phpredis client.');
        }

        $client = new PhpRedisClientFake(['m' => '1']);

        // @phpstan-ignore argument.type
        self::assertTrue($this->taggedCache(new PhpRedisClusterConnection($client), ['t'])->flush());
        self::assertSame([
            ['zscan', ['cache:tag:t:entries', '*', 1000]],
            ['del', ['cache:m']],
            ['del', ['cache:tag:t:entries']],
        ], $client->calls);
    }

    /**
     * Verify a failed reset of the tag set throws, and the flush is not
     * reported as done.
     *
     * @return void
     */
    #[Test]
    public function flushPropagatesTheTagSetFailure(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('zscan', [['0', ['m' => '1']]]);
        $client->willReturn('del', false);

        $cache  = $this->taggedCache($client, ['t']);
        $events = $this->recordFlushEvents($cache);

        try {
            $cache->flush();
            self::fail('A failed flush must throw.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Valkey GLIDE failed to run DEL on [cache:m].', $exception->getMessage());
        }

        self::assertNotContains(CacheFlushed::class, array_map(get_class(...), $events->getArrayCopy()));
    }

    /**
     * Provide the tagged counter calls, with the command each sends and its
     * reply.
     *
     * @return iterable<string, array{\Closure(\SineMacula\Valkey\Cache\ValkeyGlideTaggedCache): (bool|int), string, int}>
     */
    public static function counterMethods(): iterable
    {
        yield 'increment' => [static fn (ValkeyGlideTaggedCache $cache): bool|int => $cache->increment('n'), 'incrby', 1];
        yield 'decrement' => [static fn (ValkeyGlideTaggedCache $cache): bool|int => $cache->decrement('n'), 'decrby', -1];
    }

    /**
     * Verify a tagged increment or decrement records its entry with GLIDE's NX
     * option, so a flush removes it.
     *
     * @param  \Closure(\SineMacula\Valkey\Cache\ValkeyGlideTaggedCache): (bool|int)  $count
     * @param  string  $command
     * @param  int  $reply
     * @return void
     */
    #[DataProvider('counterMethods')]
    #[Test]
    public function counterRecordsItsEntryWithTheNxOption(\Closure $count, string $command, int $reply): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn($command, $reply);

        self::assertSame($reply, $count($this->taggedCache($client, ['t'])));

        $calls = $client->callsFor('zadd');

        self::assertCount(1, $calls);
        self::assertSame('cache:tag:t:entries', $calls[0][0]);
        self::assertSame(['NX'], $calls[0][1]);
        self::assertSame(-1, $calls[0][2]);
    }

    /**
     * Verify flushing stale entries removes the tag's expired entries.
     *
     * @return void
     */
    #[Test]
    public function flushStaleRemovesExpiredEntries(): void
    {
        $client = new ValkeyGlideFake;

        self::assertTrue($this->taggedCache($client, ['t'])->flushStale());
        self::assertCount(1, $client->callsFor('zremrangebyscore'));
        self::assertSame('cache:tag:t:entries', $client->callsFor('zremrangebyscore')[0][0]);
    }

    /**
     * Record every cache flush event the tagged cache dispatches, in order.
     *
     * @param  \SineMacula\Valkey\Cache\ValkeyGlideTaggedCache  $cache
     * @return \ArrayObject<int, \Illuminate\Cache\Events\CacheFlushed|\Illuminate\Cache\Events\CacheFlushing>
     */
    private function recordFlushEvents(ValkeyGlideTaggedCache $cache): \ArrayObject
    {
        /** @var \ArrayObject<int, \Illuminate\Cache\Events\CacheFlushed|\Illuminate\Cache\Events\CacheFlushing> $events */
        $events     = new \ArrayObject;
        $dispatcher = new Dispatcher;

        $dispatcher->listen([CacheFlushing::class, CacheFlushed::class], static function (CacheFlushed|CacheFlushing $event) use ($events): void {
            $events->append($event);
        });

        $cache->setEventDispatcher($dispatcher);

        return $events;
    }

    /**
     * Build a tagged cache for the given tags over the given client or
     * connection, in a store with the `cache:` prefix.
     *
     * @param  \Illuminate\Redis\Connections\Connection|\ValkeyGlide|\ValkeyGlideCluster  $client
     * @param  array<int, string>  $names
     * @return \SineMacula\Valkey\Cache\ValkeyGlideTaggedCache
     */
    private function taggedCache(Connection|\ValkeyGlide|\ValkeyGlideCluster $client, array $names): ValkeyGlideTaggedCache
    {
        $connection = $client instanceof Connection ? $client : new ValkeyGlideConnection($client);

        return (new ValkeyGlideStore(new RedisFactoryFake($connection), 'cache:'))->tags($names);
    }
}
