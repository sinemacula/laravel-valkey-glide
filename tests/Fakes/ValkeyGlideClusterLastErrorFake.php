<?php

declare(strict_types = 1);

namespace Tests\Fakes;

use Tests\Fakes\Concerns\RecordsLastError;

/**
 * Cluster client fake that records command errors as ext-valkey_glide 1.2 does,
 * for getLastError().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ValkeyGlideClusterLastErrorFake extends ValkeyGlideClusterFake
{
    use RecordsLastError;
}
