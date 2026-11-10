<?php

declare(strict_types = 1);

namespace Tests\Fakes\Enums;

/**
 * A queue named by an enum with integer values, which Laravel names by the
 * value.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
enum NumberedQueueFake: int
{
    case MAIL = 1;
}
