<?php

declare(strict_types = 1);

namespace Tests\Fakes\Jobs;

use Illuminate\Queue\Attributes\Delay;

/**
 * A queued job whose delay is given by Laravel 13's `#[Delay]` attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[Delay(30)]
final class DelayAttributeJobFake {}
