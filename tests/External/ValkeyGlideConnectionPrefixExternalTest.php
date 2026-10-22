<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Application;
use Laravel\Horizon\Horizon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\Support\Config;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\TestCase;

/**
 * External checks for the key prefix a Laravel Redis connection resolves to
 * when a prefix is set in more than one place, read back as physical keys.
 *
 * As Laravel's phpredis connector does, a standalone connection's own `prefix`
 * overrides its nested `options` and the shared `database.redis.options`, while
 * a cluster takes its prefix from the options and not from its first node.
 *
 * The cluster cases are skipped unless VALKEY_GLIDE_TEST_CLUSTER_HOST and
 * VALKEY_GLIDE_TEST_CLUSTER_PORT are set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnector::class)]
#[CoversClass(Config::class)]
#[Group('external')]
final class ValkeyGlideConnectionPrefixExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string Prefix in the shared database.redis.options. */
    private const string SHARED_PREFIX = 'lvkglide:prefix:shared:';

    /** @var string Prefix set on a connection itself. */
    private const string OWN_PREFIX = 'lvkglide:prefix:own:';

    /** @var string Prefix in a connection's nested options. */
    private const string NESTED_PREFIX = 'lvkglide:prefix:nested:';

    /** @var string Prefix set on a cluster's first node. */
    private const string NODE_PREFIX = 'lvkglide:prefix:node:';

    /** @var string Prefix configured for Horizon. */
    private const string HORIZON_PREFIX = 'lvkglide:prefix:horizon:';

    /** @var list<string> Every prefix the configuration uses, including none. */
    private const array PREFIXES = [
        self::SHARED_PREFIX,
        self::OWN_PREFIX,
        self::NESTED_PREFIX,
        self::NODE_PREFIX,
        self::HORIZON_PREFIX,
        '',
    ];

    /** @var array<string, \SineMacula\Valkey\Connections\ValkeyGlideConnection> Unprefixed connections, by topology. */
    private array $physicalConnections = [];

    /** @var array<string, list<string>> Physical keys to delete after the test, by topology. */
    private array $trackedKeys = [];

    /**
     * Ensure extension preconditions are met before each external test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertExtensionIsLoaded();
    }

    /**
     * Delete the physical keys the test wrote and close the connections.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->trackedKeys as $topology => $keys) {
            $this->physical($topology)->command('del', $keys);
        }

        foreach ($this->physicalConnections as $connection) {
            $connection->disconnect();
        }

        $this->trackedKeys         = [];
        $this->physicalConnections = [];

        parent::tearDown();
    }

    /**
     * Provide each connection, its topology and the prefix it resolves to.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function connections(): iterable
    {
        yield 'own prefix over nested and shared' => [self::STANDALONE, 'own', self::OWN_PREFIX];

        yield 'empty own prefix over shared' => [self::STANDALONE, 'unprefixed', ''];

        yield 'nested prefix over shared' => [self::STANDALONE, 'nested', self::NESTED_PREFIX];

        yield 'shared prefix' => [self::STANDALONE, 'inherit', self::SHARED_PREFIX];

        yield 'cluster shared prefix over first node' => [self::CLUSTER, 'cluster-node', self::SHARED_PREFIX];

        yield 'cluster own options over first node and shared' => [self::CLUSTER, 'cluster-own', self::OWN_PREFIX];
    }

    /**
     * Verify a key written through the Laravel connection lands under the
     * prefix the connection resolves to, and under no other.
     *
     * @param  string  $topology
     * @param  string  $name
     * @param  string  $expectedPrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('connections')]
    #[Test]
    public function keyIsWrittenUnderTheResolvedPrefix(string $topology, string $name, string $expectedPrefix): void
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $this->assertKeyLandsUnder($topology, $this->connection($name), $expectedPrefix);
    }

    /**
     * Verify Horizon's standalone connection, which Horizon::use() copies from
     * the app's connection with its own prefix in the nested options, writes
     * under the app connection's own prefix when that sets one, as it does on
     * phpredis.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function horizonConnectionCopiedFromAConnectionWithItsOwnPrefixWritesUnderThatPrefix(): void
    {
        config(['horizon.prefix' => self::HORIZON_PREFIX]);

        Horizon::use('own');

        // The Redis manager copies its configuration when it is built, so it
        // is rebuilt to see the horizon connection Horizon::use() just added.
        $this->application()->forgetInstance('redis');

        self::assertSame(self::HORIZON_PREFIX, config('database.redis.horizon.options.prefix'));

        $this->assertKeyLandsUnder(self::STANDALONE, $this->connection('horizon'), self::OWN_PREFIX);
    }

    /**
     * Configure the shared prefix and the connections under test.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $standalone = $this->standaloneConfig();
        $node       = $this->clusterSeed() + ['prefix' => self::NODE_PREFIX];

        $app['config']->set('database.redis.options', ['prefix' => self::SHARED_PREFIX]);
        $app['config']->set('database.redis.own', $standalone + ['prefix' => self::OWN_PREFIX, 'options' => ['prefix' => self::NESTED_PREFIX]]);
        $app['config']->set('database.redis.unprefixed', $standalone + ['prefix' => '']);
        $app['config']->set('database.redis.nested', $standalone + ['options' => ['prefix' => self::NESTED_PREFIX]]);
        $app['config']->set('database.redis.inherit', $standalone);
        $app['config']->set('database.redis.clusters.cluster-node', [$node]);
        $app['config']->set('database.redis.clusters.cluster-own', [$node, 'options' => ['prefix' => self::OWN_PREFIX]]);
    }

    /**
     * Write a unique key through the connection, then assert the physical key
     * exists under the expected prefix only.
     *
     * @param  string  $topology
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $expectedPrefix
     * @return void
     *
     * @throws \Throwable
     */
    private function assertKeyLandsUnder(string $topology, ValkeyGlideConnection $connection, string $expectedPrefix): void
    {
        $key = $this->uniqueName('prefix');

        $this->trackedKeys[$topology][] = $expectedPrefix . $key;

        self::assertSame($expectedPrefix, $connection->getPrefix());

        $connection->command('set', [$key, 'value']);

        self::assertSame('value', $connection->command('get', [$key]));

        foreach (self::PREFIXES as $prefix) {
            $expected = $prefix === $expectedPrefix ? 'value' : false;

            self::assertSame($expected, $this->physical($topology)->command('get', [$prefix . $key]), sprintf('Physical key under prefix [%s].', $prefix));
        }

        $connection->disconnect();
    }

    /**
     * Resolve a Laravel Redis connection as the valkey-glide wrapper.
     *
     * @param  string  $name
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \UnexpectedValueException
     */
    private function connection(string $name): ValkeyGlideConnection
    {
        $redis = $this->application()->make('redis');

        if (!$redis instanceof Factory) {
            throw new \UnexpectedValueException(sprintf('Expected the redis binding to be [%s], received [%s].', Factory::class, get_debug_type($redis)));
        }

        $connection = $redis->connection($name);

        if (!$connection instanceof ValkeyGlideConnection) {
            throw new \UnexpectedValueException(sprintf('Expected connection [%s] to be [%s], received [%s].', $name, ValkeyGlideConnection::class, get_debug_type($connection)));
        }

        return $connection;
    }

    /**
     * Get an unprefixed connection to the topology, to read physical keys.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function physical(string $topology): ValkeyGlideConnection
    {
        return $this->physicalConnections[$topology] ??= $this->connectTo($topology);
    }

    /**
     * Resolve the application container.
     *
     * @return \Illuminate\Foundation\Application
     *
     * @throws \UnexpectedValueException
     */
    private function application(): Application
    {
        if (!$this->app instanceof Application) {
            throw new \UnexpectedValueException('Application container is unavailable.');
        }

        return $this->app;
    }
}
