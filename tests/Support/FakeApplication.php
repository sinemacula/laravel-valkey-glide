<?php

declare(strict_types = 1);

namespace Tests\Support;

final class FakeApplication
{
    /** @var array<string, callable(mixed): void> */
    public array $resolvingCallbacks = [];

    /** @var array<string, bool> */
    public array $resolved = [];

    /** @var array<string, mixed> */
    public array $instances = [];

    /** @var int */
    public int $makeCalls = 0;

    /**
     * @param  string  $abstract
     * @param  callable(mixed): void  $callback
     * @return void
     */
    public function resolving(string $abstract, callable $callback): void
    {
        $this->resolvingCallbacks[$abstract] = $callback;
    }

    /**
     * @param  string  $abstract
     * @return bool
     */
    public function resolved(string $abstract): bool
    {
        return $this->resolved[$abstract] ?? false;
    }

    /**
     * @param  string  $abstract
     * @return mixed
     */
    public function make(string $abstract): mixed
    {
        $this->makeCalls++;

        if (!array_key_exists($abstract, $this->instances)) {
            throw new \OutOfBoundsException(sprintf('No instance registered for [%s].', $abstract));
        }

        return $this->instances[$abstract];
    }
}
