<?php

declare(strict_types = 1);

namespace Tests\Fakes;

use Illuminate\Support\Collection;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Contracts\Storage;

/**
 * Stand-in for Pulse's own Redis ingest, recording the calls handed to it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class PulseIngestFake implements Ingest
{
    /** @var list<array{string, mixed}> The calls received, in order, with their argument. */
    public array $calls = [];

    /**
     * Record the items to ingest.
     *
     * @param  \Illuminate\Support\Collection<int, \Laravel\Pulse\Entry>  $items
     * @return void
     */
    #[\Override]
    public function ingest(Collection $items): void
    {
        $this->calls[] = ['ingest', $items];
    }

    /**
     * Record the storage to digest into, and report one entry digested.
     *
     * @param  \Laravel\Pulse\Contracts\Storage  $storage
     * @return int
     */
    #[\Override]
    public function digest(Storage $storage): int
    {
        $this->calls[] = ['digest', $storage];

        return 1;
    }

    /**
     * Record the trim.
     *
     * @return void
     */
    #[\Override]
    public function trim(): void
    {
        $this->calls[] = ['trim', null];
    }
}
