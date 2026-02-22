<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\TestCase;

/**
 * External Laravel session integration checks for the valkey-glide client.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideLaravelSessionExternalTest extends TestCase
{
    /** @var string Prefix configured on the Redis connection. */
    private const string REDIS_CONNECTION_PREFIX = 'lvkglide:session:conn:';

    /** @var string Prefix configured on the Laravel Redis cache store. */
    private const string CACHE_STORE_PREFIX = 'lvkglide:session:cache:';

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
     * Verify session values roundtrip using Laravel's Redis session driver.
     *
     * @return void
     */
    #[Test]
    public function sessionDataRoundtripSucceedsWithRedisDriver(): void
    {
        $session_id    = $this->newSessionId();
        $session_store = $this->freshSessionStore();

        $session_store->setId($session_id);
        self::assertTrue($session_store->start());
        $session_store->put('external-test-key', 'external-test-value');
        $session_store->save();

        $reloaded_store = $this->freshSessionStore();
        $reloaded_store->setId($session_id);
        self::assertTrue($reloaded_store->start());
        self::assertSame('external-test-value', $reloaded_store->get('external-test-key'));
        $reloaded_store->save();

        $this->redisCacheStore()->forget($session_id);
    }

    /**
     * Verify session writes use both cache and connection prefixes on raw keys.
     *
     * @return void
     */
    #[Test]
    public function sessionWritesExpectedPhysicalRedisKey(): void
    {
        $session_id    = $this->newSessionId();
        $session_store = $this->freshSessionStore();
        $connection    = $this->redisConnection();
        $physical_key  = self::REDIS_CONNECTION_PREFIX . self::CACHE_STORE_PREFIX . $session_id;
        $non_prefixed  = self::CACHE_STORE_PREFIX . $session_id;

        $session_store->setId($session_id);
        self::assertTrue($session_store->start());
        $session_store->put('session-key', 'session-value');
        $session_store->save();

        self::assertNotFalse($connection->client()->get($physical_key));
        self::assertFalse($connection->client()->get($non_prefixed));

        $this->redisCacheStore()->forget($session_id);
    }

    /**
     * Configure Redis, cache, and session defaults used by session tests.
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
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', [
            'driver'          => 'redis',
            'connection'      => 'default',
            'lock_connection' => 'default',
            'prefix'          => self::CACHE_STORE_PREFIX,
        ]);
        $app['config']->set('session.driver', 'redis');
        $app['config']->set('session.connection', 'default');
        $app['config']->set('session.store', 'redis');
        $app['config']->set('session.lifetime', 120);
        $app['config']->set('session.cookie', 'lvkglide_session');
        $app['config']->set('session.encrypt', false);
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
     * Resolve a fresh Laravel session store instance.
     *
     * @return \Illuminate\Session\Store
     */
    private function freshSessionStore(): Store
    {
        if (!$this->app instanceof Application) {
            throw new \UnexpectedValueException('Application container is unavailable.');
        }

        $manager = $this->app->make(SessionManager::class);
        $manager->forgetDrivers();

        $session_store = $manager->driver();

        if ($session_store instanceof Store) {
            return $session_store;
        }

        throw new \UnexpectedValueException(sprintf('Expected session store to be [%s], received [%s].', Store::class, get_debug_type($session_store)));
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
     * Build a valid 40-character alphanumeric session ID.
     *
     * @return string
     */
    private function newSessionId(): string
    {
        return Str::random(40);
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
