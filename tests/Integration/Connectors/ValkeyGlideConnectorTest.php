<?php

declare(strict_types = 1);

namespace Tests\Integration\Connectors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\Exceptions\ConnectionException;
use Tests\Support\FakeValkeyGlide;

/**
 * @internal
 */
#[CoversClass(ValkeyGlideConnector::class)]
final class ValkeyGlideConnectorTest extends TestCase
{
    /**
     * Verify a single-node connection is created and configured.
     *
     * @return void
     */
    public function testConnectBuildsConnectionAndConfiguresClient(): void
    {
        $client    = new FakeValkeyGlide;
        $connector = $this->makeConnector($client, prefix_constant_value: 222);

        $connection = $connector->connect(
            [
                'host'     => 'cache.local',
                'port'     => 6380,
                'database' => 1,
                'name'     => 'worker-1',
                'prefix'   => 'acme:',
                'password' => 'secret',
            ],
            ['database' => 3],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertArrayHasKey('connect', $client->calls);
        self::assertSame(3, $client->calls['select'][0][0]);
        self::assertSame('SETNAME', $client->calls['client'][0][0]);
        self::assertSame('worker-1', $client->calls['client'][0][1]);
        self::assertSame([222, 'acme:'], $client->calls['setOption'][0]);
    }

    /**
     * Verify cluster configuration is mapped to multiple addresses.
     *
     * @return void
     */
    public function testConnectToClusterUsesClusterAddresses(): void
    {
        $client    = new FakeValkeyGlide;
        $connector = $this->makeConnector($client);

        $connection = $connector->connectToCluster(
            [
                ['host' => 'cluster-a', 'port' => 7000],
                ['host' => 'cluster-b', 'port' => 7001],
            ],
            ['name' => 'cluster-client'],
            [],
        );

        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertArrayHasKey('connect', $client->calls);
        self::assertSame('cluster-a', $client->calls['connect'][0]['addresses'][0]['host']);
        self::assertSame('cluster-b', $client->calls['connect'][0]['addresses'][1]['host']);
    }

    /**
     * Verify client exceptions are wrapped in connection exceptions.
     *
     * @return void
     */
    public function testConnectWrapsUnderlyingClientFailures(): void
    {
        $client = new FakeValkeyGlide;
        $client->queueResponse('connect', new \RuntimeException('boom'));

        $connector = $this->makeConnector($client);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Unable to establish a Valkey GLIDE connection: boom');

        $connector->connect(['host' => 'cache.local'], []);
    }

    /**
     * Verify missing extension is reported with a connection exception.
     *
     * @return void
     */
    public function testConnectThrowsWhenExtensionIsUnavailable(): void
    {
        $connector = $this->makeConnector(new FakeValkeyGlide, extension_loaded: false);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('ext-valkey_glide');

        $connector->connect(['host' => 'cache.local'], []);
    }

    /**
     * Verify missing class availability is reported with a connection exception.
     *
     * @return void
     */
    public function testConnectThrowsWhenClassIsUnavailable(): void
    {
        $connector = $this->makeConnector(new FakeValkeyGlide, class_exists: false);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('class "ValkeyGlide" is unavailable');

        $connector->connect(['host' => 'cache.local'], []);
    }

    /**
     * Verify prefix configuration is skipped when setOption is unavailable.
     *
     * @return void
     */
    public function testPrefixIsSkippedWhenSetOptionMethodIsUnavailable(): void
    {
        $client    = new FakeValkeyGlide;
        $connector = $this->makeConnector($client, set_option_exists: false, prefix_constant_value: 55);
        $connector->connect(['host' => 'cache.local', 'prefix' => 'skip:'], []);

        self::assertArrayNotHasKey('setOption', $client->calls);
    }

    /**
     * Verify prefix configuration is skipped when Redis::OPT_PREFIX is missing.
     *
     * @return void
     */
    public function testPrefixIsSkippedWhenRedisPrefixConstantIsMissing(): void
    {
        $client    = new FakeValkeyGlide;
        $connector = $this->makeConnector($client, prefix_constant_defined: false);
        $connector->connect(['host' => 'cache.local', 'prefix' => 'skip:'], []);

        self::assertArrayNotHasKey('setOption', $client->calls);
    }

    /**
     * Verify stringable values are accepted for database and prefix options.
     *
     * @return void
     */
    public function testConnectSupportsStringableDatabaseAndPrefixValues(): void
    {
        $client    = new FakeValkeyGlide;
        $connector = $this->makeConnector($client, prefix_constant_value: 9);

        $connector->connect(
            [
                'host'     => 'cache.local',
                'database' => new class implements \Stringable {
                    /**
                     * @return string
                     */
                    public function __toString(): string
                    {
                        return '5';
                    }
                },
                'name'   => '',
                'prefix' => new class implements \Stringable {
                    /**
                     * @return string
                     */
                    public function __toString(): string
                    {
                        return 'pref:';
                    }
                },
            ],
            [],
        );

        self::assertSame(5, $client->calls['select'][0][0]);
        self::assertArrayNotHasKey('client', $client->calls);
        self::assertSame([9, 'pref:'], $client->calls['setOption'][0]);
    }

    /**
     * Verify unsupported prefix value types are ignored.
     *
     * @return void
     */
    public function testPrefixIsSkippedForUnsupportedPrefixType(): void
    {
        $client    = new FakeValkeyGlide;
        $connector = $this->makeConnector($client, prefix_constant_value: 7);

        $connector->connect(
            [
                'host'   => 'cache.local',
                'prefix' => ['invalid'],
            ],
            [],
        );

        self::assertArrayNotHasKey('setOption', $client->calls);
    }

    /**
     * Create a connector with deterministic environment probes.
     *
     * @param  \ValkeyGlide  $client
     * @param  bool  $extension_loaded
     * @param  bool  $class_exists
     * @param  bool  $set_option_exists
     * @param  bool  $prefix_constant_defined
     * @param  mixed  $prefix_constant_value
     * @return \SineMacula\Valkey\Connectors\ValkeyGlideConnector
     */
    private function makeConnector(
        \ValkeyGlide $client,
        bool $extension_loaded = true,
        bool $class_exists = true,
        bool $set_option_exists = true,
        bool $prefix_constant_defined = true,
        mixed $prefix_constant_value = 0,
    ): ValkeyGlideConnector {
        return new ValkeyGlideConnector(
            static fn (): \ValkeyGlide => $client,
            static fn (string $extension): bool => $extension === 'valkey_glide' ? $extension_loaded : extension_loaded($extension),
            static fn (string $class): bool => $class === \ValkeyGlide::class ? $class_exists : class_exists($class),
            static fn (object|string $object_or_class, string $method): bool => $method === 'setOption'
                ? $set_option_exists
                : method_exists($object_or_class, $method),
            static fn (string $constant): bool => $constant === 'Redis::OPT_PREFIX'
                ? $prefix_constant_defined
                : defined($constant),
            static fn (string $constant): mixed => $constant === 'Redis::OPT_PREFIX'
                ? $prefix_constant_value
                : constant($constant),
        );
    }
}
