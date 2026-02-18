<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Support\Config;

/**
 * @internal
 */
#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    /**
     * Verify option arrays merge with connection options precedence.
     *
     * @return void
     */
    public function testMergeMergesTopLevelOptionsAndConnectionOptions(): void
    {
        $config = [
            'host'     => 'cache.local',
            'database' => 0,
            'options'  => [
                'database' => 3,
                'scheme'   => 'tls',
            ],
        ];

        $options = [
            'timeout'  => 5,
            'database' => 2,
        ];

        $merged = Config::merge($config, $options);

        self::assertSame('cache.local', $merged['host']);
        self::assertSame(3, $merged['database']);
        self::assertSame('tls', $merged['scheme']);
        self::assertSame(5, $merged['timeout']);
        self::assertArrayNotHasKey('options', $merged);
    }

    /**
     * Verify explicit address lists are normalized.
     *
     * @return void
     */
    public function testAddressesReturnsNormalizedConfiguredAddresses(): void
    {
        $addresses = Config::addresses([
            'addresses' => [
                ['host' => 'a.example', 'port' => '6380'],
                ['host' => 'b.example'],
                'invalid',
            ],
        ]);

        self::assertSame(
            [
                ['host' => 'a.example', 'port' => 6380],
                ['host' => 'b.example', 'port' => 6379],
            ],
            $addresses,
        );
    }

    /**
     * Verify host/port fallback builds a single normalized address.
     *
     * @return void
     */
    public function testAddressesFallsBackToSingleAddressConfig(): void
    {
        $addresses = Config::addresses([
            'host' => 'single.example',
            'port' => 6381,
        ]);

        self::assertSame(
            [['host' => 'single.example', 'port' => 6381]],
            $addresses,
        );
    }

    /**
     * Verify cluster seed arrays are normalized and invalid nodes are skipped.
     *
     * @return void
     */
    public function testClusterAddressesNormalizesNodesAndSkipsInvalidValues(): void
    {
        $addresses = Config::clusterAddresses([
            ['host' => 'node-1', 'port' => '7000'],
            'skip-me',
            ['host' => 'node-2'],
        ]);

        self::assertSame(
            [
                ['host' => 'node-1', 'port' => 7000],
                ['host' => 'node-2', 'port' => 6379],
            ],
            $addresses,
        );
    }

    /**
     * Verify cluster addresses fall back to localhost when no nodes are valid.
     *
     * @return void
     */
    public function testClusterAddressesFallsBackWhenNoValidNodesExist(): void
    {
        $addresses = Config::clusterAddresses(['invalid']);

        self::assertSame(
            [['host' => '127.0.0.1', 'port' => 6379]],
            $addresses,
        );
    }

    /**
     * Verify TLS and username/password credentials are included when configured.
     *
     * @return void
     */
    public function testConnectArgumentsIncludesTlsCredentialsAndProvidedAddresses(): void
    {
        $arguments = Config::connectArguments(
            [
                'scheme'   => 'tls',
                'username' => 'app-user',
                'password' => 'secret',
            ],
            [['host' => 'provided', 'port' => 6400]],
        );

        self::assertSame([['host' => 'provided', 'port' => 6400]], $arguments['addresses']);
        self::assertTrue($arguments['use_tls']);
        self::assertSame(
            [
                'username' => 'app-user',
                'password' => 'secret',
            ],
            $arguments['credentials'],
        );
    }

    /**
     * Verify IAM credentials normalize refresh interval and required keys.
     *
     * @return void
     */
    public function testConnectArgumentsBuildsIamCredentialsAndNormalizesRefreshInterval(): void
    {
        $arguments = Config::connectArguments([
            'iam' => [
                'username'         => 'iam-user',
                'cluster_name'     => 'cluster-a',
                'region'           => 'eu-west-1',
                'refresh_interval' => 0,
            ],
        ]);

        self::assertTrue($arguments['use_tls']);
        self::assertSame('iam-user', $arguments['credentials']['username']);
        self::assertSame('cluster-a', $arguments['credentials']['iamConfig'][\ValkeyGlide::IAM_CONFIG_CLUSTER_NAME]);
        self::assertSame('eu-west-1', $arguments['credentials']['iamConfig'][\ValkeyGlide::IAM_CONFIG_REGION]);
        self::assertSame(\ValkeyGlide::IAM_SERVICE_ELASTICACHE, $arguments['credentials']['iamConfig'][\ValkeyGlide::IAM_CONFIG_SERVICE]);
        self::assertSame(300, $arguments['credentials']['iamConfig'][\ValkeyGlide::IAM_CONFIG_REFRESH_INTERVAL]);
    }

    /**
     * Verify invalid credential input does not emit credentials payloads.
     *
     * @return void
     */
    public function testConnectArgumentsOmitsCredentialsWhenInvalid(): void
    {
        $arguments = Config::connectArguments([
            'password' => '',
            'iam'      => [
                'username'     => 'iam-user',
                'cluster_name' => '',
                'region'       => 'eu-west-1',
            ],
        ]);

        self::assertArrayNotHasKey('credentials', $arguments);
        self::assertTrue($arguments['use_tls']);
    }

    /**
     * Verify password-only credentials are supported.
     *
     * @return void
     */
    public function testConnectArgumentsSupportsPasswordOnlyCredentials(): void
    {
        $arguments = Config::connectArguments([
            'password' => 'top-secret',
        ]);

        self::assertSame(['password' => 'top-secret'], $arguments['credentials']);
    }

    /**
     * Verify TLS resolution honors explicit and scheme-based settings.
     *
     * @param  array<string, mixed>  $config
     * @param  bool  $expected_tls
     * @return void
     */
    #[DataProvider('usesTlsProvider')]
    public function testConnectArgumentsTlsResolution(array $config, bool $expected_tls): void
    {
        $arguments = Config::connectArguments($config);

        self::assertSame($expected_tls, $arguments['use_tls'] ?? false);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function usesTlsProvider(): iterable
    {
        yield 'explicit tls true' => [
            ['tls' => true],
            true,
        ];

        yield 'explicit tls false' => [
            ['tls' => false, 'scheme' => 'tls'],
            false,
        ];

        yield 'tls scheme fallback' => [
            ['scheme' => 'tls'],
            true,
        ];
    }
}
