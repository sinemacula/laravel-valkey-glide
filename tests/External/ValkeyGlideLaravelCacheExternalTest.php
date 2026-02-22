<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\TestCase;

/**
 * External Laravel cache integration checks for the valkey-glide Redis client.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideLaravelCacheExternalTest extends TestCase
{
    /** @var string Prefix configured on the Redis connection. */
    private const string REDIS_CONNECTION_PREFIX = 'lvkglide:conn:';

    /** @var string Prefix configured on the dedicated lock Redis connection. */
    private const string LOCK_CONNECTION_PREFIX = 'lvkglide:lock:conn:';

    /** @var string Prefix configured on the Laravel Redis cache store. */
    private const string CACHE_STORE_PREFIX = 'lvkglide:cache:';

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
     * Verify the Laravel cache store uses the valkey-glide Redis connection.
     *
     * @return void
     */
    #[Test]
    public function cacheStoreUsesValkeyGlideConnection(): void
    {
        self::assertInstanceOf(ValkeyGlideConnection::class, $this->redisConnection());
    }

    /**
     * Verify cache put/get/forget works through the valkey-glide Redis store.
     *
     * @return void
     */
    #[Test]
    public function cachePutGetForgetRoundtripSucceeds(): void
    {
        $key         = $this->uniqueKey('roundtrip');
        $cache_store = $this->redisCacheStore();

        self::assertTrue($cache_store->put($key, 'value-a', 600));
        self::assertSame('value-a', $cache_store->get($key));

        self::assertTrue($cache_store->forget($key));
        self::assertNull($cache_store->get($key));
    }

    /**
     * Verify cache many() returns values and nulls for missing keys.
     *
     * @return void
     */
    #[Test]
    public function cacheManyReturnsValuesAndNullForMissingKeys(): void
    {
        $first_key   = $this->uniqueKey('many-a');
        $second_key  = $this->uniqueKey('many-b');
        $missing_key = $this->uniqueKey('many-missing');
        $cache_store = $this->redisCacheStore();

        $cache_store->putMany([
            $first_key  => 'value-a',
            $second_key => 'value-b',
        ], 600);

        self::assertSame(
            [
                $first_key   => 'value-a',
                $missing_key => null,
                $second_key  => 'value-b',
            ],
            $cache_store->many([$first_key, $missing_key, $second_key]),
        );

        $cache_store->forget($first_key);
        $cache_store->forget($second_key);
    }

    /**
     * Verify cache writes use both cache and connection prefixes on raw keys.
     *
     * @return void
     */
    #[Test]
    public function cacheWritesExpectedPhysicalRedisKey(): void
    {
        $key          = $this->uniqueKey('physical-key');
        $cache_store  = $this->redisCacheStore();
        $connection   = $this->redisConnection();
        $physical_key = self::REDIS_CONNECTION_PREFIX . self::CACHE_STORE_PREFIX . $key;
        $non_prefixed = self::CACHE_STORE_PREFIX . $key;

        $cache_store->put($key, 'value-c', 600);

        self::assertNotFalse($connection->client()->get($physical_key));
        self::assertFalse($connection->client()->get($non_prefixed));

        $cache_store->forget($key);
    }

    /**
     * Verify locks use the configured lock connection and enforce exclusivity.
     *
     * @return void
     */
    #[Test]
    public function cacheLocksUseConfiguredLockConnectionAndEnforceOwnership(): void
    {
        $key                = $this->uniqueKey('lock');
        $cache_store        = $this->redisLockStore();
        $default_connection = $this->redisConnection();
        $lock_connection    = $this->redisConnection('lock');

        $first_lock = $cache_store->lock($key, 10);
        self::assertTrue($first_lock->get());

        $second_lock = $cache_store->lock($key, 10);
        self::assertFalse($second_lock->get());

        $default_physical_key = self::REDIS_CONNECTION_PREFIX . self::CACHE_STORE_PREFIX . $key;
        $lock_physical_key    = self::LOCK_CONNECTION_PREFIX . self::CACHE_STORE_PREFIX . $key;

        self::assertFalse($default_connection->client()->get($default_physical_key));
        self::assertNotFalse($lock_connection->client()->get($lock_physical_key));

        $first_lock->release();
        self::assertTrue($second_lock->get());
        $second_lock->release();
    }

    /**
     * Verify cache entries with positive TTL values expire as expected.
     *
     * @return void
     */
    #[Test]
    public function cacheEntriesExpireAfterTtl(): void
    {
        $key         = $this->uniqueKey('ttl-expiry');
        $cache_store = $this->redisCacheStore();

        self::assertTrue($cache_store->put($key, 'value-ttl', 1));
        self::assertSame('value-ttl', $cache_store->get($key));

        sleep(2);

        self::assertNull($cache_store->get($key));
    }

    /**
     * Verify non-positive TTL values remove previously cached entries.
     *
     * @return void
     */
    #[Test]
    public function cachePutWithNonPositiveTtlForgetsExistingValue(): void
    {
        $key         = $this->uniqueKey('ttl-non-positive');
        $cache_store = $this->redisCacheStore();

        self::assertTrue($cache_store->put($key, 'value-before', 600));
        self::assertSame('value-before', $cache_store->get($key));

        self::assertTrue($cache_store->put($key, 'value-after', 0));
        self::assertNull($cache_store->get($key));
    }

    /**
     * Configure Redis and cache defaults used by cache integration tests.
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

        $redis_lock           = $redis_default;
        $redis_lock['prefix'] = self::LOCK_CONNECTION_PREFIX;

        $app['config']->set('database.redis.default', $redis_default);
        $app['config']->set('database.redis.lock', $redis_lock);
        $app['config']->set('database.redis.options', []);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', [
            'driver'          => 'redis',
            'connection'      => 'default',
            'lock_connection' => 'lock',
            'prefix'          => self::CACHE_STORE_PREFIX,
        ]);
    }

    /**
     * Resolve a Redis manager connection as the valkey-glide wrapper.
     *
     * @param  string  $connection_name
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     */
    private function redisConnection(string $connection_name = 'default'): ValkeyGlideConnection
    {
        if (!$this->app instanceof Application) {
            throw new \UnexpectedValueException('Application container is unavailable.');
        }

        $redis      = $this->app->make(RedisManager::class);
        $connection = $redis->connection($connection_name);

        if ($connection instanceof ValkeyGlideConnection) {
            return $connection;
        }

        $message = sprintf(
            'Expected redis connection [%s] to be [%s], received [%s].',
            $connection_name,
            ValkeyGlideConnection::class,
            get_debug_type($connection),
        );

        throw new \UnexpectedValueException($message);
    }

    /**
     * Build a unique key name for external cache test isolation.
     *
     * @param  string  $suffix
     * @return string
     */
    private function uniqueKey(string $suffix): string
    {
        $entropy = str_replace('.', '', uniqid('', true));

        return sprintf('lvkglide:test:%s:%s', $suffix, $entropy);
    }

    /**
     * Resolve the Laravel redis cache store as the concrete cache repository.
     *
     * @return \Illuminate\Cache\Repository
     */
    private function redisCacheStore(): Repository
    {
        $cache_store = Cache::store('redis');

        if ($cache_store instanceof Repository) {
            return $cache_store;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis cache store to be [%s], received [%s].', Repository::class, get_debug_type($cache_store)));
    }

    /**
     * Resolve the underlying Redis cache store for lock assertions.
     *
     * @return \Illuminate\Cache\RedisStore
     */
    private function redisLockStore(): RedisStore
    {
        $store = $this->redisCacheStore()->getStore();

        if ($store instanceof RedisStore) {
            return $store;
        }

        throw new \UnexpectedValueException(sprintf('Expected underlying redis store to be [%s], received [%s].', RedisStore::class, get_debug_type($store)));
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
