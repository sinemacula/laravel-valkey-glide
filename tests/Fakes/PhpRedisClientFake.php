<?php

declare(strict_types = 1);

namespace Tests\Fakes;

/**
 * Deterministic stand-in for a phpredis client, so Laravel's own
 * `PhpRedisConnection` and `PhpRedisClusterConnection` can run over it and the
 * commands they send can be checked.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class PhpRedisClientFake
{
    /** @var list<array{string, array<array-key, mixed>}> The commands received, in order. */
    public array $calls = [];

    /**
     * Create a new client whose member scans answer with the given members.
     *
     * @param  array<array-key, mixed>  $members
     * @return void
     */
    public function __construct(

        /** The members, with their scores, every member scan answers with. */
        private readonly array $members = [],
    ) {}

    /**
     * Record any other command and answer as a successful write.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return int
     */
    public function __call(string $method, array $parameters): int
    {
        $this->calls[] = [strtolower($method), $parameters];

        return 1;
    }

    /**
     * Record a sorted-set scan and answer as phpredis does: every member as one
     * page, then the finished cursor 0 written back.
     *
     * A call with the finished cursor answers false without being recorded, as
     * phpredis answers it without sending a command. That ends Laravel's scan
     * loop whichever cursor it starts from.
     *
     * @param  string  $key
     * @param  mixed  $cursor
     * @param  string  $pattern
     * @param  int  $count
     * @return array<array-key, mixed>|false
     */
    public function zscan(string $key, mixed &$cursor, string $pattern, int $count): array|false
    {
        if ($cursor === 0) {
            return false;
        }

        $this->calls[] = ['zscan', [$key, $pattern, $count]];
        $cursor        = 0;

        return $this->members;
    }
}
