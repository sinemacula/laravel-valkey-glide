<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\CacheFlushing;
use Illuminate\Cache\RedisTaggedCache;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Cache\ValkeyGlideTaggedCache;
use SineMacula\Valkey\Cache\ValkeyGlideTagSet;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\CommandException;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\TestCase;

/**
 * External checks for cache tags through Laravel's redis cache driver: tagged
 * writes, flush(), flushStale() and cache:prune-stale-tags, with and without
 * the connection and cache prefixes.
 *
 * Every test runs against the standalone server and, when
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT are set,
 * against the cluster; the cluster cases are skipped otherwise. Physical keys
 * are read through an unprefixed connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideStore::class)]
#[CoversClass(ValkeyGlideTaggedCache::class)]
#[CoversClass(ValkeyGlideTagSet::class)]
#[Group('external')]
final class ValkeyGlideLaravelTaggedCacheExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var array<string, string> The connection prefixes, by label. */
    private const array CONNECTION_PREFIXES = [
        'prefixed' => 'lvkglide:tags:conn:',
        'bare'     => '',
    ];

    /** @var array<string, string> The cache prefixes, by label. */
    private const array CACHE_PREFIXES = [
        'cached' => 'lvkglide:tags:cache:',
        'bare'   => '',
    ];

    /** @var int The number of keys deleted by each cleanup DEL. */
    private const int CLEANUP_CHUNK_SIZE = 1000;

    /** @var array<string, \SineMacula\Valkey\Connections\ValkeyGlideConnection> Unprefixed connections, by topology. */
    private array $physicalConnections = [];

    /** @var array<string, array<string, true>> Physical keys to delete after the test, by topology. */
    private array $trackedKeys = [];

    /**
     * Ensure extension preconditions are met before each external test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertExtensionIsLoaded();
    }

    /**
     * Delete the physical keys the test wrote, restore the real clock and close
     * the unprefixed connections.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->trackedKeys as $topology => $keys) {
            foreach (array_chunk(array_keys($keys), self::CLEANUP_CHUNK_SIZE) as $chunk) {
                $this->physical($topology)->command('del', $chunk);
            }
        }

        Carbon::setTestNow();

        foreach ($this->physicalConnections as $connection) {
            $connection->disconnect();
        }

        $this->trackedKeys         = [];
        $this->physicalConnections = [];

        parent::tearDown();
    }

    /**
     * Provide every topology with each combination of connection and cache
     * prefix.
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function scenarios(): iterable
    {
        $labels = [
            'prefixed-cached' => 'connection and cache prefix',
            'prefixed-bare'   => 'connection prefix only',
            'bare-cached'     => 'cache prefix only',
            'bare-bare'       => 'no prefix',
        ];

        foreach (self::topologies() as [$topology]) {
            foreach (self::CONNECTION_PREFIXES as $connectionLabel => $connectionPrefix) {
                foreach (self::CACHE_PREFIXES as $cacheLabel => $cachePrefix) {
                    $name = $connectionLabel . '-' . $cacheLabel;

                    yield $topology . ', ' . $labels[$name] => [$topology, $topology . '-' . $name, $connectionPrefix, $cachePrefix];
                }
            }
        }
    }

    /**
     * Verify tagged writes record their entries in the tag's sorted set under
     * the physical, fully prefixed key, with the expected scores.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('scenarios')]
    #[Test]
    public function taggedWritesRecordEntriesUnderThePhysicalKeys(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $tag    = $this->uniqueName('tag');
        $cache  = $this->tagged($store, [$tag]);
        $setKey = $this->track($topology, $connectionPrefix . $cachePrefix . 'tag:' . $tag . ':entries')[0];
        $keys   = ['put' => 'p', 'forever' => 'f', 'add' => 'a', 'increment' => 'n', 'decrement' => 'd'];
        $now    = time();

        self::assertTrue($cache->put($keys['put'], 'v', 600));
        self::assertTrue($cache->forever($keys['forever'], 'v'));
        self::assertTrue($cache->add($keys['add'], 'v', 600));
        self::assertSame(1, $cache->increment($keys['increment']));
        self::assertSame(-1, $cache->decrement($keys['decrement']));

        $physical = $this->physical($topology);

        self::assertSame(-1.0, $this->score($topology, $setKey, $cache->taggedItemKey($keys['increment'])));
        self::assertSame(2, $cache->increment($keys['increment']));
        self::assertSame(-1.0, $this->score($topology, $setKey, $cache->taggedItemKey($keys['increment'])));
        self::assertSame(-1.0, $this->score($topology, $setKey, $cache->taggedItemKey($keys['decrement'])));
        self::assertSame(-1.0, $this->score($topology, $setKey, $cache->taggedItemKey($keys['forever'])));
        self::assertEqualsWithDelta($now + 600, $this->score($topology, $setKey, $cache->taggedItemKey($keys['put'])), 2);
        self::assertEqualsWithDelta($now + 600, $this->score($topology, $setKey, $cache->taggedItemKey($keys['add'])), 2);
        self::assertSame(5, $physical->command('zcard', [$setKey]));

        if ($connectionPrefix !== '') {
            self::assertSame(0, $physical->command('exists', [$cachePrefix . 'tag:' . $tag . ':entries']));
        }

        foreach ($keys as $key) {
            $valueKey = $this->track($topology, $connectionPrefix . $cachePrefix . $cache->taggedItemKey($key))[0];

            self::assertSame(1, $physical->command('exists', [$valueKey]));
        }
    }

    /**
     * Verify a tagged increment or decrement of a counter stored with a TTL
     * keeps the entry's expiry score: the NX condition only adds a missing
     * entry, where a plain ZADD would overwrite the score with -1.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('scenarios')]
    #[Test]
    public function taggedCountersKeepTheScoreOfAnExistingEntry(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $tag    = $this->uniqueName('nx');
        $prefix = $connectionPrefix . $cachePrefix;
        $cache  = $this->tagged($store, [$tag]);
        $member = $cache->taggedItemKey('n');
        $setKey = $this->track($topology, $prefix . 'tag:' . $tag . ':entries', $prefix . $member)[0];
        $now    = time();

        self::assertTrue($cache->put('n', 1, 600));

        $score = $this->score($topology, $setKey, $member);

        self::assertEqualsWithDelta($now + 600, $score, 2);
        self::assertSame(2, $cache->increment('n'));
        self::assertSame($score, $this->score($topology, $setKey, $member));
        self::assertSame(1, $cache->decrement('n'));
        self::assertSame($score, $this->score($topology, $setKey, $member));
    }

    /**
     * Verify a flush deletes the entries of its tag, entries shared with other
     * tags included, and nothing else.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('scenarios')]
    #[Test]
    public function flushRemovesTheTagsEntriesAndNothingElse(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        [$tagA, $tagB, $tagC] = [$this->uniqueName('a'), $this->uniqueName('b'), $this->uniqueName('c')];

        $prefix     = $connectionPrefix . $cachePrefix;
        $repository = $this->repository($store);
        $plain      = $this->uniqueName('plain');
        $items      = [
            'x' => $this->tagged($store, [$tagA]),
            'y' => $this->tagged($store, [$tagA, $tagB]),
            'z' => $this->tagged($store, [$tagB]),
            'w' => $this->tagged($store, [$tagC]),
        ];

        foreach ($items as $key => $cache) {
            self::assertTrue($cache->put($key, 'value-' . $key, 600));
            $this->track($topology, $prefix . $cache->taggedItemKey($key));
        }

        self::assertTrue($repository->put($plain, 'plain-value', 600));
        $this->track($topology, $prefix . $plain, ...$this->tagSetKeys($prefix, [$tagA, $tagB, $tagC]));

        self::assertTrue($this->tagged($store, [$tagA])->flush());

        $physical = $this->physical($topology);

        foreach (['x', 'y'] as $key) {
            self::assertNull($items[$key]->get($key));
            self::assertSame(0, $physical->command('exists', [$prefix . $items[$key]->taggedItemKey($key)]));
        }

        foreach (['z', 'w'] as $key) {
            self::assertSame('value-' . $key, $items[$key]->get($key));
        }

        self::assertSame('plain-value', $repository->get($plain));
        self::assertSame(0, $physical->command('exists', [$prefix . 'tag:' . $tagA . ':entries']));
        self::assertSame(1, $physical->command('exists', [$prefix . 'tag:' . $tagB . ':entries']));
        self::assertSame(1, $physical->command('exists', [$prefix . 'tag:' . $tagC . ':entries']));
    }

    /**
     * Verify a flush of several tags deletes the entries of each, and leaves
     * none of their sorted sets behind.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('scenarios')]
    #[Test]
    public function flushOfSeveralTagsRemovesTheEntriesOfEach(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        [$tagA, $tagB] = [$this->uniqueName('a'), $this->uniqueName('b')];

        $prefix = $connectionPrefix . $cachePrefix;
        $items  = [
            'only-a' => $this->tagged($store, [$tagA]),
            'only-b' => $this->tagged($store, [$tagB]),
            'both'   => $this->tagged($store, [$tagA, $tagB]),
        ];

        foreach ($items as $key => $cache) {
            self::assertTrue($cache->put($key, 'v', 600));
            $this->track($topology, $prefix . $cache->taggedItemKey($key));
        }

        $this->track($topology, ...$this->tagSetKeys($prefix, [$tagA, $tagB]));

        self::assertTrue($this->tagged($store, [$tagA, $tagB])->flush());

        $physical = $this->physical($topology);

        foreach ($items as $key => $cache) {
            self::assertSame(0, $physical->command('exists', [$prefix . $cache->taggedItemKey($key)]));
        }

        foreach ($this->tagSetKeys($prefix, [$tagA, $tagB]) as $setKey) {
            self::assertSame(0, $physical->command('exists', [$setKey]));
        }
    }

    /**
     * Provide every topology with both prefixes set.
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function prefixedTopologies(): iterable
    {
        foreach (self::topologies() as $label => [$topology]) {
            yield $label => [$topology, $topology . '-prefixed-cached', self::CONNECTION_PREFIXES['prefixed'], self::CACHE_PREFIXES['cached']];
        }
    }

    /**
     * Verify a flush of more entries than one DEL takes deletes them all, over
     * keys spread across the hash slots.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('prefixedTopologies')]
    #[Test]
    public function flushDeletesEntriesInSeveralBatchesAcrossSlots(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $tag       = $this->uniqueName('bulk');
        $prefix    = $connectionPrefix . $cachePrefix;
        $cache     = $this->tagged($store, [$tag]);
        $valueKeys = [];

        $this->track($topology, $prefix . 'tag:' . $tag . ':entries');

        foreach (range(1, 2100) as $index) {
            $key = 'item-' . $index;

            $cache->put($key, 'v', 600);

            $valueKeys[] = $prefix . $cache->taggedItemKey($key);
        }

        $this->track($topology, ...$valueKeys);

        self::assertSame(2100, $this->countExisting($topology, $valueKeys));
        self::assertTrue($this->tagged($store, [$tag])->flush());
        self::assertSame(0, $this->countExisting($topology, $valueKeys));
        self::assertSame(0, $this->physical($topology)->command('exists', [$prefix . 'tag:' . $tag . ':entries']));
    }

    /**
     * Verify the tag set yields every recorded entry once, across several scan
     * pages.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('prefixedTopologies')]
    #[Test]
    public function entriesYieldsEveryRecordedEntry(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $tag      = $this->uniqueName('scan');
        $prefix   = $connectionPrefix . $cachePrefix;
        $cache    = $this->tagged($store, [$tag]);
        $expected = [];

        $this->track($topology, $prefix . 'tag:' . $tag . ':entries');

        foreach (range(1, 2500) as $index) {
            $key = 'item-' . $index;

            $cache->put($key, 'v', 600);

            $expected[] = $cache->taggedItemKey($key);
        }

        $this->track($topology, ...array_map(static fn (string $member): string => $prefix . $member, $expected));

        $tagSet = $cache->getTags();

        self::assertInstanceOf(ValkeyGlideTagSet::class, $tagSet);

        $entries = $tagSet->entries()->values()->all();

        sort($entries);
        sort($expected);

        self::assertSame($expected, $entries);
    }

    /**
     * Verify a tagged increment and decrement are recorded, so a flush deletes
     * them.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('scenarios')]
    #[Test]
    public function taggedCountersAreRemovedByFlush(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $tag       = $this->uniqueName('counter');
        $prefix    = $connectionPrefix . $cachePrefix;
        $cache     = $this->tagged($store, [$tag]);
        $valueKeys = $this->track($topology, $prefix . $cache->taggedItemKey('hits'), $prefix . $cache->taggedItemKey('misses'));

        $this->track($topology, $prefix . 'tag:' . $tag . ':entries');

        self::assertSame(1, $cache->increment('hits'));
        self::assertSame(-1, $cache->decrement('misses'));
        self::assertSame(2, $this->countExisting($topology, $valueKeys));
        self::assertTrue($this->tagged($store, [$tag])->flush());
        self::assertSame(0, $this->countExisting($topology, $valueKeys));
    }

    /**
     * Verify flushStale() removes only the expired entries, keeping those
     * stored forever.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('scenarios')]
    #[Test]
    public function flushStaleRemovesOnlyExpiredEntries(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $tag    = $this->uniqueName('stale');
        $prefix = $connectionPrefix . $cachePrefix;
        $cache  = $this->tagged($store, [$tag]);
        $setKey = $this->track($topology, $prefix . 'tag:' . $tag . ':entries')[0];

        self::assertTrue($cache->put('stale', 'v', 60));
        self::assertTrue($cache->forever('forever', 'v'));
        self::assertTrue($cache->put('fresh', 'v', 3600));

        $this->track($topology, ...array_map(static fn (string $key): string => $prefix . $cache->taggedItemKey($key), ['stale', 'forever', 'fresh']));

        Carbon::setTestNow(Carbon::now()->addSeconds(120));

        self::assertTrue($this->tagged($store, [$tag])->flushStale());

        $physical = $this->physical($topology);

        self::assertSame(2, $physical->command('zcard', [$setKey]));
        self::assertFalse($physical->command('zscore', [$setKey, $cache->taggedItemKey('stale')]));
        self::assertSame(-1.0, $this->score($topology, $setKey, $cache->taggedItemKey('forever')));
        self::assertGreaterThan(Carbon::now()->getTimestamp(), $this->score($topology, $setKey, $cache->taggedItemKey('fresh')));
    }

    /**
     * Verify cache:prune-stale-tags finds every tag's sorted set on the server,
     * on every primary of a cluster, and prunes each one.
     *
     * Ninety tags spread over every primary with near certainty, so a scan of
     * one node alone would miss most of them.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('scenarios')]
    #[Test]
    public function pruneStaleTagsCommandPrunesEveryTagSetOnTheServer(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $prefix = $connectionPrefix . $cachePrefix;
        $tags   = array_map(fn (int $index): string => $this->uniqueName('prune-' . $index), range(1, 90));

        foreach ($tags as $tag) {
            $cache = $this->tagged($store, [$tag]);

            self::assertTrue($cache->put('stale', 'v', 60));
            self::assertTrue($cache->put('fresh', 'v', 3600));

            $this->track($topology, $prefix . 'tag:' . $tag . ':entries', $prefix . $cache->taggedItemKey('stale'), $prefix . $cache->taggedItemKey('fresh'));
        }

        Carbon::setTestNow(Carbon::now()->addSeconds(120));

        $command = $this->artisan('cache:prune-stale-tags', ['store' => $store]);

        self::assertInstanceOf(PendingCommand::class, $command);

        $command->assertSuccessful()->run();

        $physical = $this->physical($topology);

        foreach ($tags as $tag) {
            $cache  = $this->tagged($store, [$tag]);
            $setKey = $prefix . 'tag:' . $tag . ':entries';

            self::assertFalse($physical->command('zscore', [$setKey, $cache->taggedItemKey('stale')]), $tag);
            self::assertSame(1, $physical->command('zcard', [$setKey]), $tag);
        }
    }

    /**
     * Verify flushStaleTags() prunes the store's own tag sets and leaves alone
     * those of a store with the other cache prefix on the same connection, and
     * of a store with the same cache prefix on the connection with the other
     * prefix.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('scenarios')]
    #[Test]
    public function flushStaleTagsLeavesOtherPrefixesAlone(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $connectionLabel = $connectionPrefix === '' ? 'bare' : 'prefixed';
        $cacheLabel      = $cachePrefix      === '' ? 'bare' : 'cached';
        $otherConnection = $connectionLabel  === 'bare' ? 'prefixed' : 'bare';
        $otherCache      = $cacheLabel       === 'bare' ? 'cached' : 'bare';
        $tag             = $this->uniqueName('sibling');
        $setKeys         = [];

        $stores = [
            $store                                                 => $connectionPrefix . $cachePrefix,
            $topology . '-' . $connectionLabel . '-' . $otherCache => $connectionPrefix . self::CACHE_PREFIXES[$otherCache],
            $topology . '-' . $otherConnection . '-' . $cacheLabel => self::CONNECTION_PREFIXES[$otherConnection] . $cachePrefix,
        ];

        foreach ($stores as $name => $prefix) {
            $cache = $this->tagged($name, [$tag]);

            self::assertTrue($cache->put('stale', 'v', 60));

            $setKeys[$name] = $this->track($topology, $prefix . 'tag:' . $tag . ':entries', $prefix . $cache->taggedItemKey('stale'))[0];
        }

        Carbon::setTestNow(Carbon::now()->addSeconds(120));

        $repositoryStore = $this->repository($store)->getStore();

        self::assertInstanceOf(ValkeyGlideStore::class, $repositoryStore);

        $repositoryStore->flushStaleTags();

        $physical = $this->physical($topology);

        foreach ($setKeys as $name => $setKey) {
            self::assertSame($name === $store ? 0 : 1, $physical->command('zcard', [$setKey]), $name);
        }
    }

    /**
     * Verify the tag upkeep throws, naming the command and key, when a tag's
     * key holds a value that is not a sorted set: GLIDE answers the WRONGTYPE
     * error with false.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('scenarios')]
    #[Test]
    public function tagUpkeepThrowsWhenATagKeyIsNotASortedSet(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $tag    = $this->uniqueName('wrongtype');
        $tagKey = $cachePrefix . 'tag:' . $tag . ':entries';
        $cache  = $this->tagged($store, [$tag]);
        $setKey = $this->track($topology, $connectionPrefix . $tagKey, $connectionPrefix . $cachePrefix . $cache->taggedItemKey('item'))[0];

        $physical = $this->physical($topology);

        $physical->command('set', [$setKey, 'not-a-sorted-set']);

        self::assertThrowsUnexpectedValue(sprintf('Expected array response from zscan on [%s], received [bool].', $tagKey), fn (): bool => $this->tagged($store, [$tag])->flush());
        self::assertThrowsUnexpectedValue(sprintf('Valkey GLIDE failed to run ZREMRANGEBYSCORE on [%s].', $tagKey), fn (): bool => $this->tagged($store, [$tag])->flushStale());
        self::assertThrowsUnexpectedValue(sprintf('Valkey GLIDE failed to run ZADD on [%s].', $tagKey), static fn (): bool => $cache->put('item', 'v', 600));
        self::assertSame('not-a-sorted-set', $physical->command('get', [$setKey]));
    }

    /**
     * Verify a flush dispatches CacheFlushing, then CacheFlushed, carrying the
     * store's name and the tags.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('prefixedTopologies')]
    #[Test]
    public function flushDispatchesCacheFlushingThenCacheFlushed(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        if (!class_exists(CacheFlushing::class)) {
            self::markTestSkipped('This Laravel version dispatches no cache flush events.');
        }

        $tag = $this->uniqueName('events');

        /** @var \ArrayObject<int, \Illuminate\Cache\Events\CacheFlushed|\Illuminate\Cache\Events\CacheFlushing> $events */
        $events = new \ArrayObject;

        Event::listen([CacheFlushing::class, CacheFlushed::class], static function (CacheFlushed|CacheFlushing $event) use ($events): void {
            $events->append($event);
        });

        $cache = $this->tagged($store, [$tag]);

        self::assertTrue($cache->put('item', 'v', 600));

        $this->track($topology, $connectionPrefix . $cachePrefix . 'tag:' . $tag . ':entries', $connectionPrefix . $cachePrefix . $cache->taggedItemKey('item'));

        self::assertTrue($this->tagged($store, [$tag])->flush());
        self::assertSame([CacheFlushing::class, CacheFlushed::class], array_map(get_class(...), $events->getArrayCopy()));

        foreach ($events as $event) {
            self::assertSame($store, $event->storeName);
            self::assertSame([$tag], $event->tags);
        }
    }

    /**
     * Verify a flush of a tag without entries succeeds and writes nothing.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('prefixedTopologies')]
    #[Test]
    public function flushOfATagWithoutEntriesSucceeds(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $tag    = $this->uniqueName('empty');
        $setKey = $this->track($topology, $connectionPrefix . $cachePrefix . 'tag:' . $tag . ':entries')[0];

        self::assertTrue($this->tagged($store, [$tag])->flush());
        self::assertSame(0, $this->physical($topology)->command('exists', [$setKey]));
    }

    /**
     * Verify the redis cache driver's tags are the GLIDE-aware tagged cache,
     * which is still Laravel's Redis tagged cache, over the configured
     * prefixes.
     *
     * @param  string  $topology
     * @param  string  $store
     * @param  string  $connectionPrefix
     * @param  string  $cachePrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('prefixedTopologies')]
    #[Test]
    public function tagsReturnsTheGlideTaggedCache(string $topology, string $store, string $connectionPrefix, string $cachePrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $cache      = $this->repository($store)->tags([$this->uniqueName('type')]);
        $cacheStore = $cache->getStore();

        self::assertInstanceOf(ValkeyGlideTaggedCache::class, $cache);
        self::assertInstanceOf(RedisTaggedCache::class, $cache);
        self::assertInstanceOf(ValkeyGlideStore::class, $cacheStore);
        self::assertSame($cachePrefix, $cacheStore->getPrefix());

        $connection = $cacheStore->connection();

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertSame($connectionPrefix, $connection->getPrefix());
        self::assertSame($topology === self::CLUSTER, $connection->client() instanceof \ValkeyGlideCluster);
    }

    /**
     * Configure a standalone and a cluster connection with and without a key
     * prefix, and a redis cache store with and without a cache prefix over
     * each.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.options', []);

        foreach (self::CONNECTION_PREFIXES as $connectionLabel => $connectionPrefix) {
            $app['config']->set('database.redis.' . self::STANDALONE . '-' . $connectionLabel, $this->standaloneConfig() + ['prefix' => $connectionPrefix]);
            $app['config']->set('database.redis.clusters.' . self::CLUSTER . '-' . $connectionLabel, [$this->clusterSeed() + ['prefix' => $connectionPrefix]]);

            foreach ([self::STANDALONE, self::CLUSTER] as $topology) {
                foreach (self::CACHE_PREFIXES as $cacheLabel => $cachePrefix) {
                    $app['config']->set('cache.stores.' . $topology . '-' . $connectionLabel . '-' . $cacheLabel, [
                        'driver'     => 'redis',
                        'connection' => $topology . '-' . $connectionLabel,
                        'prefix'     => $cachePrefix,
                    ]);
                }
            }
        }
    }

    /**
     * Assert the callback throws an UnexpectedValueException with the given
     * message.
     *
     * @param  string  $message
     * @param  \Closure(): mixed  $callback
     * @return void
     */
    private static function assertThrowsUnexpectedValue(string $message, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\UnexpectedValueException $exception) {
            // From 1.2, GLIDE records the error, which is thrown instead.
            if ($exception instanceof CommandException) {
                self::assertStringStartsWith('WRONGTYPE', $exception->getMessage());

                return;
            }

            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail(sprintf('Expected an UnexpectedValueException: %s', $message));
    }

    /**
     * Get the unprefixed connection to the topology, to read and delete
     * physical keys.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function physical(string $topology): ValkeyGlideConnection
    {
        return $this->physicalConnections[$topology] ??= $this->connectTo($topology);
    }

    /**
     * Record physical keys to delete after the test, and return them.
     *
     * @param  string  $topology
     * @param  string  ...$keys
     * @return list<string>
     */
    private function track(string $topology, string ...$keys): array
    {
        foreach ($keys as $key) {
            $this->trackedKeys[$topology][$key] = true;
        }

        return array_values($keys);
    }

    /**
     * Build the physical keys of the given tags' sorted sets.
     *
     * @param  string  $prefix
     * @param  array<int, string>  $tags
     * @return list<string>
     */
    private function tagSetKeys(string $prefix, array $tags): array
    {
        return array_values(array_map(static fn (string $tag): string => $prefix . 'tag:' . $tag . ':entries', $tags));
    }

    /**
     * Read the score of a member of a sorted set.
     *
     * @param  string  $topology
     * @param  string  $setKey
     * @param  string  $member
     * @return float|null
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function score(string $topology, string $setKey, string $member): ?float
    {
        $score = $this->physical($topology)->command('zscore', [$setKey, $member]);

        return is_numeric($score) ? (float) $score : null;
    }

    /**
     * Count how many of the physical keys exist, a thousand at a time.
     *
     * @param  string  $topology
     * @param  list<string>  $keys
     * @return int
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function countExisting(string $topology, array $keys): int
    {
        $existing = 0;

        foreach (array_chunk($keys, self::CLEANUP_CHUNK_SIZE) as $chunk) {
            $count = $this->physical($topology)->command('exists', $chunk);

            self::assertIsInt($count);

            $existing += $count;
        }

        return $existing;
    }

    /**
     * Resolve a Laravel cache store as the concrete cache repository.
     *
     * @param  string  $store
     * @return \Illuminate\Cache\Repository
     *
     * @throws \UnexpectedValueException
     */
    private function repository(string $store): Repository
    {
        $repository = Cache::store($store);

        if ($repository instanceof Repository) {
            return $repository;
        }

        throw new \UnexpectedValueException(sprintf('Expected cache store [%s] to be [%s], received [%s].', $store, Repository::class, get_debug_type($repository)));
    }

    /**
     * Resolve the GLIDE-aware tagged cache for the given tags of a store.
     *
     * @param  string  $store
     * @param  array<int, string>  $tags
     * @return \SineMacula\Valkey\Cache\ValkeyGlideTaggedCache
     *
     * @throws \UnexpectedValueException
     */
    private function tagged(string $store, array $tags): ValkeyGlideTaggedCache
    {
        $cache = $this->repository($store)->tags($tags);

        if ($cache instanceof ValkeyGlideTaggedCache) {
            return $cache;
        }

        throw new \UnexpectedValueException(sprintf('Expected tagged cache to be [%s], received [%s].', ValkeyGlideTaggedCache::class, get_debug_type($cache)));
    }
}
