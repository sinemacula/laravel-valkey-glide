<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Support\AddressResolver;

/**
 * AddressResolver test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AddressResolver::class)]
final class AddressResolverTest extends TestCase
{
    /** @var string Loopback host used in expected normalized addresses. */
    private const string LOOPBACK_HOST = '127.0.0.1';

    /** @var int Default Redis port used in expected normalized addresses. */
    private const int DEFAULT_PORT = 6379;

    /**
     * Verify addresses returns the default loopback endpoint for an empty config.
     *
     * @return void
     */
    #[Test]
    public function addressesReturnsDefaultEndpointForEmptyConfig(): void
    {
        $addresses = AddressResolver::addresses([]);

        self::assertSame(
            [['host' => self::LOOPBACK_HOST, 'port' => self::DEFAULT_PORT]],
            $addresses,
        );
    }

    /**
     * Verify addresses normalizes a list of address arrays from the config.
     *
     * @return void
     */
    #[Test]
    public function addressesNormalizesConfiguredAddressList(): void
    {
        $addresses = AddressResolver::addresses([
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
     * Verify addresses skips non-array entries within the addresses list.
     *
     * @return void
     */
    #[Test]
    public function addressesSkipsNonArrayEntriesInAddressList(): void
    {
        $addresses = AddressResolver::addresses([
            'addresses' => [
                ['host' => 'cache-a', 'port' => 6380],
                'invalid-entry',
            ],
        ]);

        self::assertSame(
            [['host' => 'cache-a', 'port' => 6380]],
            $addresses,
        );
    }

    /**
     * Verify addresses falls back to the single endpoint when the list is empty.
     *
     * @return void
     */
    #[Test]
    public function addressesFallsBackToSingleEndpointWhenAddressListIsEmpty(): void
    {
        $addresses = AddressResolver::addresses([
            'addresses' => [],
            'host'      => 'single-host',
            'port'      => 6390,
        ]);

        self::assertSame(
            [['host' => 'single-host', 'port' => 6390]],
            $addresses,
        );
    }

    /**
     * Verify addresses falls back to the endpoint when every address entry is invalid.
     *
     * @return void
     */
    #[Test]
    public function addressesFallsBackToEndpointWhenEveryAddressEntryIsInvalid(): void
    {
        $addresses = AddressResolver::addresses([
            'addresses' => ['only-a-string', 42],
            'host'      => 'endpoint-host',
            'port'      => 6399,
        ]);

        self::assertSame(
            [['host' => 'endpoint-host', 'port' => 6399]],
            $addresses,
        );
    }

    /**
     * Verify addresses ignores a non-array addresses value and uses the endpoint.
     *
     * @return void
     */
    #[Test]
    public function addressesIgnoresNonArrayAddressesValueAndUsesEndpoint(): void
    {
        $addresses = AddressResolver::addresses([
            'addresses' => 'not-an-array',
            'host'      => 'fallback-host',
            'port'      => 6395,
        ]);

        self::assertSame(
            [['host' => 'fallback-host', 'port' => 6395]],
            $addresses,
        );
    }

    /**
     * Verify addresses normalizes scalar host values to strings.
     *
     * @return void
     */
    #[Test]
    public function addressesNormalizesScalarHostValues(): void
    {
        $addresses = AddressResolver::addresses(['host' => 1234, 'port' => 6380]);

        self::assertSame([['host' => '1234', 'port' => 6380]], $addresses);
    }

    /**
     * Verify addresses normalizes stringable host objects to strings.
     *
     * @return void
     */
    #[Test]
    public function addressesNormalizesStringableHostValues(): void
    {
        $addresses = AddressResolver::addresses([
            'host' => new \SimpleXMLElement('<root>cache-xml</root>'),
            'port' => 6403,
        ]);

        self::assertSame([['host' => 'cache-xml', 'port' => 6403]], $addresses);
    }

    /**
     * Verify addresses uses the default host when the host is a non-stringable object.
     *
     * @return void
     */
    #[Test]
    public function addressesUsesDefaultHostForNonStringableObjectHost(): void
    {
        $addresses = AddressResolver::addresses(['host' => new \stdClass, 'port' => 6404]);

        self::assertSame(
            [['host' => self::LOOPBACK_HOST, 'port' => 6404]],
            $addresses,
        );
    }

    /**
     * Provide port values that must resolve to the default port.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidPortProvider(): iterable
    {
        yield 'null port' => [null];
        yield 'zero port' => [0];
        yield 'string port' => ['not-a-port'];
        yield 'negative int' => [-1];
    }

    /**
     * Verify addresses uses the default port when the port value is absent or invalid.
     *
     * @param  mixed  $port
     * @return void
     */
    #[DataProvider('invalidPortProvider')]
    #[Test]
    public function addressesUsesDefaultPortWhenPortIsAbsentOrInvalid(mixed $port): void
    {
        $addresses = AddressResolver::addresses(['host' => 'cache-host', 'port' => $port]);

        self::assertSame(self::DEFAULT_PORT, $addresses[0]['port']);
    }

    /**
     * Verify clusterAddresses returns configured nodes from flat shapes.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesReturnsConfiguredNodesFromFlatShape(): void
    {
        $addresses = AddressResolver::clusterAddresses([
            ['host' => 'node-1', 'port' => 6380],
            ['host' => 'node-2', 'port' => 6381],
        ]);

        self::assertSame(
            [
                ['host' => 'node-1', 'port' => 6380],
                ['host' => 'node-2', 'port' => 6381],
            ],
            $addresses,
        );
    }

    /**
     * Verify clusterAddresses extracts nodes from nested shapes.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesExtractsNodesFromNestedShape(): void
    {
        $addresses = AddressResolver::clusterAddresses([
            [
                'writer' => ['host' => 'node-2', 'port' => '6381'],
                'reader' => ['host' => 'node-3'],
            ],
        ]);

        self::assertSame(
            [
                ['host' => 'node-2', 'port' => 6381],
                ['host' => 'node-3', 'port' => self::DEFAULT_PORT],
            ],
            $addresses,
        );
    }

    /**
     * Verify clusterAddresses handles both flat and nested node shapes together.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesHandlesMixedFlatAndNestedShapes(): void
    {
        $addresses = AddressResolver::clusterAddresses([
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
     * Verify clusterAddresses falls back to the default address when no valid nodes exist.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesFallsBackToDefaultAddressWhenNoValidNodesExist(): void
    {
        $addresses = AddressResolver::clusterAddresses(['invalid']);

        self::assertSame(
            [['host' => self::LOOPBACK_HOST, 'port' => self::DEFAULT_PORT]],
            $addresses,
        );
    }

    /**
     * Verify clusterAddresses skips scalar entries and collects later nested nodes.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesSkipsScalarEntriesAndKeepsLaterNestedNodes(): void
    {
        $addresses = AddressResolver::clusterAddresses([
            'plain-string',
            [
                'writer' => ['host' => 'node-late', 'port' => 6400],
            ],
        ]);

        self::assertSame(
            [['host' => 'node-late', 'port' => 6400]],
            $addresses,
        );
    }

    /**
     * Verify clusterAddresses ignores nested non-node arrays alongside real nodes.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesIgnoresNestedNonNodeArraysAlongsideRealNodes(): void
    {
        $addresses = AddressResolver::clusterAddresses([
            [
                'meta' => ['weight' => 5, 'role' => 'reader'],
                'real' => ['host' => 'node-real', 'port' => 6400],
            ],
        ]);

        self::assertSame(
            [['host' => 'node-real', 'port' => 6400]],
            $addresses,
        );
    }

    /**
     * Verify clusterAddresses treats an array with only a port key as a valid node.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesRecognizesPortOnlyArrayAsNode(): void
    {
        $addresses = AddressResolver::clusterAddresses([
            ['port' => 6385],
        ]);

        self::assertSame(
            [['host' => self::LOOPBACK_HOST, 'port' => 6385]],
            $addresses,
        );
    }

    /**
     * Verify clusterAddresses treats an array with only a host key as a valid node.
     *
     * @return void
     */
    #[Test]
    public function clusterAddressesRecognizesHostOnlyArrayAsNode(): void
    {
        $addresses = AddressResolver::clusterAddresses([
            ['host' => 'solo-host'],
        ]);

        self::assertSame(
            [['host' => 'solo-host', 'port' => self::DEFAULT_PORT]],
            $addresses,
        );
    }
}
