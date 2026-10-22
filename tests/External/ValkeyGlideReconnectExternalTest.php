<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;

/**
 * External checks for the use of a connection after disconnect() on the real
 * extension, which answers every call on a closed client with false.
 *
 * Every test runs against the standalone server and, when
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT are set,
 * against the cluster; the cluster cases are skipped otherwise. Each test uses
 * its own hash-tagged prefix, so all of its keys share one cluster slot.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideReconnectExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var list<array{\SineMacula\Valkey\Connections\ValkeyGlideConnection, list<string>}> Connections and the logical keys to delete after the test. */
    private array $cleanups = [];

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
                // Best effort: the test has already reported its failure.
            }
        }

        $this->cleanups = [];

        parent::tearDown();
    }

    /**
     * Verify commands after disconnect() reach the server again, including one
     * that is not safe to retry.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function commandsAfterDisconnectReconnect(string $topology): void
    {
        $connection = $this->connect($topology, ['key', 'counter']);

        $connection->command('set', ['key', 'value']);
        $connection->disconnect();

        self::assertSame('value', $connection->command('get', ['key']));

        $connection->disconnect();

        self::assertSame(1, $connection->command('incr', ['counter']));
        self::assertSame($topology === self::CLUSTER, $connection->isCluster());
    }

    /**
     * Verify a pipeline and a transaction after disconnect() run on a new
     * client.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function batchesAfterDisconnectReconnect(string $topology): void
    {
        $connection = $this->connect($topology, ['a', 'n']);

        $connection->disconnect();

        self::assertSame([true, 'value-a'], $connection->pipeline(static fn (ValkeyGlideBatch $pipe) => $pipe->set('a', 'value-a')->get('a')));

        $connection->disconnect();

        self::assertSame([1, 'value-a'], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->incr('n')->get('a')));
    }

    /**
     * Verify a pipeline whose listener disconnects while an immediate command
     * runs resumes on a new client.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function pipelineDisconnectedByAListenerResumes(string $topology): void
    {
        $connection   = $this->connect($topology, ['a', 'b']);
        $dispatcher   = new Dispatcher;
        $disconnected = false;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $executed) use ($connection, &$disconnected): void {
            if ($executed->command !== 'eval' || $disconnected) {
                return;
            }

            $disconnected = true;

            $connection->disconnect();
        });

        $connection->setEventDispatcher($dispatcher);

        self::assertSame([true, 1, true, 'value-a'], $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->set('a', 'value-a');
            $pipe->eval('return 1', 1, 'a');
            $pipe->set('b', 'value-b');
            $pipe->get('a');
        }));
        self::assertTrue($disconnected);
        self::assertSame('value-b', $connection->command('get', ['b']));
    }

    /**
     * Verify a WATCH set after disconnect() guards the next transaction, and
     * the one set before it is gone with the closed client.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function watchAfterDisconnectGuardsTheTransaction(string $topology): void
    {
        $prefix     = $this->uniquePrefix();
        $connection = $this->connect($topology, ['w', 'out'], $prefix);
        $other      = $this->connect($topology, [], $prefix);

        $connection->command('set', ['w', '1']);
        $connection->command('watch', ['w']);
        $connection->disconnect();
        $other->command('set', ['w', '2']);

        self::assertSame([true], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'unwatched')));

        $connection->command('watch', ['w']);
        $connection->disconnect();
        $connection->command('watch', ['w']);
        $other->command('set', ['w', '3']);

        self::assertFalse($connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'aborted')));
        self::assertSame('unwatched', $connection->command('get', ['out']));
    }

    /**
     * Verify a scan resumed after disconnect() continues from its cursor on the
     * new client and returns every key.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function scanResumedAfterDisconnectReturnsEveryKey(string $topology): void
    {
        $keys       = ['k1', 'k2', 'k3', 'k4', 'k5', 'k6'];
        $connection = $this->connect($topology, $keys);
        $options    = ['match' => $connection->getPrefix() . '*', 'count' => 2];

        foreach ($keys as $key) {
            $connection->command('set', [$key, 'v']);
        }

        [$cursor, $found] = $connection->scan('0', $options);
        $resumes          = 0;

        while ($cursor !== '0') {
            $connection->disconnect();

            [$cursor, $page] = $connection->scan($cursor, $options);
            $found           = [...$found, ...$page];

            $resumes++;
        }

        $expected = array_map(static fn (string $key): string => $connection->getPrefix() . $key, $keys);

        sort($found);

        self::assertGreaterThan(0, $resumes);
        self::assertSame($expected, array_values(array_unique($found)));
    }

    /**
     * Connect to the topology with a hash-tagged prefix, deleting the given
     * logical keys after the test.
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
        $connection = $this->connectTo($topology, $prefix ?? $this->uniquePrefix());

        $this->cleanups[] = [$connection, $keys];

        return $connection;
    }

    /**
     * Build a unique hash-tagged key prefix for test isolation.
     *
     * @return string
     */
    private function uniquePrefix(): string
    {
        return sprintf('lvkglide:{reconnect-%s}:', str_replace('.', '', uniqid('', true)));
    }
}
