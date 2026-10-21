<?php

declare(strict_types = 1);

namespace Tests\Fakes;

/**
 * Cluster keyspace fake that records an error as ext-valkey_glide 1.2 does, for
 * getLastError().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ClusterKeyspaceLastErrorFake extends ClusterKeyspaceFake
{
    /** @var string|null The error recorded last, or null when there is none. */
    private ?string $lastError = null;

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
}
