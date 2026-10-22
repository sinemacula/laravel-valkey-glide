<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Queue;

use Laravel\Horizon\Events\JobPending;
use Laravel\Horizon\Events\JobPushed;
use Laravel\Horizon\JobPayload;
use Laravel\Horizon\RedisQueue;
use SineMacula\Valkey\Queue\Concerns\HashTagsClusterQueues;
use SineMacula\Valkey\Queue\Concerns\PushesBulkJobsAtomically;

/**
 * Horizon's Redis queue, whose bulk push is atomic on a GLIDE connection, as it
 * is through phpredis, and whose keys are hash-tagged on a GLIDE cluster
 * connection before Laravel 13.5, as Laravel does from 13.5.
 *
 * Each job of the bulk is prepared, and its Horizon events fired, as Horizon's
 * push and later do: `JobPending` as it is gathered, before any worker can take
 * it, where Horizon has the event, as from Horizon 5.42, and `JobPushed` once
 * the bulk is pushed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ValkeyGlideHorizonQueue extends RedisQueue
{
    use HashTagsClusterQueues, PushesBulkJobsAtomically;

    /**
     * Create the payload of a job in the bulk, as Horizon's push and later do:
     * a delayed job's payload is created for the queue as it was named, and
     * prepared before the job is queued.
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
        if (!isset($delay)) {
            return $this->createPayload($job, $this->getQueue($queue), $data);
        }

        return (new JobPayload($this->createPayload($job, $queue, $data, $delay)))->prepare($job)->value;
    }

    /**
     * Gather a job of the bulk to push with the others, as Horizon's push and
     * later push it, and return its id.
     *
     * A job pushed onto the queue itself is prepared as it is pushed.
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
        $prepared = isset($delay) ? new JobPayload($payload) : (new JobPayload($payload))->prepare($job);

        if (class_exists(JobPending::class)) {
            $this->event($this->getQueue($queue), new JobPending($prepared->value));
        }

        if (isset($delay)) {
            $bulk->addDelayed($this->availableAt($delay), $prepared->value);
        } else {
            $bulk->add($prepared->value);
        }

        $bulk->afterPush(function () use ($queue, $prepared): void {
            $this->event($this->getQueue($queue), new JobPushed($prepared->value));
        });

        return $prepared->id();
    }

    /**
     * Push a job of the bulk on its own, as Horizon's push and later do, and
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
        if (!isset($delay)) {
            $this->lastPushed = $job;

            return $this->pushRaw($payload, $queue);
        }

        if (class_exists(JobPending::class)) {
            $this->event($this->getQueue($queue), new JobPending($payload));
        }

        $id = $this->laterRaw($delay, $payload, $queue);

        $this->event($this->getQueue($queue), new JobPushed($payload));

        return $id;
    }
}
