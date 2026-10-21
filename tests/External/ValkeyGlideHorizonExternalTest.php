<?php

declare(strict_types = 1);

namespace Tests\External;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\JobPayload;
use Laravel\Horizon\RedisHorizonCommandQueue;
use Laravel\Horizon\Repositories\RedisJobRepository;
use Laravel\Horizon\Repositories\RedisMetricsRepository;
use Laravel\Horizon\Repositories\RedisProcessRepository;
use Laravel\Horizon\Repositories\RedisSupervisorRepository;
use Laravel\Horizon\Repositories\RedisTagRepository;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;
use Tests\TestCase;

/**
 * External checks that run Laravel Horizon's own Redis repositories on the
 * valkey-glide connection.
 *
 * Horizon batches nearly every write in a pipeline, reads pipeline results by
 * position, passes integer arguments, and gives its connection a `horizon:`
 * prefix (hash-tagged on a cluster), so these tests assert the physical keys.
 * Every test runs against the standalone server and, when
 * VALKEY_GLIDE_TEST_CLUSTER_HOST and VALKEY_GLIDE_TEST_CLUSTER_PORT are set on
 * Laravel 13 and later, where Horizon supports clusters, against the cluster;
 * the cluster cases are skipped otherwise.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideHorizonExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var string Display name of the job payloads the tests store. */
    private const string JOB_NAME = 'App\Jobs\ProcessPodcast';

    /** @var list<string> Logical Horizon keys to delete after the test. */
    private array $keys = [];

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
     * Delete the Horizon keys the test wrote and restore the real clock.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        if ($this->keys !== []) {
            try {
                $connection = $this->horizonConnection();

                foreach (array_unique($this->keys) as $key) {
                    $connection->command('del', [$key]);
                }
            } catch (\Throwable) {
                // Best effort: the keys expire with Horizon's own TTLs.
            }
        }

        $this->keys = [];

        parent::tearDown();
    }

    /**
     * Verify the job repository's pipelines record a job from push to
     * completion and failure under the Horizon prefix, and that getJobs() reads
     * the pipelined HMGET results by position.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function jobRepositoryRecordsTheJobLifecycle(string $topology): void
    {
        $prefix   = $this->useHorizon($topology);
        $jobs     = new RedisJobRepository($this->redis());
        $physical = $this->physical($topology);
        $payload  = $this->jobPayload();
        $failed   = $this->jobPayload();

        $this->keys = [
            ...$this->keys,
            'recent_jobs',
            'pending_jobs',
            'completed_jobs',
            'failed_jobs',
            'recent_failed_jobs',
            'silenced_jobs',
            $payload->id(),
            $failed->id(),
        ];

        $jobs->pushed('redis', 'default', $payload);

        self::assertSame('pending', $physical->command('hget', [$prefix . $payload->id(), 'status']));
        self::assertSame(0, $physical->command('exists', [$payload->id()]));
        self::assertIsNumeric($physical->command('zscore', [$prefix . 'pending_jobs', $payload->id()]));
        self::assertGreaterThan(0, $physical->command('ttl', [$prefix . $payload->id()]));

        $jobs->reserved('redis', 'default', $payload);
        $jobs->completed($payload);

        self::assertSame('completed', $physical->command('hget', [$prefix . $payload->id(), 'status']));
        self::assertFalse($physical->command('zscore', [$prefix . 'pending_jobs', $payload->id()]));
        self::assertIsNumeric($physical->command('zscore', [$prefix . 'completed_jobs', $payload->id()]));

        $jobs->pushed('redis', 'default', $failed);
        $jobs->failed(new \RuntimeException('job failed'), 'redis', 'default', $failed);

        self::assertSame('failed', $physical->command('hget', [$prefix . $failed->id(), 'status']));
        self::assertSame(1, $jobs->totalFailed());

        $found = $jobs->getJobs([$payload->id(), 'missing-job', $failed->id()]);

        self::assertSame([$payload->id(), $failed->id()], $found->pluck('id')->all());
        self::assertSame(['completed', 'failed'], $found->pluck('status')->all());
        self::assertSame([0, 1], $found->pluck('index')->all());
        self::assertSame([self::JOB_NAME, self::JOB_NAME], $found->pluck('name')->all());
    }

    /**
     * Verify trimRecentJobs(), which pipelines ZREMRANGEBYSCORE with the
     * negative integer scores Horizon computes, trims the prefixed sets.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function jobRepositoryTrimsRecentJobs(string $topology): void
    {
        $prefix   = $this->useHorizon($topology);
        $jobs     = new RedisJobRepository($this->redis());
        $physical = $this->physical($topology);
        $payload  = $this->jobPayload();

        $this->keys = [...$this->keys, 'recent_jobs', 'pending_jobs', $payload->id()];

        $jobs->pushed('redis', 'default', $payload);

        self::assertSame(1, $physical->command('zcard', [$prefix . 'recent_jobs']));
        self::assertSame(1, $jobs->countRecent());

        Carbon::setTestNow(Carbon::now()->addHours(2));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));

        $jobs->trimRecentJobs();

        self::assertSame(0, $physical->command('zcard', [$prefix . 'recent_jobs']));
        self::assertSame(0, $physical->command('zcard', [$prefix . 'pending_jobs']));
    }

    /**
     * Verify the empty pipelines Horizon runs on its hot paths, such as
     * migrated() with no jobs on every worker pop, return without wedging the
     * client, so the next direct command still runs.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function emptyHorizonPipelinesLeaveTheConnectionUsable(string $topology): void
    {
        $this->useHorizon($topology);

        $redis = $this->redis();
        $jobs  = new RedisJobRepository($redis);

        $this->keys[] = 'job_id';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $jobs->migrated('redis', 'default', collect());
        }

        $jobs->deleteMonitored([]);
        (new RedisTagRepository($redis))->addTemporary(60, 'job', []);

        self::assertTrue($jobs->getJobs([])->isEmpty());
        self::assertSame([], (new RedisSupervisorRepository($redis))->get([]));
        self::assertSame([], (new RedisHorizonCommandQueue($redis))->pending('nothing-pending'));

        $first = $jobs->nextJobId();

        self::assertIsNumeric($first);
        self::assertSame((string) ((int) $first + 1), $jobs->nextJobId());
    }

    /**
     * Verify the tag repository's pipelines add, expire and forget the prefixed
     * tag sets.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function tagRepositoryAddsAndForgetsTaggedJobs(string $topology): void
    {
        $prefix   = $this->useHorizon($topology);
        $tags     = new RedisTagRepository($this->redis());
        $physical = $this->physical($topology);

        $first     = $this->uniqueName('first');
        $second    = $this->uniqueName('second');
        $temporary = $this->uniqueName('temporary');

        $this->keys = [...$this->keys, $first, $second, $temporary];

        $tags->add('job-1', [$first, $second]);
        $tags->add('job-2', [$first]);
        $tags->addTemporary(5, 'job-3', [$temporary]);

        self::assertSame(2, $tags->count($first));
        self::assertSame(['job-1', 'job-2'], $physical->command('zrange', [$prefix . $first, 0, -1]));
        self::assertSame(0, $physical->command('exists', [$first]));
        self::assertGreaterThan(0, $physical->command('ttl', [$prefix . $temporary]));
        self::assertSame(['job-3'], $tags->jobs($temporary));

        $tags->forgetJobs([$first, $second], ['job-1']);

        self::assertSame(['job-2'], $tags->jobs($first));
        self::assertSame(0, $tags->count($second));
    }

    /**
     * Verify the supervisor repository stores a real Supervisor with a
     * pipelined HMSET, ZADD and EXPIRE, and reads it back through a pipelined
     * HMGET and ZREVRANGEBYSCORE.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function supervisorRepositoryStoresAndReadsSupervisors(string $topology): void
    {
        $prefix       = $this->useHorizon($topology);
        $redis        = $this->redis();
        $supervisors  = new RedisSupervisorRepository($redis);
        $physical     = $this->physical($topology);
        $name         = 'lvkglide-master:supervisor-' . str_replace('.', '', uniqid('', true));
        $this->keys[] = 'supervisors';
        $this->keys[] = 'supervisor:' . $name;
        $this->keys[] = 'commands:' . $name;

        $this->application()->instance(HorizonCommandQueue::class, new RedisHorizonCommandQueue($redis));

        $supervisors->update(new Supervisor(new SupervisorOptions($name, 'redis', 'default')));

        self::assertSame($name, $physical->command('hget', [$prefix . 'supervisor:' . $name, 'name']));
        $ttl = $physical->command('ttl', [$prefix . 'supervisor:' . $name]);

        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(30, $ttl);
        self::assertSame(0, $physical->command('exists', ['supervisor:' . $name]));
        self::assertContains($name, $supervisors->names());

        $records = $supervisors->get([$name, 'lvkglide-master:missing']);

        self::assertCount(1, $records);
        self::assertSame($name, $records[0]->name);
        self::assertSame('lvkglide-master', $records[0]->master);
        self::assertSame((string) getmypid(), $records[0]->pid);
        self::assertSame('running', $records[0]->status);
        self::assertSame(['redis:default' => 0], $records[0]->processes);

        $supervisors->forget($name);

        self::assertSame([], $supervisors->get([$name]));
    }

    /**
     * Verify the process repository records orphaned processes with a pipelined
     * HSETNX of integer process ids and times, under the Horizon prefix,
     * keeping the time a process was first seen.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function processRepositoryRecordsOrphanedProcesses(string $topology): void
    {
        $prefix       = $this->useHorizon($topology);
        $processes    = new RedisProcessRepository($this->redis());
        $physical     = $this->physical($topology);
        $master       = 'lvkglide-master-' . str_replace('.', '', uniqid('', true));
        $this->keys[] = $master . ':orphans';

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1700000000));

        $processes->orphaned($master, [101, 102]);

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(1700000060));

        $processes->orphaned($master, [102, 103]);

        $orphans = $physical->command('hgetall', [$prefix . $master . ':orphans']);

        self::assertIsArray($orphans);
        self::assertEqualsCanonicalizing([102, 103], array_keys($orphans));
        self::assertSame('1700000000', $orphans[102]);
        self::assertSame('1700000060', $orphans[103]);
        self::assertSame(0, $physical->command('exists', [$master . ':orphans']));
        self::assertSame([102], $processes->orphanedFor($master, 30));
    }

    /**
     * Verify the metrics snapshot, which reads and deletes the measured hash in
     * a transaction and trims the snapshot set with ZREMRANGEBYRANK.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function metricsRepositorySnapshotsMeasuredJobs(string $topology): void
    {
        $prefix   = $this->useHorizon($topology);
        $metrics  = new RedisMetricsRepository($this->redis());
        $physical = $this->physical($topology);
        $job      = self::JOB_NAME . str_replace('.', '', uniqid('', true));

        $this->keys = [...$this->keys, 'measured_jobs', 'measured_queues', 'last_snapshot_at', 'job:' . $job, 'snapshot:job:' . $job];

        $metrics->incrementJob($job, 2.0);
        $metrics->incrementJob($job, 4.0);

        self::assertSame([$job], $metrics->measuredJobs());
        self::assertSame(2, $metrics->throughputForJob($job));

        $metrics->snapshot();

        self::assertSame(0, $physical->command('exists', [$prefix . 'job:' . $job]));

        $snapshots = $metrics->snapshotsForJob($job);

        self::assertCount(1, $snapshots);
        self::assertSame('2', $snapshots[0]->throughput);
        self::assertSame('3', $snapshots[0]->runtime);
        self::assertIsNumeric($physical->command('get', [$prefix . 'last_snapshot_at']));
    }

    /**
     * Verify the command queue reads its pending commands by position from a
     * pipelined LRANGE and LTRIM.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function commandQueueReturnsAndTrimsPendingCommands(string $topology): void
    {
        $prefix       = $this->useHorizon($topology);
        $commands     = new RedisHorizonCommandQueue($this->redis());
        $physical     = $this->physical($topology);
        $name         = 'lvkglide-supervisor-' . str_replace('.', '', uniqid('', true));
        $this->keys[] = 'commands:' . $name;

        $commands->push($name, 'Laravel\Horizon\SupervisorCommands\Scale', ['scale' => 3]);
        $commands->push($name, 'Laravel\Horizon\SupervisorCommands\Terminate', []);

        self::assertSame(2, $physical->command('llen', [$prefix . 'commands:' . $name]));

        $pending = $commands->pending($name);

        self::assertCount(2, $pending);
        self::assertSame('Laravel\Horizon\SupervisorCommands\Scale', $pending[0]->command);
        self::assertSame(['scale' => 3], $pending[0]->options);
        self::assertSame(0, $physical->command('llen', [$prefix . 'commands:' . $name]));
        self::assertSame([], $commands->pending($name));
    }

    /**
     * Verify a Horizon pipeline on the horizon connection runs while a batch is
     * open on the application's own connection, as when Horizon's listeners
     * record jobs pushed by Queue::bulk().
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function horizonPipelineRunsWhileAnotherConnectionBatches(string $topology): void
    {
        $prefix      = $this->useHorizon($topology);
        $redis       = $this->redis();
        $jobs        = new RedisJobRepository($redis);
        $application = $redis->connection($topology);
        $payload     = $this->jobPayload();
        $marker      = '{lvkglide-horizon}:marker-' . str_replace('.', '', uniqid('', true));

        $this->keys = [...$this->keys, 'recent_jobs', 'pending_jobs', $payload->id()];

        self::assertInstanceOf(ValkeyGlideConnection::class, $application);

        $results = $application->pipeline(static function ($pipe) use ($jobs, $payload, $marker): void {
            $pipe->set($marker, 'outer');
            $jobs->pushed('redis', 'default', $payload);
            $pipe->get($marker);
            $pipe->del($marker);
        });

        self::assertSame([true, 'outer', 1], $results);
        self::assertSame('pending', $this->physical($topology)->command('hget', [$prefix . $payload->id(), 'status']));
    }

    /**
     * Configure the standalone and cluster connections Horizon can use.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.options', []);
        $app['config']->set('database.redis.' . self::STANDALONE, $this->standaloneConfig());
        $app['config']->set('database.redis.clusters.' . self::CLUSTER, [$this->clusterSeed()]);
    }

    /**
     * Point Horizon at the topology, as Horizon::use() does at boot, with a
     * unique `horizon:` prefix, and return the prefix Horizon settled on.
     *
     * @param  string  $topology
     * @return string
     *
     * @throws \Throwable
     */
    private function useHorizon(string $topology): string
    {
        $this->skipUnlessTopologyIsConfigured($topology);

        // Horizon only configures a cluster connection, with its hash-tagged
        // prefix, when Laravel's connections have hasHashTag(), which Laravel
        // 13 added; before that it connects to the first node as a standalone
        // server, so the cluster cases cannot run. Reflection reads the
        // installed framework rather than the one static analysis sees.
        if ($topology === self::CLUSTER && !(new \ReflectionClass(Connection::class))->hasMethod('hasHashTag')) {
            self::markTestSkipped('Horizon supports Redis clusters only on Laravel 13 and later, where Connection::hasHashTag() exists.');
        }

        config(['horizon.prefix' => 'horizon:' . $this->uniqueName('horizon') . ':']);

        Horizon::use($topology);

        // The Redis manager copies its configuration when it is built, so it
        // is rebuilt to see the horizon connection Horizon::use() just added.
        $this->application()->forgetInstance('redis');

        $prefix = config('horizon.prefix');

        self::assertIsString($prefix);
        self::assertStringStartsWith($topology === self::CLUSTER ? '{horizon:' : 'horizon:', $prefix);

        return $prefix;
    }

    /**
     * Resolve the Redis manager.
     *
     * @return \Illuminate\Contracts\Redis\Factory
     *
     * @throws \Throwable
     */
    private function redis(): RedisFactory
    {
        return $this->application()->make(RedisFactory::class);
    }

    /**
     * Resolve Horizon's connection as the valkey-glide wrapper.
     *
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \UnexpectedValueException
     */
    private function horizonConnection(): ValkeyGlideConnection
    {
        return $this->managedConnection('horizon');
    }

    /**
     * Resolve the topology's unprefixed connection, to read physical keys.
     *
     * @param  string  $topology
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \UnexpectedValueException
     */
    private function physical(string $topology): ValkeyGlideConnection
    {
        return $this->managedConnection($topology);
    }

    /**
     * Resolve a Redis manager connection as the valkey-glide wrapper.
     *
     * @param  string  $name
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     *
     * @throws \UnexpectedValueException
     */
    private function managedConnection(string $name): ValkeyGlideConnection
    {
        $connection = $this->redis()->connection($name);

        if ($connection instanceof ValkeyGlideConnection) {
            return $connection;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis connection [%s] to be [%s], received [%s].', $name, ValkeyGlideConnection::class, get_debug_type($connection)));
    }

    /**
     * Build a Horizon job payload with a unique id.
     *
     * @return \Laravel\Horizon\JobPayload
     */
    private function jobPayload(): JobPayload
    {
        $id = 'lvkglide-job-' . str_replace('.', '', uniqid('', true));

        return new JobPayload(json_encode([
            'uuid'        => $id,
            'id'          => $id,
            'displayName' => self::JOB_NAME,
            'job'         => 'Illuminate\Queue\CallQueuedHandler@call',
            'data'        => ['commandName' => self::JOB_NAME],
            'attempts'    => 0,
        ], JSON_THROW_ON_ERROR));
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
