<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\TestCase;

/**
 * External Laravel queue integration checks for the valkey-glide Redis client.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideLaravelQueueExternalTest extends TestCase
{
    /** @var string Prefix configured on the Redis queue connection. */
    private const string REDIS_CONNECTION_PREFIX = 'lvkglide:queue:conn:';

    /** @var string Default queue name used for Redis queue configuration. */
    private const string DEFAULT_QUEUE_NAME = 'default';

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
     * Verify Laravel's Redis queue driver resolves the valkey-glide connection.
     *
     * @return void
     */
    #[Test]
    public function queueDriverUsesValkeyGlideRedisConnection(): void
    {
        self::assertInstanceOf(
            ValkeyGlideConnection::class,
            $this->redisQueueConnection()->getConnection(),
        );
    }

    /**
     * Verify queue push/pop roundtrip succeeds through Laravel's Redis queue.
     *
     * @return void
     */
    #[Test]
    public function queuePushPopRoundtripSucceeds(): void
    {
        $queue_name = $this->uniqueQueueName('roundtrip');
        $queue      = $this->redisQueueConnection();
        $payload    = $this->queuePayload();

        $queued_id = $queue->pushRaw($payload, $queue_name);

        self::assertIsString($queued_id);
        self::assertSame(1, $queue->size($queue_name));

        $job = $queue->pop($queue_name);

        self::assertInstanceOf(RedisJob::class, $job);
        self::assertSame($payload, $job->getRawBody());

        $job->delete();

        self::assertSame(0, $queue->size($queue_name));
        $queue->clear($queue_name);
    }

    /**
     * Verify queue writes are stored under the expected prefixed Redis key.
     *
     * @return void
     */
    #[Test]
    public function queuePushWritesExpectedPhysicalRedisKey(): void
    {
        $queue_name   = $this->uniqueQueueName('physical-key');
        $queue        = $this->redisQueueConnection();
        $connection   = $this->redisConnection();
        $physical_key = self::REDIS_CONNECTION_PREFIX . 'queues:' . $queue_name;
        $logical_key  = 'queues:' . $queue_name;

        $queue->pushRaw($this->queuePayload(), $queue_name);

        self::assertGreaterThan(
            0,
            $this->normalizeIntegerResponse(
                $connection->client()->rawcommand('LLEN', $physical_key),
            ),
        );

        self::assertSame(
            0,
            $this->normalizeIntegerResponse(
                $connection->client()->rawcommand('LLEN', $logical_key),
            ),
        );

        $queue->clear($queue_name);
    }

    /**
     * Configure Redis and queue defaults used by queue integration tests.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $host = getenv('VALKEY_GLIDE_TEST_HOST');
        $port = getenv('VALKEY_GLIDE_TEST_PORT');

        /** @var array<string, mixed> $redis_default */
        $redis_default = [
            'host'   => is_string($host) && $host !== '' ? $host : '127.0.0.1',
            'port'   => is_string($port) && is_numeric($port) ? (int) $port : 6379,
            'tls'    => $this->resolveBooleanEnv('VALKEY_GLIDE_TEST_TLS', false),
            'prefix' => self::REDIS_CONNECTION_PREFIX,
        ];

        $password = getenv('VALKEY_GLIDE_TEST_PASSWORD');

        if (is_string($password) && $password !== '') {
            $redis_default['password'] = $password;
        }

        $username = getenv('VALKEY_GLIDE_TEST_USERNAME');

        if (is_string($username) && $username !== '') {
            $redis_default['username'] = $username;
        }

        $app['config']->set('database.redis.default', $redis_default);
        $app['config']->set('database.redis.options', []);
        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', [
            'driver'       => 'redis',
            'connection'   => 'default',
            'queue'        => self::DEFAULT_QUEUE_NAME,
            'retry_after'  => 90,
            'block_for'    => null,
            'after_commit' => false,
        ]);
    }

    /**
     * Resolve the Redis manager's default connection as the valkey-glide wrapper.
     *
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     */
    private function redisConnection(): ValkeyGlideConnection
    {
        if (!$this->app instanceof Application) {
            throw new \UnexpectedValueException('Application container is unavailable.');
        }

        $redis      = $this->app->make(RedisManager::class);
        $connection = $redis->connection('default');

        if ($connection instanceof ValkeyGlideConnection) {
            return $connection;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis default connection to be [%s], received [%s].', ValkeyGlideConnection::class, get_debug_type($connection)));
    }

    /**
     * Resolve Laravel's Redis queue connection.
     *
     * @return \Illuminate\Queue\RedisQueue
     */
    private function redisQueueConnection(): RedisQueue
    {
        if (!$this->app instanceof Application) {
            throw new \UnexpectedValueException('Application container is unavailable.');
        }

        $manager = $this->app->make(QueueManager::class);
        $queue   = $manager->connection('redis');

        if ($queue instanceof RedisQueue) {
            return $queue;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis queue connection to be [%s], received [%s].', RedisQueue::class, get_debug_type($queue)));
    }

    /**
     * Build a JSON payload accepted by Laravel's Redis queue driver.
     *
     * @return string
     */
    private function queuePayload(): string
    {
        $id = str_replace('.', '', uniqid('job', true));

        return json_encode(
            [
                'id'       => $id,
                'job'      => 'tests.stub',
                'data'     => ['message' => 'payload'],
                'attempts' => 0,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Build a unique queue name for external queue test isolation.
     *
     * @param  string  $suffix
     * @return string
     */
    private function uniqueQueueName(string $suffix): string
    {
        $entropy = str_replace('.', '', uniqid('', true));

        return sprintf('lvkglide:queue:%s:%s', $suffix, $entropy);
    }

    /**
     * Normalize mixed integer-like command responses.
     *
     * @param  mixed  $response
     * @return int
     */
    private function normalizeIntegerResponse(mixed $response): int
    {
        if (is_int($response)) {
            return $response;
        }

        if (is_string($response) && is_numeric($response)) {
            return (int) $response;
        }

        throw new \UnexpectedValueException(sprintf('Expected integer response, received [%s].', get_debug_type($response)));
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

    /**
     * Resolve boolean environment variables with a fallback default.
     *
     * @param  string  $name
     * @param  bool  $default
     * @return bool
     */
    private function resolveBooleanEnv(string $name, bool $default): bool
    {
        $value = getenv($name);

        if (!is_string($value)) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
