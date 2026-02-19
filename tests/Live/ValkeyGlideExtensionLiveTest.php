<?php

declare(strict_types = 1);

namespace Tests\Live;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Live extension checks for CI and explicit local opt-in.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
#[Group('live')]
final class ValkeyGlideExtensionLiveTest extends TestCase
{
    /** @var string Environment flag required to enable live tests. */
    private const string LIVE_TEST_FLAG = 'VALKEY_GLIDE_LIVE_TESTS';

    /**
     * Verify the extension class is available when live tests are enabled.
     *
     * @return void
     */
    #[Test]
    public function extensionClassIsAvailable(): void
    {
        $this->skipUnlessLiveEnabled();

        self::assertTrue(class_exists(\ValkeyGlide::class));
    }

    /**
     * Verify the extension client can be instantiated when live tests run.
     *
     * @return void
     */
    #[Test]
    public function extensionClientCanBeInstantiated(): void
    {
        $this->skipUnlessLiveEnabled();

        self::assertInstanceOf(\ValkeyGlide::class, new \ValkeyGlide);
    }

    /**
     * Verify a minimal connect/close roundtrip when host config is provided.
     *
     * @return void
     */
    #[Test]
    public function extensionCanConnectAndCloseWhenHostIsConfigured(): void
    {
        $this->skipUnlessLiveEnabled();

        $host = getenv('VALKEY_GLIDE_TEST_HOST');

        if (!is_string($host) || $host === '') {
            self::markTestSkipped('Set VALKEY_GLIDE_TEST_HOST to run network live tests.');
        }

        $port = getenv('VALKEY_GLIDE_TEST_PORT');

        $client = new \ValkeyGlide;

        $connected = $client->connect(
            addresses  : [
                [
                    'host' => $host,
                    'port' => is_numeric($port) ? (int) $port : 6379,
                ],
            ],
            use_tls    : $this->resolveBooleanEnv('VALKEY_GLIDE_TEST_TLS', false),
            credentials: $this->resolveCredentials(),
        );

        self::assertTrue($connected);
        self::assertTrue($client->close());
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
     * Resolve optional ACL credentials from environment variables.
     *
     * @return array<string, string>|null
     */
    private function resolveCredentials(): ?array
    {
        $password = getenv('VALKEY_GLIDE_TEST_PASSWORD');

        if (!is_string($password) || $password === '') {
            return null;
        }

        $credentials = ['password' => $password];

        $username = getenv('VALKEY_GLIDE_TEST_USERNAME');

        if (is_string($username) && $username !== '') {
            $credentials['username'] = $username;
        }

        return $credentials;
    }

    /**
     * Resolve boolean environment variables with a fallback default.
     *
     * @param  string  $name
     * @param  bool  $default
     * @return bool
     */
    private function resolveBooleanEnv(string $name, bool $default): bool
    {
        $value = getenv($name);

        if (!is_string($value)) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
