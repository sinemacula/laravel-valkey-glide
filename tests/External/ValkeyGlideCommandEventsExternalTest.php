<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\External\Concerns\ConnectsToTestServers;

/**
 * External checks for the Redis command events a connection dispatches.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideCommandEventsExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var int The number of keys written for the cluster scan, enough to span every primary. */
    private const int SCAN_KEY_COUNT = 30;

    /**
     * Ensure extension preconditions are met before each external test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertExtensionIsLoaded();
    }

    /**
     * Verify a cluster scan completes with a listener that formats the event
     * parameters as a string, and that each event carries the cursor id the
     * call scanned from.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function clusterScanEventsCarryTheCursorId(): void
    {
        $connection = $this->connectTo(self::CLUSTER);
        $namespace  = $this->uniqueName('scan-events');
        $keys       = [];

        for ($index = 0; $index < self::SCAN_KEY_COUNT; $index++) {
            $keys[] = sprintf('%s:%d', $namespace, $index);
            $connection->command('set', [end($keys), '1']);
        }

        $formatted  = [];
        $dispatcher = new Dispatcher;

        $dispatcher->listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$formatted): void {
            $formatted[] = implode(' ', $event->parameters);
        });

        $connection->setEventDispatcher($dispatcher);

        try {
            $found    = [];
            $expected = [];
            $cursor   = new \ClusterScanCursor;

            do {
                $expected[] = sprintf('%s %s:* 100', $cursor->getCursor(), $namespace);
                $page       = $connection->command('scan', [$cursor, $namespace . ':*', 100]);
                $found      = [...$found, ...(is_array($page) ? $page : [])];
                $cursor     = new \ClusterScanCursor($cursor->getNextCursor());
            } while (!$cursor->isFinished());

            sort($keys);
            sort($found);

            self::assertSame($keys, $found);
            self::assertSame($expected, $formatted);
        } finally {
            $connection->unsetEventDispatcher();

            foreach ($keys as $key) {
                $connection->command('del', [$key]);
            }

            $connection->disconnect();
        }
    }
}
