<?php

declare(strict_types = 1);

namespace Tests\Fakes\Jobs;

/**
 * A queued job with the delay and after-commit settings a bulk push reads.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class BulkJobFake
{
    /**
     * Create a new job.
     *
     * @param  string  $name
     * @param  int|null  $delay
     * @param  bool|null  $afterCommit
     * @return void
     */
    public function __construct(

        /** The name the job is told apart by. */
        public string $name = 'job',

        /** The delay of the job in seconds, if any. */
        public ?int $delay = null,

        /** Whether the job is pushed once the database transaction commits. */
        public ?bool $afterCommit = null,
    ) {}
}
