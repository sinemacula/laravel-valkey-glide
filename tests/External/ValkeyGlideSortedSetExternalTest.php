<?php

declare(strict_types = 1);

namespace Tests\External;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\SortedSetArguments;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;

/**
 * External checks for the sorted-set calls ext-valkey_glide 1.1.2 corrupts the
 * heap on when it is handed their numbers: a ZADD with more than 20 integer or
 * float scores, a ZADD whose member is not a string, and a ZUNIONSTORE,
 * ZINTERSTORE, ZUNION or ZINTER with more than 20 integer or float weights.
 * Through the connection each is sent as strings, so it stores what was asked
 * and the process carries on; each test ends by allocating and running more
 * commands, where a corrupted heap would abort the process. The sorted-set
 * forms GLIDE takes differently from phpredis and Laravel are checked too: a
 * ZINCRBY increment, which GLIDE writes with six significant digits, Laravel's
 * zadd() dictionary and option strings, and Laravel's zinterstore() and
 * zunionstore() options array.
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
#[CoversClass(SortedSetArguments::class)]
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideSortedSetExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string The key prefix of the connection under test. */
    private const string PREFIX = 'lvkglide:zset:';

    /** @var int The score and member pairs of a ZADD, well past the 20 numeric scores GLIDE can track. */
    private const int PAIRS = 512;

    /** @var int The keys, and weights, of a ZUNIONSTORE-style command, past the 20 numeric weights GLIDE can track. */
    private const int WEIGHTED_KEYS = 40;

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
     * Verify a ZADD with 512 integer scores, and one with 512 float scores and
     * an options array, store every score.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function zaddWithManyNumericScoresStoresEveryScore(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $integers   = $this->track($topology, $this->uniqueName('integers'));
        $floats     = $this->track($topology, $this->uniqueName('floats'));

        self::assertSame(self::PAIRS, $connection->command('zadd', [$integers, ...self::pairs(static fn (int $i): int => $i)]));
        self::assertSame(self::PAIRS, $connection->command('zadd', [$floats, ['NX', 'CH'], ...self::pairs(static fn (int $i): float => $i + 0.5)]));

        self::assertSame(self::scores(static fn (int $i): float => (float) $i), $connection->command('zrange', [$integers, 0, -1, true]));
        self::assertSame(self::scores(static fn (int $i): float => $i + 0.5), $connection->command('zrange', [$floats, 0, -1, true]));

        $this->assertProcessCarriesOn($topology);
    }

    /**
     * Verify a ZADD whose members are not strings stores them as their string
     * form, where GLIDE would return false and corrupt the heap.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function zaddWithNonStringMembersStoresTheirStringForm(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('members'));

        self::assertSame(2, $connection->command('zadd', [$key, 1, 123, 2.5, 0.1 + 0.2]));
        self::assertSame(['123' => 1.0, '0.3' => 2.5], $connection->command('zrange', [$key, 0, -1, true]));

        $this->assertProcessCarriesOn($topology);
    }

    /**
     * Verify a ZADD with 512 integer scores and integer members, buffered in a
     * pipeline and in a transaction, stores every score.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function zaddInABatchStoresEveryScore(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $piped      = $this->track($topology, $this->uniqueName('piped'));
        $atomic     = $this->track($topology, $this->uniqueName('atomic'));
        $pairs      = [];

        for ($i = 0; $i < self::PAIRS; $i++) {
            array_push($pairs, $i, $i);
        }

        self::assertSame([self::PAIRS, self::PAIRS], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->zadd($piped, ...$pairs)->zcard($piped)));
        self::assertSame([self::PAIRS], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->zadd($atomic, ...$pairs)));

        $expected = [];

        for ($i = 0; $i < self::PAIRS; $i++) {
            $expected[$i] = (float) $i;
        }

        self::assertSame($expected, $connection->command('zrange', [$piped, 0, -1, true]));
        self::assertSame($expected, $connection->command('zrange', [$atomic, 0, -1, true]));

        $this->assertProcessCarriesOn($topology);
    }

    /**
     * Verify float scores read back exactly, where GLIDE would write them with
     * six significant digits, and that the increment form adds the exact float.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function floatScoresReadBackExactly(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('exact'));
        $scores     = ['a' => 1234567.5, 'b' => 0.1 + 0.2, 'c' => 1.5e-7, 'd' => PHP_FLOAT_MAX, 'e' => INF, 'f' => -INF];
        $pairs      = [];

        foreach ($scores as $member => $score) {
            array_push($pairs, $score, $member);
        }

        self::assertSame(count($scores), $connection->command('zadd', [$key, ...$pairs]));

        foreach ($scores as $member => $score) {
            self::assertSame($score, $connection->command('zscore', [$key, $member]), $member);
        }

        self::assertSame(1234567.6, $connection->command('zadd', [$key, ['XX', 'INCR'], 0.1, 'a']));

        $this->assertProcessCarriesOn($topology);
    }

    /**
     * Verify ZUNIONSTORE, ZINTERSTORE, ZUNION and ZINTER with 40 integer
     * weights weigh every key, the weights passed by position or by name, and
     * that ZDIFFSTORE, which GLIDE sends weights for although the server takes
     * none, is refused by the server.
     *
     * The keys share a hash tag so a cluster takes them in one command; these
     * commands' keys are not prefixed, so they are named in full.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function manyNumericWeightsWeighEveryKey(string $topology): void
    {
        $connection  = $this->connection($topology, '');
        $tag         = '{' . $this->uniqueName('weights') . '}';
        $destination = $this->track($topology, $tag . ':destination', '');
        $keys        = [];
        $weights     = [];

        for ($i = 1; $i <= self::WEIGHTED_KEYS; $i++) {
            $keys[]    = $this->track($topology, $tag . ':' . $i, '');
            $weights[] = $i;

            $connection->command('zadd', [$keys[$i - 1], 1, 'm']);
        }

        $total = (float) array_sum($weights);

        self::assertSame(1, $connection->command('zunionstore', [$destination, $keys, $weights]));
        self::assertSame($total, $connection->command('zscore', [$destination, 'm']));
        self::assertSame(1, $connection->command('zunionstore', [$destination, $keys, 'weights' => $weights]));
        self::assertSame($total, $connection->command('zscore', [$destination, 'm']));
        self::assertSame(1, $connection->command('zinterstore', [$destination, $keys, $weights, 'SUM']));
        self::assertSame($total, $connection->command('zscore', [$destination, 'm']));
        self::assertSame(['m' => $total], $connection->command('zunion', [$keys, $weights, ['withscores' => true]]));
        self::assertSame(['m'], $connection->command('zinter', [$keys, $weights]));
        self::assertFalse($connection->command('zdiffstore', [$destination, $keys, $weights]));

        $this->assertProcessCarriesOn($topology);
    }

    /**
     * Verify ZINCRBY adds the exact increment, where GLIDE's zIncrBy() would
     * add it with six significant digits, and returns the new score as a float,
     * directly and in a pipeline and a transaction.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function zincrbyAddsTheExactIncrement(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('increment'));

        self::assertSame(1.23456789, $connection->command('zIncrBy', [$key, 1.23456789, 'm']));
        self::assertSame(1.23456789, $connection->command('zscore', [$key, 'm']));
        self::assertSame(3.0, $connection->command('zincrby', [$key, 3, 'n']));
        self::assertSame([1.23456789 + 2.3456789], $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->zincrby($key, '2.3456789', 'm')));
        self::assertSame([1.23456789 + 2.3456789 - 0.123456789, 1.23456789 + 2.3456789 - 0.123456789], $connection->transaction(
            static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->zincrby($key, -0.123456789, 'm')->zscore($key, 'm'),
        ));
        self::assertSame(['n' => 3.0, 'm' => 1.23456789 + 2.3456789 - 0.123456789], $connection->command('zrange', [$key, 0, -1, true]));

        $this->assertProcessCarriesOn($topology);
    }

    /**
     * Verify zadd() takes Laravel's dictionary of scores by member and its
     * option strings, as Laravel's phpredis connection does, and returns what
     * GLIDE returns for them.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function zaddTakesLaravelsDictionaryAndOptionStrings(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('dictionary'));

        self::assertSame(2, $connection->zadd($key, ['a' => 1, 'b' => 2.5]));
        self::assertSame(1, $connection->zadd($key, 'NX', ['a' => 9, 'c' => 3]));
        self::assertSame(1, $connection->zadd($key, 'XX', 'CH', 7, 'a', 8, 'd'));
        self::assertSame(7.5, $connection->zadd($key, 'INCR', 0.5, 'a'));
        self::assertFalse($connection->zadd($key, 'NX', 'INCR', 1, 'a'));
        self::assertSame(['b' => 2.5, 'c' => 3.0, 'a' => 7.5], $connection->command('zrange', [$key, 0, -1, true]));

        $this->assertProcessCarriesOn($topology);
    }

    /**
     * Verify zinterstore() and zunionstore() take Laravel's options array, as
     * Laravel's phpredis connection does, where GLIDE would send it as the
     * weights, and GLIDE's own weights list and aggregate.
     *
     * The keys share a hash tag so a cluster takes them in one command; these
     * commands' keys are not prefixed, so they are named in full.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function storeCommandsTakeLaravelsOptions(string $topology): void
    {
        $connection  = $this->connection($topology, '');
        $tag         = '{' . $this->uniqueName('store') . '}';
        $destination = $this->track($topology, $tag . ':destination', '');
        $first       = $this->track($topology, $tag . ':first', '');
        $second      = $this->track($topology, $tag . ':second', '');

        $connection->zadd($first, ['m' => 1, 'n' => 5]);
        $connection->zadd($second, ['m' => 3, 'n' => 2]);

        self::assertSame(2, $connection->zinterstore($destination, [$first, $second], ['weights' => [2, 1], 'aggregate' => 'max']));
        self::assertSame(['m' => 3.0, 'n' => 10.0], $connection->command('zrange', [$destination, 0, -1, true]));
        self::assertSame(2, $connection->zunionstore($destination, [$first, $second], ['aggregate' => 'min']));
        self::assertSame(['m' => 1.0, 'n' => 2.0], $connection->command('zrange', [$destination, 0, -1, true]));
        self::assertSame(2, $connection->zunionstore($destination, [$first, $second], []));
        self::assertSame(['m' => 4.0, 'n' => 7.0], $connection->command('zrange', [$destination, 0, -1, true]));
        self::assertSame(2, $connection->zinterstore($destination, [$first, $second], [1, 3], 'MIN'));
        self::assertSame(['m' => 1.0, 'n' => 5.0], $connection->command('zrange', [$destination, 0, -1, true]));
        self::assertSame(2, $connection->zunionstore($destination, [$first, $second], array_filter([0, 2, 1])));
        self::assertSame(['m' => 5.0, 'n' => 12.0], $connection->command('zrange', [$destination, 0, -1, true]));
        self::assertSame(2, $connection->zunionstore($destination, [$first, $second]));
        self::assertSame(['m' => 4.0, 'n' => 7.0], $connection->command('zrange', [$destination, 0, -1, true]));

        $this->assertProcessCarriesOn($topology);
    }

    /**
     * Build score and member pairs, each member named after its index.
     *
     * @param  \Closure(int): (float|int)  $score
     * @return list<float|int|string>
     */
    private static function pairs(\Closure $score): array
    {
        $pairs = [];

        for ($i = 0; $i < self::PAIRS; $i++) {
            array_push($pairs, $score($i), 'member' . $i);
        }

        return $pairs;
    }

    /**
     * Build the scores ZRANGE ... WITHSCORES reads back for the pairs.
     *
     * @param  \Closure(int): float  $score
     * @return array<string, float>
     */
    private static function scores(\Closure $score): array
    {
        $scores = [];

        for ($i = 0; $i < self::PAIRS; $i++) {
            $scores['member' . $i] = $score($i);
        }

        return $scores;
    }

    /**
     * Assert that the process can still allocate and run commands, which a
     * corrupted heap would abort.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    private function assertProcessCarriesOn(string $topology): void
    {
        $connection = $this->connection($topology, self::PREFIX);
        $key        = $this->track($topology, $this->uniqueName('after'));
        $buffers    = [];

        for ($i = 0; $i < 2000; $i++) {
            $buffers[] = str_repeat('x', $i % 300);
        }

        self::assertCount(2000, $buffers);
        self::assertSame(2, $connection->command('sadd', [$key, 'a', 'b']));
        self::assertSame(2, $connection->command('scard', [$key]));
    }

    /**
     * Get the connection to the topology with the given prefix, opening it
     * once.
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
     * Record a key, under the given prefix, to delete after the test.
     *
     * @param  string  $topology
     * @param  string  $key
     * @param  string  $prefix
     * @return string
     */
    private function track(string $topology, string $key, string $prefix = self::PREFIX): string
    {
        $this->trackedKeys[$topology][$prefix . $key] = true;

        return $key;
    }
}
