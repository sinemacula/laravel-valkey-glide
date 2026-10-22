<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Support\HorizonClusterConnection;

/**
 * Horizon cluster connection test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(HorizonClusterConnection::class)]
final class HorizonClusterConnectionTest extends TestCase
{
    /** @var array<string, mixed> The cluster's first node. */
    private const array FIRST_NODE = ['host' => '10.0.0.1', 'port' => 7000, 'options' => ['timeout' => 2.0], 'password' => 'secret'];

    /** @var array<string, mixed> The cluster's second node. */
    private const array SECOND_NODE = ['host' => '10.0.0.2', 'port' => 7000];

    /** @var array<string, mixed> The standalone connection beside the cluster. */
    private const array STANDALONE = ['host' => '10.0.0.9', 'port' => 6379];

    /**
     * Verify Horizon's first-node fallback for the default valkey-glide cluster
     * becomes the cluster connection Horizon configures from Laravel 13.5, with
     * the prefix hash-tagged, and the rest of the Redis configuration is kept.
     *
     * @return void
     */
    #[Test]
    public function configureReplacesHorizonsFirstNodeFallbackWithTheCluster(): void
    {
        $config = $this->fallback('horizon:');

        HorizonClusterConnection::configure($config);

        self::assertSame('{horizon:}', $config->get('horizon.prefix'));
        self::assertSame([
            'client'   => 'valkey-glide',
            'default'  => self::STANDALONE,
            'clusters' => [
                'default' => [self::FIRST_NODE, self::SECOND_NODE, 'options' => ['retry_interval' => 5]],
                'horizon' => [self::FIRST_NODE, self::SECOND_NODE, 'options' => ['retry_interval' => 5, 'prefix' => '{horizon:}']],
            ],
        ], $config->get('database.redis'));
    }

    /**
     * Verify the cluster that horizon.use names is the one Horizon gets, and a
     * single-node cluster without an options entry gets one holding the prefix.
     *
     * @return void
     */
    #[Test]
    public function configureUsesTheClusterThatHorizonUseNames(): void
    {
        $config = $this->fallback('horizon:', [
            'horizon.use'                    => 'queues',
            'database.redis.clusters.queues' => [self::SECOND_NODE],
            'database.redis.horizon'         => self::SECOND_NODE + ['options' => ['prefix' => 'horizon:']],
        ]);

        HorizonClusterConnection::configure($config);

        self::assertFalse($config->has('database.redis.horizon'));
        self::assertSame([self::SECOND_NODE, 'options' => ['prefix' => '{horizon:}']], $config->get('database.redis.clusters.horizon'));
    }

    /**
     * Provide Horizon prefixes and the prefix each settles on.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function prefixes(): iterable
    {
        yield 'no tag' => ['horizon:', '{horizon:}'];

        yield 'tag' => ['app:{horizon}:', 'app:{horizon}:'];

        yield 'empty tag' => ['{}horizon:', '{{}horizon:}'];
    }

    /**
     * Verify the prefix is hash-tagged unless it already has a hash tag, as
     * Horizon does.
     *
     * @param  string  $prefix
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('prefixes')]
    #[Test]
    public function configureHashTagsThePrefixAsHorizonDoes(string $prefix, string $expected): void
    {
        $config = $this->fallback($prefix);

        HorizonClusterConnection::configure($config);

        self::assertSame($expected, $config->get('horizon.prefix'));
        self::assertSame($expected, $config->get('database.redis.clusters.horizon.options.prefix'));
    }

    /**
     * Provide configurations that are not Horizon's fallback for a valkey-glide
     * cluster.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notFallbacks(): iterable
    {
        yield 'phpredis client' => [['database.redis.client' => 'phpredis']];

        yield 'no horizon configuration' => [['horizon' => null]];

        yield 'horizon on a standalone connection' => [[
            'horizon.use'            => 'cache',
            'database.redis.cache'   => self::STANDALONE,
            'database.redis.horizon' => self::STANDALONE + ['options' => ['prefix' => 'horizon:']],
        ]];

        yield 'horizon already on the cluster' => [['database.redis.horizon' => null]];

        yield 'horizon connection on another host' => [['database.redis.horizon.host' => '10.0.0.3']];

        yield 'horizon connection with another prefix' => [['database.redis.horizon.options.prefix' => 'other:']];

        yield 'cluster with named nodes' => [['database.redis.clusters.default' => ['a' => self::FIRST_NODE, 'b' => self::SECOND_NODE]]];
    }

    /**
     * Verify the configuration is left as it is when it is not Horizon's
     * first-node fallback for a valkey-glide cluster.
     *
     * @param  array<string, mixed>  $changes
     * @return void
     */
    #[DataProvider('notFallbacks')]
    #[Test]
    public function configureLeavesAnythingButTheFallbackAlone(array $changes): void
    {
        $config   = $this->fallback('horizon:', $changes);
        $original = $config->all();

        HorizonClusterConnection::configure($config);

        self::assertSame($original, $config->all());
    }

    /**
     * Build the configuration Horizon::use() leaves for the default cluster
     * before Laravel 13.5: the cluster's first node as the standalone `horizon`
     * connection, with the prefix in its options. `horizon.use` is left unset,
     * so Horizon's `default` applies.
     *
     * @param  string  $prefix
     * @param  array<string, mixed>  $changes
     * @return \Illuminate\Config\Repository
     */
    private function fallback(string $prefix, array $changes = []): Repository
    {
        $first                      = self::FIRST_NODE;
        $first['options']['prefix'] = $prefix;

        $config = new Repository([
            'horizon'  => ['prefix' => $prefix],
            'database' => ['redis' => [
                'client'   => 'valkey-glide',
                'default'  => self::STANDALONE,
                'clusters' => [
                    'default' => [self::FIRST_NODE, self::SECOND_NODE, 'options' => ['retry_interval' => 5]],
                ],
                'horizon' => $first,
            ]],
        ]);

        $config->set($changes);

        return $config;
    }
}
