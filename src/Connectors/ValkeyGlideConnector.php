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
final class ValkeyGlideConnector implements ConnectorContract
{
    /**
     * Create a new connector instance.
     *
     * @param  (\Closure(): \ValkeyGlide)|null  $clientFactory
     * @param  (\Closure(string): bool)|null  $extensionLoader
     * @param  (\Closure(string): bool)|null  $classResolver
     * @return void
     */
    public function __construct(

        /** Factory used to instantiate the Valkey GLIDE client. */
        private readonly ?\Closure $clientFactory = null,

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
        $resolved_config = Config::merge($config, $options);

        return $this->createConnection($resolved_config);
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
        $seed_node                    = $this->firstClusterNode($config);
        $resolved_config              = Config::merge($seed_node, array_merge($options, $clusterOptions));
        $resolved_config['addresses'] = Config::clusterAddresses($config);

        return $this->createConnection($resolved_config);
    }

    /**
     * Build the Laravel connection wrapper and reconnect callback.
     *
     * @param  array<string, mixed>  $resolved_config
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function createConnection(array $resolved_config): ValkeyGlideConnection
    {
        $connector = fn (): \ValkeyGlide => $this->createClient($resolved_config);

        return new ValkeyGlideConnection($connector(), $connector, $resolved_config);
    }

    /**
     * Create and connect the underlying GLIDE client instance.
     *
     * @param  array<string, mixed>  $config
     * @return \ValkeyGlide
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function createClient(array $config): \ValkeyGlide
    {
        $this->validateGlideExtension();

        $client_factory = $this->clientFactory ?? static fn (): \ValkeyGlide => new \ValkeyGlide;
        $client         = $client_factory();

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
     * @return void
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function validateGlideExtension(): void
    {
        $extension_loader = $this->extensionLoader ?? static fn (string $extension): bool => extension_loaded($extension);

        if (!$extension_loader('valkey_glide')) {
            throw new ConnectionException('Valkey GLIDE extension (ext-valkey_glide) is not loaded.');
        }

        $class_resolver = $this->classResolver ?? static fn (string $class): bool => class_exists($class);

        if (!$class_resolver(\ValkeyGlide::class)) {
            throw new ConnectionException('Valkey GLIDE extension is loaded but class "ValkeyGlide" is unavailable.');
        }
    }
}
