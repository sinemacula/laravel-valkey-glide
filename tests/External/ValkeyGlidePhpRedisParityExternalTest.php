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
use SineMacula\Valkey\Connections\KeyPrefixer;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;

/**
 * External checks for the commands the connection answers as Laravel's phpredis
 * connection does, in the call shapes Laravel's and Horizon's locks, Horizon's
 * tag pages and Horizon's metrics clearing use: setnx, zrevrange and scan with
 * an options array; and for the keys of the commands that take a destination
 * key, a list of keys or a key after a subcommand, which are prefixed, inside
 * and outside batches, as phpredis prefixes them.
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
#[CoversClass(KeyPrefixer::class)]
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

    /** @var string|null The hash tag the multi-key commands' keys of the test share. */
    private ?string $tag = null;

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
        $this->tag         = null;

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
     * Verify a cluster scan refuses a cursor id the connection did not return
     * rather than hand it to GLIDE, which aborts the process on it.
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
     * that a listener can turn into a string, carrying the cursor id the page
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
     * Verify the sorted-set commands that store into a destination, read a list
     * of keys, or pop from the first of several keys, use the prefixed keys.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function sortedSetMultiKeyCommandsUseThePrefixedKeys(string $topology): void
    {
        $connection                                     = $this->connection($topology, self::PREFIX);
        $raw                                            = $this->connection($topology, '');
        [$a, $b, $empty, $union, $inter, $diff, $range] = $this->keys($topology, 'a', 'b', 'empty', 'union', 'inter', 'diff', 'range');

        $connection->command('zadd', [$a, 1, 'm1', 2, 'm2']);
        $connection->command('zadd', [$b, 3, 'm2', 4, 'm3']);

        self::assertSame(3, $connection->command('zunionstore', [$union, [$a, $b]]));
        self::assertSame(1, $connection->command('zinterstore', [$inter, [$a, $b]]));
        self::assertSame(1, $connection->command('zdiffstore', [$diff, [$a, $b]]));
        self::assertSame(2, $connection->command('zrangestore', [$range, $a, 0, -1]));
        self::assertSame(['m1', 'm3', 'm2'], $connection->command('zunion', [[$a, $b]]));
        self::assertSame(['m2'], $connection->command('zinter', [[$a, $b]]));
        self::assertSame(['m1'], $connection->command('zdiff', [[$a, $b]]));
        self::assertSame(1, $connection->command('zintercard', [[$a, $b]]));
        self::assertSame(['m1', 'm3', 'm2'], $raw->command('zrange', [self::PREFIX . $union, 0, -1]));
        self::assertSame(['m2'], $raw->command('zrange', [self::PREFIX . $inter, 0, -1]));
        self::assertSame(['m1'], $raw->command('zrange', [self::PREFIX . $diff, 0, -1]));
        self::assertSame(['m1', 'm2'], $raw->command('zrange', [self::PREFIX . $range, 0, -1]));

        self::assertSame([self::PREFIX . $a, ['m1' => 1.0]], $connection->command('zmpop', [[$empty, $a], 'MIN']));
        self::assertSame([self::PREFIX . $b, ['m3' => 4.0]], $connection->command('bzmpop', [0.1, [$empty, $b], 'MAX']));
        self::assertSame([self::PREFIX . $a, 'm2', '2'], $connection->command('bzpopmin', [$empty, $a, 1]));
        self::assertSame([self::PREFIX . $b, 'm2', '3'], $connection->command('bzpopmax', [[$empty, $b], 1]));
        $this->assertNoUnprefixedKeys($topology, $a, $b, $union, $inter, $diff, $range);
    }

    /**
     * Verify the set and HyperLogLog commands that store into a destination, or
     * read a list of keys, use the prefixed keys.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function setAndHyperLogLogMultiKeyCommandsUseThePrefixedKeys(string $topology): void
    {
        $connection                                                   = $this->connection($topology, self::PREFIX);
        $raw                                                          = $this->connection($topology, '');
        [$s1, $s2, $union, $inter, $listed, $diff, $h1, $h2, $merged] = $this->keys($topology, 's1', 's2', 'union', 'inter', 'listed', 'diff', 'h1', 'h2', 'merged');

        $connection->command('sadd', [$s1, 'a', 'b', 'c']);
        $connection->command('sadd', [$s2, 'b', 'c', 'd']);

        self::assertSame(4, $connection->command('sunionstore', [$union, $s1, $s2]));
        self::assertSame(2, $connection->command('sinterstore', [$inter, $s1, $s2]));
        self::assertSame(2, $connection->command('sinterstore', [[$listed, $s1, $s2]]));
        self::assertSame(1, $connection->command('sdiffstore', [$diff, $s1, $s2]));
        self::assertSame(2, $connection->command('sintercard', [[$s1, $s2]]));
        self::assertSame(4, $raw->command('scard', [self::PREFIX . $union]));
        self::assertSame(2, $raw->command('scard', [self::PREFIX . $inter]));
        self::assertSame(2, $raw->command('scard', [self::PREFIX . $listed]));
        self::assertSame(['a'], $raw->command('smembers', [self::PREFIX . $diff]));

        self::assertSame(1, $connection->command('pfadd', [$h1, ['a', 'b']]));
        self::assertSame(1, $connection->command('pfadd', [$h2, ['c']]));
        self::assertSame(3, $connection->command('pfcount', [[$h1, $h2]]));
        self::assertTrue($connection->command('pfmerge', [$merged, [$h1, $h2]]));
        self::assertSame(3, $raw->command('pfcount', [self::PREFIX . $merged]));
        $this->assertNoUnprefixedKeys($topology, $s1, $s2, $union, $inter, $listed, $diff, $h1, $h2, $merged);
    }

    /**
     * Verify the list commands that move between two keys, or pop from the
     * first of several keys, use the prefixed keys.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function listMultiKeyCommandsUseThePrefixedKeys(string $topology): void
    {
        $connection                             = $this->connection($topology, self::PREFIX);
        $raw                                    = $this->connection($topology, '');
        [$source, $destination, $empty, $other] = $this->keys($topology, 'source', 'destination', 'empty', 'other');

        $connection->command('rpush', [$source, 'x', 'y', 'z']);

        self::assertSame('x', $connection->command('lmove', [$source, $destination, 'LEFT', 'RIGHT']));
        self::assertSame('y', $connection->command('blmove', [$source, $destination, 'LEFT', 'RIGHT', 0.1]));
        self::assertSame(['x', 'y'], $raw->command('lrange', [self::PREFIX . $destination, 0, -1]));
        self::assertSame([self::PREFIX . $destination, ['x']], $connection->command('lmpop', [[$empty, $destination], 'LEFT']));
        self::assertSame([self::PREFIX . $source, ['z']], $connection->command('blmpop', [0.1, [$empty, $source], 'LEFT']));

        $connection->command('rpush', [$other, 'a', 'b']);

        self::assertSame([self::PREFIX . $other, 'a'], $connection->command('blpop', [[$empty, $other], 1]));
        self::assertSame([self::PREFIX . $other, 'b'], $connection->command('brpop', [[$empty, $other], 1]));
        $this->assertNoUnprefixedKeys($topology, $source, $destination, $other);
    }

    /**
     * Verify the stream commands, including the stream maps of XREAD and
     * XREADGROUP and the key after XGROUP's and XINFO's subcommand, use the
     * prefixed key.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function streamCommandsUseThePrefixedKey(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $raw        = $this->connection($topology, '');
        [$stream]   = $this->keys($topology, 'stream');

        $id = $connection->command('xadd', [$stream, '*', ['field' => 'value']]);

        self::assertIsString($id);
        self::assertSame(1, $raw->command('xlen', [self::PREFIX . $stream]));
        self::assertTrue($connection->command('xgroup', ['CREATE', $stream, 'group', '0']));
        self::assertSame([self::PREFIX . $stream => [$id => ['field' => 'value']]], $connection->command('xreadgroup', ['group', 'consumer', [$stream => '>']]));
        self::assertSame([self::PREFIX . $stream => [$id => ['field' => 'value']]], $connection->command('xread', [[$stream => '0']]));
        self::assertSame(1, self::infoLength($connection->command('xinfo', ['STREAM', $stream])));
        self::assertSame(1, $connection->command('xack', [$stream, 'group', [$id]]));
        self::assertSame(1, $connection->command('xdel', [$stream, [$id]]));
        self::assertSame(0, $connection->command('xlen', [$stream]));
        $this->assertNoUnprefixedKeys($topology, $stream);
    }

    /**
     * Verify the other commands that take two keys, a key after a subcommand,
     * or keys inside a script's arguments, use the prefixed keys, and that
     * SORT, as with phpredis, prefixes its key but not its STORE key.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function otherKeyCommandsUseThePrefixedKeys(string $topology): void
    {
        $connection                                            = $this->connection($topology, self::PREFIX);
        $raw                                                   = $this->connection($topology, '');
        [$t1, $t2, $restored, $places, $found, $list, $sorted] = $this->keys($topology, 't1', 't2', 'restored', 'places', 'found', 'list', 'sorted');

        $connection->command('set', [$t1, 'ohmytext']);
        $connection->command('set', [$t2, 'mynewtext']);

        self::assertSame('mytext', $connection->command('lcs', [$t1, $t2]));
        self::assertSame($raw->command('bitcount', [self::PREFIX . $t1]), $connection->command('bitcount', [$t1]));
        self::assertSame($raw->command('object', ['encoding', self::PREFIX . $t1]), $connection->command('object', ['encoding', $t1]));
        self::assertTrue($connection->command('restore', [$restored, 0, $connection->command('dump', [$t1])]));
        self::assertSame('ohmytext', $raw->command('get', [self::PREFIX . $restored]));
        self::assertSame([self::PREFIX . $t1, 'argument'], $connection->command('eval_ro', ['return {KEYS[1], ARGV[1]}', [$t1, 'argument'], 1]));

        self::assertSame(1, $connection->command('geoadd', [$places, 13.361389, 38.115556, 'palermo']));
        self::assertSame(1, $connection->command('geosearchstore', [$found, $places, 'palermo', 100, 'km']));
        self::assertSame(1, $raw->command('zcard', [self::PREFIX . $found]));

        $connection->command('rpush', [$list, '3', '1', '2']);

        self::assertSame(3, $connection->command('sort', [$list, ['store' => $sorted]]));
        self::assertSame(['1', '2', '3'], $raw->command('lrange', [$sorted, 0, -1]));
        $this->assertNoUnprefixedKeys($topology, $t1, $t2, $restored, $places, $found, $list);
    }

    /**
     * Verify multi-key commands use the prefixed keys inside a pipeline and a
     * transaction, as they do outside a batch.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function multiKeyCommandsInBatchesUseThePrefixedKeys(string $topology): void
    {
        $connection                                                   = $this->connection($topology, self::PREFIX);
        $raw                                                          = $this->connection($topology, '');
        [$a, $b, $union, $inter, $source, $destination, $h1, $merged] = $this->keys($topology, 'a', 'b', 'union', 'inter', 'source', 'destination', 'h1', 'merged');

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($a, $b, $union, $inter): void {
            $pipe->zadd($a, 1, 'm1');
            $pipe->zadd($b, 2, 'm2');
            $pipe->zunionstore($union, [$a, $b]);
            $pipe->sadd($a . ':set', 'x');
            $pipe->__call('sinterstore', [$inter, $a . ':set', $a . ':set']);
        });

        $this->keys($topology, 'a:set');

        self::assertSame([1, 1, 2, 1, 1], $results);
        self::assertSame(2, $raw->command('zcard', [self::PREFIX . $union]));
        self::assertSame(['x'], $raw->command('smembers', [self::PREFIX . $inter]));

        $results = $connection->transaction(static function (ValkeyGlideBatch $tx) use ($source, $destination, $h1, $merged): void {
            $tx->rpush($source, 'x');
            $tx->__call('lmove', [$source, $destination, 'LEFT', 'LEFT']);
            $tx->pfadd($h1, ['a']);
            $tx->__call('pfmerge', [$merged, [$h1]]);
        });

        self::assertSame([1, 'x', 1, true], $results);
        self::assertSame(['x'], $raw->command('lrange', [self::PREFIX . $destination, 0, -1]));
        self::assertSame(1, $raw->command('pfcount', [self::PREFIX . $merged]));
        $this->assertNoUnprefixedKeys($topology, $a, $b, $union, $inter, $source, $destination, $h1, $merged);
    }

    /**
     * Read the length XINFO STREAM reports.
     *
     * @param  mixed  $info
     * @return mixed
     */
    private static function infoLength(mixed $info): mixed
    {
        return is_array($info) ? ($info['length'] ?? null) : null;
    }

    /**
     * Assert that none of the given keys was written without the prefix.
     *
     * @param  string  $topology
     * @param  string  ...$keys
     * @return void
     *
     * @throws \Throwable
     */
    private function assertNoUnprefixedKeys(string $topology, string ...$keys): void
    {
        self::assertSame(0, $this->connection($topology, '')->command('exists', $keys));
    }

    /**
     * Build logical keys that share the test's hash tag, tracking both their
     * prefixed and their unprefixed physical keys for deletion after the test.
     *
     * @param  string  $topology
     * @param  string  ...$names
     * @return list<string>
     */
    private function keys(string $topology, string ...$names): array
    {
        $keys = [];

        foreach ($names as $name) {
            $key = ($this->tag ??= '{' . $this->uniqueName('prefixed') . '}') . $name;

            $this->trackedKeys[$topology][self::PREFIX . $key] = true;
            $this->trackedKeys[$topology][$key]                = true;

            $keys[] = $key;
        }

        return $keys;
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
