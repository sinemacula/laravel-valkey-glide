<?php

declare(strict_types = 1);

namespace Tests\Fakes\Enums;

/**
 * A queue named by an enum without values, which Laravel names by the case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
enum UnitQueueFake
{
    case MAIL;
}
