<?php

declare(strict_types = 1);

namespace Benchmarks;

use Benchmarks\Support\BenchClient;
use PhpBench\Attributes as Bench;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;

/**
 * Benchmarks for the per-command connection hot path.
 *
 * Every cache, queue, and session operation funnels through command(), which
 * normalizes the method and rewrites key arguments for the configured prefix
 * before dispatching to the client. These benchmarks guard that hot path
 * against regressions; the client is a no-op stub so only the wrapper overhead
 * is measured.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('microseconds')]
final readonly class ConnectionBench
{
    /** @var string Primary sample key reused across the command benchmarks. */
    private const string SAMPLE_KEY = 'user:1';

    /** @var \SineMacula\Valkey\Connections\ValkeyGlideConnection Connection with a configured key prefix. */
    private ValkeyGlideConnection $prefixed;

    /** @var \SineMacula\Valkey\Connections\ValkeyGlideConnection Connection without a key prefix. */
    private ValkeyGlideConnection $unprefixed;

    /**
     * Build the benchmarked connections backed by a no-op client.
     *
     * @return void
     */
    public function __construct()
    {
        $this->prefixed   = new ValkeyGlideConnection(new BenchClient, null, ['prefix' => 'app:']);
        $this->unprefixed = new ValkeyGlideConnection(new BenchClient);
    }

    /**
     * Benchmark a single-key command on the no-prefix fast path.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchSingleKeyWithoutPrefix(): void
    {
        $this->unprefixed->command('get', [self::SAMPLE_KEY]);
    }

    /**
     * Benchmark a single-key command with prefix rewriting.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchSingleKeyWithPrefix(): void
    {
        $this->prefixed->command('get', [self::SAMPLE_KEY]);
    }

    /**
     * Benchmark an all-key command (MGET) with prefix rewriting.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchAllKeyWithPrefix(): void
    {
        $this->prefixed->command('mget', [self::SAMPLE_KEY, 'user:2', 'user:3']);
    }

    /**
     * Benchmark a double-key command (RENAME) with prefix rewriting.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchDoubleKeyWithPrefix(): void
    {
        $this->prefixed->command('rename', [self::SAMPLE_KEY, 'user:2']);
    }

    /**
     * Benchmark EVAL key-segment prefixing routed through rawcommand.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchEvalKeyPrefixing(): void
    {
        $this->prefixed->command('eval', ['return 1', 2, 'k1', 'k2', 'arg']);
    }

    /**
     * Benchmark the phpredis-style SET option rewrite routed through rawcommand.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchLegacySetOptions(): void
    {
        $this->prefixed->command('set', ['lock:1', 'owner', 'EX', 10, 'NX']);
    }
}
