<?php

declare(strict_types = 1);

namespace Tests\External;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * External extension checks for CI and explicit local opt-in.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
#[Group('external')]
final class ValkeyGlideExtensionExternalTest extends TestCase
{
    /**
     * Verify the extension class is available when external tests are enabled.
     *
     * @return void
     */
    #[Test]
    public function extensionClassIsAvailable(): void
    {
        $this->skipUnlessExtensionLoaded();

        self::assertTrue(class_exists(\ValkeyGlide::class));
    }

    /**
     * Verify the extension client can be instantiated when external tests run.
     *
     * @return void
     */
    #[Test]
    public function extensionClientCanBeInstantiated(): void
    {
        $this->skipUnlessExtensionLoaded();

        self::assertInstanceOf(\ValkeyGlide::class, new \ValkeyGlide);
    }

    /**
     * Verify a minimal connect and close roundtrip using resolved host config.
     *
     * @return void
     */
    #[Test]
    public function extensionCanConnectAndCloseWithResolvedHostConfig(): void
    {
        $this->skipUnlessExtensionLoaded();

        $host = getenv('VALKEY_GLIDE_TEST_HOST');
        $port = getenv('VALKEY_GLIDE_TEST_PORT');

        $resolved_host = is_string($host) && $host !== '' ? $host : '127.0.0.1';

        $client = new \ValkeyGlide;

        $connected = $client->connect(
            addresses  : [
                [
                    'host' => $resolved_host,
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
     * Skip the current test unless the extension is loaded.
     *
     * @return void
     */
    private function skipUnlessExtensionLoaded(): void
    {
        if (!extension_loaded('valkey_glide')) {
            self::markTestSkipped('External tests require ext-valkey_glide to be loaded.');
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
