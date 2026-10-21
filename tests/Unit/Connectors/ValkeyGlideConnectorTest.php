<?php

declare(strict_types = 1);

namespace Tests\Unit\Connectors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\Exceptions\ConnectionException;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Valkey GLIDE connector test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnector::class)]
final class ValkeyGlideConnectorTest extends TestCase
{
    /**
     * Verify connect throws when the GLIDE extension is unavailable.
     *
     * @return void
     */
    #[Test]
    public function connectThrowsWhenExtensionIsUnavailable(): void
    {
        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => new ValkeyGlideFake,
            extensionLoader: static fn (string $extension): bool => false,
            classResolver  : static fn (string $class): bool => true,
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Valkey GLIDE extension (ext-valkey_glide) is not loaded.');

        $connector->connect([], []);
    }

    /**
     * Verify connect throws when extension is loaded but class cannot be found.
     *
     * @return void
     */
    #[Test]
    public function connectThrowsWhenClassIsUnavailable(): void
    {
        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => new ValkeyGlideFake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => false,
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Valkey GLIDE extension is loaded but class "ValkeyGlide" is unavailable.');

        $connector->connect([], []);
    }

    /**
     * Verify connect returns a connection wrapper and forwards normalized args.
     *
     * @return void
     */
    #[Test]
    public function connectBuildsConnectionAndPassesNormalizedConnectArguments(): void
    {
        $fake = new ValkeyGlideFake;

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connect(
            [
                'host'     => 'cache-base',
                'password' => 'secret',
                'options'  => [
                    'host'     => 'cache-final',
                    'database' => 4,
                    'name'     => '  worker-a  ',
                ],
            ],
            [
                'port'     => 6381,
                'database' => 2,
            ],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);

        $connectCalls = $fake->callsFor('connect');

        self::assertCount(1, $connectCalls);
        self::assertSame(
            [
                ['host' => 'cache-final', 'port' => 6381],
            ],
            $connectCalls[0]['addresses'],
        );
        self::assertSame(['password' => 'secret'], $connectCalls[0]['credentials']);
        self::assertSame(4, $connectCalls[0]['database_id']);
        self::assertSame('worker-a', $connectCalls[0]['client_name']);
    }

    /**
     * Verify connectToCluster builds the cluster client via the default factory
     * when none is injected.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterUsesDefaultClusterClientFactoryWhenNoneInjected(): void
    {
        $connector = new ValkeyGlideConnector(
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connectToCluster(
            [['host' => '127.0.0.1', 'port' => 6379]],
            [],
            [],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertInstanceOf(\ValkeyGlideCluster::class, $connection->client());
    }

    /**
     * Verify connectToCluster returns a connection whose client is a
     * ValkeyGlideCluster and that the factory received the resolved connect
     * arguments.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterBuildsClusterConnectionAndPassesNormalizedArguments(): void
    {
        $clusterFake  = new ValkeyGlideClusterFake;
        $capturedArgs = null;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use ($clusterFake, &$capturedArgs): \ValkeyGlideCluster {
                $capturedArgs = $args;

                return $clusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connectToCluster(
            [
                ['host' => 'node-1', 'port' => 6380],
                [
                    'replica' => ['host' => 'node-2', 'port' => '6381'],
                ],
            ],
            [
                'password'  => 'cluster-secret',
                'read_from' => 'prefer_replica',
            ],
            [
                'database' => 3,
            ],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertInstanceOf(\ValkeyGlideCluster::class, $connection->client());
        self::assertSame($clusterFake, $connection->client());
        self::assertNotNull($capturedArgs);
        self::assertSame(
            [
                ['host' => 'node-1', 'port' => 6380],
                ['host' => 'node-2', 'port' => 6381],
            ],
            $capturedArgs['addresses'],
        );
        self::assertSame(['password' => 'cluster-secret'], $capturedArgs['credentials']);
        self::assertSame(3, $capturedArgs['database_id']);
        self::assertArrayHasKey('read_from', $capturedArgs);
    }

    /**
     * Verify an options entry beside the cluster's nodes, as Horizon writes for
     * its key prefix, overrides the shared and cluster-wide options and the
     * first node's own, and is not taken for a node.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectToClusterAppliesTheClusterOwnOptions(): void
    {
        $clusterFake  = new ValkeyGlideClusterFake;
        $capturedArgs = null;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use ($clusterFake, &$capturedArgs): \ValkeyGlideCluster {
                $capturedArgs = $args;

                return $clusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connectToCluster(
            [
                'options' => ['prefix' => '{horizon:}', 'name' => 'horizon'],
                ['host' => 'node-1', 'port' => 6380, 'options' => ['prefix' => 'seed:', 'name' => 'seed', 'database' => 3]],
            ],
            ['prefix' => 'cluster:', 'name' => 'cluster'],
            ['prefix' => 'shared:', 'database' => 2],
        );

        $connection->command('get', ['k']);

        self::assertSame([['{horizon:}k']], $clusterFake->callsFor('get'));
        self::assertNotNull($capturedArgs);
        self::assertSame([['host' => 'node-1', 'port' => 6380]], $capturedArgs['addresses']);
        self::assertSame('horizon', $capturedArgs['client_name']);
        self::assertSame(3, $capturedArgs['database_id']);
    }

    /**
     * Verify a cluster options entry that is not an array is ignored.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectToClusterIgnoresANonArrayOwnOptionsEntry(): void
    {
        $clusterFake = new ValkeyGlideClusterFake;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static fn (array $args): \ValkeyGlideCluster => $clusterFake,
            extensionLoader     : static fn (string $extension): bool => true,
            classResolver       : static fn (string $class): bool => true,
        );

        $connection = $connector->connectToCluster(
            ['options' => 'invalid', ['host' => 'node-1', 'port' => 6380]],
            ['prefix' => 'cluster:'],
            [],
        );

        $connection->command('get', ['k']);

        self::assertSame([['cluster:k']], $clusterFake->callsFor('get'));
    }

    /**
     * Verify connectToCluster passes a configured periodic_checks setting to
     * the cluster client factory.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterPassesPeriodicChecksToClusterClient(): void
    {
        $capturedArgs = null;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use (&$capturedArgs): \ValkeyGlideCluster {
                $capturedArgs = $args;

                return new ValkeyGlideClusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connector->connectToCluster(
            [['host' => 'node-1', 'port' => 6379, 'periodic_checks' => 'disabled']],
            [],
            [],
        );

        self::assertNotNull($capturedArgs);
        self::assertSame(\ValkeyGlideCluster::PERIODIC_CHECK_DISABLED, $capturedArgs['periodic_checks']);
    }

    /**
     * Verify connect never forwards periodic_checks to the standalone client.
     *
     * @return void
     */
    #[Test]
    public function connectDoesNotPassPeriodicChecksToStandaloneClient(): void
    {
        $fake = new ValkeyGlideFake;

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connector->connect(['host' => 'standalone-host', 'periodic_checks' => 'disabled'], []);

        $connectCalls = $fake->callsFor('connect');

        self::assertCount(1, $connectCalls);
        self::assertArrayNotHasKey('periodic_checks', $connectCalls[0]);
    }

    /**
     * Verify connect path still builds a standalone ValkeyGlide and calls
     * connect().
     *
     * @return void
     */
    #[Test]
    public function connectBuildsStandaloneClientAndCallsConnect(): void
    {
        $fake = new ValkeyGlideFake;

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connect(['host' => 'standalone-host', 'port' => 6379], []);

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertInstanceOf(\ValkeyGlide::class, $connection->client());
        self::assertCount(1, $fake->callsFor('connect'));
        self::assertEmpty($fake->callsFor('__construct'));
    }

    /**
     * Verify connectToCluster throws when ValkeyGlideCluster class is
     * unavailable.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterThrowsWhenClusterClassIsUnavailable(): void
    {
        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static fn (array $args): \ValkeyGlideCluster => new ValkeyGlideClusterFake,
            extensionLoader     : static fn (string $extension): bool => true,
            classResolver       : static fn (string $class): bool => $class !== \ValkeyGlideCluster::class,
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Valkey GLIDE extension is loaded but class "ValkeyGlideCluster" is unavailable.');

        $connector->connectToCluster([], [], []);
    }

    /**
     * Verify a domain ConnectionException from the cluster factory is rethrown
     * without double-wrapping.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterRethrowsConnectionExceptionWithoutWrapping(): void
    {
        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args): \ValkeyGlideCluster {
                throw new ConnectionException('cluster-already-normalized');
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        try {
            $connector->connectToCluster([], [], []);
            self::fail('Expected ConnectionException to be thrown.');
        } catch (ConnectionException $exception) {
            self::assertSame('cluster-already-normalized', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * Verify a generic Throwable from the cluster factory is wrapped in a
     * ConnectionException with the correct message and cause.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterWrapsGenericThrowableWithConnectionException(): void
    {
        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args): \ValkeyGlideCluster {
                throw new \RuntimeException('cluster-boom');
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        try {
            $connector->connectToCluster([], [], []);
            self::fail('Expected ConnectionException to be thrown.');
        } catch (ConnectionException $exception) {
            self::assertStringContainsString('Unable to establish a Valkey GLIDE cluster connection: cluster-boom', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    /**
     * Verify client connection failures are wrapped with ConnectionException.
     *
     * @return void
     */
    #[Test]
    public function connectWrapsUnderlyingClientExceptions(): void
    {
        $fake = new ValkeyGlideFake;
        $fake->willThrow('connect', new \RuntimeException('boom'));

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        try {
            $connector->connect([], []);
            self::fail('Expected connection exception to be thrown.');
        } catch (ConnectionException $exception) {
            self::assertStringContainsString('Unable to establish a Valkey GLIDE connection: boom', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    /**
     * Verify an incomplete IAM block surfaces as an unwrapped
     * InvalidArgumentException before any client is created.
     *
     * @return void
     */
    #[Test]
    public function connectThrowsInvalidArgumentForIncompleteIamWithoutCreatingClient(): void
    {
        $clientCreated = false;

        $connector = new ValkeyGlideConnector(
            clientFactory: static function () use (&$clientCreated): \ValkeyGlide {
                $clientCreated = true;

                return new ValkeyGlideFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        try {
            $connector->connect(['iam' => ['username' => 'iam-user']], []);
            self::fail('Expected InvalidArgumentException to be thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Incomplete IAM configuration; missing or empty iam keys [cluster_name, region].', $exception->getMessage());
        }

        self::assertFalse($clientCreated);
    }

    /**
     * Verify an incomplete IAM block on a cluster seed node surfaces as an
     * unwrapped InvalidArgumentException before the cluster client is created.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterThrowsInvalidArgumentForIncompleteIamWithoutCreatingClient(): void
    {
        $clientCreated = false;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use (&$clientCreated): \ValkeyGlideCluster {
                $clientCreated = true;

                return new ValkeyGlideClusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        try {
            $connector->connectToCluster(
                [
                    ['host' => 'node-1', 'port' => 6379, 'iam' => ['username' => 'iam-user', 'cluster_name' => 'cache']],
                ],
                [],
                [],
            );
            self::fail('Expected InvalidArgumentException to be thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Incomplete IAM configuration; missing or empty iam keys [region].', $exception->getMessage());
        }

        self::assertFalse($clientCreated);
    }

    /**
     * Verify reconnect callback is wired by retrying an idempotent command.
     *
     * @return void
     */
    #[Test]
    public function connectRetriesUsingReconnectFactoryWhenTransientFailureOccurs(): void
    {
        $firstClient = new ValkeyGlideFake;
        $firstClient->willThrow('get', new \RuntimeException('connection reset by peer'));

        $secondClient = new ValkeyGlideFake;
        $secondClient->willReturn('get', 'ok');

        $clients = [$firstClient, $secondClient];

        $connector = new ValkeyGlideConnector(
            clientFactory  : static function () use (&$clients): \ValkeyGlide {
                return array_shift($clients) ?? new ValkeyGlideFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connect([], []);

        self::assertSame('ok', $connection->command('get', ['cache-key']));
        self::assertCount(1, $firstClient->callsFor('get'));
        self::assertCount(1, $secondClient->callsFor('get'));
    }

    /**
     * Verify cluster connection handles configs without array seed nodes.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterFallsBackWhenNoArraySeedNodeExists(): void
    {
        $clusterFake  = new ValkeyGlideClusterFake;
        $capturedArgs = null;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use ($clusterFake, &$capturedArgs): \ValkeyGlideCluster {
                $capturedArgs = $args;

                return $clusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connectToCluster(
            ['invalid-node-shape'],
            ['password' => 'cluster-secret'],
            [],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertNotNull($capturedArgs);
        self::assertSame(
            [
                ['host' => '127.0.0.1', 'port' => 6379],
            ],
            $capturedArgs['addresses'],
        );
        self::assertSame(['password' => 'cluster-secret'], $capturedArgs['credentials']);
    }

    /**
     * Verify domain connection exceptions are rethrown without a wrapping
     * cause.
     *
     * @return void
     */
    #[Test]
    public function connectRethrowsConnectionExceptionWithoutAddingAPreviousCause(): void
    {
        $fake = new ValkeyGlideFake;
        $fake->willThrow('connect', new ConnectionException('already-normalized'));

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        try {
            $connector->connect([], []);
            self::fail('Expected connection exception to be thrown.');
        } catch (ConnectionException $exception) {
            self::assertSame('already-normalized', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * Verify the first array cluster node seeds non-address base configuration.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterSeedsBaseConfigFromFirstArrayNode(): void
    {
        $clusterFake  = new ValkeyGlideClusterFake;
        $capturedArgs = null;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use ($clusterFake, &$capturedArgs): \ValkeyGlideCluster {
                $capturedArgs = $args;

                return $clusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connectToCluster(
            [
                ['host' => 'node-1', 'port' => 6380, 'database' => 5],
                ['host' => 'node-2', 'port' => 6381],
            ],
            [],
            [],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertNotNull($capturedArgs);
        self::assertSame(5, $capturedArgs['database_id']);
        self::assertSame(
            [
                ['host' => 'node-1', 'port' => 6380],
                ['host' => 'node-2', 'port' => 6381],
            ],
            $capturedArgs['addresses'],
        );
    }

    /**
     * Verify a leading scalar cluster entry is skipped to seed the next array
     * node.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterSkipsLeadingScalarEntryWhenSeedingBaseConfig(): void
    {
        $clusterFake  = new ValkeyGlideClusterFake;
        $capturedArgs = null;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use ($clusterFake, &$capturedArgs): \ValkeyGlideCluster {
                $capturedArgs = $args;

                return $clusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connectToCluster(
            [
                'leading-scalar',
                ['host' => 'node-2', 'port' => 6381, 'database' => 6],
            ],
            [],
            [],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertNotNull($capturedArgs);
        self::assertSame(6, $capturedArgs['database_id']);
    }

    /**
     * Verify connect forwards the reconnect strategy and lazy_connect flag to
     * the standalone client.
     *
     * @return void
     */
    #[Test]
    public function connectForwardsReconnectStrategyAndLazyConnect(): void
    {
        $fake = new ValkeyGlideFake;

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connector->connect(
            [
                'reconnect_strategy' => ['num_of_retries' => 8, 'jitter_percent' => 10],
                'lazy_connect'       => true,
            ],
            [],
        );

        $connectCalls = $fake->callsFor('connect');

        self::assertSame(['num_of_retries' => 8, 'jitter_percent' => 10], $connectCalls[0]['reconnect_strategy']);
        self::assertTrue($connectCalls[0]['lazy_connect']);
    }

    /**
     * Verify connect leaves the reconnect strategy and lazy_connect unset when
     * they are not configured.
     *
     * @return void
     */
    #[Test]
    public function connectLeavesReconnectStrategyAndLazyConnectUnsetByDefault(): void
    {
        $fake = new ValkeyGlideFake;

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connector->connect([], []);

        $connectCalls = $fake->callsFor('connect');

        self::assertNull($connectCalls[0]['reconnect_strategy']);
        self::assertNull($connectCalls[0]['lazy_connect']);
    }

    /**
     * Verify connectToCluster forwards the reconnect strategy and lazy_connect
     * flag from the cluster options to the cluster client.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterForwardsReconnectStrategyAndLazyConnect(): void
    {
        $capturedArgs = null;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use (&$capturedArgs): \ValkeyGlideCluster {
                $capturedArgs = $args;

                return new ValkeyGlideClusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connector->connectToCluster(
            [['host' => 'node-1', 'port' => 6379]],
            [
                'reconnect_strategy' => ['factor' => 250, 'exponent_base' => 3],
                'lazy_connect'       => false,
            ],
            [],
        );

        self::assertNotNull($capturedArgs);
        self::assertSame(['factor' => 250, 'exponent_base' => 3], $capturedArgs['reconnect_strategy']);
        self::assertFalse($capturedArgs['lazy_connect']);
    }

    /**
     * Verify a cluster client created to retry a command is given the same
     * cluster-only and reconnect options as the client it replaces.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterRetriesWithTheSameClusterArguments(): void
    {
        $firstClient = new ValkeyGlideClusterFake;
        $firstClient->willThrow('get', new \RuntimeException('connection reset by peer'));

        $secondClient = new ValkeyGlideClusterFake;
        $secondClient->willReturn('get', 'ok');

        $clients      = [$firstClient, $secondClient];
        $capturedArgs = [];

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use (&$clients, &$capturedArgs): \ValkeyGlideCluster {
                $capturedArgs[] = $args;

                return array_shift($clients) ?? new ValkeyGlideClusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connectToCluster(
            [['host' => 'node-1', 'port' => 6379, 'periodic_checks' => 'disabled']],
            [
                'reconnect_strategy' => ['num_of_retries' => 8],
                'lazy_connect'       => true,
            ],
            [],
        );

        self::assertSame('ok', $connection->command('get', ['cache-key']));
        self::assertCount(2, $capturedArgs);
        self::assertSame($capturedArgs[0], $capturedArgs[1]);
        self::assertSame(\ValkeyGlideCluster::PERIODIC_CHECK_DISABLED, $capturedArgs[1]['periodic_checks']);
        self::assertSame(['num_of_retries' => 8], $capturedArgs[1]['reconnect_strategy']);
        self::assertTrue($capturedArgs[1]['lazy_connect']);
    }

    /**
     * Verify invalid connection options surface as configuration errors rather
     * than connection failures, before a client is created.
     *
     * @return void
     */
    #[Test]
    public function connectRejectsInvalidOptionsWithoutCreatingAClient(): void
    {
        $created = false;

        $connector = new ValkeyGlideConnector(
            clientFactory: static function () use (&$created): \ValkeyGlide {
                $created = true;

                return new ValkeyGlideFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        try {
            $connector->connect(['reconnect_strategy' => ['num_of_retries' => 0]], []);
            self::fail('Expected an invalid argument exception for the reconnect strategy.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(\InvalidArgumentException::class, $exception);
            self::assertFalse($created);
        }
    }

    /**
     * Verify invalid cluster options surface as configuration errors rather
     * than wrapped connection failures.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterRejectsInvalidOptionsWithoutWrapping(): void
    {
        $created = false;

        $connector = new ValkeyGlideConnector(
            clusterClientFactory: static function (array $args) use (&$created): \ValkeyGlideCluster {
                $created = true;

                return new ValkeyGlideClusterFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        try {
            $connector->connectToCluster([['host' => 'node-1']], ['lazy_connect' => 'sometimes'], []);
            self::fail('Expected an invalid argument exception for lazy_connect.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(\InvalidArgumentException::class, $exception);
            self::assertFalse($created);
        }
    }
}
