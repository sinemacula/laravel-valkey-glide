<?php

declare(strict_types = 1);

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SineMacula\Valkey\ValkeyGlideServiceProvider;

/**
 * Base Testbench test case for package integration tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Register the package service providers for the test application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ValkeyGlideServiceProvider::class,
        ];
    }

    /**
     * Configure package-specific defaults used by integration tests.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment(mixed $app): void
    {
        $app['config']->set('database.redis.client', 'valkey-glide');
        $app['config']->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 6379,
        ]);
    }
}
