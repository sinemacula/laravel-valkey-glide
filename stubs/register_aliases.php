<?php

declare(strict_types = 1);

if (!class_exists(\Redis::class)) {
    class_alias('SineMacula\Valkey\Stubs\Redis', \Redis::class);
}

if (!class_exists(\ValkeyGlide::class)) {
    class_alias('SineMacula\Valkey\Stubs\ValkeyGlide', \ValkeyGlide::class);
}
