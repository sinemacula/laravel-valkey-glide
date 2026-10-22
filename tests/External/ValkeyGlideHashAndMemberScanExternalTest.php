<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\RedisTagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\Fakes\RedisFactoryFake;

/**
 * External checks for the hash and member scan commands the connection answers
 * as Laravel's phpredis connection does: hsetnx, and zscan, hscan and sscan
 * with an options array.
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
final class ValkeyGlideHashAndMemberScanExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string The key prefix of the connection under test. */
    private const string PREFIX = 'lvkglide:member-scan:';

    /** @var int The number of members a scan loop is checked against, more than one page of 1,000. */
    private const int SCANNED_MEMBER_COUNT = 2500;

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
     * Verify hsetnx answers 1 for the field it sets and 0 once the field
     * exists, leaving the first value in the prefixed hash.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function hsetnxAnswersOneThenZeroForThePrefixedKey(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('hsetnx'));

        self::assertSame(1, $connection->hsetnx($key, 'field', 'first'));
        self::assertSame(0, $connection->hsetnx($key, 'field', 'second'));
        self::assertSame('first', $this->connection($topology, '')->command('hget', [self::PREFIX . $key, 'field']));
    }

    /**
     * Verify hsetnx on a pipeline joins it and answers with GLIDE's reply, as
     * Horizon's process repository calls it on the pipeline.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function hsetnxOnAPipelineAnswersWithGlidesReply(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('hsetnx-pipeline'));

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($key): void {
            $pipe->hsetnx($key, 'field', 'first');
            $pipe->hsetnx($key, 'field', 'second');
        });

        self::assertSame([true, false], $results);
        self::assertSame('first', $this->connection($topology, '')->command('hget', [self::PREFIX . $key, 'field']));
    }

    /**
     * Verify a loop over each member scan, from cursor '0' until it is '0'
     * again, reads every member of the prefixed key once, in the shape phpredis
     * gives it, over more than one page.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function memberScanLoopsReadEveryMemberOnce(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $expected   = $this->seedMemberScanKeys($topology, $connection, 'all');

        foreach ($expected as $method => [$key, $members]) {
            $pages = 0;
            $found = $this->scanAll($connection, $method, $key, ['match' => '*', 'count' => 1000], $pages);

            self::assertGreaterThan(1, $pages, $method);
            self::assertCount(self::SCANNED_MEMBER_COUNT, $found, $method);

            if ($method === 'sscan') {
                sort($found);

                self::assertSame($members, $found, $method);

                continue;
            }

            ksort($found);

            self::assertSame($members, $found, $method);
        }
    }

    /**
     * Verify zscan maps each member to its score as the float phpredis gives,
     * for the spellings the server writes scores in, infinities included.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function zscanReturnsEachScoreAsTheFloatPhpRedisGives(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('scores'));

        $connection->command('zadd', [
            $key,
            '3', 'int',
            '1.5', 'fraction',
            '-2.5e-7', 'small',
            '1e20', 'large',
            '123456789012345678', 'long',
            '+inf', 'plus-inf',
            'inf', 'inf',
            '-inf', 'minus-inf',
        ]);

        [$cursor, $scores] = $connection->zscan($key, '0', ['match' => '*', 'count' => 100]);

        self::assertSame('0', $cursor);
        self::assertSame([
            'minus-inf' => -INF,
            'small'     => -2.5e-7,
            'fraction'  => 1.5,
            'int'       => 3.0,
            'long'      => 1.2345678901234568e17,
            'large'     => 1.0e20,
            'inf'       => INF,
            'plus-inf'  => INF,
        ], $scores);
    }

    /**
     * Verify each member scan sends its match pattern, which matches members
     * rather than keys, unprefixed.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function memberScanMatchesMembersWithTheUnprefixedPattern(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $expected   = $this->seedMemberScanKeys($topology, $connection, 'match');

        foreach ($expected as $method => [$key]) {
            $found = $this->scanAll($connection, $method, $key, ['match' => 'm:7*', 'count' => 1000]);

            self::assertCount(111, $found, $method);
            self::assertSame([], $this->scanAll($connection, $method, $key, ['match' => self::PREFIX . 'm:*', 'count' => 1000]), $method);
        }
    }

    /**
     * Verify each member scan answers a missing key with the empty last page of
     * a completed scan.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function memberScanAnswersAMissingKeyWithAnEmptyLastPage(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);

        foreach (['zscan', 'hscan', 'sscan'] as $method) {
            self::assertSame(['0', []], self::memberScan($connection, $method, $this->uniqueName('missing'), '0', ['match' => '*']), $method);
            self::assertSame(['0', []], self::memberScan($connection, $method, $this->uniqueName('missing'), null), $method);
        }
    }

    /**
     * Verify a member scan of a key of the wrong type throws rather than read
     * as the empty last page of a completed scan: GLIDE answers it with false
     * and writes '0' back as the cursor.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function memberScanThrowsWhenTheServerRefusesIt(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('string'));

        $connection->command('set', [$key, 'value']);

        foreach (['zscan', 'hscan', 'sscan'] as $method) {
            try {
                self::memberScan($connection, $method, $key, '0', ['match' => '*']);
                self::fail(sprintf('Expected %s of a string to throw.', $method));
            } catch (\UnexpectedValueException $exception) {
                self::assertSame(sprintf('Expected array response from %s on [%s], received [bool].', $method, $key), $exception->getMessage());
            }
        }
    }

    /**
     * Verify a member scan inside a pipeline is refused before anything is
     * sent, as the SCAN family is, so the pipeline sends nothing.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function memberScanIsRefusedInsideAPipeline(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('refused'));

        foreach (['zscan', 'hscan', 'sscan'] as $method) {
            try {
                $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection, $key, $method): void {
                    $pipe->set($key, 'value');
                    self::memberScan($connection, $method, $key, '0', ['match' => '*']);
                });

                self::fail(sprintf('Expected %s to be refused.', $method));
            } catch (\LogicException $exception) {
                self::assertStringStartsWith('[' . strtoupper($method) . '] cannot be used inside a pipeline or transaction', $exception->getMessage());
            }

            self::assertFalse($connection->hasOpenBatch());
            self::assertSame(0, $this->connection($topology, '')->command('exists', [self::PREFIX . $key]));
        }
    }

    /**
     * Verify GLIDE's own member scan form, through command() with the cursor by
     * reference, still reads every member of the prefixed key.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function memberScanThroughCommandStillReadsEveryMember(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $expected   = $this->seedMemberScanKeys($topology, $connection, 'command');

        foreach ($expected as $method => [$key]) {
            /** @var string $cursor */
            $cursor = '0';
            $found  = 0;

            do {
                $page = $connection->command($method, [$key, &$cursor, '*', 1000]);

                self::assertIsArray($page);

                $found += count($page);
            } while ($cursor !== '0');

            self::assertSame(self::SCANNED_MEMBER_COUNT, $found, $method);
        }
    }

    /**
     * Verify Laravel's own tag set, which reads each tag's sorted set with
     * zscan() and Laravel's options array, reads every entry over the
     * connection.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function laravelTagSetReadsEveryEntryThroughZscan(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $tag        = $this->uniqueName('tag');
        $setKey     = $this->track($topology, 'cache:tag:' . $tag . ':entries');
        $expected   = [];

        foreach (array_chunk(range(0, self::SCANNED_MEMBER_COUNT - 1), 500) as $chunk) {
            $pairs = [];

            foreach ($chunk as $index) {
                $expected[] = 'entry:' . $index;
                $pairs      = [...$pairs, '-1', 'entry:' . $index];
            }

            $connection->command('zadd', [$setKey, ...$pairs]);
        }

        $tagSet  = new RedisTagSet(new RedisStore(new RedisFactoryFake($connection), 'cache:'), [$tag]);
        $entries = $tagSet->entries()->all();

        sort($expected);
        sort($entries);

        self::assertSame($expected, $entries);
    }

    /**
     * Seed a sorted set, a hash and a set under the prefix, each with the same
     * members, and return each scan method's key and expected members.
     *
     * Sorted set scores are sent as strings, as GLIDE 1.1.2 corrupts its heap
     * on a ZADD with more than 20 integer or float scores.
     *
     * @param  string  $topology
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $label
     * @return array<string, array{string, array<array-key, mixed>}>
     *
     * @throws \Throwable
     */
    private function seedMemberScanKeys(string $topology, ValkeyGlideConnection $connection, string $label): array
    {
        $zset = $this->track($topology, $this->uniqueName($label . '-zset'));
        $hash = $this->track($topology, $this->uniqueName($label . '-hash'));
        $set  = $this->track($topology, $this->uniqueName($label . '-set'));

        $scores = [];
        $fields = [];

        foreach (range(0, self::SCANNED_MEMBER_COUNT - 1) as $index) {
            $scores['m:' . $index] = (string) $index;
            $fields['m:' . $index] = 'v:' . $index;
        }

        foreach (array_chunk($scores, 500, true) as $chunk) {
            $pairs = [];

            foreach ($chunk as $member => $score) {
                $pairs = [...$pairs, $score, $member];
            }

            $connection->command('zadd', [$zset, ...$pairs]);
            $connection->command('hmset', [$hash, array_map(static fn (string $score): string => 'v:' . $score, $chunk)]);
            $connection->command('sadd', [$set, ...array_map('strval', array_keys($chunk))]);
        }

        ksort($scores);
        ksort($fields);

        $members = array_map('strval', array_keys($scores));

        sort($members);

        return [
            'zscan' => [$zset, array_map('floatval', $scores)],
            'hscan' => [$hash, $fields],
            'sscan' => [$set, $members],
        ];
    }

    /**
     * Read every page of a member scan, from cursor '0' until it is '0' again,
     * merging the pages as Laravel's callers read them.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $method
     * @param  string  $key
     * @param  array<string, mixed>  $options
     * @param  int  $pages
     *
     * @param-out  int  $pages
     *
     * @return array<array-key, mixed>
     *
     * @throws \Throwable
     */
    private function scanAll(ValkeyGlideConnection $connection, string $method, string $key, array $options, int &$pages = 0): array
    {
        $cursor  = '0';
        $members = [];

        do {
            [$cursor, $page] = self::memberScan($connection, $method, $key, $cursor, $options);
            $members         = $method === 'sscan' ? [...$members, ...$page] : $members + $page;

            $pages++;
        } while ($cursor !== '0');

        return $members;
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

    /**
     * Call the named member scan on the connection, leaving the options out
     * when none are given.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $method
     * @param  string  $key
     * @param  mixed  $cursor
     * @param  array<array-key, mixed>|null  $options
     * @return array{0: string, 1: array<array-key, mixed>}
     *
     * @throws \Throwable
     */
    private static function memberScan(ValkeyGlideConnection $connection, string $method, string $key, mixed $cursor, ?array $options = null): array
    {
        if ($options === null) {
            return match ($method) {
                'zscan' => $connection->zscan($key, $cursor),
                'hscan' => $connection->hscan($key, $cursor),
                default => $connection->sscan($key, $cursor),
            };
        }

        return match ($method) {
            'zscan' => $connection->zscan($key, $cursor, $options),
            'hscan' => $connection->hscan($key, $cursor, $options),
            default => $connection->sscan($key, $cursor, $options),
        };
    }
}
