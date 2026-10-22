<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

/**
 * Scan the keyspace of a GLIDE cluster one primary at a time.
 *
 * `ValkeyGlideCluster::scan()` with a `ClusterScanCursor` aborts the PHP
 * process (`zend_mm_heap corrupted`) in ext-valkey_glide 1.1.2 through at least
 * 1.2.0-rc1 whenever a node answers a page with an error, such as `NOPERM` for
 * an ACL user without `SCAN`, and does not return when a node stops answering
 * mid-scan. Each page here is a plain `SCAN` that `rawcommand()` sends to one
 * primary by its address, and GLIDE answers its error, or its timeout, with
 * false, as it does for any other command.
 *
 * The primaries, and the slots each serves, are read with `CLUSTER SLOTS`
 * before every page. The cursor carries the slots already scanned, the primary
 * being scanned, the slots it served when its scan began and its node-local
 * cursor, so any connection to the cluster can resume it. A primary whose slots
 * change mid-scan, after a failover or a slot migration, is scanned again from
 * its start, and its slots are counted as scanned once its last page is read.
 *
 * A primary is routed by the address GLIDE keys its connection by: the host
 * `CLUSTER SLOTS` reports, or the hostname its metadata announces when that
 * host is an IP, as with `cluster-announce-hostname`. A node that has never met
 * another reports itself with an empty host, so a cluster of that one node is
 * scanned through a random-node route. A primary reported without a usable
 * endpoint cannot be scanned this way.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class ClusterScan
{
    /** @var string The refusal of a CLUSTER SLOTS reply that gives no usable address for a primary. */
    private const string UNUSABLE_SLOTS_MESSAGE = 'Expected each CLUSTER SLOTS entry to name its slots and the address of their primary.';

    /** @var string The cursor's shape: the scanned slots, the primary, its slots, its cursor. */
    private const string CURSOR_PATTERN = '/^(?<scanned>(?:\d+-\d+(?:,\d+-\d+)*)?)\|(?<node>[^|]+)\|(?<slots>\d+-\d+(?:,\d+-\d+)*)\|(?<cursor>\d{1,20})$/D';

    /**
     * Read the scan state a cursor carries, '0' starting a new scan.
     *
     * @param  string  $cursor
     * @return array{0: list<array{0: int, 1: int}>, 1: string, 2: list<array{0: int, 1: int}>, 3: string}
     *
     * @throws \InvalidArgumentException
     */
    public function resume(string $cursor): array
    {
        if ($cursor === '0') {
            return [[], '', [], '0'];
        }

        if (preg_match(self::CURSOR_PATTERN, $cursor, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf('Cannot resume a cluster scan from cursor [%s]: it is not a cursor the cluster scan returned.', $cursor));
        }

        return [self::parseSlots($matches['scanned']), $matches['node'], self::parseSlots($matches['slots']), $matches['cursor']];
    }

    /**
     * Scan one page of keys from the given state.
     *
     * Returns the cursor to resume from and the page's keys, which are false,
     * with the cursor as given, when GLIDE answers `CLUSTER SLOTS` or the
     * `SCAN` with false. The cursor is '0' once every primary has been scanned
     * to the end.
     *
     * @param  \ValkeyGlideCluster  $client
     * @param  array{0: list<array{0: int, 1: int}>, 1: string, 2: list<array{0: int, 1: int}>, 3: string}  $state
     * @param  string  $pattern
     * @param  int  $count
     * @param  string|null  $type
     * @return array{0: string, 1: mixed}
     *
     * @throws \UnexpectedValueException
     */
    public function page(\ValkeyGlideCluster $client, array $state, string $pattern, int $count, ?string $type): array
    {
        $primaries = $this->primaries($client);
        $filters   = ['MATCH', $pattern, ...($count > 0 ? ['COUNT', (string) $count] : []), ...($type !== null ? ['TYPE', $type] : [])];

        return $primaries !== null ? $this->scanFrom($client, $primaries, $state, $filters) : [self::format(...$state), false];
    }

    /**
     * Scan one page from the given state, on the cluster's primaries.
     *
     * The primary the state names is scanned on from its cursor while it serves
     * the slots it served when its scan began; otherwise the first primary
     * serving a slot not yet scanned is scanned from its start.
     *
     * @param  \ValkeyGlideCluster  $client
     * @param  array<string, array{host: string, port: int, slots: list<array{0: int, 1: int}>}>  $primaries
     * @param  array{0: list<array{0: int, 1: int}>, 1: string, 2: list<array{0: int, 1: int}>, 3: string}  $state
     * @param  list<string>  $filters
     * @return array{0: string, 1: mixed}
     *
     * @throws \UnexpectedValueException
     */
    private function scanFrom(\ValkeyGlideCluster $client, array $primaries, array $state, array $filters): array
    {
        [$scanned, $node, $slots, $cursor] = $state;

        if (($primaries[$node]['slots'] ?? null) !== $slots) {
            [$node, $slots, $cursor] = [...self::nextPrimary($primaries, $scanned), '0'];
        }

        if ($node === '') {
            return ['0', []];
        }

        $reply = $this->scanPrimary($client, $primaries[$node], [$cursor, ...$filters]);

        return $reply !== null ? self::advance($primaries, [$scanned, $node, $slots, $reply[0]], $reply[1]) : [self::format(...$state), false];
    }

    /**
     * Advance the scan past the page a primary answered.
     *
     * Once the primary's cursor is '0', its slots are counted as scanned and
     * the scan moves on to the next primary serving a slot not yet scanned.
     *
     * @param  array<string, array{host: string, port: int, slots: list<array{0: int, 1: int}>}>  $primaries
     * @param  array{0: list<array{0: int, 1: int}>, 1: string, 2: list<array{0: int, 1: int}>, 3: string}  $next
     * @param  array<array-key, mixed>  $keys
     * @return array{0: string, 1: array<array-key, mixed>}
     */
    private static function advance(array $primaries, array $next, array $keys): array
    {
        [$scanned, $node, $slots, $cursor] = $next;

        if ($cursor === '0') {
            $scanned        = self::mergeSlots([...$scanned, ...$slots]);
            [$node, $slots] = self::nextPrimary($primaries, $scanned);
        }

        return [self::format($scanned, $node, $slots, $cursor), $keys];
    }

    /**
     * Send a SCAN to a primary by its address.
     *
     * Null when GLIDE answers it with false.
     *
     * @param  \ValkeyGlideCluster  $client
     * @param  array{host: string, port: int, slots: list<array{0: int, 1: int}>}  $primary
     * @param  list<string>  $arguments
     * @return array{0: string, 1: array<array-key, mixed>}|null
     *
     * @throws \UnexpectedValueException
     */
    private function scanPrimary(\ValkeyGlideCluster $client, array $primary, array $arguments): ?array
    {
        $reply = $client->rawcommand(self::route($primary), 'SCAN', ...$arguments);

        if ($reply === false) {
            return null;
        }

        if (!is_array($reply) || !is_string($reply[0] ?? null) || !is_array($reply[1] ?? null)) {
            $address = $primary['host'] . ':' . $primary['port'];

            throw new \UnexpectedValueException(sprintf('Expected a cursor and keys from SCAN on [%s], received [%s].', $address, get_debug_type($reply)));
        }

        [$cursor, $keys] = $reply;

        return [$cursor, $keys];
    }

    /**
     * Build the GLIDE route to a primary by its address, or to a random node
     * for a lone node with an empty host, as the cluster has no other.
     *
     * @param  array{host: string, port: int, slots: list<array{0: int, 1: int}>}  $primary
     * @return array{type: string, host: string, port: int}|string
     */
    private static function route(array $primary): array|string
    {
        return $primary['host'] !== '' ? ['type' => 'routeByAddress', 'host' => $primary['host'], 'port' => $primary['port']] : 'randomNode';
    }

    /**
     * Read the cluster's primaries, by address, with the slots each serves.
     *
     * Null when GLIDE answers `CLUSTER SLOTS` with false. A primary with an
     * empty host, which only a node that has never met another reports of
     * itself, is kept only when it is the cluster's only primary.
     *
     * @param  \ValkeyGlideCluster  $client
     * @return array<string, array{host: string, port: int, slots: list<array{0: int, 1: int}>}>|null
     *
     * @throws \UnexpectedValueException
     */
    private function primaries(\ValkeyGlideCluster $client): ?array
    {
        $ranges = $client->rawcommand('randomNode', 'CLUSTER', 'SLOTS');

        if (!is_array($ranges)) {
            return null;
        }

        $primaries = [];

        foreach ($ranges as $range) {
            [$host, $port, $start, $end] = self::slotsEntry($range);

            $primaries[$host . ':' . $port] ??= ['host' => $host, 'port' => $port, 'slots' => []];
            $primaries[$host . ':' . $port]['slots'][] = [$start, $end];
        }

        foreach ($primaries as $primary) {
            if ($primary['host'] === '' && count($primaries) > 1) {
                throw new \UnexpectedValueException(self::UNUSABLE_SLOTS_MESSAGE);
            }
        }

        return array_map(static fn (array $primary): array => ['slots' => self::mergeSlots($primary['slots'])] + $primary, $primaries);
    }

    /**
     * Read the address GLIDE connects to a `CLUSTER SLOTS` entry's primary by
     * and the slots it serves.
     *
     * A primary reported with no endpoint, as NULL under
     * `cluster-preferred-endpoint-type unknown-endpoint` or `?` for a node
     * without a hostname, is refused, as GLIDE addresses it by whichever node
     * answered its own topology query.
     *
     * @param  mixed  $range
     * @return array{0: string, 1: int, 2: int, 3: int}
     *
     * @throws \UnexpectedValueException
     */
    private static function slotsEntry(mixed $range): array
    {
        $range   = is_array($range) ? $range : [];
        $primary = $range[2] ?? null;
        $primary = is_array($primary) ? $primary : [];

        [$start, $end, $host, $port] = [$range[0] ?? null, $range[1] ?? null, $primary[0] ?? null, $primary[1] ?? null];

        $host = $host !== '?' ? $host : null;

        if (!is_int($start) || !is_int($end) || !is_int($port) || !is_string($host)) {
            throw new \UnexpectedValueException(self::UNUSABLE_SLOTS_MESSAGE);
        }

        return [self::connectionHost($host, $primary), $port, $start, $end];
    }

    /**
     * Read the host GLIDE keys its connection to a primary by.
     *
     * A primary reported by its IP whose metadata announces a hostname, as a
     * node with `cluster-announce-hostname` is under the default preferred
     * endpoint type, is connected to by that hostname, and a route to its IP
     * reaches no connection. Any other host is the one reported.
     *
     * @param  string  $host
     * @param  array<array-key, mixed>  $primary
     * @return string
     */
    private static function connectionHost(string $host, array $primary): string
    {
        $metadata = $primary[3] ?? null;

        if (filter_var($host, FILTER_VALIDATE_IP) === false || !is_array($metadata)) {
            return $host;
        }

        $pairs = array_is_list($metadata) ? array_chunk($metadata, 2) : array_map(null, array_keys($metadata), $metadata);

        foreach ($pairs as $pair) {
            $hostname = $pair[0] === 'hostname' ? $pair[1] ?? null : null;

            if (is_string($hostname) && $hostname !== '') {
                return $hostname;
            }
        }

        return $host;
    }

    /**
     * Find the first primary serving a slot not yet scanned, or an empty
     * address when there is none.
     *
     * @param  array<string, array{host: string, port: int, slots: list<array{0: int, 1: int}>}>  $primaries
     * @param  list<array{0: int, 1: int}>  $scanned
     * @return array{0: string, 1: list<array{0: int, 1: int}>}
     */
    private static function nextPrimary(array $primaries, array $scanned): array
    {
        foreach ($primaries as $node => $primary) {
            foreach ($primary['slots'] as [$start, $end]) {
                if (!self::covers($scanned, $start, $end)) {
                    return [$node, $primary['slots']];
                }
            }
        }

        return ['', []];
    }

    /**
     * Determine whether the merged ranges cover every slot from start to end.
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     * @param  int  $start
     * @param  int  $end
     * @return bool
     */
    private static function covers(array $ranges, int $start, int $end): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($from <= $start && $end <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sort slot ranges and merge those that overlap or adjoin.
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return list<array{0: int, 1: int}>
     */
    private static function mergeSlots(array $ranges): array
    {
        sort($ranges);

        $merged = [];

        foreach ($ranges as [$start, $end]) {
            [$from, $to] = array_pop($merged) ?? [$start, $end];

            if ($start > $to + 1) {
                $merged[]    = [$from, $to];
                [$from, $to] = [$start, $end];
            }

            $merged[] = [$from, max($to, $end)];
        }

        return $merged;
    }

    /**
     * Parse the slot ranges of a cursor, written as `start-end` pairs joined by
     * commas.
     *
     * @param  string  $ranges
     * @return list<array{0: int, 1: int}>
     */
    private static function parseSlots(string $ranges): array
    {
        preg_match_all('/(?<start>\d+)-(?<end>\d+)/', $ranges, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [(int) $match['start'], (int) $match['end']], $matches);
    }

    /**
     * Write the cursor that resumes a scan from the given state.
     *
     * @param  list<array{0: int, 1: int}>  $scanned
     * @param  string  $node
     * @param  list<array{0: int, 1: int}>  $slots
     * @param  string  $cursor
     * @return string
     */
    private static function format(array $scanned, string $node, array $slots, string $cursor): string
    {
        if ($node === '') {
            return '0';
        }

        $ranges = static fn (array $ranges): string => implode(',', array_map(static fn (array $range): string => implode('-', $range), $ranges));

        return implode('|', [$ranges($scanned), $node, $ranges($slots), $cursor]);
    }
}
