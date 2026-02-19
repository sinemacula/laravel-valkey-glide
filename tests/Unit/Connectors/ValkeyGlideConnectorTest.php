<?php

declare(strict_types = 1);

namespace Tests\Unit\Connectors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\Exceptions\ConnectionException;
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

        $connect_calls = $fake->callsFor('connect');

        self::assertCount(1, $connect_calls);
        self::assertSame(
            [
                ['host' => 'cache-final', 'port' => 6381],
            ],
            $connect_calls[0]['addresses'],
        );
        self::assertSame(['password' => 'secret'], $connect_calls[0]['credentials']);
        self::assertSame(4, $connect_calls[0]['database_id']);
        self::assertSame('worker-a', $connect_calls[0]['client_name']);
    }

    /**
     * Verify connectToCluster forwards normalized cluster seed addresses.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterUsesClusterAddressesAndMergedOptions(): void
    {
        $fake = new ValkeyGlideFake;

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
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
                'password' => 'cluster-secret',
            ],
            [
                'database' => 3,
            ],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);

        $connect_calls = $fake->callsFor('connect');

        self::assertCount(1, $connect_calls);
        self::assertSame(
            [
                ['host' => 'node-1', 'port' => 6380],
                ['host' => 'node-2', 'port' => 6381],
            ],
            $connect_calls[0]['addresses'],
        );
        self::assertSame(['password' => 'cluster-secret'], $connect_calls[0]['credentials']);
        self::assertSame(3, $connect_calls[0]['database_id']);
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
     * Verify connector rethrows domain connection exceptions unchanged.
     *
     * @return void
     */
    #[Test]
    public function connectRethrowsConnectionExceptionWithoutWrapping(): void
    {
        $fake = new ValkeyGlideFake;
        $fake->willThrow('connect', new ConnectionException('already-normalized'));

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('already-normalized');

        $connector->connect([], []);
    }

    /**
     * Verify reconnect callback is wired by retrying an idempotent command.
     *
     * @return void
     */
    #[Test]
    public function connectRetriesUsingReconnectFactoryWhenTransientFailureOccurs(): void
    {
        $first_client = new ValkeyGlideFake;
        $first_client->willThrow('get', new \RuntimeException('connection reset by peer'));

        $second_client = new ValkeyGlideFake;
        $second_client->willReturn('get', 'ok');

        $clients = [$first_client, $second_client];

        $connector = new ValkeyGlideConnector(
            clientFactory  : static function () use (&$clients): \ValkeyGlide {
                return array_shift($clients) ?? new ValkeyGlideFake;
            },
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connect([], []);

        self::assertSame('ok', $connection->command('get', ['cache-key']));
        self::assertCount(1, $first_client->callsFor('get'));
        self::assertCount(1, $second_client->callsFor('get'));
    }

    /**
     * Verify cluster connection handles configs without array seed nodes.
     *
     * @return void
     */
    #[Test]
    public function connectToClusterFallsBackWhenNoArraySeedNodeExists(): void
    {
        $fake = new ValkeyGlideFake;

        $connector = new ValkeyGlideConnector(
            clientFactory  : static fn (): \ValkeyGlide => $fake,
            extensionLoader: static fn (string $extension): bool => true,
            classResolver  : static fn (string $class): bool => true,
        );

        $connection = $connector->connectToCluster(
            ['invalid-node-shape'],
            ['password' => 'cluster-secret'],
            [],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);

        $connect_calls = $fake->callsFor('connect');

        self::assertCount(1, $connect_calls);
        self::assertSame(
            [
                ['host' => '127.0.0.1', 'port' => 6379],
            ],
            $connect_calls[0]['addresses'],
        );
        self::assertSame(['password' => 'cluster-secret'], $connect_calls[0]['credentials']);
    }
}
