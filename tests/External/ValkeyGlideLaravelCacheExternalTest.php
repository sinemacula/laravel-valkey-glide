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
        $key        = $this->uniqueKey('roundtrip');
        $cacheStore = $this->redisCacheStore();

        self::assertTrue($cacheStore->put($key, 'value-a', 600));
        self::assertSame('value-a', $cacheStore->get($key));

        self::assertTrue($cacheStore->forget($key));
        self::assertNull($cacheStore->get($key));
    }

    /**
     * Verify cache many() returns values and nulls for missing keys.
     *
     * @return void
     */
    #[Test]
    public function cacheManyReturnsValuesAndNullForMissingKeys(): void
    {
        $firstKey   = $this->uniqueKey('many-a');
        $secondKey  = $this->uniqueKey('many-b');
        $missingKey = $this->uniqueKey('many-missing');
        $cacheStore = $this->redisCacheStore();

        $cacheStore->putMany([
            $firstKey  => 'value-a',
            $secondKey => 'value-b',
        ], 600);

        self::assertSame(
            [
                $firstKey   => 'value-a',
                $missingKey => null,
                $secondKey  => 'value-b',
            ],
            $cacheStore->many([$firstKey, $missingKey, $secondKey]),
        );

        $cacheStore->forget($firstKey);
        $cacheStore->forget($secondKey);
    }

    /**
     * Verify cache writes use both cache and connection prefixes on raw keys.
     *
     * @return void
     */
    #[Test]
    public function cacheWritesExpectedPhysicalRedisKey(): void
    {
        $key         = $this->uniqueKey('physical-key');
        $cacheStore  = $this->redisCacheStore();
        $connection  = $this->redisConnection();
        $physicalKey = self::REDIS_CONNECTION_PREFIX . self::CACHE_STORE_PREFIX . $key;
        $nonPrefixed = self::CACHE_STORE_PREFIX . $key;

        $cacheStore->put($key, 'value-c', 600);

        self::assertNotFalse($connection->client()->get($physicalKey));
        self::assertFalse($connection->client()->get($nonPrefixed));

        $cacheStore->forget($key);
    }

    /**
     * Verify locks use the configured lock connection and enforce exclusivity.
     *
     * @return void
     */
    #[Test]
    public function cacheLocksUseConfiguredLockConnectionAndEnforceOwnership(): void
    {
        $key               = $this->uniqueKey('lock');
        $cacheStore        = $this->redisLockStore();
        $defaultConnection = $this->redisConnection();
        $lockConnection    = $this->redisConnection('lock');

        $firstLock = $cacheStore->lock($key, 10);
        self::assertTrue($firstLock->get());

        $secondLock = $cacheStore->lock($key, 10);
        self::assertFalse($secondLock->get());

        $defaultPhysicalKey = self::REDIS_CONNECTION_PREFIX . self::CACHE_STORE_PREFIX . $key;
        $lockPhysicalKey    = self::LOCK_CONNECTION_PREFIX . self::CACHE_STORE_PREFIX . $key;

        self::assertFalse($defaultConnection->client()->get($defaultPhysicalKey));
        self::assertNotFalse($lockConnection->client()->get($lockPhysicalKey));

        $firstLock->release();
        self::assertTrue($secondLock->get());
        $secondLock->release();
    }

    /**
     * Verify cache entries with positive TTL values expire as expected.
     *
     * @return void
     */
    #[Test]
    public function cacheEntriesExpireAfterTtl(): void
    {
        $key        = $this->uniqueKey('ttl-expiry');
        $cacheStore = $this->redisCacheStore();

        self::assertTrue($cacheStore->put($key, 'value-ttl', 1));
        self::assertSame('value-ttl', $cacheStore->get($key));

        sleep(2);

        self::assertNull($cacheStore->get($key));
    }

    /**
     * Verify non-positive TTL values remove previously cached entries.
     *
     * @return void
     */
    #[Test]
    public function cachePutWithNonPositiveTtlForgetsExistingValue(): void
    {
        $key        = $this->uniqueKey('ttl-non-positive');
        $cacheStore = $this->redisCacheStore();

        self::assertTrue($cacheStore->put($key, 'value-before', 600));
        self::assertSame('value-before', $cacheStore->get($key));

        self::assertTrue($cacheStore->put($key, 'value-after', 0));
        self::assertNull($cacheStore->get($key));
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

        /** @var array<string, mixed> $redisDefault */
        $redisDefault = [
            'host'   => is_string($host) && $host !== '' ? $host : '127.0.0.1',
            'port'   => is_string($port) && is_numeric($port) ? (int) $port : 6379,
            'tls'    => $this->resolveBooleanEnv('VALKEY_GLIDE_TEST_TLS', false),
            'prefix' => self::REDIS_CONNECTION_PREFIX,
        ];

        $password = getenv('VALKEY_GLIDE_TEST_PASSWORD');

        if (is_string($password) && $password !== '') {
            $redisDefault['password'] = $password;
        }

        $username = getenv('VALKEY_GLIDE_TEST_USERNAME');

        if (is_string($username) && $username !== '') {
            $redisDefault['username'] = $username;
        }

        $redisLock           = $redisDefault;
        $redisLock['prefix'] = self::LOCK_CONNECTION_PREFIX;

        $app['config']->set('database.redis.default', $redisDefault);
        $app['config']->set('database.redis.lock', $redisLock);
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
     * @param  string  $connectionName
     * @return \SineMacula\Valkey\Connections\ValkeyGlideConnection
     */
    private function redisConnection(string $connectionName = 'default'): ValkeyGlideConnection
    {
        if (!$this->app instanceof Application) {
            throw new \UnexpectedValueException('Application container is unavailable.');
        }

        $redis      = $this->app->make(RedisManager::class);
        $connection = $redis->connection($connectionName);

        if ($connection instanceof ValkeyGlideConnection) {
            return $connection;
        }

        $message = sprintf(
            'Expected redis connection [%s] to be [%s], received [%s].',
            $connectionName,
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
        $cacheStore = Cache::store('redis');

        if ($cacheStore instanceof Repository) {
            return $cacheStore;
        }

        throw new \UnexpectedValueException(sprintf('Expected redis cache store to be [%s], received [%s].', Repository::class, get_debug_type($cacheStore)));
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
