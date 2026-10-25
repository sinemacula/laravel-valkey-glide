<?php

declare(strict_types = 1);

namespace Tests\External;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\Exceptions\CommandException;
use Tests\External\Concerns\ConnectsToTestServers;

/**
 * External checks for a DBSIZE the server refuses, which GLIDE's own dbSize()
 * answers by aborting the process.
 *
 * Every test runs against the standalone server and, when
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT are set,
 * against the cluster; the cluster cases are skipped otherwise. Each test
 * creates its own ACL user denied DBSIZE, and deletes it after the test; it is
 * skipped when the server refuses `ACL SETUSER`.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideRefusedDbsizeExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var array<string, \SineMacula\Valkey\Connections\ValkeyGlideConnection> The default user's connection to each topology the test used. */
    private array $connections = [];

    /** @var array<string, list<string>> The ACL users the test created, by topology. */
    private array $users = [];

    /** @var list<\SineMacula\Valkey\Connections\ValkeyGlideConnection> The connections opened as the test's ACL users. */
    private array $userConnections = [];

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
     * Delete the ACL users the test created, then close every connection.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->userConnections as $connection) {
            $connection->disconnect();
        }

        foreach ($this->connections as $topology => $connection) {
            if (($this->users[$topology] ?? []) !== []) {
                $connection->executeRaw(['ACL', 'DELUSER', ...$this->users[$topology]]);
            }

            $connection->disconnect();
        }

        $this->connections     = [];
        $this->users           = [];
        $this->userConnections = [];

        parent::tearDown();
    }

    /**
     * Verify a DBSIZE the server refuses with NOPERM throws, rather than
     * aborting the process as GLIDE's own dbSize() does when it fails.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function dbsizeTheServerRefusesThrowsRatherThanAbortingTheProcess(string $topology): void
    {
        $connection = $this->connectAsUserDeniedDbsize($topology);

        try {
            $connection->command('dbsize');
            self::fail('A refused DBSIZE must throw.');
        } catch (CommandException $exception) {
            self::assertMatchesRegularExpression('/^(\[DBSIZE\] failed|NOPERM)/', $exception->getMessage());
        }
    }

    /**
     * Get the default user's connection to the topology, opening it once.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function connection(string $topology): ValkeyGlideConnection
    {
        return $this->connections[$topology] ??= $this->connectTo($topology);
    }

    /**
     * Create an ACL user denied DBSIZE on the topology, and open a connection
     * to it as that user, skipping the test when the server refuses to create
     * it. The user is recorded for deletion before it is created, so a cluster
     * node that created it is cleaned up even when another refused.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \Throwable
     */
    private function connectAsUserDeniedDbsize(string $topology): ValkeyGlideConnection
    {
        $connection = $this->connection($topology);
        $username   = $this->uniqueName('nodbsize');
        $password   = bin2hex(random_bytes(16));

        $this->users[$topology][] = $username;

        try {
            $created = $connection->executeRaw(['ACL', 'SETUSER', $username, 'on', '>' . $password, '~*', '&*', '+@all', '-dbsize']);
        } catch (\Throwable $exception) {
            $created = $exception->getMessage();
        }

        if ($created === false || is_string($created) && $created !== 'OK') {
            self::markTestSkipped(sprintf('The server refused ACL SETUSER, which the refused DBSIZE cases need: %s', var_export($created, true)));
        }

        $credentials = ['username' => $username, 'password' => $password];
        $connector   = new ValkeyGlideConnector;

        $userConnection = $topology === self::CLUSTER
            ? $connector->connectToCluster([$this->clusterSeed() + $credentials], [], [])
            : $connector->connect($credentials + $this->standaloneConfig(), []);

        $this->userConnections[] = $userConnection;

        return $userConnection;
    }
}
