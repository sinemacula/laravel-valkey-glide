<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ClusterScan;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\CommandException;
use Tests\Fakes\ClusterKeyspaceFake;
use Tests\Fakes\ClusterKeyspaceLastErrorFake;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Options-array keyspace scan test case for cluster connections.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[CoversClass(ClusterScan::class)]
final class ClusterScanValkeyGlideConnectionTest extends TestCase
{
    /**
     * Verify a scan loop from '0' until the cursor is '0' again finds every key
     * of every primary once, sending the match pattern unprefixed and the count
     * as given, without GLIDE's own cluster scan.
     *
     * @return void
     */
    #[Test]
    public function clusterScanLoopFindsEveryKeyOfEveryPrimaryOnce(): void
    {
        $client = $this->cluster();

        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        self::assertSame(['app:a1', 'app:a2', 'app:b1'], $this->scanAll($connection, ['match' => 'app:*', 'count' => 1]));
        self::assertSame([
            ['10.0.0.1:7000', '0', 'MATCH', 'app:*', 'COUNT', '1'],
            ['10.0.0.1:7000', '1', 'MATCH', 'app:*', 'COUNT', '1'],
            ['10.0.0.2:7001', '0', 'MATCH', 'app:*', 'COUNT', '1'],
        ], $client->scans());
    }

    /**
     * Verify a cluster scan clears the error an earlier command left, as every
     * command does, and throws the error GLIDE records for a refused page, as
     * ext-valkey_glide 1.2 does, after a command failed event.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterScanClearsAnEarlierErrorAndThrowsTheOneRecordedForARefusedPage(): void
    {
        $client = new ClusterKeyspaceLastErrorFake;
        $client->withPrimary('10.0.0.1:7000', [[0, 16383]], ['app:a1']);
        $client->recordError('WRONGTYPE: Operation against a key holding the wrong kind of value');

        $connection = new ValkeyGlideConnection($client);
        $failed     = [];

        $this->dispatcher($connection)->listen(CommandFailed::class, static function (CommandFailed $event) use (&$failed): void {
            $failed[] = $event->exception;
        });

        self::assertSame(['0', ['app:a1']], $connection->scan('0'));
        self::assertNull($client->getLastError());

        $client->willAnswer('SCAN', false)->whenNextScanned(static function () use ($client): void {
            $client->recordError('NOPERM User has no permissions to run the \'scan\' command');
        });

        try {
            $connection->scan('0');
            self::fail('Expected the refused page to throw the recorded error.');
        } catch (CommandException $exception) {
            self::assertSame('NOPERM User has no permissions to run the \'scan\' command', $exception->getMessage());
            self::assertSame([$exception], $failed);
        }
    }

    /**
     * Verify the scan defaults to every key, ten at a time, and sends a type
     * option as TYPE.
     *
     * @return void
     */
    #[Test]
    public function clusterScanDefaultsAndSendsTheTypeOption(): void
    {
        $client = $this->cluster();

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['0-8191|10.0.0.2:7001|8192-16383|0', ['app:a1', 'app:a2']], $connection->scan(null));
        self::assertSame(['0', ['app:b1']], $connection->scan('0-8191|10.0.0.2:7001|8192-16383|0', ['type' => 'zset']));
        self::assertSame([
            ['10.0.0.1:7000', '0', 'MATCH', '*', 'COUNT', '10'],
            ['10.0.0.2:7001', '0', 'MATCH', '*', 'COUNT', '10', 'TYPE', 'zset'],
        ], $client->scans());
    }

    /**
     * Verify a standalone scan sends a type option to GLIDE after the count.
     *
     * @return void
     */
    #[Test]
    public function standaloneScanSendsTheTypeOption(): void
    {
        $client = new ValkeyGlideFake;
        $client->willScan('scan', [['0', ['app:z']]]);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(['0', ['app:z']], $connection->scan('0', ['match' => 'app:*', 'type' => 'zset']));
        self::assertSame([['0', 'app:*', 10, 'zset']], $client->callsFor('scan'));
    }

    /**
     * Verify each page dispatches one command executed event, named scan,
     * carrying the cursor it scans from with the filters, which listeners can
     * turn into a string.
     *
     * @return void
     */
    #[Test]
    public function clusterScanDispatchesOneEventPerPageWithTheCursorItScansFrom(): void
    {
        $connection = new ValkeyGlideConnection($this->cluster());
        $events     = [];

        $this->dispatcher($connection)->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        $this->scanAll($connection, ['match' => 'app:*', 'count' => 2, 'type' => 'string']);

        self::assertSame(['scan', 'scan'], array_column($events, 'command'));
        self::assertSame([
            ['0', 'app:*', 2, 'string'],
            ['0-8191|10.0.0.2:7001|8192-16383|0', 'app:*', 2, 'string'],
        ], array_column($events, 'parameters'));
        self::assertSame(['0 app:* 2 string', '0-8191|10.0.0.2:7001|8192-16383|0 app:* 2 string'], array_map(
            static fn (CommandExecuted $event): string => implode(' ', $event->parameters),
            $events,
        ));
    }

    /**
     * Verify a page the server refuses, which GLIDE answers with false, throws
     * after its command event, and leaves the cursor it was given to resume
     * from.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function aRefusedClusterPageThrowsAndTheScanCanResume(): void
    {
        $client     = $this->cluster()->willAnswer('SCAN', false);
        $connection = new ValkeyGlideConnection($client);
        $events     = [];

        $this->dispatcher($connection)->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = $event->parameters;
        });

        try {
            $connection->scan('0', ['match' => 'app:*']);
            self::fail('Expected a refused cluster scan to throw.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Expected array response from scan on [app:*], received [bool].', $exception->getMessage());
            self::assertSame([['0', 'app:*', 10]], $events);
        }

        $client->willAnswer('SCAN', ['0', ['app:a1', 'app:a2']]);

        self::assertSame(['0-8191|10.0.0.2:7001|8192-16383|0', ['app:a1', 'app:a2']], $connection->scan('0', ['match' => 'app:*']));
    }

    /**
     * Verify a page GLIDE throws for dispatches a command failed event carrying
     * the cursor and filters, and rethrows.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function aClusterPageThatThrowsDispatchesACommandFailedEvent(): void
    {
        $connection = new ValkeyGlideConnection($this->cluster()->willThrow('SCAN', new \RuntimeException('permanent failure')));
        $dispatcher = $this->dispatcher($connection);
        $events     = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $event) use (&$events): void {
            $events[] = [$event->command, $event->parameters];
        });
        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$events): void {
            $events[] = ['executed', $event->command];
        });

        try {
            $connection->scan('0', ['match' => 'app:*']);
            self::fail('Expected the cluster scan to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('permanent failure', $exception->getMessage());
        }

        self::assertSame([['scan', ['0', 'app:*', 10]]], $events);
    }

    /**
     * Verify a cursor the cluster scan never wrote is refused before anything
     * is sent or any event dispatched.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function aForeignCursorIsRefusedBeforeAnythingIsSent(): void
    {
        $client     = $this->cluster();
        $connection = new ValkeyGlideConnection($client);
        $events     = [];

        $this->dispatcher($connection)->listen(CommandFailed::class, static function (CommandFailed $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $connection->scan('17');
            self::fail('Expected a foreign cluster scan cursor to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Cannot resume a cluster scan from cursor [17]: it is not a cursor the cluster scan returned.', $exception->getMessage());
        }

        self::assertSame([], $client->calls());
        self::assertSame([], $events);
    }

    /**
     * Verify a cluster scan refused inside a batch sends nothing and resumes
     * from the same cursor once the batch is discarded.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function aClusterScanRefusedInsideABatchResumesAfterIt(): void
    {
        $client     = $this->cluster();
        $connection = new ValkeyGlideConnection($client);

        [$cursor] = $connection->scan('0');

        $connection->pipeline();

        try {
            $connection->scan($cursor);
            self::fail('Expected the scan inside the batch to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringStartsWith('[SCAN] cannot be used inside a pipeline or transaction', $exception->getMessage());
        }

        self::assertCount(2, $client->calls());
        self::assertTrue($connection->discard());
        self::assertSame(['0', ['app:b1']], $connection->scan($cursor));
    }

    /**
     * Verify a cluster scan, which sends its pages to the primaries itself
     * rather than through command(), first sends the UNWATCH of a WATCH left
     * over from an unwatch() that failed, as any command does.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function aClusterScanUnwatchesALeftoverWatchFirst(): void
    {
        $client     = new ValkeyGlideClusterFake;
        $connection = new ValkeyGlideConnection($client);

        self::assertTrue($connection->command('watch', ['k']));

        $client->willReturn('unwatch', false);

        self::assertFalse($connection->command('unwatch'));

        $client->willReturn('unwatch', true);
        $client->willReturn('rawcommand', false);

        try {
            $connection->scan('0', ['match' => 'app:*']);
            self::fail('Expected the refused CLUSTER SLOTS to fail the page.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('Expected array response from scan on [app:*], received [bool].', $exception->getMessage());
        }

        self::assertSame(['watch', 'unwatch', 'unwatch', 'rawcommand'], $client->callOrder());
    }

    /**
     * Build a cluster of two primaries, two keys on the first and one on the
     * second.
     *
     * @return \Tests\Fakes\ClusterKeyspaceFake
     */
    private function cluster(): ClusterKeyspaceFake
    {
        return (new ClusterKeyspaceFake)
            ->withPrimary('10.0.0.1:7000', [[0, 8191]], ['app:a1', 'app:a2'])
            ->withPrimary('10.0.0.2:7001', [[8192, 16383]], ['app:b1']);
    }

    /**
     * Give the connection a new event dispatcher.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return \Illuminate\Events\Dispatcher
     */
    private function dispatcher(ValkeyGlideConnection $connection): Dispatcher
    {
        $dispatcher = new Dispatcher;

        $connection->setEventDispatcher($dispatcher);

        return $dispatcher;
    }

    /**
     * Scan every key with the given options, from '0' until the cursor is '0'
     * again.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function scanAll(ValkeyGlideConnection $connection, array $options): array
    {
        $cursor = '0';
        $keys   = [];
        $pages  = 0;

        do {
            [$cursor, $page] = $connection->scan($cursor, $options);
            $keys            = [...$keys, ...$page];
        } while ($cursor !== '0' && ++$pages < 100);

        return $keys;
    }
}
