<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Support\Config;

/**
 * Config test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    /** @var string Loopback host used in expected normalized addresses. */
    private const string LOOPBACK_HOST = '127.0.0.1';

    /** @var int Default Redis port used in expected normalized addresses. */
    private const int DEFAULT_PORT = 6379;

    /**
     * Verify nested options override top-level options and base config values.
     *
     * @return void
     */
    #[Test]
    public function mergePrioritizesNestedOptionsOverOptionsAndBaseConfig(): void
    {
        $config = [
            'host'     => 'base-host',
            'port'     => 6380,
            'database' => 0,
            'options'  => [
                'host'     => 'nested-host',
                'database' => 3,
            ],
        ];

        $options = [
            'host'     => 'option-host',
            'port'     => 6381,
            'database' => 2,
        ];

        $merged = Config::merge($config, $options);

        self::assertSame('nested-host', $merged['host']);
        self::assertSame(6381, $merged['port']);
        self::assertSame(3, $merged['database']);
        self::assertArrayNotHasKey('options', $merged);
    }

    /**
     * Verify merge ignores non-array nested options payloads.
     *
     * @return void
     */
    #[Test]
    public function mergeIgnoresNonArrayNestedOptions(): void
    {
        $merged = Config::merge([
            'host'    => 'base-host',
            'options' => 'invalid-options',
        ], [
            'host' => 'option-host',
        ]);

        self::assertSame('option-host', $merged['host']);
        self::assertArrayNotHasKey('options', $merged);
    }

    /**
     * Verify single-endpoint connect arguments use package defaults.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsDefaultsToLoopbackAndDefaultPort(): void
    {
        $arguments = Config::connectArguments([]);

        self::assertSame(
            [
                'addresses' => [
                    ['host' => self::LOOPBACK_HOST, 'port' => self::DEFAULT_PORT],
                ],
            ],
            $arguments,
        );
    }

    /**
     * Verify configured addresses are normalized and invalid entries ignored.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsNormalizesConfiguredAddressesAndSkipsInvalidEntries(): void
    {
        $arguments = Config::connectArguments([
            'addresses' => [
                ['host' => 'cache-a', 'port' => '6380'],
                'invalid-address',
                ['host' => '', 'port' => 0],
            ],
        ]);

        self::assertSame(
            [
                'addresses' => [
                    ['host' => 'cache-a', 'port' => 6380],
                    ['host' => self::LOOPBACK_HOST, 'port' => self::DEFAULT_PORT],
                ],
            ],
            $arguments,
        );
    }

    /**
     * Verify scalar host values are string-normalized for connect arguments.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsNormalizesScalarHostValues(): void
    {
        $arguments = Config::connectArguments([
            'host' => 1234,
            'port' => 6380,
        ]);

        self::assertSame(
            [
                'addresses' => [
                    ['host' => '1234', 'port' => 6380],
                ],
            ],
            $arguments,
        );
    }

    /**
     * Provide connection config shapes that must enable TLS.
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function tlsConfigurationProvider(): iterable
    {
        yield 'tls boolean flag' => [['tls' => true]];

        yield 'tls scheme' => [['scheme' => 'tls']];

        yield 'iam configuration' => [
            [
                'iam' => [
                    'username'     => 'iam-user',
                    'cluster_name' => 'cluster-a',
                    'region'       => 'eu-west-1',
                ],
            ],
        ];
    }

    /**
     * Verify TLS is enabled for supported TLS and IAM input shapes.
     *
     * @param  array<string, mixed>  $config
     * @return void
     */
    #[DataProvider('tlsConfigurationProvider')]
    #[Test]
    public function connectArgumentsEnablesTlsWhenConfigured(array $config): void
    {
        $arguments = Config::connectArguments($config);

        self::assertArrayHasKey('use_tls', $arguments);
        self::assertTrue($arguments['use_tls']);
    }

    /**
     * Verify ACL credentials are emitted when username and password are set.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsBuildsPasswordCredentialsIncludingUsernameWhenProvided(): void
    {
        $arguments = Config::connectArguments([
            'username' => 'cache-user',
            'password' => 'secret',
        ]);

        self::assertSame(
            [
                'password' => 'secret',
                'username' => 'cache-user',
            ],
            $arguments['credentials'],
        );
    }

    /**
     * Verify IAM credentials are mapped into GLIDE's expected shape.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsBuildsIamCredentialsInExpectedShape(): void
    {
        $arguments = Config::connectArguments([
            'iam' => [
                'username'         => 'iam-user',
                'cluster_name'     => 'cluster-prod',
                'region'           => 'eu-west-2',
                'refresh_interval' => 120,
            ],
        ]);

        self::assertTrue($arguments['use_tls']);
        self::assertSame(
            [
                'username'  => 'iam-user',
                'iamConfig' => [
                    'clusterName'            => 'cluster-prod',
                    'region'                 => 'eu-west-2',
                    'service'                => 'Elasticache',
                    'refreshIntervalSeconds' => 120,
                ],
            ],
            $arguments['credentials'],
        );
    }

    /**
     * Verify incomplete IAM definitions are ignored for credentials.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsDropsIncompleteIamCredentials(): void
    {
        $arguments = Config::connectArguments([
            'iam' => [
                'username'     => 'iam-user',
                'cluster_name' => '',
                'region'       => 'us-east-1',
            ],
        ]);

        self::assertArrayNotHasKey('credentials', $arguments);
        self::assertTrue($arguments['use_tls']);
    }

    /**
     * Verify database and client name values are normalized when valid.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsIncludesDatabaseAndClientNameWhenValid(): void
    {
        $arguments = Config::connectArguments([
            'database' => '4',
            'name'     => '  worker-a  ',
        ]);

        self::assertSame(4, $arguments['database_id']);
        self::assertSame('worker-a', $arguments['client_name']);
    }

    /**
     * Verify invalid database and client name values are omitted.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsExcludesInvalidDatabaseAndClientNameValues(): void
    {
        $arguments = Config::connectArguments([
            'database' => -1,
            'name'     => '   ',
        ]);

        self::assertArrayNotHasKey('database_id', $arguments);
        self::assertArrayNotHasKey('client_name', $arguments);
    }

    /**
     * Verify cluster node extraction supports flat and nested definitions.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesReturnsConfiguredNodesFromFlatAndNestedShapes(): void
    {
        $addresses = Config::clusterAddresses([
            ['host' => 'node-1', 'port' => 6380],
            [
                'writer' => ['host' => 'node-2', 'port' => '6381'],
                'reader' => ['host' => 'node-3'],
            ],
            'invalid',
        ]);

        self::assertSame(
            [
                ['host' => 'node-1', 'port' => 6380],
                ['host' => 'node-2', 'port' => 6381],
                ['host' => 'node-3', 'port' => self::DEFAULT_PORT],
            ],
            $addresses,
        );
    }

    /**
     * Verify cluster fallback address is used when no nodes are configured.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesFallsBackToDefaultAddressWhenNoValidNodesExist(): void
    {
        $addresses = Config::clusterAddresses(['invalid']);

        self::assertSame(
            [
                ['host' => self::LOOPBACK_HOST, 'port' => self::DEFAULT_PORT],
            ],
            $addresses,
        );
    }

    /**
     * Verify cluster connect arguments include seed addresses and base config.
     *
     * @return void
     */
    #[Test]
    public function clusterConnectArgumentsUsesSeedAddressesAndBaseConfiguration(): void
    {
        $arguments = Config::clusterConnectArguments(
            [
                ['host' => 'node-a', 'port' => 6380],
                ['host' => 'node-b', 'port' => 6381],
            ],
            [
                'password' => 'secret',
                'database' => 2,
            ],
        );

        self::assertSame(
            [
                ['host' => 'node-a', 'port' => 6380],
                ['host' => 'node-b', 'port' => 6381],
            ],
            $arguments['addresses'],
        );
        self::assertSame(['password' => 'secret'], $arguments['credentials']);
        self::assertSame(2, $arguments['database_id']);
    }

    /**
     * Verify the public addresses helper normalizes a configured address list.
     *
     * @return void
     */
    #[Test]
    public function addressesNormalizesConfiguredAddressList(): void
    {
        $addresses = Config::addresses([
            'addresses' => [
                ['host' => 'cache-a', 'port' => '6380'],
                ['host' => 'cache-b'],
            ],
        ]);

        self::assertSame(
            [
                ['host' => 'cache-a', 'port' => 6380],
                ['host' => 'cache-b', 'port' => self::DEFAULT_PORT],
            ],
            $addresses,
        );
    }

    /**
     * Verify addresses falls back to the single endpoint when none are listed.
     *
     * @return void
     */
    #[Test]
    public function addressesFallsBackToSingleEndpointWhenAddressListIsEmpty(): void
    {
        $addresses = Config::addresses([
            'addresses' => [],
            'host'      => 'single-host',
            'port'      => 6390,
        ]);

        self::assertSame(
            [
                ['host' => 'single-host', 'port' => 6390],
            ],
            $addresses,
        );
    }

    /**
     * Verify addresses ignores a non-array address list and uses the endpoint.
     *
     * @return void
     */
    #[Test]
    public function addressesIgnoresNonArrayAddressListAndUsesEndpoint(): void
    {
        $addresses = Config::addresses([
            'addresses' => 'not-an-array',
            'host'      => 'fallback-host',
            'port'      => 6395,
        ]);

        self::assertSame(
            [
                ['host' => 'fallback-host', 'port' => 6395],
            ],
            $addresses,
        );
    }

    /**
     * Verify addresses falls back to the endpoint when every entry is invalid.
     *
     * @return void
     */
    #[Test]
    public function addressesFallsBackToEndpointWhenEveryAddressEntryIsInvalid(): void
    {
        $addresses = Config::addresses([
            'addresses' => ['only-a-string', 42],
            'host'      => 'endpoint-host',
            'port'      => 6399,
        ]);

        self::assertSame(
            [
                ['host' => 'endpoint-host', 'port' => 6399],
            ],
            $addresses,
        );
    }

    /**
     * Verify IAM credentials require the iam config to be an array, not a scalar.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsIgnoresScalarIamConfiguration(): void
    {
        $arguments = Config::connectArguments([
            'iam' => 'not-an-array',
        ]);

        self::assertArrayNotHasKey('credentials', $arguments);
        self::assertArrayNotHasKey('use_tls', $arguments);
    }

    /**
     * Verify a configured non-zero IAM refresh interval is preserved verbatim.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsPreservesNonZeroIamRefreshInterval(): void
    {
        $arguments = Config::connectArguments([
            'iam' => [
                'username'         => 'iam-user',
                'cluster_name'     => 'cluster-x',
                'region'           => 'eu-west-3',
                'refresh_interval' => 45,
            ],
        ]);

        self::assertSame(45, $arguments['credentials']['iamConfig']['refreshIntervalSeconds']);
    }

    /**
     * Verify a zero IAM refresh interval is replaced with the package default.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsReplacesZeroIamRefreshIntervalWithDefault(): void
    {
        $arguments = Config::connectArguments([
            'iam' => [
                'username'         => 'iam-user',
                'cluster_name'     => 'cluster-x',
                'region'           => 'eu-west-3',
                'refresh_interval' => 0,
            ],
        ]);

        self::assertSame(300, $arguments['credentials']['iamConfig']['refreshIntervalSeconds']);
    }

    /**
     * Verify cluster nodes nested under a non-node array are skipped after a scalar.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesSkipsScalarEntriesAndKeepsLaterNestedNodes(): void
    {
        $addresses = Config::clusterAddresses([
            'plain-string',
            [
                'writer' => ['host' => 'node-late', 'port' => 6400],
            ],
        ]);

        self::assertSame(
            [
                ['host' => 'node-late', 'port' => 6400],
            ],
            $addresses,
        );
    }

    /**
     * Verify nested non-node arrays are not collected alongside real nodes.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesIgnoresNestedNonNodeArraysAlongsideRealNodes(): void
    {
        $addresses = Config::clusterAddresses([
            [
                'meta' => ['weight' => 5, 'role' => 'reader'],
                'real' => ['host' => 'node-real', 'port' => 6400],
            ],
        ]);

        self::assertSame(
            [
                ['host' => 'node-real', 'port' => 6400],
            ],
            $addresses,
        );
    }

    /**
     * Verify boolean scalar host values are string-normalized for connect args.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsNormalizesBooleanHostValues(): void
    {
        $arguments = Config::connectArguments([
            'host' => true,
            'port' => 6402,
        ]);

        self::assertSame(
            [
                'addresses' => [
                    ['host' => '1', 'port' => 6402],
                ],
            ],
            $arguments,
        );
    }

    /**
     * Verify stringable host values are string-normalized for connect args.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsNormalizesStringableHostValues(): void
    {
        $arguments = Config::connectArguments([
            'host' => new \SimpleXMLElement('<root>cache-xml</root>'),
            'port' => 6403,
        ]);

        self::assertSame(
            [
                'addresses' => [
                    ['host' => 'cache-xml', 'port' => 6403],
                ],
            ],
            $arguments,
        );
    }

    /**
     * Verify a non-stringable database id falls back to being omitted.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsOmitsNonNumericStringableDatabaseId(): void
    {
        $arguments = Config::connectArguments([
            'database' => new \SimpleXMLElement('<root>not-a-number</root>'),
        ]);

        self::assertArrayNotHasKey('database_id', $arguments);
    }

    /**
     * Verify a numeric stringable database id is normalized to an integer.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsNormalizesNumericStringableDatabaseId(): void
    {
        $arguments = Config::connectArguments([
            'database' => new \SimpleXMLElement('<root>7</root>'),
        ]);

        self::assertSame(7, $arguments['database_id']);
    }

    /**
     * Verify a float database id is truncated to an integer.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsTruncatesFloatDatabaseId(): void
    {
        $arguments = Config::connectArguments([
            'database' => 3.9,
        ]);

        self::assertSame(3, $arguments['database_id']);
    }

    /**
     * Verify a zero database id is preserved as a valid non-negative value.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsPreservesZeroDatabaseId(): void
    {
        $arguments = Config::connectArguments([
            'database' => 0,
        ]);

        self::assertSame(0, $arguments['database_id']);
    }

    /**
     * Verify a numeric-string database id is normalized to an integer.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsNormalizesNumericStringDatabaseId(): void
    {
        $arguments = Config::connectArguments([
            'database' => '11',
        ]);

        self::assertSame(11, $arguments['database_id']);
    }

    /**
     * Verify a non-numeric string database id is rejected rather than cast.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsOmitsNonNumericStringDatabaseId(): void
    {
        $arguments = Config::connectArguments([
            'database' => 'not-numeric',
        ]);

        self::assertArrayNotHasKey('database_id', $arguments);
    }

    /**
     * Verify a non-stringable object host falls back to the default loopback host.
     *
     * @return void
     */
    #[Test]
    public function connectArgumentsUsesDefaultHostForNonStringableObjectHost(): void
    {
        $arguments = Config::connectArguments([
            'host' => new \stdClass,
            'port' => 6404,
        ]);

        self::assertSame(
            [
                'addresses' => [
                    ['host' => self::LOOPBACK_HOST, 'port' => 6404],
                ],
            ],
            $arguments,
        );
    }
}
