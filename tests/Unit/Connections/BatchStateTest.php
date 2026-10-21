<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\BatchState;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Batch state test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(BatchState::class)]
final class BatchStateTest extends TestCase
{
    /**
     * Verify a new state is open, empty, and carries its batch type and batch
     * object.
     *
     * @return void
     */
    #[Test]
    public function newStateIsOpenAndEmpty(): void
    {
        $state = $this->newState(true);

        self::assertTrue($state->atomic);
        self::assertFalse($this->newState(false)->atomic);
        self::assertInstanceOf(ValkeyGlideBatch::class, $state->proxy);
        self::assertFalse($state->isClosed());
        self::assertFalse($state->isInCallback());
        self::assertFalse($state->hasNestedMulti());
        self::assertFalse($state->hasQueuedCommands());
        self::assertSame([], $state->queuedCommands());
        self::assertFalse($state->hasOpenNativeBatch());
        self::assertSame([], $state->results());
        self::assertNull($state->claimedSlot());
    }

    /**
     * Verify close marks the state closed.
     *
     * @return void
     */
    #[Test]
    public function closeMarksTheStateClosed(): void
    {
        $state = $this->newState(false);

        $state->close();

        self::assertTrue($state->isClosed());
    }

    /**
     * Verify nested callbacks are counted until the outermost one leaves.
     *
     * @return void
     */
    #[Test]
    public function callbacksAreCountedUntilTheOutermostLeaves(): void
    {
        $state = $this->newState(false);

        $state->enterCallback();
        $state->enterCallback();
        $state->leaveCallback();

        self::assertTrue($state->isInCallback());

        $state->leaveCallback();

        self::assertFalse($state->isInCallback());
    }

    /**
     * Verify a multi() opened inside the pipeline is tracked until its exec()
     * closes it, however often it is opened.
     *
     * @return void
     */
    #[Test]
    public function nestedMultiIsTrackedUntilItIsClosed(): void
    {
        $state = $this->newState(false);

        $state->openNestedMulti();
        $state->openNestedMulti();

        self::assertTrue($state->hasNestedMulti());

        $state->closeNestedMulti();

        self::assertFalse($state->hasNestedMulti());
    }

    /**
     * Verify queued methods are recorded in order and lower-cased.
     *
     * @return void
     */
    #[Test]
    public function queuedMethodsAreRecordedInOrderAndLowerCased(): void
    {
        $state = $this->newState(false);

        $state->queue('SET');
        $state->queue('zAdd');

        self::assertTrue($state->hasQueuedCommands());
        self::assertSame(['set', 'zadd'], $state->queuedCommands());
    }

    /**
     * Verify the native batch is marked open, then closed with nothing left
     * queued.
     *
     * @return void
     */
    #[Test]
    public function nativeBatchIsMarkedOpenThenClosed(): void
    {
        $state = $this->newState(false);

        $state->markNativeBatchOpen();
        $state->queue('set');

        self::assertTrue($state->hasOpenNativeBatch());

        $state->markNativeBatchClosed();

        self::assertFalse($state->hasOpenNativeBatch());
        self::assertFalse($state->hasQueuedCommands());
        self::assertSame([], $state->results());
    }

    /**
     * Verify an executed native batch is closed and its results appended after
     * the earlier ones.
     *
     * @return void
     */
    #[Test]
    public function executedNativeBatchAppendsItsResults(): void
    {
        $state = $this->newState(false);

        $state->append(['immediate']);
        $state->markNativeBatchOpen();
        $state->queue('set');
        $state->queue('get');
        $state->markNativeBatchExecuted([true, 'value']);
        $state->append(['key' => 'keyed']);

        self::assertFalse($state->hasOpenNativeBatch());
        self::assertFalse($state->hasQueuedCommands());
        self::assertSame(['immediate', true, 'value', 'keyed'], $state->results());
    }

    /**
     * Verify the first claimed slot binds the state, and later claims, of the
     * same slot, another slot or none, leave it bound.
     *
     * @return void
     */
    #[Test]
    public function firstClaimedSlotBindsTheState(): void
    {
        $state = $this->newState(true);

        $state->claimSlot(null);

        self::assertNull($state->claimedSlot());

        $state->claimSlot(42);
        $state->claimSlot(7);
        $state->claimSlot(null);

        self::assertSame(42, $state->claimedSlot());
    }

    /**
     * Verify slot zero binds the state like any other slot.
     *
     * @return void
     */
    #[Test]
    public function slotZeroBindsTheState(): void
    {
        $state = $this->newState(true);

        $state->claimSlot(0);
        $state->claimSlot(1);

        self::assertSame(0, $state->claimedSlot());
    }

    /**
     * Build a state on a connection backed by the standalone fake.
     *
     * @param  bool  $atomic
     * @return \SineMacula\Valkey\Connections\BatchState
     */
    private function newState(bool $atomic): BatchState
    {
        return new BatchState(new ValkeyGlideConnection(new ValkeyGlideFake), $atomic);
    }
}
