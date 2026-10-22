<?php

declare(strict_types = 1);

namespace Tests\External;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;

/**
 * External checks for the keyless commands a cluster client takes a route for,
 * called as Laravel and health checks call them on a standalone connection:
 * without one.
 *
 * Every test runs against the standalone server and, when
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT are set,
 * against the cluster; the cluster cases are skipped otherwise. Each test
 * writes one key of its own, so the keyspace is never empty, and deletes it
 * afterwards.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideKeylessCommandsExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var list<string> Hash tags whose slots, 3300, 7365 and 15495, are spread across the keyspace. */
    private const array SPREAD_HASH_TAGS = ['b', 'c', 'a'];

    /** @var \SineMacula\Valkey\Connections\ValkeyGlideConnection|null The connection under test, once opened. */
    private ?ValkeyGlideConnection $connection = null;

    /** @var list<string> The keys the test wrote. */
    private array $keys = [];

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
     * Delete the keys the test wrote and close the connection.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->connection !== null && $this->keys !== []) {
            $this->connection->command('del', $this->keys);
        }

        $this->connection?->disconnect();

        $this->connection = null;
        $this->keys       = [];

        parent::tearDown();
    }

    /**
     * Verify the keyless commands called without a route answer as they do on a
     * standalone connection.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function keylessCommandsWithoutRouteAnswerAsOnStandalone(string $topology): void
    {
        $connection = $this->connectWithKey($topology);

        self::assertTrue($connection->command('ping'));
        self::assertGreaterThanOrEqual(1, $connection->command('dbsize'));
        self::assertIsString($connection->command('randomkey'));
        self::assertSame('hello', $connection->command('echo', ['hello']));

        $time = $connection->command('time');

        self::assertIsArray($time);
        self::assertCount(2, $time);
        self::assertIsNumeric($time[0]);
    }

    /**
     * Verify a cluster dbsize() without a route counts the keys of every
     * primary, not those of one.
     *
     * The keys are hash tagged into slots spread across the keyspace, so they
     * are held by more than one primary, and the total exceeds the count of the
     * primary that holds any one of them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterDbsizeWithoutRouteCountsEveryPrimary(): void
    {
        $connection = $this->connectWithKey(self::CLUSTER);
        $keys       = array_map(fn (string $tag): string => $this->write(sprintf('{%s}%s', $tag, $this->uniqueName('dbsize'))), self::SPREAD_HASH_TAGS);
        $total      = $connection->command('dbsize');

        self::assertIsInt($total);

        foreach ($keys as $key) {
            $primaryCount = $connection->command('dbsize', [$key]);

            self::assertIsInt($primaryCount);
            self::assertGreaterThan($primaryCount, $total);
        }
    }

    /**
     * Verify explicit cluster routes are passed through unchanged, with the
     * replies GLIDE gives each one, such as each node's time for every primary.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function explicitClusterRoutesPassThroughUnchanged(): void
    {
        $connection = $this->connectWithKey(self::CLUSTER);

        $info          = $connection->command('info', ['randomNode', 'server']);
        $nodeTime      = $connection->command('time', ['randomNode']);
        $primaryTimes  = $connection->command('time', ['allPrimaries']);
        $firstNodeTime = is_array($primaryTimes) ? $primaryTimes[1] ?? null : null;

        self::assertIsInt($connection->command('dbsize', ['randomNode']));
        self::assertSame('hello', $connection->command('echo', ['randomNode', 'hello']));
        self::assertIsArray($info);
        self::assertSame('redis_version', array_key_first($info));
        self::assertIsArray($nodeTime);
        self::assertCount(2, $nodeTime);
        self::assertIsArray($firstNodeTime);
        self::assertCount(2, $firstNodeTime);
    }

    /**
     * Verify info() without a route reports the server on a standalone
     * connection and is refused on a cluster one before anything is sent.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function infoWithoutRouteIsRefusedOnlyOnTheCluster(string $topology): void
    {
        $connection = $this->connectWithKey($topology);

        if ($topology !== self::CLUSTER) {
            $info = $connection->command('info');

            self::assertIsArray($info);
            self::assertArrayHasKey('redis_version', $info);

            return;
        }

        $this->expectException(\InvalidArgumentException::class);

        $connection->command('info');
    }

    /**
     * Verify a pipeline runs the keyless commands without a route in call
     * order, around the commands it buffers.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function pipelineRunsKeylessCommandsWithoutRouteInCallOrder(string $topology): void
    {
        $connection = $this->connectWithKey($topology);
        $key        = $this->write($this->uniqueName('pipeline'));

        $results = $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->get($key)->dbsize()->echo('hello')->get($key));

        self::assertCount(4, $results);
        self::assertSame('1', $results[0]);
        self::assertGreaterThanOrEqual(1, $results[1]);
        self::assertSame('hello', $results[2]);
        self::assertSame('1', $results[3]);
    }

    /**
     * Connect to the topology and write the test's key, so the keyspace is
     * never empty.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \Throwable
     */
    private function connectWithKey(string $topology): ValkeyGlideConnection
    {
        $this->connection = $this->connectTo($topology);

        $this->write($this->uniqueName('keyless'));

        return $this->connection;
    }

    /**
     * Write a key on the open connection and track it for deletion after the
     * test.
     *
     * @param  string  $key
     * @return string
     *
     * @throws \Throwable
     */
    private function write(string $key): string
    {
        $this->connection?->command('set', [$key, '1']);

        $this->keys[] = $key;

        return $key;
    }
}
