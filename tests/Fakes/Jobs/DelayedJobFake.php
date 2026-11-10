<?php

declare(strict_types = 1);

namespace Tests\Fakes\Jobs;

/**
 * A queued job delayed by any value, which a bulk push reads as its delay.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class DelayedJobFake
{
    /**
     * Create a new job.
     *
     * @param  mixed  $delay
     * @return void
     */
    public function __construct(

        /** The delay of the job. */
        public mixed $delay,
    ) {}
}
