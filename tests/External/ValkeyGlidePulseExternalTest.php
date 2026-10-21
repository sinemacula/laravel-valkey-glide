<?php

declare(strict_types = 1);

namespace Tests\External;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\PulseServiceProvider;
use Laravel\Pulse\Value;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Pulse\ValkeyGlideIngest;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\Fakes\PulseStorageFake;
use Tests\TestCase;

/**
 * External checks that run Laravel Pulse's Redis ingest on the valkey-glide
 * connection, from recording entries through `Pulse::ingest()` to digesting
 * them with `Pulse::digest()`, as `pulse:work` does.
 *
 * Pulse's stream key carries the shared `database.redis.options.prefix`, which
 * each test makes unique, and never the connection's own prefix, as Pulse's raw
 * commands reach it over phpredis, so the tests assert the physical key. Every
 * test runs on a prefixed connection, one with a prefix of its own and one with
 * the shared prefix, as Laravel gives a connection without its own, against the
 * standalone server and, when VALKEY_GLIDE_TEST_CLUSTER_HOST and
 * VALKEY_GLIDE_TEST_CLUSTER_PORT are set, against the cluster; the cluster
 * cases are skipped otherwise.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideIngest::class)]
#[Group('external')]
final class ValkeyGlidePulseExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string Pulse's stream, before the shared prefix. */
    private const string STREAM = 'laravel:pulse:ingest';

    /** @var string The prefix of the connections with a prefix of their own. */
    private const string OWN_PREFIX = 'lvkglide-pulse-own:';

    /** @var string The shared Redis prefix of the test, unique to it. */
    private string $prefix = '';

    /** @var \Tests\Fakes\PulseStorageFake The storage Pulse digests into. */
    private PulseStorageFake $storage;

    /** @var string|null The topology whose stream the test wrote. */
    private ?string $topology = null;

    /** @var string The prefix of the connection Pulse ingests over. */
    private string $connectionPrefix = '';

    /**
     * Ensure extension preconditions are met before each external test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        $this->prefix  = $this->uniqueName('pulse') . ':';
        $this->storage = new PulseStorageFake;

        parent::setUp();

        $this->assertExtensionIsLoaded();

        app()->instance(Storage::class, $this->storage);

        Pulse::handleExceptionsUsing(static function (\Throwable $exception): never {
            throw $exception;
        });
    }

    /**
     * Delete the stream the test wrote and restore the real clock.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        if ($this->topology !== null) {
            try {
                $this->physical($this->topology)->command('del', [$this->stream(), $this->connectionPrefix . $this->stream()]);
            } catch (\Throwable) {
                // Best effort: the stream's key is unique to the test.
            }
        }

        $this->topology = null;

        parent::tearDown();
    }

    /**
     * Provide each prefixed connection Pulse ingests over: one with a prefix of
     * its own, and one with the shared prefix, on each topology.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function pulseConnections(): iterable
    {
        foreach (self::topologies() as $label => [$topology]) {
            yield $label . ', own prefix' => [$topology, true];

            yield $label . ', shared prefix' => [$topology, false];
        }
    }

    /**
     * Verify the entries Pulse records are added to its stream under the shared
     * prefix alone, as over phpredis, then digested into storage and deleted
     * from the stream.
     *
     * @param  string  $topology
     * @param  bool  $ownPrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('pulseConnections')]
    #[Test]
    public function pulseIngestsAndDigestsEntries(string $topology, bool $ownPrefix): void
    {
        $physical = $this->usePulse($topology, $ownPrefix);

        Pulse::record('slow_request', 'GET /podcasts', 1200)->max()->count();
        Pulse::set('system', 'server-1', 'up');

        self::assertSame(2, Pulse::ingest());
        self::assertSame(2, $physical->command('xlen', [$this->stream()]));
        self::assertSame(0, $physical->command('exists', [$this->connectionPrefix . $this->stream()]));

        self::assertSame(2, Pulse::digest());
        self::assertSame(0, $physical->command('xlen', [$this->stream()]));
        self::assertCount(1, $this->storage->stored);

        [$entry, $value] = $this->storage->stored[0];

        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame(['slow_request', 'GET /podcasts', 1200], [$entry->type, $entry->key, $entry->value]);
        self::assertSame(['max', 'count'], $entry->aggregations());
        self::assertInstanceOf(Value::class, $value);
        self::assertSame(['system', 'server-1', 'up'], [$value->type, $value->key, $value->value]);
    }

    /**
     * Verify the stream is digested a chunk at a time, in the order the entries
     * were ingested.
     *
     * @param  string  $topology
     * @param  bool  $ownPrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('pulseConnections')]
    #[Test]
    public function pulseDigestsTheStreamInChunks(string $topology, bool $ownPrefix): void
    {
        $physical = $this->usePulse($topology, $ownPrefix);

        config(['pulse.ingest.redis.chunk' => 2]);

        foreach (['a', 'b', 'c', 'd', 'e'] as $key) {
            Pulse::record('cache_hit', $key)->count();
        }

        self::assertSame(5, Pulse::ingest());
        self::assertSame(5, $physical->command('xlen', [$this->stream()]));
        self::assertSame(5, Pulse::digest());
        self::assertSame([2, 2, 1], array_map(count(...), $this->storage->stored));
        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn (Entry|Value $item): string => $item->key, array_merge(...$this->storage->stored)));
        self::assertSame(0, $physical->command('xlen', [$this->stream()]));
        self::assertSame(0, Pulse::digest());
    }

    /**
     * Verify a configured length trims the stream, approximately, as Pulse's
     * own ingest does.
     *
     * @param  string  $topology
     * @param  bool  $ownPrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('pulseConnections')]
    #[Test]
    public function pulseTrimsTheStreamToTheConfiguredLength(string $topology, bool $ownPrefix): void
    {
        $physical = $this->usePulse($topology, $ownPrefix);

        $this->ingestEntries(300);

        config(['pulse.ingest.trim.keep' => 10]);

        app(Ingest::class)->trim();

        $length = $physical->command('xlen', [$this->stream()]);

        self::assertIsInt($length);
        self::assertGreaterThanOrEqual(10, $length);
        self::assertLessThan(300, $length);
    }

    /**
     * Verify a configured age trims the stream, approximately, to the entries
     * added within it.
     *
     * @param  string  $topology
     * @param  bool  $ownPrefix
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('pulseConnections')]
    #[Test]
    public function pulseTrimsTheStreamToTheConfiguredAge(string $topology, bool $ownPrefix): void
    {
        $physical = $this->usePulse($topology, $ownPrefix);

        $this->ingestEntries(300);

        config(['pulse.ingest.trim.keep' => '1 minute']);

        app(Ingest::class)->trim();

        self::assertSame(300, $physical->command('xlen', [$this->stream()]));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        app(Ingest::class)->trim();

        $length = $physical->command('xlen', [$this->stream()]);

        self::assertIsInt($length);
        self::assertLessThan(300, $length);
    }

    /**
     * Register Pulse's provider beside the package's.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            PulseServiceProvider::class,
        ];
    }

    /**
     * Configure Pulse's Redis ingest under the test's shared prefix, over
     * connections with a prefix of their own, set in their options as Horizon
     * sets its own, and connections without one.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.options', ['prefix' => $this->prefix]);
        $app['config']->set('database.redis.' . self::connectionName(self::STANDALONE, true), $this->standaloneConfig() + ['options' => ['prefix' => self::OWN_PREFIX]]);
        $app['config']->set('database.redis.' . self::connectionName(self::STANDALONE, false), $this->standaloneConfig());
        $app['config']->set('database.redis.clusters.' . self::connectionName(self::CLUSTER, true), ['options' => ['prefix' => self::OWN_PREFIX], $this->clusterSeed()]);
        $app['config']->set('database.redis.clusters.' . self::connectionName(self::CLUSTER, false), [$this->clusterSeed()]);
        $app['config']->set('pulse.ingest.driver', 'redis');
    }

    /**
     * Point Pulse's Redis ingest at a prefixed connection to the topology, and
     * return a connection that reads physical keys.
     *
     * @param  string  $topology
     * @param  bool  $ownPrefix
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \Throwable
     */
    private function usePulse(string $topology, bool $ownPrefix): ValkeyGlideConnection
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        $name = self::connectionName($topology, $ownPrefix);

        config(['pulse.ingest.redis.connection' => $name]);

        $connection = app(RedisFactory::class)->connection($name);

        self::assertInstanceOf(ValkeyGlideIngest::class, app(Ingest::class));
        self::assertInstanceOf(ValkeyGlideConnection::class, $connection);
        self::assertSame($ownPrefix ? self::OWN_PREFIX : $this->prefix, $connection->getPrefix());

        $this->topology         = $topology;
        $this->connectionPrefix = $connection->getPrefix();

        return $this->physical($topology);
    }

    /**
     * Record and ingest the given number of entries.
     *
     * @param  int  $count
     * @return void
     */
    private function ingestEntries(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Pulse::record('cache_miss', 'key-' . $i)->count();
        }

        self::assertSame($count, Pulse::ingest());
    }

    /**
     * Connect to the topology without a prefix, to read physical keys.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function physical(string $topology): ValkeyGlideConnection
    {
        return $this->connectTo($topology);
    }

    /**
     * Name the connection to the topology, with a prefix of its own or with the
     * shared prefix.
     *
     * @param  string  $topology
     * @param  bool  $ownPrefix
     * @return string
     */
    private static function connectionName(string $topology, bool $ownPrefix): string
    {
        return $topology . ($ownPrefix ? '-own-prefix' : '-shared-prefix');
    }

    /**
     * Build the stream's physical key under the test's shared prefix.
     *
     * @return string
     */
    private function stream(): string
    {
        return $this->prefix . self::STREAM;
    }
}
