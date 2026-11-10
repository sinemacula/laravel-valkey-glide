<?php

declare(strict_types = 1);

namespace Tests\Fakes\Enums;

/**
 * A queue named by an enum, as Laravel takes it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
enum QueueFake: string
{
    case MAIL = 'mail';
}
