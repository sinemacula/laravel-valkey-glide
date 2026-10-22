<?php

declare(strict_types = 1);

namespace Tests\External;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Cache\ValkeyGlideStore;
use SineMacula\Valkey\Connections\ClusterScan;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\Fakes\RedisFactoryFake;

/**
 * External checks for the options-array keyspace scan of a cluster, which scans
 * each primary in turn with a plain SCAN sent to its address rather than
 * through GLIDE's own cluster scan.
 *
 * Every test needs the cluster named by VALKEY_GLIDE_TEST_CLUSTER_HOST and
 * VALKEY_GLIDE_TEST_CLUSTER_PORT, and is skipped without it. The cases of a
 * refused scan create their own ACL user denied SCAN on every node, and delete
 * it after the test; they are skipped when the cluster refuses `ACL SETUSER`.
 * Had the scan gone through GLIDE's own, the refusal would abort the process.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[CoversClass(ClusterScan::class)]
#[CoversClass(ValkeyGlideStore::class)]
#[Group('external')]
final class ValkeyGlideClusterScanExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var int The number of keys each scan is checked against, enough to span every primary. */
    private const int KEY_COUNT = 300;

    /** @var string The key prefix of the connections under test. */
    private const string PREFIX = 'lvkglide:cluster-scan:';

    /** @var \SineMacula\Valkey\Connections\ValkeyGlideConnection|null The prefixed connection the keys are written through. */
    private ?ValkeyGlideConnection $connection = null;

    /** @var list<\SineMacula\Valkey\Connections\ValkeyGlideConnection> Open connections, closed after the test. */
    private array $connections = [];

    /** @var list<string> Logical keys to delete after the test. */
    private array $keys = [];

    /** @var list<string> ACL users the test created, deleted from every node after it. */
    private array $users = [];

    /**
     * Ensure extension preconditions are met and the cluster is configured.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertExtensionIsLoaded();
        $this->skipUnlessTopologyIsConfigured(self::CLUSTER);
    }

    /**
     * Delete the ACL users the test created and the keys it wrote, then close
     * the connections, each step attempted whether or not the one before it
     * failed. The users are deleted on a best-effort basis, as a cluster that
     * refused to create them may refuse `ACL DELUSER` too.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[\Override]
    protected function tearDown(): void
    {
        try {
            if ($this->users !== []) {
                $this->connection()->executeRaw(['ACL', 'DELUSER', ...$this->users]);
            }
        } catch (\UnexpectedValueException) {
            // From 1.2, GLIDE throws the refusal rather than returning false.
        } finally {
            try {
                if ($this->keys !== []) {
                    $this->connection()->command('del', $this->keys);
                }
            } finally {
                $this->closeConnections();

                parent::tearDown();
            }
        }
    }

    /**
     * Verify a scan loop from '0' until the cursor is '0' again finds every
     * matching key, across every primary.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function scanFindsEveryMatchingKey(): void
    {
        $namespace = $this->uniqueName('every');
        $expected  = $this->writeKeys($namespace);

        [$found, $pages] = $this->scanAll($this->connection(), ['match' => self::PREFIX . $namespace . ':*', 'count' => 25]);

        self::assertSame($expected, self::distinct($found));
        self::assertGreaterThan(3, $pages);
        self::assertSame([], $this->scanAll($this->connection(), ['match' => self::PREFIX . $namespace . ':missing:*', 'count' => 1000])[0]);
    }

    /**
     * Verify a type option keeps only the keys of that type.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function scanWithATypeFindsOnlyKeysOfThatType(): void
    {
        $namespace = $this->uniqueName('type');
        $sets      = [];

        $this->writeKeys($namespace);

        for ($index = 0; $index < 30; $index++) {
            $this->keys[] = $key = sprintf('%s:set:%d', $namespace, $index);
            $sets[]       = self::PREFIX . $key;

            $this->connection()->command('zadd', [$key, 1, 'member']);
        }

        [$found] = $this->scanAll($this->connection(), ['match' => self::PREFIX . $namespace . ':*', 'count' => 100, 'type' => 'zset']);

        sort($sets);

        self::assertSame($sets, self::distinct($found));
    }

    /**
     * Verify a scan the server refuses with NOPERM, for an ACL user denied
     * SCAN, throws a catchable exception rather than aborting the process: the
     * bare false of 1.1.2 as an UnexpectedValueException, and the error 1.2
     * records as the CommandException that extends it.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function aScanRefusedByAnAclThrowsACatchableException(): void
    {
        $denied = $this->connectAsUserDeniedScan();

        $this->expectException(\UnexpectedValueException::class);

        $denied->scan('0', ['match' => self::PREFIX . '*']);
    }

    /**
     * Verify a page refused mid-scan throws, and the scan resumes from the
     * cursor that page was given on another connection, allowed to scan, as the
     * cursor carries the scan's whole state, still finding every key.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function aScanRefusedMidScanResumesFromTheSameCursor(): void
    {
        $denied    = $this->connectAsUserDeniedScan();
        $namespace = $this->uniqueName('refused');
        $expected  = $this->writeKeys($namespace);
        $options   = ['match' => self::PREFIX . $namespace . ':*', 'count' => 25];

        [$cursor, $first] = $this->connection()->scan('0', $options);

        try {
            $denied->scan($cursor, $options);
            self::fail('Expected the refused page to throw.');
        } catch (\UnexpectedValueException) {
            [$rest] = $this->scanAll($this->connect(), $options, $cursor);
        }

        self::assertSame($expected, self::distinct([...$first, ...$rest]));
    }

    /**
     * Verify the tag prune of a cache store on the cluster throws a catchable
     * exception for a user denied SCAN.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function aTagPruneRefusedByAnAclThrowsACatchableException(): void
    {
        $store = new ValkeyGlideStore(new RedisFactoryFake($this->connectAsUserDeniedScan()), 'cache:');

        $this->expectException(\UnexpectedValueException::class);

        $store->flushStaleTags();
    }

    /**
     * Close the connections the test opened and forget its state.
     *
     * @return void
     */
    private function closeConnections(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->connection  = null;
        $this->connections = [];
        $this->keys        = [];
        $this->users       = [];
    }

    /**
     * Sort the keys and drop any found more than once, as SCAN may return a key
     * again when a node's keyspace is resized during the scan.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function distinct(array $keys): array
    {
        $keys = array_values(array_unique($keys));

        sort($keys);

        return $keys;
    }

    /**
     * Write the namespace's string keys and return their physical names, in
     * order.
     *
     * @param  string  $namespace
     * @return list<string>
     *
     * @throws \Throwable
     */
    private function writeKeys(string $namespace): array
    {
        $physical = [];

        for ($index = 0; $index < self::KEY_COUNT; $index++) {
            $this->keys[] = $key = sprintf('%s:key:%d', $namespace, $index);
            $physical[]   = self::PREFIX . $key;

            $this->connection()->command('set', [$key, 'value']);
        }

        sort($physical);

        return $physical;
    }

    /**
     * Scan with the given options from the cursor until it is '0' again.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  array<string, mixed>  $options
     * @param  string  $cursor
     * @return array{0: list<string>, 1: int}
     *
     * @throws \Throwable
     */
    private function scanAll(ValkeyGlideConnection $connection, array $options, string $cursor = '0'): array
    {
        $keys  = [];
        $pages = 0;

        do {
            [$cursor, $page] = $connection->scan($cursor, $options);
            $keys            = [...$keys, ...array_values($page)];
        } while ($cursor !== '0' && ++$pages < 10000);

        return [$keys, $pages + 1];
    }

    /**
     * Get the prefixed connection to the cluster, opening it on first use.
     *
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function connection(): ValkeyGlideConnection
    {
        return $this->connection ??= $this->connect();
    }

    /**
     * Open another prefixed connection to the cluster.
     *
     * @param  array<string, string>  $credentials
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function connect(array $credentials = []): ValkeyGlideConnection
    {
        $connection = (new ValkeyGlideConnector)->connectToCluster([$this->clusterSeed() + $credentials], [], ['prefix' => self::PREFIX]);

        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * Create an ACL user denied SCAN on every node of the cluster, and open a
     * prefixed connection to the cluster as that user, skipping the test when
     * the cluster refuses to create it. The user is recorded for deletion
     * before it is created, so a node that created it is cleaned up even when
     * another refused.
     *
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \Throwable
     */
    private function connectAsUserDeniedScan(): ValkeyGlideConnection
    {
        $username = $this->uniqueName('noscan');
        $password = bin2hex(random_bytes(16));

        $this->users[] = $username;

        try {
            $created = $this->connection()->executeRaw(['ACL', 'SETUSER', $username, 'on', '>' . $password, '~*', '&*', '+@all', '-scan']);
        } catch (\Throwable $exception) {
            $created = $exception->getMessage();
        }

        if ($created === false || is_string($created) && $created !== 'OK') {
            self::markTestSkipped(sprintf('The cluster refused ACL SETUSER, which the refused cluster scan cases need: %s', var_export($created, true)));
        }

        return $this->connect(['username' => $username, 'password' => $password]);
    }
}
