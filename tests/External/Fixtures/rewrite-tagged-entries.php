<?php

declare(strict_types = 1);

/*
 * Rewrite tagged cache entries until told to stop, for the external tag flush
 * race checks.
 *
 * Reads a JSON job from stdin: the standalone connection config, the physical
 * prefix of the entries' value keys, the physical key of the tag's sorted set,
 * the entries' members, a key to set once connected, a key whose existence
 * stops the loop, and a time limit in seconds. Each rewrite writes one entry's
 * value and its tag record together in a MULTI/EXEC transaction, cycling
 * through the members, so a value key never exists without its record unless
 * a tag flush removed the record after deleting the value.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */

use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @var array{config: array<string, mixed>, valuePrefix: string, setKey: string, members: list<string>, readyKey: string, stopKey: string, seconds: int} $job */
$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

$connection = (new ValkeyGlideConnector)->connect($job['config'], []);
$deadline   = microtime(true) + $job['seconds'];
$rewrites   = 0;

$connection->command('set', [$job['readyKey'], '1', 'EX', 60]);

while ($connection->command('exists', [$job['stopKey']]) === 0 && microtime(true) < $deadline) {
    foreach ($job['members'] as $member) {
        $connection->transaction(static function (ValkeyGlideBatch $transaction) use ($job, $member): void {
            $transaction->setex($job['valuePrefix'] . $member, 600, 'rewritten');
            $transaction->zadd($job['setKey'], -1, $member);
        });

        $rewrites++;
    }
}

$connection->disconnect();

fwrite(STDOUT, (string) $rewrites);
