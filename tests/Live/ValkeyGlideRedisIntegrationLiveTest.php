<?php

declare(strict_types = 1);

namespace Tests\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;

/**
 * Redis-backed live integration checks for connector and connection behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnector::class)]
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('live')]
final class ValkeyGlideRedisIntegrationLiveTest extends TestCase
{
    /** @var string Environment flag required to enable live tests. */
    private const string LIVE_TEST_FLAG = 'VALKEY_GLIDE_LIVE_TESTS';

    /**
     * Verify the connector can establish a live connection and execute PING.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectorConnectsAndPingSucceeds(): void
    {
        $connection = $this->connectLive();

        $result = $connection->command('ping');

        self::assertContains($result, [true, 'PONG', 'pong']);

        $connection->disconnect();
    }

    /**
     * Verify set/get roundtrip works through the Laravel connection wrapper.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function connectionSetGetRoundtripSucceeds(): void
    {
        $connection = $this->connectLive();
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
        $connection = $this->connectLive(['prefix' => $prefix]);

        $connection->command('set', [$plain_key, 'value-b']);

        self::assertSame('value-b', $connection->client()->get($prefixed));
        self::assertSame('value-b', $connection->command('get', [$plain_key]));

        $connection->command('del', [$plain_key]);
        $connection->disconnect();
    }

    /**
     * Create a live connector-backed connection or skip when unavailable.
     *
     * @param  array<array-key, mixed>  $overrides
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     */
    private function connectLive(array $overrides = []): ValkeyGlideConnection
    {
        $this->skipUnlessLiveEnabled();

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

        try {
            return $connector->connect($config, []);
        } catch (\Throwable $exception) {
            self::markTestSkipped('Unable to establish live Redis connection: ' . $exception->getMessage());
        }
    }

    /**
     * Skip the current test unless live gating is fully enabled.
     *
     * @return void
     */
    private function skipUnlessLiveEnabled(): void
    {
        if (getenv(self::LIVE_TEST_FLAG) !== '1') {
            self::markTestSkipped(self::LIVE_TEST_FLAG . '=1 is required to run live tests.');
        }

        if (!extension_loaded('valkey_glide')) {
            self::markTestSkipped('Live tests require ext-valkey_glide to be loaded.');
        }
    }

    /**
     * Build a unique key name for live test isolation.
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
