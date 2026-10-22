<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ClusterScan;
use Tests\Fakes\ClusterKeyspaceFake;

/**
 * Primary-by-primary cluster keyspace scan test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ClusterScan::class)]
final class ClusterScanTest extends TestCase
{
    /**
     * Verify cursor '0' starts a scan with no slots scanned and no primary.
     *
     * @return void
     */
    #[Test]
    public function resumeFromZeroStartsANewScan(): void
    {
        self::assertSame([[], '', [], '0'], (new ClusterScan)->resume('0'));
    }

    /**
     * Verify a cursor the scan wrote is read back into its state.
     *
     * @return void
     */
    #[Test]
    public function resumeReadsTheStateACursorCarries(): void
    {
        self::assertSame(
            [[[0, 5], [7, 9]], '10.0.0.1:7000', [[10, 20], [30, 40]], '42'],
            (new ClusterScan)->resume('0-5,7-9|10.0.0.1:7000|10-20,30-40|42'),
        );
        self::assertSame([[], 'node:1', [[0, 16383]], '0'], (new ClusterScan)->resume('|node:1|0-16383|0'));
        self::assertSame([[[7, 7]], 'node:1', [[5, 5]], '18446744073709551615'], (new ClusterScan)->resume('7-7|node:1|5-5|18446744073709551615'));
    }

    /**
     * Provide cursors the scan never writes.
     *
     * @return iterable<string, array{string}>
     */
    public static function foreignCursors(): iterable
    {
        yield 'a server cursor' => ['17'];
        yield 'a GLIDE cluster scan cursor id' => ['3noOMOCV-a1b2'];
        yield 'no primary' => ['0-5||10-20|42'];
        yield 'a non-numeric node cursor' => ['0-5|node:1|10-20|x'];
        yield 'a trailing field' => ['0-5|node:1|10-20|42|7'];
        yield 'a leading field' => ['x|0-5|node:1|10-20|42'];
        yield 'a trailing newline' => ["0-5|node:1|10-20|42\n"];
        yield 'a node cursor too long for 64 bits' => ['|node:1|10-20|123456789012345678901'];
    }

    /**
     * Verify a cursor the scan never writes is refused.
     *
     * @param  string  $cursor
     * @return void
     */
    #[DataProvider('foreignCursors')]
    #[Test]
    public function resumeRefusesACursorTheScanNeverWrites(string $cursor): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Cannot resume a cluster scan from cursor [%s]: it is not a cursor the cluster scan returned.', $cursor));

        (new ClusterScan)->resume($cursor);
    }

    /**
     * Verify a scan from '0' to '0' finds every key of every primary once,
     * scanning each primary by its address to the end before the next, and
     * carrying the scanned slots, the primary, its slots and its cursor.
     *
     * @return void
     */
    #[Test]
    public function scanFindsEveryKeyOfEveryPrimaryOnce(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.2:7001', [[5461, 10922]], ['b1', 'b2'])
            ->withPrimary('10.0.0.1:7000', [[0, 5460]], ['a1', 'a2', 'a3'])
            ->withPrimary('10.0.0.3:7002', [[10923, 16383]], ['c1']);

        [$keys, $cursors] = $this->scanAll($client, 2);

        self::assertSame(['a1', 'a2', 'a3', 'b1', 'b2', 'c1'], $keys);
        self::assertSame([
            '|10.0.0.1:7000|0-5460|2',
            '0-5460|10.0.0.2:7001|5461-10922|0',
            '0-10922|10.0.0.3:7002|10923-16383|0',
            '0',
        ], $cursors);
        self::assertSame([
            ['10.0.0.1:7000', '0', 'MATCH', '*', 'COUNT', '2'],
            ['10.0.0.1:7000', '2', 'MATCH', '*', 'COUNT', '2'],
            ['10.0.0.2:7001', '0', 'MATCH', '*', 'COUNT', '2'],
            ['10.0.0.3:7002', '0', 'MATCH', '*', 'COUNT', '2'],
        ], $client->scans());
    }

    /**
     * Verify the topology is read with CLUSTER SLOTS from a random node before
     * every page, and each page is routed to the primary by its address.
     *
     * @return void
     */
    #[Test]
    public function eachPageReadsTheTopologyThenRoutesTheScanByAddress(): void
    {
        $client = (new ClusterKeyspaceFake)->withPrimary('10.0.0.1:7000', [[0, 16383]], ['a1']);

        self::assertSame(['0', ['a1']], (new ClusterScan)->page($client, [[], '', [], '0'], 'a*', 10, null));
        self::assertSame([
            ['randomNode', 'CLUSTER', ['SLOTS']],
            [['type' => 'routeByAddress', 'host' => '10.0.0.1', 'port' => 7000], 'SCAN', ['0', 'MATCH', 'a*', 'COUNT', '10']],
        ], $client->calls());
    }

    /**
     * Verify a count of zero sends no COUNT, leaving the server's default, and
     * a type is sent as TYPE.
     *
     * @return void
     */
    #[Test]
    public function aZeroCountSendsNoCountAndATypeIsSent(): void
    {
        $client = (new ClusterKeyspaceFake)->withPrimary('10.0.0.1:7000', [[0, 16383]], ['a1']);

        (new ClusterScan)->page($client, [[], '', [], '0'], '*', 0, 'zset');

        self::assertSame([['10.0.0.1:7000', '0', 'MATCH', '*', 'TYPE', 'zset']], $client->scans());
    }

    /**
     * Verify a cluster that lists no primary ends the scan at once.
     *
     * @return void
     */
    #[Test]
    public function aClusterWithoutPrimariesEndsTheScanAtOnce(): void
    {
        $client = new ClusterKeyspaceFake;

        self::assertSame(['0', []], (new ClusterScan)->page($client, [[], '', [], '0'], '*', 10, null));
        self::assertSame([], $client->scans());
    }

    /**
     * Verify a scan resumed once every primary is scanned ends, whatever
     * primary the cursor names.
     *
     * @return void
     */
    #[Test]
    public function aScanResumedOnceEverySlotIsScannedEnds(): void
    {
        $client = (new ClusterKeyspaceFake)->withPrimary('10.0.0.2:7001', [[0, 16383]], ['b1']);

        self::assertSame(['0', []], (new ClusterScan)->page($client, [[[0, 16383]], '10.0.0.1:7000', [[0, 100]], '5'], '*', 10, null));
        self::assertSame([], $client->scans());
    }

    /**
     * Verify a primary's ranges are sorted and merged where they adjoin, so it
     * is scanned once, while ranges with a gap are kept apart.
     *
     * @return void
     */
    #[Test]
    public function aPrimaryServingSeveralRangesIsScannedOnce(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 99], [101, 16383]], ['a1', 'a2'])
            ->willAnswer('CLUSTER SLOTS', [
                [200, 16383, ['10.0.0.1', 7000, 'id', []]],
                [100, 100, ['10.0.0.2', 7001, 'id', []]],
                [101, 199, ['10.0.0.1', 7000, 'id', []]],
                [0, 99, ['10.0.0.1', 7000, 'id', []]],
            ]);

        self::assertSame(['|10.0.0.1:7000|0-99,101-16383|1', ['a1']], (new ClusterScan)->page($client, [[], '', [], '0'], '*', 1, null));
    }

    /**
     * Verify a primary that serves only slots already scanned is not scanned
     * again, while one serving a slot not yet scanned is.
     *
     * @return void
     */
    #[Test]
    public function aPrimaryIsScannedOnlyForSlotsNotYetScanned(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 50], [101, 200]], ['a1'])
            ->withPrimary('10.0.0.2:7001', [[51, 100]], ['b1'])
            ->withPrimary('10.0.0.3:7002', [[201, 16383]], ['c1']);

        self::assertSame(['0', ['c1']], (new ClusterScan)->page($client, [[[0, 200]], '', [], '0'], '*', 10, null));
        self::assertSame([['10.0.0.3:7002', '0', 'MATCH', '*', 'COUNT', '10']], $client->scans());
    }

    /**
     * Verify the slots scanned are merged with those of the primary just
     * scanned when they overlap or contain them, so a primary whose slots are
     * all scanned is not scanned again.
     *
     * @return void
     */
    #[Test]
    public function scannedSlotsAreMergedWithThoseOfTheLastPrimary(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 40]], ['a1'])
            ->withPrimary('10.0.0.2:7001', [[41, 60], [300, 400]], ['b1'])
            ->withPrimary('10.0.0.3:7002', [[61, 299]], ['c1'])
            ->withPrimary('10.0.0.4:7003', [[401, 16383]], ['d1']);

        self::assertSame(
            ['0-400|10.0.0.4:7003|401-16383|0', ['b1']],
            (new ClusterScan)->page($client, [[[0, 299]], '10.0.0.2:7001', [[41, 60], [300, 400]], '0'], '*', 10, null),
        );
    }

    /**
     * Verify a failover that replaces the primary being scanned drops its scan
     * and scans the promoted primary in full, so every key is found.
     *
     * @return void
     */
    #[Test]
    public function aFailoverMidScanScansThePromotedPrimaryInFull(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 8191]], ['a1', 'a2', 'a3'])
            ->withPrimary('10.0.0.2:7001', [[8192, 16383]], ['b1']);

        $scan = new ClusterScan;

        [$cursor, $keys] = $scan->page($client, $scan->resume('0'), '*', 1, null);

        self::assertSame(['|10.0.0.1:7000|0-8191|1', ['a1']], [$cursor, $keys]);

        $client->withoutPrimary('10.0.0.1:7000')->withPrimary('10.0.0.5:7000', [[0, 8191]], ['a1', 'a2', 'a3']);

        [$remaining, $cursors] = $this->scanAll($client, 1, $cursor);

        self::assertSame(['a1', 'a2', 'a3', 'b1'], $remaining);
        self::assertSame('0-8191|10.0.0.2:7001|8192-16383|0', $cursors[2]);
        self::assertSame(['10.0.0.5:7000', '0', 'MATCH', '*', 'COUNT', '1'], $client->scans()[1]);
    }

    /**
     * Verify slots that move from a primary not yet scanned to one already
     * scanned are scanned on their new primary, which is scanned again, and a
     * primary whose slots changed while it was scanned is scanned again in
     * full, so every key is found.
     *
     * @return void
     */
    #[Test]
    public function aSlotMigrationMidScanScansTheMovedSlotsOnTheirNewPrimary(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 5460]], ['a1'])
            ->withPrimary('10.0.0.2:7001', [[5461, 10922]], ['b1', 'b2', 'moved'])
            ->withPrimary('10.0.0.3:7002', [[10923, 16383]], ['c1']);

        $scan = new ClusterScan;

        [$cursor] = $scan->page($client, $scan->resume('0'), '*', 1, null);
        [$cursor] = $scan->page($client, $scan->resume($cursor), '*', 1, null);

        self::assertSame('0-5460|10.0.0.2:7001|5461-10922|1', $cursor);

        $client
            ->withPrimary('10.0.0.1:7000', [[0, 5460], [10000, 10922]], ['a1', 'moved'])
            ->withPrimary('10.0.0.2:7001', [[5461, 9999]], ['b1', 'b2']);

        [$keys] = $this->scanAll($client, 1, $cursor);

        self::assertSame(['a1', 'moved', 'b1', 'b2', 'c1'], $keys);
        self::assertSame(['10.0.0.1:7000', '0', 'MATCH', '*', 'COUNT', '1'], $client->scans()[2]);
    }

    /**
     * Verify CLUSTER SLOTS answered with false gives no keys and the cursor as
     * given, so the caller can resume from it.
     *
     * @return void
     */
    #[Test]
    public function aFailedTopologyReadAnswersFalseWithTheCursorGiven(): void
    {
        $client = (new ClusterKeyspaceFake)->willAnswer('CLUSTER SLOTS', false);
        $scan   = new ClusterScan;

        self::assertSame(['0', false], $scan->page($client, $scan->resume('0'), '*', 10, null));
        self::assertSame(['0-5|10.0.0.1:7000|6-9|4', false], $scan->page($client, $scan->resume('0-5|10.0.0.1:7000|6-9|4'), '*', 10, null));
        self::assertSame([], $client->scans());
    }

    /**
     * Verify a SCAN answered with false, as GLIDE answers an error such as
     * NOPERM or a timeout, gives no keys and the cursor as given.
     *
     * @return void
     */
    #[Test]
    public function aFailedPageAnswersFalseWithTheCursorGiven(): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 16383]], ['a1'])
            ->willAnswer('SCAN', false);

        $scan = new ClusterScan;

        self::assertSame(['|10.0.0.1:7000|0-16383|3', false], $scan->page($client, $scan->resume('|10.0.0.1:7000|0-16383|3'), '*', 10, null));
        self::assertSame([['10.0.0.1:7000', '3', 'MATCH', '*', 'COUNT', '10']], $client->scans());
    }

    /**
     * Provide SCAN replies that are not a cursor and a list of keys.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedScanReplies(): iterable
    {
        yield 'a string' => ['reply', 'string'];
        yield 'a cursor alone' => [['0'], 'array'];
        yield 'an integer cursor' => [[0, []], 'array'];
        yield 'keys that are not a list' => [['0', 'a1'], 'array'];
    }

    /**
     * Verify a SCAN reply that is not a cursor and a list of keys throws.
     *
     * @param  mixed  $reply
     * @param  string  $type
     * @return void
     */
    #[DataProvider('malformedScanReplies')]
    #[Test]
    public function aMalformedScanReplyThrows(mixed $reply, string $type): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 16383]])
            ->willAnswer('SCAN', $reply);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf('Expected a cursor and keys from SCAN on [10.0.0.1:7000], received [%s].', $type));

        (new ClusterScan)->page($client, [[], '', [], '0'], '*', 10, null);
    }

    /**
     * Provide CLUSTER SLOTS entries that do not name their slots and an address
     * their primary can be scanned at.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function malformedSlotsEntries(): iterable
    {
        yield 'a string' => ['entry'];
        yield 'a string first slot' => [['0', 16383, ['10.0.0.1', 7000]]];
        yield 'no primary' => [[0, 16383]];
        yield 'a primary with an integer host' => [[0, 16383, [7, 7000]]];
        yield 'a primary with a string port' => [[0, 16383, ['10.0.0.1', '7000']]];
        yield 'a NULL endpoint, as under unknown-endpoint' => [[2, 16383, [null, 7000, 'id', []]]];
        yield 'a question mark, as for a node without a hostname' => [[2, 16383, ['?', 7000, 'id', []]]];
        yield 'an empty host beside another primary' => [[2, 16383, ['', 7000, 'id', []]]];
    }

    /**
     * Verify a CLUSTER SLOTS entry that does not name its slots and an address
     * their primary can be scanned at throws before any SCAN is sent.
     *
     * @param  mixed  $entry
     * @return void
     */
    #[DataProvider('malformedSlotsEntries')]
    #[Test]
    public function aMalformedSlotsEntryThrows(mixed $entry): void
    {
        $client = (new ClusterKeyspaceFake)->willAnswer('CLUSTER SLOTS', [[0, 1, ['10.0.0.9', 7000, 'id', []]], $entry]);

        try {
            (new ClusterScan)->page($client, [[], '', [], '0'], '*', 10, null);
            self::fail('Expected a malformed CLUSTER SLOTS entry to throw.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Expected each CLUSTER SLOTS entry to name its slots and the address of their primary.', $exception->getMessage());
            self::assertSame([], $client->scans());
        }
    }

    /**
     * Provide CLUSTER SLOTS primaries and the host GLIDE keys its connection to
     * each by: the hostname a primary reported by its IP announces in its
     * metadata, as under cluster-announce-hostname, in the flat list of pairs
     * or the map GLIDE returns, or otherwise the host reported.
     *
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function connectionHosts(): iterable
    {
        yield 'an IP announcing a hostname after another pair' => [['10.0.0.1', 7000, 'id', ['ip', '10.0.0.1', 'hostname', 'node-1']], 'node-1'];
        yield 'an IP announcing a hostname in a map' => [['10.0.0.1', 7000, 'id', ['hostname' => 'node-1']], 'node-1'];
        yield 'an IPv6 address announcing a hostname' => [['fd00::1', 7000, 'id', ['hostname', 'node-1']], 'node-1'];
        yield 'an IP without metadata' => [['10.0.0.1', 7000, 'id'], '10.0.0.1'];
        yield 'a hostname with another hostname in its metadata' => [['node-1', 7000, 'id', ['hostname', 'node-2']], 'node-1'];
    }

    /**
     * Verify a primary's SCAN is routed by the host GLIDE keys its connection
     * to it by, and the cursor names the primary by that host.
     *
     * @param  list<mixed>  $primary
     * @param  string  $host
     * @return void
     */
    #[DataProvider('connectionHosts')]
    #[Test]
    public function aPrimaryIsRoutedByTheHostGlideConnectsTo(array $primary, string $host): void
    {
        $client = (new ClusterKeyspaceFake)
            ->withPrimary($host . ':7000', [[0, 16383]], ['a1', 'a2'])
            ->willAnswer('CLUSTER SLOTS', [[0, 16383, $primary]]);

        self::assertSame(['|' . $host . ':7000|0-16383|1', ['a1']], (new ClusterScan)->page($client, [[], '', [], '0'], '*', 1, null));
        self::assertSame([['type' => 'routeByAddress', 'host' => $host, 'port' => 7000], 'SCAN', ['0', 'MATCH', '*', 'COUNT', '1']], $client->calls()[1]);
    }

    /**
     * Verify a lone node, which reports itself with an empty host as it has
     * never met another node, is scanned through a random-node route, which can
     * reach no other node.
     *
     * @return void
     */
    #[Test]
    public function aLoneNodeReportingAnEmptyHostIsScannedThroughARandomNode(): void
    {
        $client = (new ClusterKeyspaceFake)->withPrimary(':7000', [[0, 16383]], ['a1', 'a2', 'a3']);

        [$keys, $cursors] = $this->scanAll($client, 2);

        self::assertSame(['a1', 'a2', 'a3'], $keys);
        self::assertSame(['|:7000|0-16383|2', '0'], $cursors);
        self::assertSame([
            ['randomNode', 'CLUSTER', ['SLOTS']],
            ['randomNode', 'SCAN', ['0', 'MATCH', '*', 'COUNT', '2']],
            ['randomNode', 'CLUSTER', ['SLOTS']],
            ['randomNode', 'SCAN', ['2', 'MATCH', '*', 'COUNT', '2']],
        ], $client->calls());
    }

    /**
     * Scan from the given cursor until the cursor is '0' again.
     *
     * @param  \Tests\Fakes\ClusterKeyspaceFake  $client
     * @param  int  $count
     * @param  string  $cursor
     * @return array{0: list<string>, 1: list<string>}
     */
    private function scanAll(ClusterKeyspaceFake $client, int $count, string $cursor = '0'): array
    {
        $scan    = new ClusterScan;
        $keys    = [];
        $cursors = [];

        do {
            [$cursor, $page] = $scan->page($client, $scan->resume($cursor), '*', $count, null);

            self::assertIsArray($page);

            $keys      = [...$keys, ...$page];
            $cursors[] = $cursor;
        } while ($cursor !== '0' && count($cursors) < 100);

        return [array_values(array_map(strval(...), $keys)), $cursors];
    }
}
