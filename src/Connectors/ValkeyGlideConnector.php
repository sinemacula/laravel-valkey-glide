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
    /** @var (callable(): \ValkeyGlide)|null */
    private $clientFactory;

    /** @var callable(string): bool */
    private $extensionLoader;

    /** @var callable(string): bool */
    private $classResolver;

    /** @var callable(object|string, string): bool */
    private $methodResolver;

    /** @var callable(string): bool */
    private $constantChecker;

    /** @var callable(string): mixed */
    private $constantResolver;

    /**
     * @param  (callable(): \ValkeyGlide)|null  $clientFactory
     * @param  (callable(string): bool)|null  $extensionLoader
     * @param  (callable(string): bool)|null  $classResolver
     * @param  (callable(object|string, string): bool)|null  $methodResolver
     * @param  (callable(string): bool)|null  $constantChecker
     * @param  (callable(string): mixed)|null  $constantResolver
     * @return void
     */
    public function __construct(
        ?callable $clientFactory = null,
        ?callable $extensionLoader = null,
        ?callable $classResolver = null,
        ?callable $methodResolver = null,
        ?callable $constantChecker = null,
        ?callable $constantResolver = null,
    ) {
        $this->clientFactory    = $clientFactory;
        $this->extensionLoader  = $extensionLoader  ?? static fn (string $extension): bool => extension_loaded($extension);
        $this->classResolver    = $classResolver    ?? static fn (string $class): bool => class_exists($class);
        $this->methodResolver   = $methodResolver   ?? static fn (object|string $objectOrClass, string $method): bool => method_exists($objectOrClass, $method);
        $this->constantChecker  = $constantChecker  ?? static fn (string $constant): bool => defined($constant);
        $this->constantResolver = $constantResolver ?? static fn (string $constant): mixed => constant($constant);
    }

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
     * @param  array<array-key, mixed>  $cluster_options
     * @param  array<array-key, mixed>  $options
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    #[\Override]
    public function connectToCluster(array $config, array $cluster_options, array $options): ValkeyGlideConnection
    {
        $seed_node                    = is_array($config[0] ?? null) ? $config[0] : [];
        $resolved_config              = Config::merge($seed_node, array_merge($options, $cluster_options));
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

        $clientFactory = $this->clientFactory ?? static fn (): \ValkeyGlide => new \ValkeyGlide;
        $client        = $clientFactory();

        try {
            $client->connect(...Config::connectArguments($config));
            $this->configureConnectedClient($client, $config);
        } catch (\Throwable $exception) {
            throw new ConnectionException(sprintf('Unable to establish a Valkey GLIDE connection: %s', $exception->getMessage()), previous: $exception);
        }

        return $client;
    }

    /**
     * Apply post-connect options that Laravel users commonly configure.
     *
     * Unsupported options are ignored to keep behavior non-fatal.
     *
     * @param  \ValkeyGlide  $client
     * @param  array<string, mixed>  $config
     * @return void
     */
    private function configureConnectedClient(\ValkeyGlide $client, array $config): void
    {
        $this->selectDatabase($client, $config['database'] ?? null);
        $this->setClientName($client, $config['name'] ?? null);
        $this->setPrefix($client, $config['prefix'] ?? null);
    }

    /**
     * Select the configured logical database when provided.
     *
     * @param  \ValkeyGlide  $client
     * @param  mixed  $database
     * @return void
     */
    private function selectDatabase(\ValkeyGlide $client, mixed $database): void
    {
        if (is_int($database) || is_float($database) || is_string($database)) {
            $client->select((int) $database);
            return;
        }

        if ($database instanceof \Stringable) {
            $client->select((int) (string) $database);
        }
    }

    /**
     * Configure the Redis client name when provided.
     *
     * @param  \ValkeyGlide  $client
     * @param  mixed  $name
     * @return void
     */
    private function setClientName(\ValkeyGlide $client, mixed $name): void
    {
        if (!is_scalar($name) && !$name instanceof \Stringable) {
            return;
        }

        $normalized_name = (string) $name;

        if ($normalized_name === '') {
            return;
        }

        $client->client('SETNAME', $normalized_name);
    }

    /**
     * Configure key prefixing when option APIs are available.
     *
     * @param  \ValkeyGlide  $client
     * @param  mixed  $prefix
     * @return void
     */
    private function setPrefix(\ValkeyGlide $client, mixed $prefix): void
    {
        if (is_int($prefix) || is_float($prefix) || is_bool($prefix) || is_string($prefix)) {
            $prefix = (string) $prefix;
        } elseif ($prefix instanceof \Stringable) {
            $prefix = (string) $prefix;
        } else {
            return;
        }

        $method_resolver  = $this->methodResolver;
        $constant_checker = $this->constantChecker;
        $constant_reader  = $this->constantResolver;

        if ($prefix !== '' && $method_resolver($client, 'setOption') && $constant_checker('Redis::OPT_PREFIX')) {
            call_user_func([$client, 'setOption'], $constant_reader('Redis::OPT_PREFIX'), $prefix);
        }
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
        $extension_loader = $this->extensionLoader;

        if (!$extension_loader('valkey_glide')) {
            throw new ConnectionException('Valkey GLIDE extension (ext-valkey_glide) is not loaded.');
        }

        $class_resolver = $this->classResolver;

        if (!$class_resolver(\ValkeyGlide::class)) {
            throw new ConnectionException('Valkey GLIDE extension is loaded but class "ValkeyGlide" is unavailable.');
        }
    }
}
