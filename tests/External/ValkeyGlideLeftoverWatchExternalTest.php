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
 * External checks for a WATCH left over from a transaction that ended while its
 * UNWATCH failed.
 *
 * Every test runs against the standalone server and, when
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT are set,
 * against the cluster; the cluster cases are skipped otherwise. Each test uses
 * its own prefix, hash-tagged so all of its keys share one cluster slot.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideLeftoverWatchExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var list<\SineMacula\Valkey\Connections\ValkeyGlideConnection> Connections to clean up after the test. */
    private array $connections = [];

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
        foreach ($this->connections as $connection) {
            try {
                $connection->command('del', ['watched', 'out']);
                $connection->disconnect();
            } catch (\Throwable) {
                // Best effort: a closed connection has nothing left to delete.
            }
        }

        $this->connections = [];

        parent::tearDown();
    }

    /**
     * Verify a WATCH that the server still holds after the UNWATCH of a
     * discarded transaction failed is unwatched before the next transaction,
     * which then commits although the key it no longer watches has changed.
     *
     * GLIDE buffers the UNWATCH while its client is in batch mode outside the
     * driver, so the UNWATCH fails, as one that times out does, while the
     * server keeps the WATCH; leaving batch mode with GLIDE's discard(), which
     * is client-side only, drops the buffered UNWATCH.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function leftoverWatchIsUnwatchedBeforeTheNextTransaction(string $topology): void
    {
        $prefix     = '{' . $this->uniqueName('leftover-watch') . '}:';
        $connection = $this->connect($topology, $prefix);
        $other      = $this->connect($topology, $prefix);

        $connection->command('set', ['watched', '1']);

        self::assertTrue($connection->command('watch', ['watched']));

        try {
            $connection->transaction(static function () use ($connection): void {
                $connection->client()->multi();

                throw new \DomainException('callback failed');
            });
            self::fail('Expected the callback exception to be rethrown.');
        } catch (\DomainException $exception) {
            self::assertSame('callback failed', $exception->getMessage());
        }

        self::assertTrue($connection->client()->discard());

        $other->command('set', ['watched', '2']);

        self::assertSame([true, 'committed'], $connection->transaction(static fn (ValkeyGlideBatch $tx) => $tx->set('out', 'committed')->get('out')));
    }

    /**
     * Connect to the topology with the prefix, and clean up after the test.
     *
     * @param  string  $topology
     * @param  string  $prefix
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function connect(string $topology, string $prefix): ValkeyGlideConnection
    {
        $connection = $this->connectTo($topology, $prefix);

        $this->connections[] = $connection;

        return $connection;
    }
}
