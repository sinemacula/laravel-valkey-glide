<?php

declare(strict_types = 1);

namespace Tests\Fakes;

use Illuminate\Redis\Connections\Connection;

/**
 * Deterministic stand-in for a phpredis or Predis connection, so code that
 * behaves as Laravel's for connections other than GLIDE runs Laravel's own
 * code.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class LaravelConnectionFake extends Connection
{
    /** @var list<array{string, array<array-key, mixed>}> The commands received, in order. */
    public array $commands = [];

    /** @var array<string, list<mixed>> Queued replies by lowercase method. */
    private array $replies = [];

    /**
     * Queue the replies a command answers with, in turn; once they run out it
     * answers null.
     *
     * @param  string  $method
     * @param  mixed  ...$replies
     * @return void
     */
    public function willReturn(string $method, mixed ...$replies): void
    {
        $this->replies[strtolower($method)] = array_values($replies);
    }

    /**
     * Record the command and answer with its next queued reply.
     *
     * @param  mixed  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     */
    #[\Override]
    public function command(mixed $method, array $parameters = []): mixed
    {
        $name = is_string($method) ? strtolower($method) : '';

        $this->commands[] = [$name, $parameters];

        $replies = $this->replies[$name] ?? [];
        $reply   = array_shift($replies);

        $this->replies[$name] = $replies;

        return $reply;
    }

    /**
     * Accept a subscription without subscribing to anything.
     *
     * @param  mixed  $channels
     * @param  \Closure(mixed, mixed): void  $callback
     * @param  mixed  $method
     * @return void
     *
     * @phpstan-ignore method.childParameterType
     */
    #[\Override]
    public function createSubscription(mixed $channels, \Closure $callback, mixed $method = 'subscribe'): void
    {
        // Nothing is subscribed by the fake.
    }
}
