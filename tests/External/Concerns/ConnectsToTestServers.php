<?php

declare(strict_types = 1);

namespace Tests\External\Concerns;

use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;

/**
 * Connects external tests to the standalone server and the optional cluster
 * named by the environment.
 *
 * The standalone server is read from VALKEY_GLIDE_TEST_HOST, _PORT, _TLS,
 * _USERNAME and _PASSWORD. The cluster's seed node is read from
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT; tests for
 * the cluster topology are skipped when either is unset.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait ConnectsToTestServers
{
    /** @var string Topology label, and connection name, for the standalone server. */
    private const string STANDALONE = 'standalone';

    /** @var string Topology label, and connection name, for the cluster. */
    private const string CLUSTER = 'cluster';

    /**
     * Provide the topologies each test runs against.
     *
     * @return iterable<string, array{string}>
     */
    public static function topologies(): iterable
    {
        yield 'standalone' => [self::STANDALONE];

        yield 'cluster' => [self::CLUSTER];
    }

    /**
     * Connect to the topology through the connector, with the given prefix.
     *
     * An empty prefix gives a connection that reads and writes physical keys.
     *
     * @param  string  $topology
     * @param  string  $prefix
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function connectTo(string $topology, string $prefix = ''): ValkeyGlideConnection
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $connector = new ValkeyGlideConnector;

        if ($topology === self::CLUSTER) {
            return $connector->connectToCluster([$this->clusterSeed()], [], ['prefix' => $prefix]);
        }

        return $connector->connect($this->standaloneConfig() + ['prefix' => $prefix], []);
    }

    /**
     * Skip the test when its topology is the cluster and none is configured.
     *
     * @param  string  $topology
     * @return void
     */
    private function skipUnlessTopologyIsConfigured(string $topology): void
    {
        if ($topology !== self::CLUSTER || $this->isClusterConfigured()) {
            return;
        }

        self::markTestSkipped('Set VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT to run the cluster cases.');
    }

    /**
     * Determine whether the environment names a cluster seed node.
     *
     * @return bool
     */
    private function isClusterConfigured(): bool
    {
        $host = getenv('VALKEY_GLIDE_TEST_CLUSTER_HOST');
        $port = getenv('VALKEY_GLIDE_TEST_CLUSTER_PORT');

        return is_string($host) && $host !== '' && is_string($port) && is_numeric($port);
    }

    /**
     * Build the cluster seed node from the environment, defaulting to a local
     * node on port 7000.
     *
     * @return array{host: string, port: int}
     */
    private function clusterSeed(): array
    {
        $host = getenv('VALKEY_GLIDE_TEST_CLUSTER_HOST');
        $port = getenv('VALKEY_GLIDE_TEST_CLUSTER_PORT');

        return [
            'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
            'port' => is_string($port) && is_numeric($port) ? (int) $port : 7000,
        ];
    }

    /**
     * Build the standalone connection config from the environment.
     *
     * @return array<string, mixed>
     */
    private function standaloneConfig(): array
    {
        $host = getenv('VALKEY_GLIDE_TEST_HOST');
        $port = getenv('VALKEY_GLIDE_TEST_PORT');
        $tls  = getenv('VALKEY_GLIDE_TEST_TLS');

        $config = [
            'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
            'port' => is_string($port) && is_numeric($port) ? (int) $port : 6379,
            'tls'  => is_string($tls)  && in_array(strtolower($tls), ['1', 'true', 'yes', 'on'], true),
        ];

        foreach (['username' => 'VALKEY_GLIDE_TEST_USERNAME', 'password' => 'VALKEY_GLIDE_TEST_PASSWORD'] as $key => $variable) {
            $value = getenv($variable);

            if (!is_string($value) || $value === '') {
                continue;
            }

            $config[$key] = $value;
        }

        return $config;
    }

    /**
     * Build a unique name for test isolation.
     *
     * @param  string  $label
     * @return string
     */
    private function uniqueName(string $label): string
    {
        return sprintf('lvkglide-%s-%s', $label, str_replace('.', '', uniqid('', true)));
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
}
