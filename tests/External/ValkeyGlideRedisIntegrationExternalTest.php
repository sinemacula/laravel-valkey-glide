<?php

declare(strict_types = 1);

namespace Tests\External;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\Exceptions\ConnectionException;

/**
 * Redis-backed external integration checks for connector and connection
 * behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnector::class)]
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideRedisIntegrationExternalTest extends TestCase
{
    /** @var int Default Redis database index for baseline assertions. */
    private const int DEFAULT_DATABASE = 0;

    /** @var int Secondary Redis database index for database routing checks. */
    private const int SECONDARY_DATABASE = 1;

    /** @var array{host: string, port: int} Local endpoint that refuses connections. */
    private const array REFUSING_ENDPOINT = ['host' => '127.0.0.1', 'port' => 1];

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
     * Verify the connector can establish an external connection and execute
     * PING.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectorConnectsAndPingSucceeds(): void
    {
        $connection = $this->connectExternal();

        $result = $connection->command('ping');

        self::assertContains($result, [true, 'PONG', 'pong']);

        $connection->disconnect();
    }

    /**
     * Verify set and get roundtrip works through the Laravel connection
     * wrapper.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectionSetGetRoundtripSucceeds(): void
    {
        $connection = $this->connectExternal();
        $key        = $this->uniqueKey('roundtrip');

        $connection->command('set', [$key, 'value-a']);

        self::assertSame('value-a', $connection->command('get', [$key]));

        $connection->command('del', [$key]);
        $connection->disconnect();
    }

    /**
     * Verify configured key prefixes are applied for wrapped key commands.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectionPrefixIsAppliedToKeyCommands(): void
    {
        $prefix     = 'lvkglide:test:';
        $plainKey   = $this->uniqueKey('prefixed');
        $prefixed   = $prefix . $plainKey;
        $connection = $this->connectExternal(['prefix' => $prefix]);

        $connection->command('set', [$plainKey, 'value-b']);

        self::assertSame('value-b', $connection->client()->get($prefixed));
        self::assertSame('value-b', $connection->command('get', [$plainKey]));

        $connection->command('del', [$plainKey]);
        $connection->disconnect();
    }

    /**
     * Verify configured key prefixes are applied when mget is used with key
     * lists.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectionPrefixIsAppliedToMgetKeyLists(): void
    {
        $prefix     = 'lvkglide:test:';
        $firstKey   = $this->uniqueKey('prefixed-mget-a');
        $secondKey  = $this->uniqueKey('prefixed-mget-b');
        $missingKey = $this->uniqueKey('prefixed-mget-missing');
        $connection = $this->connectExternal(['prefix' => $prefix]);

        $connection->command('set', [$firstKey, 'value-a']);
        $connection->command('set', [$secondKey, 'value-b']);

        self::assertSame(
            ['value-a', 'value-b', null],
            $connection->mget([$firstKey, $secondKey, $missingKey]),
        );

        $connection->command('del', [$firstKey, $secondKey, $missingKey]);
        $connection->disconnect();
    }

    /**
     * Verify raw command execution works through the connection wrapper.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectionExecuteRawSupportsDirectCommands(): void
    {
        $connection = $this->connectExternal();

        $result = $connection->executeRaw(['PING']);

        self::assertContains($result, [true, 'PONG', 'pong']);

        $connection->disconnect();
    }

    /**
     * Verify database selection in connector config routes data to that
     * database.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectorDatabaseConfigRoutesCommandsToExpectedDatabase(): void
    {
        $key = $this->uniqueKey('database-routing');

        $secondaryConnection = $this->connectExternal(['database' => self::SECONDARY_DATABASE]);
        $defaultConnection   = $this->connectExternal(['database' => self::DEFAULT_DATABASE]);

        $secondaryConnection->command('set', [$key, 'db-one']);

        self::assertContains($defaultConnection->command('get', [$key]), [null, false]);
        self::assertSame('db-one', $secondaryConnection->command('get', [$key]));

        $secondaryConnection->command('del', [$key]);
        $secondaryConnection->disconnect();
        $defaultConnection->disconnect();
    }

    /**
     * Verify idempotent commands succeed after explicit disconnect.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectionRecoversAfterManualDisconnectForIdempotentCommand(): void
    {
        $connection = $this->connectExternal();
        $key        = $this->uniqueKey('reconnect');

        $connection->command('set', [$key, 'value-reconnect']);
        $connection->disconnect();

        self::assertSame('value-reconnect', $connection->command('get', [$key]));

        $connection->command('del', [$key]);
        $connection->disconnect();
    }

    /**
     * Verify the extension applies the mapped reconnect strategy to the first
     * connection's attempts: two constant 400ms delays, where the five retries
     * of an ignored num_of_retries would take 2000ms.
     *
     * @return void
     */
    #[Test]
    public function connectorAppliesReconnectStrategyToInitialConnectionAttempts(): void
    {
        $config = self::REFUSING_ENDPOINT + [
            'connection_timeout' => 10,
            'reconnect_strategy' => [
                'num_of_retries' => 2,
                'factor'         => 400,
                'exponent_base'  => 1,
                'jitter_percent' => 0,
            ],
        ];

        $startedAt = hrtime(true);

        try {
            (new ValkeyGlideConnector)->connect($config, []);
        } catch (ConnectionException) {
            $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1000000;

            self::assertGreaterThanOrEqual(800, $elapsedMilliseconds);
            self::assertLessThan(2000, $elapsedMilliseconds);

            return;
        }

        self::fail('Expected the refused connection to fail.');
    }

    /**
     * Verify lazy_connect, in its environment string form, defers the
     * standalone connection past connect().
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectorDefersConnectingWhenLazyConnectIsEnabled(): void
    {
        $connection = (new ValkeyGlideConnector)->connect(self::REFUSING_ENDPOINT + ['lazy_connect' => 'true'], []);

        self::assertInstanceOf(\ValkeyGlide::class, $connection->client());

        $connection->disconnect();
    }

    /**
     * Verify the cluster constructor accepts the mapped reconnect strategy and
     * defers connecting when lazy_connect is enabled.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectorDefersClusterConnectingWhenLazyConnectIsEnabled(): void
    {
        $options = [
            'lazy_connect'       => true,
            'reconnect_strategy' => ['num_of_retries' => 2, 'factor' => 400, 'exponent_base' => 1, 'jitter_percent' => 0],
        ];

        $connection = (new ValkeyGlideConnector)->connectToCluster([self::REFUSING_ENDPOINT], $options, []);

        self::assertInstanceOf(\ValkeyGlideCluster::class, $connection->client());

        $connection->disconnect();
    }

    /**
     * Verify a disabled lazy_connect reaches the cluster constructor as false,
     * so the refused seed fails construction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectorConnectsClusterEagerlyWhenLazyConnectIsDisabled(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to create initial connections');

        (new ValkeyGlideConnector)->connectToCluster([self::REFUSING_ENDPOINT], ['lazy_connect' => 'false'], []);
    }

    /**
     * Create an external connector-backed connection.
     *
     * @param  array<array-key, mixed>  $overrides
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     */
    private function connectExternal(array $overrides = []): ValkeyGlideConnection
    {
        $connector = new ValkeyGlideConnector;

        $host = getenv('VALKEY_GLIDE_TEST_HOST');
        $port = getenv('VALKEY_GLIDE_TEST_PORT');
        $tls  = getenv('VALKEY_GLIDE_TEST_TLS');

        $config = array_replace(
            [
                'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
                'port' => is_string($port) && is_numeric($port) ? (int) $port : 6379,
                'tls'  => is_string($tls)  && in_array(strtolower($tls), ['1', 'true', 'yes', 'on'], true),
            ],
            $overrides,
        );

        $password = getenv('VALKEY_GLIDE_TEST_PASSWORD');

        if (is_string($password) && $password !== '') {
            $config['password'] = $password;
        }

        $username = getenv('VALKEY_GLIDE_TEST_USERNAME');

        if (is_string($username) && $username !== '') {
            $config['username'] = $username;
        }

        return $connector->connect($config, []);
    }

    /**
     * Assert that the Valkey GLIDE extension and class are available.
     *
     * @return void
     */
    private function assertExtensionIsLoaded(): void
    {
        self::assertTrue(extension_loaded('valkey_glide'), 'External tests require ext-valkey_glide.');
        self::assertTrue(class_exists(\ValkeyGlide::class), 'External tests require the ValkeyGlide class.');
    }

    /**
     * Build a unique key name for external test isolation.
     *
     * @param  string  $suffix
     * @return string
     */
    private function uniqueKey(string $suffix): string
    {
        $entropy = str_replace('.', '', uniqid('', true));

        return sprintf('lvkglide:%s:%s', $suffix, $entropy);
    }
}
