<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ClusterRouter;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\CommandException;
use SineMacula\Valkey\Exceptions\ConnectionException;
use Tests\External\Concerns\ConnectsToTestServers;

/**
 * External checks for pipelines and transactions on the real extension.
 *
 * Every test runs against the standalone server and, when
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT are set,
 * against the cluster; the cluster cases are skipped otherwise. Each test uses
 * its own prefix, hash-tagged so all of its keys share one cluster slot unless
 * the test spreads them over slots.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[CoversClass(ValkeyGlideBatch::class)]
#[Group('external')]
final class ValkeyGlidePipelineExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string Lua script that returns the value of its first key. */
    private const string GET_SCRIPT = 'return redis.call(\'get\', KEYS[1])';

    /** @var list<array{\SineMacula\Valkey\Connections\ValkeyGlideConnection, list<string>}> Connections and the logical keys to delete after the test. */
    private array $cleanups = [];

    /** @var array<int, string> Key prefix of each connection, by object id. */
    private array $prefixes = [];

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
     * Delete the keys each test wrote and close its connections.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->cleanups as [$connection, $keys]) {
            try {
                foreach ($keys as $key) {
                    $connection->command('del', [$key]);
                }

                $connection->disconnect();
            } catch (\Throwable) {
                // Best effort: a closed connection has nothing left to delete.
            }
        }

        $this->cleanups = [];
        $this->prefixes = [];

        parent::tearDown();
    }

    /**
     * Verify a pipeline returns every result in call order, with the shapes
     * phpredis returns.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function pipelineReturnsResultsInCallOrder(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'n', 'h', 'z', 'missing']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('a', 'value-a');
            $pipe->get('a');
            $pipe->get('missing');
            $pipe->incr('n');
            $pipe->incrby('n', 4);
            $pipe->hmset('h', ['f' => 'v', 'g' => 2]);
            $pipe->hmget('h', ['f', 'g', 'none']);
            $pipe->zadd('z', 1, 'm1');
            $pipe->zadd('z', 2, 'm2');
            $pipe->zrange('z', 0, -1);
        });

        self::assertSame(
            [true, 'value-a', false, 1, 5, true, ['f' => 'v', 'g' => '2', 'none' => false], 1, 1, ['m1', 'm2']],
            $results,
        );
    }

    /**
     * Verify a transaction returns every result in call order and commits.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function transactionReturnsResultsInCallOrder(string $topology): void
    {
        $connection = $this->connect($topology, ['n', 'a']);

        $results = $connection->transaction(static function (ValkeyGlideBatch $tx): void {
            $tx->incr('n')->incr('n')->set('a', 'committed')->get('n');
        });

        self::assertSame([1, 2, true, '2'], $results);
        self::assertSame('committed', $connection->command('get', ['a']));
    }

    /**
     * Verify the fluent form, without a callback, runs on exec().
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function batchWithoutCallbackRunsOnExec(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'b']);

        $pipeline = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $pipeline);
        self::assertSame([true, 'x'], $pipeline->set('a', 'x')->get('a')->exec());

        $transaction = $connection->transaction();

        self::assertInstanceOf(ValkeyGlideBatch::class, $transaction);
        self::assertSame([true, 'y'], $transaction->set('b', 'y')->get('b')->exec());

        $discarded = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $discarded);

        $discarded->set('a', 'discarded');

        self::assertTrue($discarded->discard());
        self::assertSame('x', $connection->command('get', ['a']));
    }

    /**
     * Verify every newly prefixed command writes, reads and deletes the
     * prefixed physical key inside a pipeline and outside one.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function newlyPrefixedCommandsUsePhysicalPrefixedKeys(string $topology): void
    {
        $k          = $this->uniqueKeys(['h', 'z', 'p', 'd1', 'd2', 'u1', 'e1', 'e2', 'hd', 'zd', 'pd']);
        $connection = $this->connect($topology, array_values($k));
        $prefix     = $this->prefixOf($connection);
        $physical   = $this->connect($topology, array_values($k), '');

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($k): void {
            $pipe->hmset($k['h'], ['f' => 'v']);
            $pipe->zadd($k['z'], 1, 'a');
            $pipe->zadd($k['z'], 2, 'b');
            $pipe->zadd($k['z'], 3, 'c');
            $pipe->zadd($k['z'], 4, 'd');
            $pipe->zremrangebyscore($k['z'], 0, 1);
            $pipe->zremrangebyrank($k['z'], 0, 0);
            $pipe->zrevrangebyscore($k['z'], '+inf', '-inf');
            $pipe->zrevrank($k['z'], 'c');
            $pipe->psetex($k['p'], 60000, 'v');
            $pipe->set($k['e1'], '1');
            $pipe->set($k['e2'], '1');
            $pipe->exists($k['e1'], $k['e2'], $k['e1'] . ':missing');
            $pipe->set($k['d1'], '1');
            $pipe->set($k['d2'], '1');
            $pipe->del([$k['d1'], $k['d2']]);
            $pipe->set($k['u1'], '1');
            $pipe->unlink([$k['u1']]);
        });

        self::assertSame([true, 1, 1, 1, 1, 1, 1, ['d', 'c'], 1, true, true, true, 2, true, true, 2, true, 1], $results);
        self::assertSame(['f' => 'v'], $physical->command('hgetall', [$prefix . $k['h']]));
        self::assertSame(['c', 'd'], $physical->command('zrange', [$prefix . $k['z'], 0, -1]));
        self::assertSame('v', $physical->command('get', [$prefix . $k['p']]));
        self::assertSame(0, $physical->command('exists', [$prefix . $k['d1'], $prefix . $k['d2'], $prefix . $k['u1']]));

        foreach (['h', 'z', 'p', 'e1'] as $label) {
            self::assertSame(0, $physical->command('exists', [$k[$label]]), sprintf('Unprefixed key [%s] must not exist.', $k[$label]));
        }

        $connection->command('hmset', [$k['hd'], ['f' => 'v']]);
        $connection->command('zadd', [$k['zd'], 1, 'a', 2, 'b', 3, 'c']);
        $connection->command('psetex', [$k['pd'], 60000, 'v']);

        self::assertSame(['f' => 'v'], $physical->command('hgetall', [$prefix . $k['hd']]));
        self::assertSame(1, $connection->command('zremrangebyscore', [$k['zd'], 0, 1]));
        self::assertSame(1, $connection->command('zremrangebyrank', [$k['zd'], 0, 0]));
        self::assertSame(['c'], $connection->command('zrevrangebyscore', [$k['zd'], '+inf', '-inf']));
        self::assertSame(0, $connection->command('zrevrank', [$k['zd'], 'c']));
        self::assertSame('v', $physical->command('get', [$prefix . $k['pd']]));
        self::assertSame(2, $connection->command('exists', [$k['hd'], $k['pd']]));
        self::assertSame(2, $connection->command('del', [[$k['hd'], $k['pd']]]));
        self::assertSame(0, $physical->command('exists', [$prefix . $k['hd'], $prefix . $k['pd']]));
        self::assertSame(0, $physical->command('exists', [$k['hd'], $k['zd'], $k['pd']]));
    }

    /**
     * Verify an empty pipeline or transaction returns an empty list and leaves
     * the client out of batch mode, so the next command runs directly.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function emptyBatchesDoNotWedgeTheClient(string $topology): void
    {
        $connection = $this->connect($topology, ['a']);

        self::assertSame([], $connection->pipeline(static function (): void {}));
        self::assertTrue($connection->command('set', ['a', 'after-pipeline']));
        self::assertSame([], $connection->transaction(static function (): void {}));
        self::assertSame('after-pipeline', $connection->command('get', ['a']));

        $connection->command('multi');

        self::assertSame([], $connection->command('exec'));
        self::assertSame('after-pipeline', $connection->get('a'));
        self::assertSame('after-pipeline', $connection->client()->get($this->prefixOf($connection) . 'a'));
    }

    /**
     * Verify an exception thrown by the callback discards the batch, so nothing
     * it buffered is written, and leaves the connection usable.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function exceptionInCallbackDiscardsTheBatch(string $topology): void
    {
        $connection = $this->connect($topology, ['p', 't', 'a']);
        $runners    = [
            'p' => static fn (callable $callback): mixed => $connection->pipeline($callback),
            't' => static fn (callable $callback): mixed => $connection->transaction($callback),
        ];

        foreach ($runners as $key => $run) {
            try {
                $run(static function (ValkeyGlideBatch $batch) use ($key): void {
                    $batch->set($key, 'never');

                    throw new \RuntimeException('callback failed');
                });

                self::fail(sprintf('Expected the callback exception for [%s] to be rethrown.', $key));
            } catch (\RuntimeException $exception) {
                self::assertSame('callback failed', $exception->getMessage());
            }

            self::assertFalse($connection->client()->get($this->prefixOf($connection) . $key));
        }

        self::assertTrue($connection->command('set', ['a', 'usable']));
        self::assertSame('usable', $connection->get('a'));
    }

    /**
     * Verify a transaction nested in a pipeline runs as its own transaction,
     * and its results take one place in the pipeline's results.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function transactionNestedInPipelineRunsOnItsOwn(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'n']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection): void {
            $pipe->set('a', '1');

            $nested = $connection->transaction(static function (ValkeyGlideBatch $tx): void {
                $tx->incr('n')->incr('n');
            });

            self::assertSame($pipe, $nested);

            $pipe->get('a');
        });

        self::assertSame([true, [1, 2], '1'], $results);
    }

    /**
     * Verify the RedisQueue::bulk() shape: a transaction nested in a pipeline
     * whose callback ignores the batch and calls EVAL and ZADD on the
     * connection itself.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function bulkShapedNestedBatchRunsConnectionCommandsInOrder(string $topology): void
    {
        $connection = $this->connect($topology, ['q', 'q:delayed']);
        $prefix     = $this->prefixOf($connection);
        $push       = 'return redis.call(\'rpush\', KEYS[1], ARGV[1])';

        $results = $connection->pipeline(static fn () => $connection->transaction(static function () use ($connection, $push): void {
            $connection->command('eval', [$push, 1, 'q', 'job-1']);
            $connection->command('zadd', ['q:delayed', 10, 'job-2']);
            $connection->command('eval', [$push, 1, 'q', 'job-3']);
        }));

        self::assertSame([[1, 1, 2]], $results);
        $physical = $this->physical($topology);

        self::assertSame(['job-1', 'job-3'], $physical->command('lrange', [$prefix . 'q', 0, -1]));
        self::assertSame(['job-2'], $physical->command('zrange', [$prefix . 'q:delayed', 0, -1]));
    }

    /**
     * Verify a transaction guarded by a prefixed WATCH returns false when the
     * physical watched key changes, and commits when only the unprefixed key
     * changes.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function watchedTransactionReturnsFalseWhenTheWatchedKeyChanges(string $topology): void
    {
        $connection = $this->connect($topology, ['w', 'out']);
        $other      = $this->connect($topology, [], $this->prefixOf($connection));

        $connection->command('set', ['w', '1']);
        $connection->command('watch', ['w']);
        $other->command('set', ['w', '2']);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'aborted')));
        self::assertFalse($connection->client()->get($this->prefixOf($connection) . 'out'));

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'unwatched')));

        $connection->command('watch', ['w']);
        $physical = $this->physical($topology);

        $physical->command('set', ['w', 'unprefixed']);

        self::assertSame([true, 'watched'], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'watched')->get('out')));

        $physical->command('del', ['w']);
    }

    /**
     * Verify a transaction refuses EVAL, which GLIDE would run outside the
     * MULTI block, before the script runs, whether or not it follows a buffered
     * command, and discards what it buffered.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function transactionWithEvalThrowsAndWritesNothing(string $topology): void
    {
        $connection = $this->connect($topology, ['e', 'w', 'a']);
        $prefix     = $this->prefixOf($connection);
        $script     = 'return redis.call(\'set\', KEYS[1], \'ran\')';

        $transactions = [
            'script first'          => static fn (ValkeyGlideBatch $tx) => $tx->eval($script, 1, 'w'),
            'script after a buffer' => static fn (ValkeyGlideBatch $tx) => $tx->set('e', 'never')->eval($script, 1, 'w'),
        ];

        foreach ($transactions as $label => $transaction) {
            try {
                $connection->transaction($transaction);

                self::fail(sprintf('Expected EVAL inside a transaction to be refused: %s.', $label));
            } catch (\LogicException $exception) {
                self::assertStringContainsString('[EVAL] cannot run inside a transaction', $exception->getMessage(), $label);
            }

            self::assertFalse($connection->client()->get($prefix . 'e'), $label);
            self::assertFalse($connection->client()->get($prefix . 'w'), $label);
        }

        self::assertTrue($connection->command('set', ['a', 'usable']));
        self::assertSame('usable', $connection->get('a'));
    }

    /**
     * Verify EVAL inside a pipeline runs in call order: what was buffered
     * before it is sent first, and its result takes its place.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function pipelineWithEvalRunsCommandsInCallOrder(string $topology): void
    {
        $connection = $this->connect($topology, ['o']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('o', 'old')
                ->eval(self::GET_SCRIPT, 1, 'o')
                ->set('o', 'new')
                ->get('o');
        });

        self::assertSame([true, 'old', true, 'new'], $results);
    }

    /**
     * Verify phpredis-style SET options buffer in call order rather than run
     * ahead of the commands buffered before them, in a pipeline, which sends
     * them in one exec(), and in a transaction, which refuses a SET it cannot
     * buffer.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function phpredisStyleSetOptionsBufferInCallOrder(string $topology): void
    {
        $connection = $this->connect($topology, ['lock']);
        $executed   = $this->recordExecutedCommands($connection);

        foreach (['pipeline', 'transaction'] as $kind) {
            $results = $this->runBatch($connection, $kind, static function (ValkeyGlideBatch $batch): void {
                $batch->get('lock');
                $batch->set('lock', 'owner', 'EX', 60, 'NX');
                $batch->set('lock', 'other', 'ex', '60', 'nx');
                $batch->get('lock');
                $batch->ttl('lock');
            });

            self::assertSame([false, true, false, 'owner', 60], $results, $kind);
            self::assertSame([$kind], $executed->getArrayCopy(), $kind);

            $connection->command('del', ['lock']);
            $executed->exchangeArray([]);
        }
    }

    /**
     * Verify a batch on a closed client throws rather than silently running its
     * commands one by one.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function batchOnClosedClientThrowsConnectionException(string $topology): void
    {
        $connection = $this->connect($topology, []);

        $connection->client()->close();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('GLIDE refused batch mode');

        $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->set('a', '1'));
    }

    /**
     * Verify the commands a batch cannot carry, and arguments GLIDE rejects
     * without buffering, throw before anything is sent, and leave the
     * connection usable.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function unsupportedBatchCommandsThrowBeforeAnythingIsSent(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'z']);
        $attempts   = [
            'ZSCAN'     => [\LogicException::class, static fn () => $connection->command('zscan', ['z', null])],
            'WATCH'     => [\LogicException::class, static fn () => $connection->command('watch', ['a'])],
            'SELECT'    => [\LogicException::class, static fn () => $connection->command('select', [1])],
            'SUBSCRIBE' => [\LogicException::class, static fn () => $connection->command('subscribe', [['channel']])],
            'GET'       => [\LogicException::class, static fn () => $connection->get('a')],
            'EXEC'      => [\LogicException::class, static fn (ValkeyGlideBatch $pipe) => $pipe->exec()],
            'ZADD'      => [\InvalidArgumentException::class, static fn (ValkeyGlideBatch $pipe) => $pipe->zadd('z', 'NX', 1, 'm')],
        ];

        foreach ($attempts as $command => [$expected, $attempt]) {
            try {
                $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($attempt): void {
                    $pipe->set('a', 'never');
                    $attempt($pipe);
                });

                self::fail(sprintf('Expected %s inside a pipeline to be refused.', $command));
            } catch (\Throwable $exception) {
                self::assertInstanceOf($expected, $exception, $command);
            }

            self::assertFalse($connection->client()->get($this->prefixOf($connection) . 'a'), $command);
        }

        self::assertTrue($connection->command('set', ['a', 'usable']));
        self::assertSame('usable', $connection->get('a'));
    }

    /**
     * Verify a command that fails on the server gives false in its own place,
     * in a pipeline and in a transaction, while the others still apply.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function serverErrorsGiveFalseInTheirOwnPlace(string $topology): void
    {
        $connection = $this->connect($topology, ['s', 'p', 't']);

        $connection->command('set', ['s', 'a string']);

        self::assertSame([false, true], $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->lpush('s', 'x')->set('p', 'applied')));
        self::assertSame([false, true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->lpush('s', 'x')->set('t', 'applied')));
        self::assertSame('applied', $connection->get('p'));
        self::assertSame('applied', $connection->get('t'));
    }

    /**
     * Verify a large pipeline returns every result in order, with its keys
     * spread over the cluster's slots, which GLIDE splits across the nodes.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function largePipelineReturnsEveryResult(string $topology): void
    {
        $keys       = array_map(static fn (int $index): string => 'large:' . $index, range(0, 2499));
        $connection = $this->connect($topology, $keys, $this->uniquePrefix(false));

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($keys): void {
            foreach ($keys as $index => $key) {
                $pipe->set($key, (string) $index);
                $pipe->get($key);
            }
        });

        self::assertIsArray($results);
        self::assertCount(5000, $results);

        foreach ($keys as $index => $key) {
            self::assertTrue($results[$index * 2], sprintf('SET [%s] must succeed.', $key));
            self::assertSame((string) $index, $results[$index * 2 + 1], sprintf('GET [%s] must return its value.', $key));
        }
    }

    /**
     * Verify a listener that throws when it hears of a batch's exec() leaves
     * the connection out of batch mode and no longer watching.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function throwingBatchListenerLeavesTheConnectionUsable(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'w', 'out']);
        $other      = $this->connect($topology, [], $this->prefixOf($connection));
        $dispatcher = new Dispatcher;

        $dispatcher->listen([CommandExecuted::class, CommandFailed::class], static function (CommandExecuted|CommandFailed $event): void {
            if (in_array($event->command, ['pipeline', 'transaction'], true)) {
                throw new \DomainException(sprintf('listener failed for %s %s', $event instanceof CommandFailed ? 'failed' : 'executed', $event->command));
            }
        });

        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->set('a', '1'));
            self::fail('Expected the listener exception to be thrown.');
        } catch (\DomainException $exception) {
            self::assertSame('listener failed for executed pipeline', $exception->getMessage());
        }

        self::assertTrue($connection->command('set', ['a', '2']));
        self::assertSame('2', $connection->get('a'));
        self::assertFalse($connection->discard());

        $connection->command('watch', ['w']);
        $other->command('set', ['w', 'changed']);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'aborted'));
            self::fail('Expected the listener exception to be thrown.');
        } catch (\DomainException $exception) {
            self::assertSame('listener failed for failed transaction', $exception->getMessage());
        }

        self::assertNull($connection->get('out'));

        $dispatcher->forget(CommandExecuted::class);
        $other->command('set', ['w', 'changed again']);

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'committed')));
        self::assertSame('committed', $connection->get('out'));
    }

    /**
     * Verify fluent nested batches follow phpredis: a transaction opened in a
     * pipeline runs at its own exec(), which returns the pipeline, and a nested
     * pipeline changes nothing.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function fluentNestedBatchesFollowPhpredis(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'b']);
        $outer      = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $outer);

        $outer->set('a', '1');

        $inner = $connection->transaction();

        self::assertInstanceOf(ValkeyGlideBatch::class, $inner);
        self::assertSame($outer, $inner->incr('a')->exec());
        self::assertSame([true, [2], '2'], $outer->get('a')->exec());

        $pipeline = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $pipeline);
        self::assertSame($pipeline, $pipeline->set('b', '1')->pipeline());
        self::assertSame([true], $pipeline->exec());
        self::assertSame('1', $connection->client()->get($this->prefixOf($connection) . 'b'));
    }

    /**
     * Verify a nested callback that throws inside a fluent batch discards the
     * whole batch, so nothing it buffered is ever written.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function failedNestedCallbackDiscardsTheFluentBatch(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'b']);
        $batch      = $connection->pipeline();

        self::assertInstanceOf(ValkeyGlideBatch::class, $batch);

        $batch->set('a', '1');

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
                $pipe->set('b', '2');

                throw new \DomainException('nested failure');
            });
            self::fail('Expected the nested callback exception to be rethrown.');
        } catch (\DomainException) {
            // The whole fluent batch is discarded.
        }

        self::assertFalse($connection->client()->get($this->prefixOf($connection) . 'a'));
        self::assertFalse($connection->client()->get($this->prefixOf($connection) . 'b'));
        self::assertTrue($connection->command('set', ['a', 'usable']));
        self::assertSame('usable', $connection->get('a'));
    }

    /**
     * Verify a bare multi(\ValkeyGlide::PIPELINE) starts a pipeline, so on a
     * cluster its keys may span slots.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function bareMultiInPipelineModeRunsAPipeline(string $topology): void
    {
        $keys       = ['m1', 'm2', 'm3', 'm4', 'm5'];
        $connection = $this->connect($topology, $keys, $this->uniquePrefix(false));

        $connection->command('multi', [\ValkeyGlide::PIPELINE]);

        foreach ($keys as $key) {
            $connection->command('set', [$key, 'v-' . $key]);
        }

        $connection->command('mget', [$keys]);

        self::assertSame([true, true, true, true, true, ['v-m1', 'v-m2', 'v-m3', 'v-m4', 'v-m5']], $connection->exec());
    }

    /**
     * Verify a SET with a single phpredis-style flag is buffered, which GLIDE
     * refuses in that form, and behaves as the flag asks, in a pipeline and in
     * a transaction.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function singleSetFlagsBufferInAPipeline(string $topology): void
    {
        $connection = $this->connect($topology, ['k']);
        $executed   = $this->recordExecutedCommands($connection);

        foreach (['pipeline', 'transaction'] as $kind) {
            $results = $this->runBatch($connection, $kind, static function (ValkeyGlideBatch $batch): void {
                $batch->set('k', 'v', 'NX');
                $batch->set('k', 'w', 'nx');
                $batch->set('k', 'x', 'GET');
                $batch->expire('k', 100);
                $batch->set('k', 'y', 'KEEPTTL');
                $batch->get('k');
                $batch->ttl('k');
            });

            self::assertIsArray($results, $kind);
            self::assertSame([true, false, 'v', true, true, 'y'], array_slice($results, 0, 6), $kind);
            self::assertGreaterThan(90, $results[6], $kind);
            self::assertSame([$kind], $executed->getArrayCopy(), $kind);

            $connection->command('del', ['k']);
            $executed->exchangeArray([]);
        }
    }

    /**
     * Verify the static client-local methods of the standalone client, which
     * GLIDE never buffers, keep the results in call order.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clientLocalMethodsKeepResultsInCallOrder(): void
    {
        $connection = $this->connect(self::STANDALONE, ['k']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('k', 'v');
            $pipe->__call('getOtelSamplePercentage', []);
            $pipe->get('k');
        });

        self::assertSame([true, null, 'v'], $results);
        self::assertSame([null], $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->__call('getOtelSamplePercentage', [])));
    }

    /**
     * Verify a client already put in batch mode outside the driver, which GLIDE
     * answers with null, is reported and nothing is written.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function clientAlreadyInBatchModeIsReported(string $topology): void
    {
        $connection = $this->connect($topology, ['a']);
        $client     = $connection->client();

        self::assertSame($client, $client->multi());

        try {
            $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->set('a', '1'));
            self::fail('Expected the refused batch mode to be reported.');
        } catch (ConnectionException $exception) {
            self::assertSame('Unable to start the pipeline; GLIDE refused batch mode, so the connection may be closed.', $exception->getMessage());
        }

        self::assertFalse($client->discard());
        self::assertFalse($client->get($this->prefixOf($connection) . 'a'));
        self::assertTrue($connection->command('set', ['a', 'usable']));
        self::assertSame('usable', $connection->get('a'));
    }

    /**
     * Verify a bare multi() on a closed client, as RedisStore::putMany() would
     * issue, reports the refused batch mode and leaves no batch behind.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function bareMultiOnClosedClientLeavesNoBatch(string $topology): void
    {
        $connection = $this->connect($topology, []);
        $other      = $this->connect($topology, ['k'], $this->prefixOf($connection));

        $connection->client()->close();
        $connection->command('multi');

        try {
            $connection->command('setex', ['k', 60, 'v']);
            self::fail('Expected the refused batch mode to be reported.');
        } catch (ConnectionException $exception) {
            self::assertSame('Unable to start the transaction; GLIDE refused batch mode, so the connection may be closed.', $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertNull($other->get('k'));
    }

    /**
     * Verify a watched transaction whose callback throws clears the WATCH on
     * the server, so a later change to the key does not abort the next
     * transaction.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function throwingWatchedTransactionClearsTheWatch(string $topology): void
    {
        $connection = $this->connect($topology, ['w', 'out']);
        $other      = $this->connect($topology, [], $this->prefixOf($connection));

        $connection->command('watch', ['w']);

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx): void {
                $tx->set('out', 'never');

                throw new \DomainException('callback failed');
            });
            self::fail('Expected the callback exception to be rethrown.');
        } catch (\DomainException) {
            // The transaction is discarded and the WATCH cleared.
        }

        $other->command('set', ['w', 'changed']);

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'committed')));
        self::assertSame('committed', $connection->get('out'));
    }

    /**
     * Verify the keys of an MSET map are prefixed, inside and outside a batch.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function msetMapKeysUsePhysicalPrefixedKeys(string $topology): void
    {
        [$m1, $m2, $m3] = array_values($this->uniqueKeys(['m1', 'm2', 'm3']));

        $connection = $this->connect($topology, [$m1, $m2, $m3]);
        $prefix     = $this->prefixOf($connection);
        $physical   = $this->connect($topology, [$m1, $m2, $m3], '');

        self::assertTrue($connection->command('mset', [[$m1 => '1', $m2 => '2']]));
        self::assertSame([true, ['1', '2', '3']], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->mset([$m3 => '3'])->mget([$m1, $m2, $m3])));
        self::assertSame(['1', '2', '3'], $physical->command('mget', [[$prefix . $m1, $prefix . $m2, $prefix . $m3]]));
        self::assertSame(0, $physical->command('exists', [$m1, $m2, $m3]));
    }

    /**
     * Verify keyless cluster commands given their route run in call order in a
     * pipeline, with their real results.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterRoutedKeylessCommandsRunInAPipeline(): void
    {
        $connection = $this->connect(self::CLUSTER, ['a']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('a', 'v');
            $pipe->ping('randomNode');
            $pipe->echo('randomNode', 'hi');
            $pipe->dbsize('allPrimaries');
            $pipe->get('a');
        });

        self::assertIsArray($results);
        self::assertSame([true, true, 'hi'], array_slice($results, 0, 3));
        self::assertIsInt($results[3]);
        self::assertGreaterThanOrEqual(1, $results[3]);
        self::assertSame('v', $results[4]);
    }

    /**
     * Verify the client-side CRC16 matches the server's CLUSTER KEYSLOT,
     * including hash-tag rules.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterKeySlotsMatchTheServer(): void
    {
        $connection = $this->connect(self::CLUSTER, []);
        $client     = $connection->client();
        $router     = new ClusterRouter;

        self::assertInstanceOf(\ValkeyGlideCluster::class, $client);

        $keys = [
            '123456789',
            '{user1000}.following',
            '{user1000}.followers',
            'foo{}{bar}',
            'foo{{bar}}zap',
            'foo{bar}{zap}',
            'a',
            'horizon:recent_jobs',
            '{horizon:}recent_jobs',
            'user:é',
        ];

        foreach ($keys as $key) {
            self::assertSame($client->rawcommand('randomNode', 'CLUSTER', 'KEYSLOT', $key), $router->slot($key), sprintf('Slot of [%s].', $key));
        }

        self::assertSame(12739, $router->slot('123456789'));
    }

    /**
     * Verify a cluster pipeline whose keys span many slots and every primary
     * keeps its results in call order.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterPipelineAcrossSlotsKeepsCallOrder(): void
    {
        $owners = $this->slotOwners();
        $prefix = $this->uniquePrefix(false);
        $router = new ClusterRouter;
        $keys   = [];
        $used   = [];

        for ($index = 1; $index <= 1000 && (count($keys) < 20 || count($used) < count(array_unique($owners))); $index++) {
            $keys[]                                                     = 'spread:' . $index;
            $used[$owners[$router->slot($prefix . 'spread:' . $index)]] = true;
        }

        $connection = $this->connect(self::CLUSTER, $keys, $prefix);
        $slots      = array_unique(array_map(static fn (string $key): int => $router->slot($prefix . $key), $keys));

        self::assertGreaterThan(10, count($slots));
        self::assertGreaterThan(1, count($used));
        self::assertCount(count(array_unique($owners)), $used, 'Every primary must own one of the keys.');

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->set($key, 'v-' . $key);
            }

            foreach ($keys as $key) {
                $pipe->get($key);
            }

            $pipe->mget($keys);
            $pipe->del(...$keys);
        });

        $values = array_map(static fn (string $key): string => 'v-' . $key, $keys);

        self::assertSame([...array_fill(0, count($keys), true), ...$values, $values, count($keys)], $results);
    }

    /**
     * Verify a cluster transaction whose keys span slots throws before anything
     * is sent, and leaves the connection usable.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterCrossSlotTransactionThrowsBeforeSending(): void
    {
        $connection = $this->connect(self::CLUSTER, ['a', 'b'], $this->uniquePrefix(false));
        $prefix     = $this->prefixOf($connection);
        $router     = new ClusterRouter;

        self::assertNotSame($router->slot($prefix . 'a'), $router->slot($prefix . 'b'));

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('a', '1')->set('b', '2'));

            self::fail('Expected the cross-slot transaction to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('[SET] key [' . $prefix . 'b]', $exception->getMessage());
        }

        self::assertFalse($connection->client()->get($prefix . 'a'));
        self::assertFalse($connection->client()->get($prefix . 'b'));
        self::assertTrue($connection->command('set', ['a', 'usable']));
        self::assertSame('usable', $connection->get('a'));
    }

    /**
     * Verify a cluster transaction over several hash-tagged keys commits, with
     * multi-key commands.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterSameHashTagTransactionCommits(): void
    {
        $connection = $this->connect(self::CLUSTER, ['{t}a', '{t}b'], $this->uniquePrefix(false));

        $results = $connection->transaction(static function (ValkeyGlideBatch $tx): void {
            $tx->set('{t}a', '1')
                ->set('{t}b', '2')
                ->mget(['{t}a', '{t}b'])
                ->exists('{t}a', '{t}b')
                ->del('{t}a', '{t}b');
        });

        self::assertSame([true, true, ['1', '2'], 2, 2], $results);
    }

    /**
     * Verify a cluster transaction refuses a watched key outside its slot,
     * where the WATCH would be silently ignored, and unwatches.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterTransactionRefusesWatchedKeyInAnotherSlot(): void
    {
        $connection = $this->connect(self::CLUSTER, ['{x}w', '{y}k'], $this->uniquePrefix(false));
        $other      = $this->connect(self::CLUSTER, [], $this->prefixOf($connection));

        $connection->command('watch', ['{x}w']);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('{y}k', 'never'));

            self::fail('Expected the transaction outside the watched slot to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('must keep every key in the hash slot of the keys it watches', $exception->getMessage());
            self::assertStringContainsString('of the watched keys [' . $this->prefixOf($connection) . '{x}w]', $exception->getMessage());
        }

        self::assertFalse($connection->client()->get($this->prefixOf($connection) . '{y}k'));

        $other->command('set', ['{x}w', 'changed']);

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('{x}w', 'mine')));
        self::assertSame('mine', $connection->get('{x}w'));
    }

    /**
     * Verify a watched transaction nested in a pipeline is guarded by the
     * WATCH: a change to a watched key aborts it, its place in the results is
     * an empty list, and the pipeline around it still applies.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function watchedTransactionNestedInAPipelineIsGuarded(string $topology): void
    {
        $connection = $this->connect($topology, ['{w}w', '{w}z', 'p']);
        $other      = $this->connect($topology, [], $this->prefixOf($connection));

        $connection->command('watch', ['{w}w']);
        $other->command('set', ['{w}w', 'changed']);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection): void {
            $pipe->set('p', 'applied');
            $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('{w}z', 'written'));
            $pipe->get('p');
        });

        self::assertSame([true, [], 'applied'], $results);
        self::assertNull($other->get('{w}z'));
        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('{w}z', 'unwatched')));
        self::assertSame('unwatched', $other->get('{w}z'));
    }

    /**
     * Verify a watched cluster transaction refuses a command whose keys the
     * driver does not know, which GLIDE would send to a node where the WATCH is
     * ignored, and unwatches.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterWatchedTransactionRefusesCommandsWithUnknownKeys(): void
    {
        $connection = $this->connect(self::CLUSTER, ['{a}k'], $this->uniquePrefix(false));
        $physical   = $this->physical(self::CLUSTER);
        $other      = $this->connect(self::CLUSTER, [], $this->prefixOf($connection));
        $target     = '{b' . str_replace('.', '', uniqid('', true)) . '}:doc';

        $connection->command('set', ['{a}k', '1']);
        $connection->command('watch', ['{a}k']);
        $other->command('set', ['{a}k', '2']);

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->__call('jsonSet', [$target, '$', '1']));
            self::fail('Expected the command with unknown keys to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('cannot carry [JSONSET]', $exception->getMessage());
        }

        self::assertSame(0, $physical->command('exists', [$target]));
        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('{a}k', 'mine')));
        self::assertSame('mine', $connection->get('{a}k'));
    }

    /**
     * Verify SET expiries that are not positive integers are sent as they are
     * in a batch, so the server rejects them there as it does outside one,
     * while a string of digits still buffers, in a pipeline and in a
     * transaction.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function setExpiriesAreJudgedAsOutsideABatch(string $topology): void
    {
        $connection = $this->connect($topology, ['direct', 'float', 'decimal', 'exponent', 'digits']);

        self::assertFalse($connection->command('set', ['direct', 'v', 'EX', 1.9, 'NX']));

        $executed = $this->recordExecutedCommands($connection);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('float', 'v', 'EX', 1.9, 'NX');
            $pipe->set('decimal', 'v', 'EX', '7.5');
            $pipe->set('exponent', 'v', 'EX', '1e3');
            $pipe->set('digits', 'v', 'EX', '60');
            $pipe->ttl('digits');
        });

        self::assertSame([false, false, false, true, 60], $results);
        self::assertSame(['set', 'set', 'set', 'pipeline'], $executed->getArrayCopy());

        foreach (['direct', 'float', 'decimal', 'exponent'] as $key) {
            self::assertSame(-2, $connection->command('pttl', [$key]), $key);
        }

        $connection->command('del', ['digits']);
        $executed->exchangeArray([]);

        self::assertSame([true, 60], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('digits', 'v', 'EX', '60')->ttl('digits')));
        self::assertSame(['transaction'], $executed->getArrayCopy());
    }

    /**
     * Verify integer keys in a key list work on an unprefixed connection, in a
     * batch and for WATCH, as GLIDE takes them in the list form.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function integerKeyListsWorkOnAnUnprefixedConnection(string $topology): void
    {
        $connection       = $this->physical($topology);
        $other            = $this->physical($topology);
        $key              = random_int(100000000, 999999999);
        $this->cleanups[] = [$connection, [(string) $key, (string) ($key + 1), (string) ($key + 2)]];

        $connection->command('set', [(string) $key, 'a']);
        $connection->command('set', [(string) ($key + 1), 'b']);
        $connection->command('set', [(string) ($key + 2), 'c']);

        self::assertSame([2, 1], $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->del([$key, $key + 1])->unlink([$key + 2])));

        $connection->command('set', [(string) $key, 'watched']);

        self::assertTrue($connection->command('watch', [[$key]]));

        $other->command('set', [(string) $key, 'changed']);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set((string) $key, 'mine')));
        self::assertSame('changed', $connection->get((string) $key));
    }

    /**
     * Verify a phpredis-style SET with a single flag behaves the same outside a
     * batch as inside one.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function singleSetFlagsWorkOutsideABatch(string $topology): void
    {
        $connection = $this->connect($topology, ['k']);

        self::assertTrue($connection->command('set', ['k', 'v', 'NX']));
        self::assertFalse($connection->command('set', ['k', 'w', 'NX']));
        self::assertSame('v', $connection->command('set', ['k', 'x', 'GET']));
        self::assertSame('x', $connection->get('k'));
    }

    /**
     * Verify pipeline(), transaction() and executeRaw() called on the batch
     * keep their commands and results in the pipeline.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function batchMethodsJoinTheBatch(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'b', 'c']);
        $prefix     = $this->prefixOf($connection);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($prefix): void {
            $pipe->set('a', '1');
            $pipe->pipeline(static fn (ValkeyGlideBatch $inner) => $inner->set('b', '2'));
            $pipe->transaction(static fn (ValkeyGlideBatch $inner) => $inner->set('c', '3'));
            $pipe->executeRaw(['GET', $prefix . 'b']);
            $pipe->get('c');
        });

        self::assertSame([true, true, [true], '2', '3'], $results);
    }

    /**
     * Verify disconnect() ends the active batch, so nothing it buffered is sent
     * and the batch is refused afterwards.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function disconnectEndsTheActiveBatch(string $topology): void
    {
        $connection = $this->connect($topology, []);
        $other      = $this->connect($topology, ['a', 'b'], $this->prefixOf($connection));
        $batch      = $connection->pipeline()->set('a', '1');

        $connection->disconnect();

        try {
            $batch->set('b', '2');
            self::fail('Expected the ended batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame('This pipeline or transaction has already been executed or discarded.', $exception->getMessage());
        }

        self::assertFalse($connection->exec());
        self::assertNull($other->get('a'));
        self::assertNull($other->get('b'));
    }

    /**
     * Verify zrem() and zmscore() without a member, which GLIDE reports
     * buffered without buffering, are refused before anything is sent, so no
     * batch fails, or reads as aborted, after the server applied it.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function unbufferedCallsAreRefusedBeforeAnythingIsSent(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'b', 'z', 'w']);
        $members    = [];

        self::assertRefusedOutsideABatch('ZREM', static fn (): mixed => $connection->command('zrem', ['z', ...$members]));

        $callback = static function (ValkeyGlideBatch $batch) use ($members): void {
            $batch->set('a', '1');
            $batch->zrem('z', ...$members);
            $batch->set('b', '1');
        };

        $batches = [
            'pipeline'    => static fn (): mixed => $connection->pipeline($callback),
            'transaction' => static fn (): mixed => $connection->transaction($callback),
            'watched'     => static function () use ($connection): mixed {
                $connection->command('watch', ['w']);

                return $connection->transaction(static function (ValkeyGlideBatch $tx) use ($connection): void {
                    $tx->set('a', '1');
                    $connection->command('zmscore', ['z']);
                    $tx->set('b', '1');
                });
            },
        ];

        foreach ($batches as $label => $run) {
            try {
                $run();
                self::fail(sprintf('Expected the %s to refuse the call.', $label));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringStartsWith('GLIDE would not buffer', $exception->getMessage(), $label);
            }

            self::assertNull($connection->get('a'), $label);
            self::assertNull($connection->get('b'), $label);
        }

        // A WATCH the refused transaction left on the server would abort this
        // transaction, as another connection has changed the watched key.
        $this->connect($topology, [], $this->prefixOf($connection))->command('set', ['w', 'changed']);

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('a', '2')));
    }

    /**
     * Verify zinter() with an empty key list, which GLIDE reports buffered
     * without buffering, and del() or unlink() with one, which GLIDE refuses,
     * are refused before anything is sent, so no batch fails, or reads as
     * aborted, after the server applied it.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function emptyKeyListsAreRefusedBeforeAnythingIsSent(string $topology): void
    {
        $connection = $this->connect($topology, ['n', 'x', 'bal']);

        self::assertFalse($connection->command('zinter', [[]]));
        self::assertRefusedOutsideABatch('DEL', static fn (): mixed => $connection->command('del', [[]]));
        self::assertRefusedOutsideABatch('UNLINK', static fn (): mixed => $connection->command('unlink', [[]]));

        $connection->command('set', ['bal', '100']);

        $batches = [
            'zinter in a pipeline' => [
                'GLIDE would not buffer [ZINTER]',
                static fn (): mixed => $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->incr('n')->zinter([])->get('n')),
            ],
            'zinter in a watched transaction' => [
                'GLIDE would not buffer [ZINTER]',
                static function () use ($connection): mixed {
                    $connection->command('watch', ['bal']);

                    return $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->decrby('bal', 10)->zinter([]));
                },
            ],
            'del in a pipeline' => [
                'GLIDE refused to buffer [DEL]',
                static fn (): mixed => $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->set('x', '1')->del([])->get('x')),
            ],
            'unlink in a transaction' => [
                'GLIDE refused to buffer [UNLINK]',
                static fn (): mixed => $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('x', '1')->unlink([])),
            ],
        ];

        foreach ($batches as $label => [$message, $run]) {
            try {
                $run();
                self::fail(sprintf('Expected the %s to be refused.', $label));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringStartsWith($message, $exception->getMessage(), $label);
            }

            self::assertNull($connection->get('n'), $label);
            self::assertNull($connection->get('x'), $label);
            self::assertSame('100', $connection->get('bal'), $label);
        }

        self::assertSame([90], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->decrby('bal', 10)));
    }

    /**
     * Verify a command GLIDE buffers and then throws for ends the batch, so a
     * caller that catches the error cannot send what the batch held.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function commandThatThrowsAfterBufferingEndsTheBatch(string $topology): void
    {
        $connection = $this->connect($topology, ['f', 's1']);
        $pipe       = $connection->pipeline();

        $pipe->set('f', '1');

        try {
            $pipe->sinter('s1');
            self::fail('Expected GLIDE to refuse a key that is not a list.');
        } catch (\TypeError) {
            // GLIDE 1.1.2 buffers the command, then throws.
        }

        try {
            $pipe->get('f');
            self::fail('Expected the ended batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertSame('This pipeline or transaction has already been executed or discarded.', $exception->getMessage());
        }

        self::assertNull($connection->get('f'));
        self::assertSame([true, '2'], $connection->pipeline(static fn (ValkeyGlideBatch $batch) => $batch->set('f', '2')->get('f')));
    }

    /**
     * Verify the key lists of TOUCH and SINTER are prefixed inside and outside
     * a batch, and give a watched cluster transaction their slot.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function touchAndSinterKeyListsUsePhysicalPrefixedKeys(string $topology): void
    {
        $connection = $this->connect($topology, ['t1', 's1', 's2']);
        $prefix     = $this->prefixOf($connection);
        $physical   = $this->physical($topology);

        $connection->command('set', ['t1', '1']);
        $connection->command('sadd', ['s1', 'a']);
        $connection->command('sadd', ['s2', 'a', 'b']);

        self::assertSame(1, $physical->command('exists', [$prefix . 't1']));
        self::assertSame(1, $connection->command('touch', [['t1']]));
        self::assertSame(['a'], $connection->command('sinter', [['s1', 's2']]));
        self::assertSame(['a'], $connection->command('sinter', [['s1']]));
        self::assertSame([1, ['a']], $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->touch(['t1'])->sinter(['s1', 's2'])));

        $connection->command('watch', ['s1']);

        self::assertSame([['a'], 1], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->sinter(['s1', 's2'])->touch(['t1'])));
    }

    /**
     * Verify a command a listener of an immediate command runs on the same
     * connection runs outside the pipeline, as after the pipeline's final send,
     * so its result takes no place in the pipeline's results.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function commandRunByAListenerOfAnImmediateCommandRunsOutsideThePipeline(string $topology): void
    {
        $connection = $this->connect($topology, ['a']);
        $dispatcher = new Dispatcher;
        $ran        = false;
        $result     = null;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($connection, &$ran, &$result): void {
            if ($event->command !== 'eval' || $ran) {
                return;
            }

            $ran    = true;
            $result = $connection->command('eval', ['return 2', 0]);
        });

        $connection->setEventDispatcher($dispatcher);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('a', '1');
            $pipe->eval('return 1', 0);
            $pipe->get('a');
        });

        self::assertSame([true, 1, '1'], $results);
        self::assertSame(2, $result);
    }

    /**
     * Verify a command a listener runs once part of a pipeline is sent, as a
     * script waits to run, runs before the script and outside the pipeline, so
     * the script sees its write and its result takes no place in the list.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function commandRunByAFlushListenerRunsBeforeTheScriptOutsideThePipeline(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'n']);
        $heard      = [];

        $this->onFirstFlush($connection, static function () use ($connection, &$heard): void {
            $heard[] = $connection->command('incr', ['n']);
        });

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('a', '1');
            $pipe->eval(self::GET_SCRIPT, 1, 'n');
            $pipe->get('a');
        });

        self::assertSame([true, '1', '1'], $results);
        self::assertSame([1], $heard);
        self::assertSame('1', $connection->command('get', ['n']));
    }

    /**
     * Verify a command a listener runs once part of a cluster pipeline is sent,
     * as a keyless command waits to run, runs outside the pipeline, so the
     * keyless command runs outside GLIDE's batch mode, where it is not refused.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandRunByAFlushListenerRunsOutsideAClusterPipeline(): void
    {
        $connection = $this->connect(self::CLUSTER, ['a', 'n']);
        $heard      = [];

        $this->onFirstFlush($connection, static function () use ($connection, &$heard): void {
            $heard[] = $connection->command('incr', ['n']);
        });

        $results = $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '1')->ping('randomNode')->get('a'));

        self::assertSame([true, true, '1'], $results);
        self::assertSame([1], $heard);
        self::assertSame('1', $connection->command('get', ['n']));
    }

    /**
     * Verify a listener that writes on every command it hears of, skipping its
     * own write, stops in a pipeline that runs a script, as it does outside a
     * batch, and sends each of its writes once.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function guardedListenerThatWritesOnEveryCommandStopsInAPipeline(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'stats']);
        $dispatcher = new Dispatcher;
        $heard      = [];

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$heard): void {
            $heard[] = $event->command;

            if ($event->command === 'hincrby') {
                return;
            }

            if (count($heard) > 50) {
                throw new \RuntimeException('The listener keeps hearing of its own writes.');
            }

            $event->connection->command('hincrby', ['stats', $event->command, 1]);
        });

        $connection->setEventDispatcher($dispatcher);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('a', '1');
            $pipe->eval('return 1', 0);
            $pipe->get('a');
        });

        self::assertSame([true, 1, '1'], $results);
        self::assertSame(['pipeline', 'hincrby', 'eval', 'hincrby', 'pipeline', 'hincrby'], $heard);
        self::assertEquals(['pipeline' => '2', 'eval' => '1'], $this->physical($topology)->command('hgetall', [$this->prefixOf($connection) . 'stats']));
    }

    /**
     * Verify a listener can read with the connection's typed get() while part
     * of a pipeline is sent and while a script runs, as it can outside a batch.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function listenerReadsWithTypedGetWhileAScriptRuns(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'flag']);
        $dispatcher = new Dispatcher;
        $read       = [];

        self::assertTrue($connection->command('set', ['flag', 'on']));

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($connection, &$read): void {
            if ($event->command === 'get') {
                return;
            }

            $read[] = $connection->get('flag');
        });

        $connection->setEventDispatcher($dispatcher);

        $results = $connection->pipeline(static fn (ValkeyGlideBatch $pipe): ValkeyGlideBatch => $pipe->set('a', '2')->eval('return 1', 0));

        self::assertSame([true, 1], $results);
        self::assertSame(['on', 'on'], $read);
    }

    /**
     * Verify connection calls that a callback makes once its batch has ended
     * inside it are refused rather than run outside the batch, for a
     * transaction that refused a script and a pipeline that a nested callback
     * ended.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function connectionCallsAfterTheBatchEndedInsideItsCallbackAreRefused(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'b', 'n']);
        $other      = $this->connect($topology, [], $this->prefixOf($connection));
        $refusals   = [];

        $carryOn = static function () use ($connection, &$refusals): void {
            foreach ([static fn (): mixed => $connection->command('set', ['b', '2']), static fn (): mixed => $connection->command('incr', ['n'])] as $call) {
                try {
                    $call();
                } catch (\LogicException $exception) {
                    $refusals[] = $exception->getMessage();
                }
            }
        };

        $batches = [
            static fn (): mixed => $connection->transaction(static function (ValkeyGlideBatch $tx) use ($carryOn): void {
                $tx->set('a', '1');

                try {
                    $tx->eval('return 1', 0);
                } catch (\LogicException) {
                    // The transaction refuses the script and ends.
                }

                $carryOn();
            }),
            static fn (): mixed => $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($connection, $carryOn): void {
                $pipe->incr('n');

                try {
                    $connection->pipeline(static function (ValkeyGlideBatch $nested): never {
                        $nested->incr('n');

                        throw new \DomainException('nested failure');
                    });
                } catch (\DomainException) {
                    // The nested callback's exception ended the pipeline.
                }

                $carryOn();
            }),
        ];

        foreach ($batches as $batch) {
            try {
                $batch();
                self::fail('Expected the ended batch to be refused.');
            } catch (\LogicException $exception) {
                self::assertSame('The batch ended inside its callback, so it was not executed; any of its commands not yet sent were discarded.', $exception->getMessage());
            }
        }

        $ended = '[%s()] cannot be called once the pipeline or transaction has ended inside its callback; return or throw from the callback.';

        self::assertSame(array_merge(...array_fill(0, 2, [sprintf($ended, 'set'), sprintf($ended, 'incr')])), $refusals);
        self::assertNull($other->get('a'));
        self::assertNull($other->get('b'));
        self::assertNull($other->get('n'));
        self::assertFalse($connection->exec());
        self::assertSame(1, $connection->command('incr', ['n']));
    }

    /**
     * Verify a batch whose exec() GLIDE fails, here on a client closed after
     * the batch buffered a command, throws the failure the README documents,
     * and that a watched transaction returns false, with no batch left behind
     * in each case.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function failedExecReportsTheBatchFailure(string $topology): void
    {
        $pipelineFailed    = 'The pipeline failed; some of its commands may have been applied.';
        $transactionFailed = 'The transaction failed and its outcome is unknown; it may have been committed.';

        // Buffer a write, close the client under the batch, and, for a flush,
        // run a script that sends what the pipeline buffered first.
        $closing = static fn (ValkeyGlideConnection $connection, bool $flush): \Closure => static function (ValkeyGlideBatch $batch) use ($connection, $flush): void {
            $batch->set('a', '1');
            $connection->client()->close();

            if ($flush) {
                $batch->eval('return 1', 0);
            }
        };

        $cases = [
            'pipeline'    => [$pipelineFailed, static fn (ValkeyGlideConnection $connection): mixed => $connection->pipeline($closing($connection, false))],
            'transaction' => [$transactionFailed, static fn (ValkeyGlideConnection $connection): mixed => $connection->transaction($closing($connection, false))],
            'flush'       => [$pipelineFailed, static fn (ValkeyGlideConnection $connection): mixed => $connection->pipeline($closing($connection, true))],
            'watched'     => [null, static function (ValkeyGlideConnection $connection) use ($closing): mixed {
                $connection->command('watch', ['w']);

                return $connection->transaction($closing($connection, false));
            }],
        ];

        foreach ($cases as $name => [$message, $run]) {
            $connection = $this->connect($topology, []);
            $other      = $this->connect($topology, ['a', 'w'], $this->prefixOf($connection));

            try {
                self::assertFalse($run($connection), $name);
                self::assertNull($message, $name);
            } catch (ConnectionException $exception) {
                self::assertSame($message, $exception->getMessage(), $name);
            }

            self::assertFalse($connection->exec(), $name);
            self::assertFalse($connection->discard(), $name);
            self::assertNull($other->get('a'), $name);
        }
    }

    /**
     * Verify an unwatch() that GLIDE buffers, rather than sends, on a client
     * put in batch mode outside the driver returns null and leaves the keys
     * over, so the UNWATCH is sent again before the next transaction, which
     * commits although the key it no longer watches changed while the WATCH was
     * still on the server.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function unwatchBufferedOutsideTheDriverLeavesTheKeysOver(string $topology): void
    {
        $connection = $this->connect($topology, ['w', 'out']);
        $other      = $this->connect($topology, [], $this->prefixOf($connection));
        $client     = $connection->client();

        $connection->command('set', ['w', '1']);

        self::assertTrue($connection->command('watch', ['w']));

        $client->multi();

        self::assertNull($connection->command('unwatch'));
        self::assertTrue($client->discard());

        $other->command('set', ['w', '2']);

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'committed')));
        self::assertSame('committed', $other->command('get', ['out']));
    }

    /**
     * Verify a phpredis-style SET with two different expiries writes nothing in
     * a batch, as the server rejects it outside one.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function setWithTwoExpiriesWritesNothingAsOutsideABatch(string $topology): void
    {
        $connection = $this->connect($topology, ['direct', 'relative', 'unix']);
        $unix       = time() + 100;

        self::assertFalse($connection->command('set', ['direct', 'v', 'EX', 60, 'PX', 100000]));

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($unix): void {
            $pipe->set('relative', 'v', 'EX', 60, 'PX', 100000);
            $pipe->set('unix', 'v', 'PX', 5000, 'EXAT', $unix);
        });

        self::assertSame([false, false], $results);

        foreach (['direct', 'relative', 'unix'] as $key) {
            self::assertSame(-2, $connection->command('pttl', [$key]), $key);
        }
    }

    /**
     * Verify a phpredis-style SET stores the same value in a batch as outside
     * one, where rawcommand sends the value as PHP converts it to a string.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function convertedSetStoresTheValueAsOutsideABatch(string $topology): void
    {
        $values     = ['false' => false, 'float' => 1e20, 'stringable' => new \SimpleXMLElement('<root>node</root>')];
        $expected   = ['false' => '', 'float' => '1.0E+20', 'stringable' => 'node'];
        $connection = $this->connect($topology, [
            'direct-false', 'direct-float', 'direct-stringable',
            'piped-false', 'piped-float', 'piped-stringable',
            'atomic-false', 'atomic-float', 'atomic-stringable',
        ]);

        foreach ($values as $label => $value) {
            self::assertTrue($connection->command('set', ['direct-' . $label, $value, 'EX', 60]), $label);
        }

        $executed = $this->recordExecutedCommands($connection);

        foreach (['pipeline' => 'piped-', 'transaction' => 'atomic-'] as $kind => $prefix) {
            $results = $this->runBatch($connection, $kind, static function (ValkeyGlideBatch $batch) use ($values, $prefix): void {
                foreach ($values as $label => $value) {
                    $batch->set($prefix . $label, $value, 'EX', 60);
                }
            });

            self::assertSame([true, true, true], $results, $kind);
            self::assertSame([$kind], $executed->getArrayCopy(), $kind);

            $executed->exchangeArray([]);
        }

        foreach ($expected as $label => $value) {
            self::assertSame($value, $connection->get('direct-' . $label), $label);
            self::assertSame($value, $connection->get('piped-' . $label), $label);
            self::assertSame($value, $connection->get('atomic-' . $label), $label);
        }
    }

    /**
     * Verify discard() of a transaction opened in a pipeline drops that
     * transaction alone, and the pipeline goes on.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function discardOfANestedTransactionKeepsThePipeline(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'b']);
        $pipe       = $connection->pipeline();

        $pipe->set('a', '1');
        $connection->transaction()->set('b', '2');

        self::assertTrue($connection->discard());
        self::assertSame([true, '1'], $pipe->get('a')->exec());
        self::assertNull($connection->get('b'));
    }

    /**
     * Verify a cluster pipeline whose keys span every primary runs scripts, a
     * routed keyless command and a nested transaction in call order: what was
     * buffered is sent before each of them, and the pipeline starts GLIDE's
     * batch again after them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterPipelineAcrossSlotsRunsImmediateCommandsInCallOrder(): void
    {
        $owners = $this->slotOwners();
        $prefix = $this->uniquePrefix(false);
        $router = new ClusterRouter;
        $keys   = [];
        $slots  = [];
        $used   = [];

        for ($index = 1; $index <= 1000 && (count($keys) < 12 || count($used) < count(array_unique($owners))); $index++) {
            $slot = $router->slot($prefix . 'mixed:' . $index);

            if (array_key_exists($slot, $slots)) {
                continue;
            }

            $keys[]               = 'mixed:' . $index;
            $slots[$slot]         = true;
            $used[$owners[$slot]] = true;
        }

        self::assertCount(count(array_unique($owners)), $used, 'Every primary must own one of the keys.');

        $connection       = $this->connect(self::CLUSTER, [...$keys, 'n'], $prefix);
        $executed         = $this->recordExecutedCommands($connection);
        [$first, $second] = array_chunk($keys, (int) ceil(count($keys) / 2));
        $values           = array_map(static fn (string $key): string => 'v-' . $key, $keys);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe) use ($keys, $first, $second): void {
            foreach ($first as $key) {
                $pipe->set($key, 'v-' . $key);
            }

            $pipe->eval(self::GET_SCRIPT, 1, $first[0]);
            $pipe->ping('randomNode');

            foreach ($second as $key) {
                $pipe->set($key, 'v-' . $key);
            }

            $pipe->transaction(static fn (ValkeyGlideBatch $tx) => $tx->incr('n')->incr('n'));
            $pipe->eval(self::GET_SCRIPT, 1, $second[count($second) - 1]);

            foreach ($keys as $key) {
                $pipe->get($key);
            }

            $pipe->mget($keys);
            $pipe->del(...[...$keys, 'n']);
        });

        self::assertSame([
            ...array_fill(0, count($first), true),
            'v-' . $first[0],
            true,
            ...array_fill(0, count($second), true),
            [1, 2],
            'v-' . $second[count($second) - 1],
            ...$values,
            $values,
            count($keys) + 1,
        ], $results);
        self::assertSame(['pipeline', 'eval', 'ping', 'pipeline', 'transaction', 'eval', 'pipeline'], $executed->getArrayCopy());
    }

    /**
     * Verify a native SET options array whose expiry GLIDE refuses, such as
     * `['EX' => '60']`, writes nothing outside a batch, and inside one is
     * reported and leaves no slot, so the next command's result keeps its
     * place.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function nativeSetExpiryGlideRefusesLeavesNoSlot(string $topology): void
    {
        $connection = $this->connect($topology, ['k']);

        self::assertFalse($connection->command('set', ['k', 'v', ['EX' => '60']]));
        self::assertSame(-2, $connection->command('pttl', ['k']));

        $pipe = $connection->pipeline()->set('k', 'first');

        try {
            $pipe->set('k', 'v', ['EX' => '60']);
            self::fail('Expected GLIDE to refuse the expiry.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('GLIDE refused to buffer [SET], most likely due to its arguments, so it was not added to the batch.', $exception->getMessage());
        }

        self::assertSame([true, 'first', -1], $pipe->get('k')->ttl('k')->exec());

        try {
            $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('k', 'second')->set('k', 'v', ['EX' => 0]));
            self::fail('Expected GLIDE to refuse the expiry.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringStartsWith('GLIDE refused to buffer [SET]', $exception->getMessage());
        }

        self::assertSame('first', $connection->get('k'));
    }

    /**
     * Verify a key list beside other keys, or a list of key lists, is refused
     * before anything is sent, inside a batch and outside one, so neither the
     * key `Array` that GLIDE would send, which lies outside the prefix, nor any
     * prefixed key is touched, including the destination of a set-store
     * command, which GLIDE 1.1.2 would replace with the result of reading the
     * key `Array`.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function keyListsBesideOtherKeysAreRefusedBeforeAnythingIsSent(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'b', 'c']);
        $physical   = $this->connect($topology, ['Array'], '');
        $marker     = uniqid('someone-elses-data-', true);

        $physical->command('set', ['Array', $marker]);

        foreach (['a', 'b', 'c'] as $key) {
            $connection->command('set', [$key, 'v']);
        }

        $calls = [
            'del'                    => static fn (): mixed => $connection->command('del', ['a', ['b', 'c']]),
            'unlink'                 => static fn (): mixed => $connection->command('unlink', [['a'], 'b']),
            'exists'                 => static fn (): mixed => $connection->command('exists', ['a', ['b']]),
            'del in a pipeline'      => static fn (): mixed => $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->set('a', 'x')->del('a', ['b', 'c'])),
            'del in a transaction'   => static fn (): mixed => $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('a', 'x')->del('a', ['b', 'c'])),
            'touch in a transaction' => static fn (): mixed => $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->touch([['a'], 'b'])),
            'sinterstore'            => static fn (): mixed => $connection->command('sinterstore', ['a', ['b', 'c']]),
            'sunionstore in a pipe'  => static fn (): mixed => $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->__call('sunionstore', ['a', ['b', 'c']])),
            'sdiffstore in a tx'     => static fn (): mixed => $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->__call('sdiffstore', ['a', ['b', 'c']])),
            'bzpopmin'               => static fn (): mixed => $connection->command('bzpopmin', [['a'], 'b', 1]),
        ];

        foreach ($calls as $label => $call) {
            try {
                $call();
                self::fail(sprintf('Expected the %s to be refused.', $label));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('was given a list of keys in a form GLIDE does not take as keys', $exception->getMessage(), $label);
            }

            self::assertSame($marker, $physical->get('Array'), $label);

            foreach (['a', 'b', 'c'] as $key) {
                self::assertSame('v', $connection->get($key), sprintf('%s: %s', $label, $key));
            }
        }
    }

    /**
     * Assert a call GLIDE refuses outside a batch throws, as the command's
     * reply is never false, so GLIDE's false can only mean it was refused.
     *
     * @param  string  $command
     * @param  \Closure(): mixed  $call
     * @return void
     */
    private static function assertRefusedOutsideABatch(string $command, \Closure $call): void
    {
        try {
            $call();
            self::fail(sprintf('Expected the refused %s to throw.', $command));
        } catch (CommandException $exception) {
            self::assertStringContainsString($command, $exception->getMessage());
        }
    }

    /**
     * Create a connection for the topology and register its keys for cleanup.
     *
     * @param  string  $topology
     * @param  list<string>  $keys
     * @param  string|null  $prefix
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function connect(string $topology, array $keys, ?string $prefix = null): ValkeyGlideConnection
    {
        $prefix ??= $this->uniquePrefix(true);

        $connection = $this->connectTo($topology, $prefix);

        $this->cleanups[]                           = [$connection, $keys];
        $this->prefixes[spl_object_id($connection)] = $prefix;

        return $connection;
    }

    /**
     * Create an unprefixed connection to the topology, to read physical keys.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function physical(string $topology): ValkeyGlideConnection
    {
        return $this->connect($topology, [], '');
    }

    /**
     * Read the key prefix a connection was created with.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return string
     */
    private function prefixOf(ValkeyGlideConnection $connection): string
    {
        return $this->prefixes[spl_object_id($connection)];
    }

    /**
     * Run an action from the listener of the first pipeline flush on the
     * connection.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  \Closure(): mixed  $action
     * @return void
     */
    private function onFirstFlush(ValkeyGlideConnection $connection, \Closure $action): void
    {
        $dispatcher = new Dispatcher;
        $done       = false;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($action, &$done): void {
            if ($event->command !== 'pipeline' || $done) {
                return;
            }

            $done = true;

            $action();
        });

        $connection->setEventDispatcher($dispatcher);
    }

    /**
     * Run a pipeline or a transaction on the connection.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $kind
     * @param  callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed  $callback
     * @return array<int, mixed>|false|\SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function runBatch(ValkeyGlideConnection $connection, string $kind, callable $callback): array|false|ValkeyGlideBatch
    {
        return $kind === 'transaction' ? $connection->transaction($callback) : $connection->pipeline($callback);
    }

    /**
     * Record the command of every CommandExecuted event the connection
     * dispatches, in order.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return \ArrayObject<int, string>
     */
    private function recordExecutedCommands(ValkeyGlideConnection $connection): \ArrayObject
    {
        $commands   = new \ArrayObject;
        $dispatcher = new Dispatcher;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($commands): void {
            $commands->append($event->command);
        });

        $connection->setEventDispatcher($dispatcher);

        return $commands;
    }

    /**
     * Map every hash slot of the cluster to the address of the primary that
     * owns it.
     *
     * @return array<int, string>
     *
     * @throws \Throwable
     */
    private function slotOwners(): array
    {
        $client = $this->physical(self::CLUSTER)->client();

        self::assertInstanceOf(\ValkeyGlideCluster::class, $client);

        $ranges = $client->rawcommand('randomNode', 'CLUSTER', 'SLOTS');

        self::assertIsArray($ranges);

        $owners = [];

        foreach ($ranges as $range) {
            self::assertIsArray($range);
            self::assertIsArray($range[2]);

            $address = sprintf('%s:%s', $range[2][0], $range[2][1]);

            foreach (range($range[0], $range[1]) as $slot) {
                $owners[$slot] = $address;
            }
        }

        self::assertCount(16384, $owners);

        return $owners;
    }

    /**
     * Build a unique logical key name for each label, so a key the test checks
     * is absent unprefixed cannot exist already on a shared server.
     *
     * @param  list<string>  $labels
     * @return array<string, string>
     */
    private function uniqueKeys(array $labels): array
    {
        $entropy = str_replace('.', '', uniqid('', true));

        return array_combine($labels, array_map(static fn (string $label): string => sprintf('%s-%s', $label, $entropy), $labels));
    }

    /**
     * Build a unique key prefix, hash-tagged so every key shares one cluster
     * slot unless the test needs its keys spread over slots.
     *
     * @param  bool  $hashTagged
     * @return string
     */
    private function uniquePrefix(bool $hashTagged): string
    {
        $entropy = str_replace('.', '', uniqid('', true));

        return $hashTagged ? sprintf('lvkglide:{pipe-%s}:', $entropy) : sprintf('lvkglide:pipe-%s:', $entropy);
    }
}
