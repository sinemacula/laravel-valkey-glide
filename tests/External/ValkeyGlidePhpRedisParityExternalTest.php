<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Cache\RedisLock;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;

/**
 * External checks for the commands the connection answers as Laravel's phpredis
 * connection does, in the call shapes Laravel's and Horizon's locks, Horizon's
 * tag pages and Horizon's metrics clearing use: setnx, zrevrange and scan with
 * an options array.
 *
 * Every test runs against the standalone server and, when
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT are set,
 * against the cluster; the cluster cases are skipped otherwise. Physical keys
 * are read and deleted through an unprefixed connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlidePhpRedisParityExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string The key prefix of the connection under test, as Horizon configures one. */
    private const string PREFIX = 'lvkglide:parity:';

    /** @var int The number of keys a scan loop is checked against, enough to span every cluster primary. */
    private const int SCANNED_KEY_COUNT = 250;

    /** @var array<string, \SineMacula\Valkey\Connections\ValkeyGlideConnection> Open connections, by topology and prefix. */
    private array $connections = [];

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
     * Delete the physical keys the test wrote and close the connections.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->trackedKeys as $topology => $keys) {
            $this->connection($topology, '')->command('del', array_keys($keys));
        }

        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->trackedKeys = [];
        $this->connections = [];

        parent::tearDown();
    }

    /**
     * Verify setnx answers 1 for the key it sets and 0 once the key exists, so
     * Horizon's lock, which compares the answer with 1, takes it once.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function setnxAnswersOneThenZeroForThePrefixedKey(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('setnx'));

        self::assertSame(1, $connection->setnx($key, 1));
        self::assertSame(0, $connection->setnx($key, 1));
        self::assertSame('1', $this->connection($topology, '')->get(self::PREFIX . $key));
    }

    /**
     * Verify Laravel's lock without an expiry, which takes the key with setnx,
     * is acquired by its first owner only.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function laravelLockWithoutExpiryIsAcquiredByItsFirstOwnerOnly(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $name       = $this->track($topology, $this->uniqueName('lock'));

        self::assertTrue((new RedisLock($connection, $name, 0, 'owner-a'))->acquire());
        self::assertFalse((new RedisLock($connection, $name, 0, 'owner-b'))->acquire());
        self::assertSame('owner-a', $this->connection($topology, '')->get(self::PREFIX . $name));
    }

    /**
     * Verify zrevrange returns the prefixed sorted set's members from the
     * highest score, by index as Horizon's tag pages read them, and with their
     * scores when asked.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function zrevrangeReturnsMembersFromTheHighestScore(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('tag'));

        $this->connection($topology, '')->command('zadd', [self::PREFIX . $key, '1', 'job-a', '2.5', 'job-b', '3', 'job-c', '3', 'job-d']);

        self::assertSame(['job-d', 'job-c'], $connection->zrevrange($key, 0, 1));
        self::assertSame(['job-b', 'job-a'], $connection->zrevrange($key, 2, 3));
        self::assertSame(['job-d' => 3.0, 'job-c' => 3.0, 'job-b' => 2.5, 'job-a' => 1.0], $connection->zrevrange($key, 0, -1, ['withscores' => true]));
        self::assertSame(['job-d' => 3.0], $connection->zrevrange($key, 0, 0, true));
        self::assertSame([], $connection->zrevrange($this->uniqueName('missing'), 0, -1));
    }

    /**
     * Verify a scan loop written as Horizon's metrics clearing writes it, from
     * cursor '0' until it is '0' again, finds every key matching a pattern that
     * carries the prefix, once, across every primary of a cluster.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function scanLoopFindsEveryMatchingKeyOnce(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $physical   = $this->connection($topology, '');
        $namespace  = $this->uniqueName('scan');
        $expected   = [];

        for ($index = 0; $index < self::SCANNED_KEY_COUNT; $index++) {
            $key        = $this->track($topology, sprintf('%s:job:%d', $namespace, $index));
            $expected[] = self::PREFIX . $key;

            $connection->command('set', [$key, 'value']);
        }

        $this->track($topology, $namespace . ':other');
        $connection->command('set', [$namespace . ':other', 'value']);

        $found = $this->scanAll($connection, self::PREFIX . $namespace . ':job:*', 50);

        sort($expected);
        sort($found);

        self::assertSame($expected, $found);
        self::assertSame([], $this->scanAll($connection, self::PREFIX . $namespace . ':missing:*', 50));
        self::assertSame(1, $physical->command('exists', [self::PREFIX . $namespace . ':other']));
    }

    /**
     * Verify a scan the server refuses throws, rather than returning the empty
     * last page of a completed scan: GLIDE answers it with false and writes '0'
     * back as the cursor.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function scanThrowsWhenTheServerRefusesIt(): void
    {
        $connection = $this->connection(self::STANDALONE, self::PREFIX);

        // From 1.2, GLIDE records the server's error, which is thrown instead.
        $message = method_exists($connection->client(), 'getLastError')
            ? 'invalid cursor'
            : 'Expected array response from scan on [' . self::PREFIX . '*], received [bool].';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $connection->scan('not-a-cursor', ['match' => self::PREFIX . '*']);
    }

    /**
     * Verify a cluster scan refuses a cursor not of the shape its scan writes
     * before anything is sent to the cluster.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterScanRefusesACursorItDidNotReturn(): void
    {
        $connection = $this->connection(self::CLUSTER, self::PREFIX);

        $this->expectException(\InvalidArgumentException::class);

        $connection->scan('17', ['match' => self::PREFIX . '*']);
    }

    /**
     * Verify each page of a cluster scan dispatches a command executed event
     * that a listener can turn into a string, carrying the cursor the page
     * scans from.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterScanEventsCarryTheCursorIdOfEachPage(): void
    {
        $connection = $this->connection(self::CLUSTER, self::PREFIX);
        $namespace  = $this->uniqueName('scan-events');
        $pattern    = self::PREFIX . $namespace . ':*';
        $expected   = [];

        for ($index = 0; $index < self::SCANNED_KEY_COUNT; $index++) {
            $connection->command('set', [$this->track(self::CLUSTER, sprintf('%s:%d', $namespace, $index)), 'value']);
        }

        $formatted  = [];
        $dispatcher = new Dispatcher;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$formatted): void {
            $formatted[] = implode(' ', $event->parameters);
        });

        $connection->setEventDispatcher($dispatcher);

        try {
            $cursor = '0';
            $found  = [];

            do {
                $expected[]      = sprintf('%s %s 50', $cursor, $pattern);
                [$cursor, $page] = $connection->scan($cursor, ['match' => $pattern, 'count' => 50]);
                $found           = [...$found, ...$page];
            } while ($cursor !== '0');
        } finally {
            $connection->unsetEventDispatcher();
        }

        self::assertCount(self::SCANNED_KEY_COUNT, $found);
        self::assertGreaterThan(1, count($expected));
        self::assertSame($expected, $formatted);
    }

    /**
     * Scan every key matching the pattern the way Horizon and Laravel do for
     * connections other than phpredis: from cursor '0' until it is '0' again.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $pattern
     * @param  int  $count
     * @return array<int, string>
     *
     * @throws \Throwable
     */
    private function scanAll(ValkeyGlideConnection $connection, string $pattern, int $count): array
    {
        $cursor = '0';
        $keys   = [];

        do {
            [$cursor, $page] = $connection->scan($cursor, ['match' => $pattern, 'count' => $count]);
            $keys            = [...$keys, ...$page];
        } while ($cursor !== '0');

        return $keys;
    }

    /**
     * Get the connection to the topology with the given prefix, opening it on
     * first use.
     *
     * @param  string  $topology
     * @param  string  $prefix
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function connection(string $topology, string $prefix): ValkeyGlideConnection
    {
        return $this->connections[$topology . '|' . $prefix] ??= $this->connectTo($topology, $prefix);
    }

    /**
     * Track a logical key of the prefixed connection for deletion after the
     * test.
     *
     * @param  string  $topology
     * @param  string  $key
     * @return string
     */
    private function track(string $topology, string $key): string
    {
        $this->trackedKeys[$topology][self::PREFIX . $key] = true;

        return $key;
    }
}
