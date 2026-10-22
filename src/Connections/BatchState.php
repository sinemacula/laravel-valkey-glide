<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

/**
 * Track one pipeline or transaction on a connection.
 *
 * The connection counts what it buffers itself rather than reading GLIDE's
 * returns, so it knows whether a native batch is open, how many commands it
 * holds, and which results earlier flushes produced. It also tracks which
 * callbacks own the batch, so that only its owner executes it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class BatchState
{
    /** @var bool Whether the batch runs as an atomic MULTI/EXEC transaction. */
    public readonly bool $atomic;

    /** @var \SineMacula\Valkey\Connections\BatchState|null The pipeline a transaction nested in it hands back to once it ends. */
    public readonly ?BatchState $pipeline;

    /** @var \SineMacula\Valkey\Connections\ValkeyGlideBatch The batch object handed to callers. */
    public readonly ValkeyGlideBatch $proxy;

    /** @var int Number of callbacks currently running against the batch. */
    private int $callbacks = 0;

    /** @var list<string> Methods buffered in the open native batch, in order. */
    private array $queued = [];

    /** @var bool Whether GLIDE's own batch mode has been started on the client. */
    private bool $nativeBatchOpen = false;

    /** @var list<mixed> Results gathered so far, in call order. */
    private array $results = [];

    /** @var int|null Hash slot a cluster transaction's keys are bound to. */
    private ?int $slot = null;

    /** @var bool Whether the batch has been executed or discarded. */
    private bool $closed = false;

    /**
     * Create the state for a new pipeline or transaction.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  bool  $atomic
     * @param  \SineMacula\Valkey\Connections\BatchState|null  $pipeline
     * @return void
     */
    public function __construct(ValkeyGlideConnection $connection, bool $atomic, ?self $pipeline = null)
    {
        $this->atomic   = $atomic;
        $this->pipeline = $pipeline;
        $this->proxy    = new ValkeyGlideBatch($connection, $this);
    }

    /**
     * Determine whether the batch has been executed or discarded.
     *
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Mark the batch as executed or discarded.
     *
     * @return void
     */
    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * Record that a callback has started running against the batch.
     *
     * @return void
     */
    public function enterCallback(): void
    {
        $this->callbacks++;
    }

    /**
     * Record that a callback has finished running against the batch.
     *
     * @return void
     */
    public function leaveCallback(): void
    {
        $this->callbacks--;
    }

    /**
     * Determine whether a callback is running against the batch.
     *
     * @return bool
     */
    public function isInCallback(): bool
    {
        return $this->callbacks > 0;
    }

    /**
     * Record a command buffered in the native batch.
     *
     * @param  string  $method
     * @return void
     */
    public function queue(string $method): void
    {
        $this->queued[] = strtolower($method);
    }

    /**
     * Determine whether the native batch holds buffered commands.
     *
     * @return bool
     */
    public function hasQueuedCommands(): bool
    {
        return $this->queued !== [];
    }

    /**
     * Get the methods buffered in the native batch, in order.
     *
     * @return list<string>
     */
    public function queuedCommands(): array
    {
        return $this->queued;
    }

    /**
     * Determine whether GLIDE's own batch mode has been started on the client.
     *
     * @return bool
     */
    public function hasOpenNativeBatch(): bool
    {
        return $this->nativeBatchOpen;
    }

    /**
     * Record that GLIDE's own batch mode has been started on the client.
     *
     * @return void
     */
    public function markNativeBatchOpen(): void
    {
        $this->nativeBatchOpen = true;
    }

    /**
     * Record that GLIDE's own batch mode has ended, with nothing left buffered.
     *
     * @return void
     */
    public function markNativeBatchClosed(): void
    {
        $this->queued          = [];
        $this->nativeBatchOpen = false;
    }

    /**
     * Record that the native batch was executed and its results returned.
     *
     * @param  array<array-key, mixed>  $results
     * @return void
     */
    public function markNativeBatchExecuted(array $results): void
    {
        $this->markNativeBatchClosed();
        $this->append($results);
    }

    /**
     * Append results, in call order.
     *
     * @param  array<array-key, mixed>  $results
     * @return void
     */
    public function append(array $results): void
    {
        foreach ($results as $result) {
            $this->results[] = $result;
        }
    }

    /**
     * Get the results gathered so far, in call order.
     *
     * @return list<mixed>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * Bind the batch to a hash slot, unless it is already bound to one.
     *
     * A null slot leaves the batch as it is.
     *
     * @param  int|null  $slot
     * @return void
     */
    public function claimSlot(?int $slot): void
    {
        $this->slot ??= $slot;
    }

    /**
     * Get the hash slot the batch is bound to, if any.
     *
     * @return int|null
     */
    public function claimedSlot(): ?int
    {
        return $this->slot;
    }
}
