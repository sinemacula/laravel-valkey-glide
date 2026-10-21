<?php

declare(strict_types = 1);

/**
 * IDE, static-analysis and test stub for ext-valkey_glide's ClusterScanCursor
 * class.
 *
 * Loaded only when the extension class is unavailable. Unlike the client stubs
 * it works, because unit tests run without the extension: the cursor id the
 * object was built with decides isFinished(), and the next id is written by
 * ValkeyGlideCluster::scan(), as the extension does.
 *
 * @see https://github.com/valkey-io/valkey-glide-php
 */
if (class_exists(ClusterScanCursor::class, false)) {
    return;
}

final class ClusterScanCursor
{
    /** The cursor id this object was built with; '0' starts a scan. */
    private string $cursor;

    /** The cursor id written by ValkeyGlideCluster::scan(); '0' once the scan is complete. */
    private string $nextCursor = '0';

    /**
     * Create a cursor, starting a new scan or resuming from an id returned by
     * getNextCursor().
     *
     * @param  string|null  $cursorId
     */
    public function __construct(?string $cursorId = null)
    {
        $this->cursor = $cursorId ?? '0';
    }

    /**
     * Get the cursor id this object was built with.
     *
     * @return string
     */
    public function getCursor(): string
    {
        return $this->cursor;
    }

    /**
     * Get the cursor id written by the last scan; build a new cursor from it
     * to continue.
     *
     * @return string
     */
    public function getNextCursor(): string
    {
        return $this->nextCursor;
    }

    /**
     * Determine whether the cursor id this object was built with ends a scan.
     *
     * A new cursor reports true before any scan, so check it after scanning.
     *
     * @return bool
     */
    public function isFinished(): bool
    {
        return $this->cursor === '0';
    }
}
