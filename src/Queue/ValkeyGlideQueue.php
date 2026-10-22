<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Queue;

use Illuminate\Queue\RedisQueue;
use SineMacula\Valkey\Queue\Concerns\HashTagsClusterQueues;
use SineMacula\Valkey\Queue\Concerns\PushesBulkJobsAtomically;

/**
 * Laravel's Redis queue, whose bulk push is atomic on a GLIDE connection, as it
 * is through phpredis, and whose keys are hash-tagged on a GLIDE cluster
 * connection before Laravel 13.5, as Laravel does from 13.5.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValkeyGlideQueue extends RedisQueue
{
    use HashTagsClusterQueues, PushesBulkJobsAtomically;

    /**
     * Create the payload of a job in the bulk, as Laravel's push and later do.
     *
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  mixed  $queue
     * @param  mixed  $delay
     * @return string
     *
     * @throws \Illuminate\Queue\InvalidPayloadException
     */
    #[\Override]
    protected function createBulkPayload(object|string $job, mixed $data, mixed $queue, mixed $delay): string
    {
        return $this->createPayload($job, $this->getQueue($queue), $data, $delay);
    }

    /**
     * Gather a job of the bulk to push with the others, and return its id.
     *
     * @param  \SineMacula\Valkey\Queue\BulkPush  $bulk
     * @param  object|string  $job
     * @param  string  $payload
     * @param  mixed  $queue
     * @param  mixed  $delay
     * @return mixed
     */
    #[\Override]
    protected function gatherBulkJob(BulkPush $bulk, object|string $job, string $payload, mixed $queue, mixed $delay): mixed
    {
        if (isset($delay)) {
            $bulk->addDelayed($this->availableAt($delay), $payload);
        } else {
            $bulk->add($payload);
        }

        return $this->bulkJobId($payload);
    }

    /**
     * Push a job of the bulk on its own, as Laravel's push and later do, and
     * return its id.
     *
     * @param  object|string  $job
     * @param  string  $payload
     * @param  mixed  $queue
     * @param  mixed  $delay
     * @return mixed
     */
    #[\Override]
    protected function pushBulkJobAlone(object|string $job, string $payload, mixed $queue, mixed $delay): mixed
    {
        return isset($delay) ? $this->laterRaw($delay, $payload, $queue) : $this->pushRaw($payload, $queue);
    }
}
