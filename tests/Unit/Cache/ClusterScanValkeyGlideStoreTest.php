<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Fakes\ClusterKeyspaceFake;
use Tests\Fakes\RedisFactoryFake;

/**
 * Cluster tag-set scan test case for the GLIDE-aware cache store.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideStore::class)]
final class ClusterScanValkeyGlideStoreTest extends TestCase
{
    /**
     * Verify flushStaleTags() scans every primary of a cluster for sorted sets
     * and prunes each tag set it finds once.
     *
     * @return void
     */
    #[Test]
    public function flushStaleTagsScansEveryPrimaryForTheTagSets(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 8191]], ['conn:cache:tag:a:entries', 'conn:cache:tag:b:entries'])
            ->withPrimary('10.0.0.2:7001', [[8192, 16383]], ['conn:cache:tag:c:entries']);

        $this->store($client, 'conn:')->flushStaleTags();

        self::assertSame([
            ['10.0.0.1:7000', '0', 'MATCH', 'conn:cache:tag:*:entries', 'COUNT', '1000', 'TYPE', 'zset'],
            ['10.0.0.2:7001', '0', 'MATCH', 'conn:cache:tag:*:entries', 'COUNT', '1000', 'TYPE', 'zset'],
        ], $client->scans());
        self::assertSame(
            ['conn:cache:tag:a:entries', 'conn:cache:tag:b:entries', 'conn:cache:tag:c:entries'],
            array_column($client->callsFor('zremrangebyscore'), 0),
        );
    }

    /**
     * Verify every tag set is pruned once when the scan spans several pages, as
     * the pages' keys are numbered afresh.
     *
     * @return void
     */
    #[Test]
    public function currentTagsReadsEveryTagOfEveryPage(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 8191]], ['cache:tag:a:entries', 'cache:tag:b:entries'])
            ->withPrimary('10.0.0.2:7001', [[8192, 16383]], ['cache:tag:c:entries']);

        $store = $this->store($client);
        $tags  = (new \ReflectionMethod($store, 'currentTags'))->invoke($store, 1);

        self::assertInstanceOf(LazyCollection::class, $tags);
        self::assertSame(['a', 'b', 'c'], $tags->values()->all());
        self::assertSame(['a', 'b', 'c'], array_merge(...$tags->chunk(2)->map(static fn ($chunk): array => $chunk->values()->all())->all()));
    }

    /**
     * Verify a page the server refuses throws at once, rather than ending the
     * scan as if it were complete.
     *
     * @return void
     */
    #[Test]
    public function flushStaleTagsThrowsWhenAClusterPageIsRefused(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 16383]], ['conn:cache:tag:a:entries'])
            ->willAnswer('SCAN', false);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected array response from scan on [conn:cache:tag:*:entries], received [bool].');

        try {
            $this->store($client, 'conn:')->flushStaleTags();
        } finally {
            self::assertCount(1, $client->scans());
            self::assertSame([], $client->callsFor('zremrangebyscore'));
        }
    }

    /**
     * Verify the next page is refused once a batch has been opened on the
     * connection between pages, rather than sent outside the batch.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function flushStaleTagsRefusesTheNextPageOnceABatchIsOpened(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 8191]], ['cache:tag:a:entries'])
            ->withPrimary('10.0.0.2:7001', [[8192, 16383]], ['cache:tag:b:entries']);

        $store      = $this->store($client);
        $connection = $store->connection();

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);

        $dispatcher = new Dispatcher;
        $dispatcher->listen(CommandExecuted::class, static function () use ($connection): void {
            if ($connection->hasOpenBatch()) {
                return;
            }

            $connection->pipeline();
        });

        $connection->setEventDispatcher($dispatcher);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('[SCAN] cannot be used inside a pipeline or transaction, as GLIDE would');

        try {
            $store->flushStaleTags();
        } finally {
            self::assertCount(1, $client->scans());
            self::assertTrue($connection->discard());
            self::assertFalse($connection->hasOpenBatch());
        }
    }

    /**
     * Build a store over a connection to the given client.
     *
     * @param  \Tests\Fakes\ClusterKeyspaceFake  $client
     * @param  string  $connectionPrefix
     * @return \SineMacula\Valkey\Cache\ValkeyGlideStore
     */
    private function store(ClusterKeyspaceFake $client, string $connectionPrefix = ''): ValkeyGlideStore
    {
        return new ValkeyGlideStore(new RedisFactoryFake(new ValkeyGlideConnection($client, null, ['prefix' => $connectionPrefix])), 'cache:');
    }
}
