<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Queue\Concerns;

use Illuminate\Queue\Attributes\Delay;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Queue\BulkPush;

/**
 * Pushes a Redis queue's bulk of jobs on a GLIDE connection with one script, so
 * the bulk is atomic, as it is through phpredis, and a failed push throws.
 *
 * Laravel pushes each job of a bulk with its own EVAL, which ext-valkey_glide
 * 1.1.2 runs at once even inside a transaction, returning false when it fails.
 * The jobs are queued here as Laravel queues them, with their events, but
 * gathered, then pushed together by one script. A job whose push waits for a
 * database transaction to commit is pushed on its own once it commits. A bulk
 * on any other connection is Laravel's.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait PushesBulkJobsAtomically
{
    /** @var string Message for a job of the bulk that is neither an object nor a string. */
    private const string INVALID_BULK_JOB_MESSAGE = 'A job pushed in bulk must be an object or a string, [%s] given.';

    /** @var string Message for a bulk whose queue is named by neither a string nor an enum. */
    private const string INVALID_BULK_QUEUE_MESSAGE = 'A bulk must be pushed to a queue named by a string or an enum, [%s] given.';

    /** @var string Message for a job of the bulk whose delay is neither seconds, a date nor an interval. */
    private const string INVALID_BULK_DELAY_MESSAGE = 'A job pushed in bulk must be delayed by seconds, a date or an interval, [%s] given.';

    /**
     * Push an array of jobs onto the queue.
     *
     * @param  mixed  $jobs
     * @param  mixed  $data
     * @param  mixed  $queue
     * @return void
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    #[\Override]
    public function bulk(mixed $jobs, mixed $data = '', mixed $queue = null): void
    {
        $jobs       = (array) $jobs;
        $queue      = $this->bulkQueue($queue);
        $connection = $this->getConnection();

        if (!$connection instanceof ValkeyGlideConnection) {
            parent::bulk($jobs, $data, $queue);

            return;
        }

        $bulk = new BulkPush;

        try {
            foreach ($jobs as $value) {
                $job   = $this->bulkJob($value);
                $delay = $this->bulkJobDelay($job);

                $this->enqueueUsing(
                    $job,
                    $this->createBulkPayload($job, $data, $queue, $delay),
                    $queue,
                    $delay,
                    fn (string $payload): mixed => $bulk->isOpen()
                        ? $this->gatherBulkJob($bulk, $job, $payload, $queue, $delay)
                        : $this->pushBulkJobAlone($job, $payload, $queue, $delay),
                );
            }
        } finally {
            $bulk->close();
        }

        $bulk->push($connection, $this->bulkQueueKey($queue));
    }

    /**
     * Create the payload of a job in the bulk.
     *
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @param  \DateInterval|\DateTimeInterface|int|null  $delay
     * @return string
     *
     * @throws \Illuminate\Queue\InvalidPayloadException
     */
    abstract protected function createBulkPayload(object|string $job, mixed $data, ?string $queue, \DateInterval|\DateTimeInterface|int|null $delay): string;

    /**
     * Gather a job of the bulk to push with the others, and return its id.
     *
     * @param  \SineMacula\Valkey\Queue\BulkPush  $bulk
     * @param  object|string  $job
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  \DateInterval|\DateTimeInterface|int|null  $delay
     * @return mixed
     */
    abstract protected function gatherBulkJob(BulkPush $bulk, object|string $job, string $payload, ?string $queue, \DateInterval|\DateTimeInterface|int|null $delay): mixed;

    /**
     * Push a job of the bulk on its own, once its database transaction has
     * committed after the bulk was pushed, and return its id.
     *
     * @param  object|string  $job
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  \DateInterval|\DateTimeInterface|int|null  $delay
     * @return mixed
     */
    abstract protected function pushBulkJobAlone(object|string $job, string $payload, ?string $queue, \DateInterval|\DateTimeInterface|int|null $delay): mixed;

    /**
     * Get a job of the bulk, which Laravel queues as an object or a string.
     *
     * @param  mixed  $job
     * @return object|string
     *
     * @throws \InvalidArgumentException
     */
    private function bulkJob(mixed $job): object|string
    {
        if (is_object($job) || is_string($job)) {
            return $job;
        }

        throw new \InvalidArgumentException(sprintf(self::INVALID_BULK_JOB_MESSAGE, get_debug_type($job)));
    }

    /**
     * Get the name of the bulk's queue, which Laravel names by a string or an
     * enum, whose value or name it reads as the name, or leaves null for the
     * default queue.
     *
     * @param  mixed  $queue
     * @return string|null
     *
     * @throws \InvalidArgumentException
     */
    private function bulkQueue(mixed $queue): ?string
    {
        if ($queue instanceof \UnitEnum) {
            return $queue instanceof \BackedEnum ? (string) $queue->value : $queue->name;
        }

        if ($queue === null || is_string($queue)) {
            return $queue;
        }

        throw new \InvalidArgumentException(sprintf(self::INVALID_BULK_QUEUE_MESSAGE, get_debug_type($queue)));
    }

    /**
     * Get the id of a job from its payload, as Laravel's push returns it.
     *
     * @param  string  $payload
     * @return mixed
     */
    private function bulkJobId(string $payload): mixed
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded['id'] ?? null : null;
    }

    /**
     * Get the delay of a job in the bulk, as Laravel's `bulk()` reads it: from
     * Laravel 13, a `#[Delay]` attribute or else the job's `delay`, and before
     * that the job's `delay`.
     *
     * @param  object|string  $job
     * @return \DateInterval|\DateTimeInterface|int|null
     *
     * @throws \InvalidArgumentException
     */
    private function bulkJobDelay(object|string $job): \DateInterval|\DateTimeInterface|int|null
    {
        if (is_string($job)) {
            return null;
        }

        // Reflection reads the installed framework, as static analysis only
        // sees the one it runs against.
        $delay = (new \ReflectionObject($this))->hasMethod('getAttributeValue') ? $this->getAttributeValue($job, Delay::class, 'delay') : $job->delay ?? null;

        if ($delay === null || is_int($delay) || $delay instanceof \DateTimeInterface || $delay instanceof \DateInterval) {
            return $delay;
        }

        throw new \InvalidArgumentException(sprintf(self::INVALID_BULK_DELAY_MESSAGE, get_debug_type($delay)));
    }

    /**
     * Get the key of the queue the bulk is pushed to, as Laravel's push builds
     * it: from Laravel 13.5 with `getQueueRedisKey()`, which hash-tags it on a
     * cluster, and before that with `getQueue()`.
     *
     * @param  string|null  $queue
     * @return string
     */
    private function bulkQueueKey(?string $queue): string
    {
        return (new \ReflectionObject($this))->hasMethod('getQueueRedisKey') ? $this->getQueueRedisKey($queue) : $this->getQueue($queue);
    }
}
