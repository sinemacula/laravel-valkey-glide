<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connectors;

use Illuminate\Contracts\Redis\Connector as ConnectorContract;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;
use SineMacula\Valkey\Support\Config;

/**
 * Builds Laravel Redis connections backed by Valkey GLIDE.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValkeyGlideConnector implements ConnectorContract
{
    /**
     * Create a new connector instance.
     *
     * @param  (\Closure(): \ValkeyGlide)|null  $clientFactory
     * @param  (\Closure(array<string, mixed>): \ValkeyGlideCluster)|null  $clusterClientFactory
     * @param  (\Closure(string): bool)|null  $extensionLoader
     * @param  (\Closure(string): bool)|null  $classResolver
     * @return void
     */
    public function __construct(

        /** Factory used to instantiate the Valkey GLIDE client. */
        private readonly ?\Closure $clientFactory = null,

        /** Factory used to instantiate the Valkey GLIDE cluster client. */
        private readonly ?\Closure $clusterClientFactory = null,

        /** Callback used to resolve loaded extension state. */
        private readonly ?\Closure $extensionLoader = null,

        /** Callback used to resolve runtime class availability. */
        private readonly ?\Closure $classResolver = null,

    ) {}

    /**
     * Create a Valkey GLIDE connection for a single configured endpoint.
     *
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $options
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    #[\Override]
    public function connect(array $config, array $options): ValkeyGlideConnection
    {
        $resolvedConfig = Config::merge($config, $options);

        return $this->createConnection($resolvedConfig);
    }

    /**
     * Create a Valkey GLIDE connection for cluster-style configuration.
     *
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $clusterOptions
     * @param  array<array-key, mixed>  $options
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    #[\Override]
    public function connectToCluster(array $config, array $clusterOptions, array $options): ValkeyGlideConnection
    {
        $seedNode                    = $this->firstClusterNode($config);
        $resolvedConfig              = Config::merge($seedNode, array_merge($options, $clusterOptions));
        $resolvedConfig['addresses'] = Config::clusterAddresses($config);

        return $this->createClusterConnection($resolvedConfig);
    }

    /**
     * Build the Laravel connection wrapper and reconnect callback for a single endpoint.
     *
     * @param  array<string, mixed>  $resolvedConfig
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function createConnection(array $resolvedConfig): ValkeyGlideConnection
    {
        $connector = fn (): \ValkeyGlide => $this->createClient($resolvedConfig);

        return new ValkeyGlideConnection($connector(), $connector, $resolvedConfig);
    }

    /**
     * Build the Laravel connection wrapper and reconnect callback for a cluster endpoint.
     *
     * @param  array<string, mixed>  $resolvedConfig
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function createClusterConnection(array $resolvedConfig): ValkeyGlideConnection
    {
        $connector = fn (): \ValkeyGlideCluster => $this->createClusterClient($resolvedConfig);

        return new ValkeyGlideConnection($connector(), $connector, $resolvedConfig);
    }

    /**
     * Create and connect the underlying GLIDE client instance.
     *
     * @param  array<string, mixed>  $config
     * @return \ValkeyGlide
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function createClient(#[\SensitiveParameter] array $config): \ValkeyGlide
    {
        $this->validateGlideExtension();

        $clientFactory = $this->clientFactory ?? static fn (): \ValkeyGlide => new \ValkeyGlide;
        $client        = $clientFactory();

        try {
            $client->connect(...Config::connectArguments($config));
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ConnectionException(sprintf('Unable to establish a Valkey GLIDE connection: %s', $exception->getMessage()), previous: $exception);
        }

        return $client;
    }

    /**
     * Create the underlying GLIDE cluster client instance via its constructor.
     *
     * @param  array<string, mixed>  $config
     * @return \ValkeyGlideCluster
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function createClusterClient(#[\SensitiveParameter] array $config): \ValkeyGlideCluster
    {
        $this->validateGlideExtension(requireCluster: true);

        $factory = $this->clusterClientFactory
            ?? static fn (array $arguments): \ValkeyGlideCluster => new \ValkeyGlideCluster(...$arguments);

        try {
            return $factory(Config::connectArguments($config));
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ConnectionException(sprintf('Unable to establish a Valkey GLIDE cluster connection: %s', $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * Resolve the first valid cluster node from config.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<array-key, mixed>
     */
    private function firstClusterNode(array $config): array
    {
        foreach ($config as $node) {
            if (is_array($node)) {
                return $node;
            }
        }

        return [];
    }

    /**
     * Verify GLIDE extension availability before creating clients.
     *
     * @param  bool  $requireCluster
     * @return void
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function validateGlideExtension(bool $requireCluster = false): void
    {
        $extensionLoader = $this->extensionLoader ?? static fn (string $extension): bool => extension_loaded($extension);

        if (!$extensionLoader('valkey_glide')) {
            throw new ConnectionException('Valkey GLIDE extension (ext-valkey_glide) is not loaded.');
        }

        $classResolver = $this->classResolver ?? static fn (string $class): bool => class_exists($class);

        if (!$classResolver(\ValkeyGlide::class)) {
            throw new ConnectionException('Valkey GLIDE extension is loaded but class "ValkeyGlide" is unavailable.');
        }

        if ($requireCluster && !$classResolver(\ValkeyGlideCluster::class)) {
            throw new ConnectionException('Valkey GLIDE extension is loaded but class "ValkeyGlideCluster" is unavailable.');
        }
    }
}
