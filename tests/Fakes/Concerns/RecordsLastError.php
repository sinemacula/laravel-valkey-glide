<?php

declare(strict_types = 1);

namespace Tests\Fakes\Concerns;

/**
 * Emulate the last-error API of ext-valkey_glide 1.2 in the client fakes.
 *
 * From 1.2, GLIDE records the error of a command that fails and returns false
 * on the client, where getLastError() reports it until clearLastError() is
 * called; a later error replaces it, and a command that succeeds leaves it in
 * place. Commands GLIDE buffers in batch mode record nothing until exec(), and
 * raw commands record nothing at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait RecordsLastError
{
    /** @var string|null The error recorded last, or null when there is none. */
    private ?string $lastError = null;

    /** @var array<string, string> Errors recorded when a method is called, keyed by lowercase method name. */
    private array $recordedErrors = [];

    /**
     * Record dynamic command invocations, then any error configured for them.
     *
     * @param  string  $name
     * @param  array<int|string, mixed>  $arguments
     * @return mixed
     */
    #[\Override]
    public function __call(string $name, array $arguments): mixed
    {
        return $this->recordConfiguredError($name, parent::__call($name, $arguments));
    }

    /**
     * Fail a method as GLIDE does from 1.2: it returns false and records the
     * error.
     *
     * @param  string  $method
     * @param  string  $error
     * @return void
     */
    public function willFail(string $method, string $error): void
    {
        $this->willReturn($method, false);
        $this->willRecordError($method, $error);
    }

    /**
     * Record an error whenever a method is called, whatever it returns.
     *
     * @param  string  $method
     * @param  string  $error
     * @return void
     */
    public function willRecordError(string $method, string $error): void
    {
        $this->recordedErrors[strtolower($method)] = $error;
    }

    /**
     * Record an error now, as a call made on the client directly would.
     *
     * @param  string  $error
     * @return void
     */
    public function recordError(string $error): void
    {
        $this->lastError = $error;
    }

    /**
     * Return the error recorded last, if any.
     *
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Clear the error recorded last.
     *
     * @return bool
     */
    public function clearLastError(): bool
    {
        $this->lastError = null;

        return true;
    }

    /**
     * Record a get(), then any error configured for it.
     *
     * @param  string  $key
     * @return mixed
     */
    #[\Override]
    public function get(string $key): mixed
    {
        return $this->recordConfiguredError('get', parent::get($key));
    }

    /**
     * Record a watch(), then any error configured for it.
     *
     * @param  mixed  $key
     * @param  mixed  ...$otherKeys
     * @return bool|self
     */
    #[\Override]
    public function watch(mixed $key, mixed ...$otherKeys): bool|self
    {
        $result = $this->recordConfiguredError('watch', parent::watch($key, ...$otherKeys));

        return is_bool($result) ? $result : $this;
    }

    /**
     * Record an unwatch(), then any error configured for it.
     *
     * @return bool
     */
    #[\Override]
    public function unwatch(): bool
    {
        return $this->recordConfiguredError('unwatch', parent::unwatch()) === true;
    }

    /**
     * Send the buffered commands, then record any error configured for exec().
     *
     * @return array<int, mixed>|false
     */
    #[\Override]
    public function exec(): array|false
    {
        $result = $this->recordConfiguredError('exec', parent::exec());

        return is_array($result) ? $result : false;
    }

    /**
     * Record the error configured for a method, unless the method was buffered
     * in batch mode, as it then returns the client.
     *
     * @param  string  $method
     * @param  mixed  $result
     * @return mixed
     */
    private function recordConfiguredError(string $method, mixed $result): mixed
    {
        $error = $this->recordedErrors[strtolower($method)] ?? null;

        if ($error !== null && $result !== $this) {
            $this->lastError = $error;
        }

        return $result;
    }
}
