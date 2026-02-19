<?php

declare(strict_types = 1);

namespace Tests\External;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;

/**
 * Redis-backed external integration checks for connector and connection behavior.
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
     * Verify the connector can establish an external connection and execute PING.
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
     * Verify set and get roundtrip works through the Laravel connection wrapper.
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
        $plain_key  = $this->uniqueKey('prefixed');
        $prefixed   = $prefix . $plain_key;
        $connection = $this->connectExternal(['prefix' => $prefix]);

        $connection->command('set', [$plain_key, 'value-b']);

        self::assertSame('value-b', $connection->client()->get($prefixed));
        self::assertSame('value-b', $connection->command('get', [$plain_key]));

        $connection->command('del', [$plain_key]);
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
     * Verify database selection in connector config routes data to that database.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectorDatabaseConfigRoutesCommandsToExpectedDatabase(): void
    {
        $key = $this->uniqueKey('database-routing');

        $secondary_connection = $this->connectExternal(['database' => self::SECONDARY_DATABASE]);
        $default_connection   = $this->connectExternal(['database' => self::DEFAULT_DATABASE]);

        $secondary_connection->command('set', [$key, 'db-one']);

        self::assertContains($default_connection->command('get', [$key]), [null, false]);
        self::assertSame('db-one', $secondary_connection->command('get', [$key]));

        $secondary_connection->command('del', [$key]);
        $secondary_connection->disconnect();
        $default_connection->disconnect();
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
