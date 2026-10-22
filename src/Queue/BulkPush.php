<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Queue;

use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Exceptions\ConnectionException;

/**
 * The jobs of one `Queue::bulk()` call on a GLIDE connection, gathered so one
 * script pushes them all.
 *
 * The server runs the script as one atomic step, as it runs the MULTI block
 * phpredis sends for Laravel's bulk. The script pushes the payloads after
 * ARGV[1], which counts them, onto the queue in KEYS[1], with a notification
 * for each on KEYS[2], then adds each time and payload after them to the
 * delayed jobs in KEYS[3], in chunks that stay within Lua's unpack() limit. It
 * answers with the number of jobs it pushed; GLIDE returns any error as false.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class BulkPush
{
    /** @var string The script that pushes the gathered jobs and answers with how many it pushed. */
    private const string SCRIPT = <<<'EOD'
        local ready = tonumber(ARGV[1])

        for i = 2, ready + 1, 100 do
            redis.call('rpush', KEYS[1], unpack(ARGV, i, math.min(i + 99, ready + 1)))
        end

        local notifications = {}

        for i = 1, math.min(ready, 100) do
            notifications[i] = 1
        end

        for i = 1, ready, 100 do
            redis.call('rpush', KEYS[2], unpack(notifications, 1, math.min(100, ready - i + 1)))
        end

        for i = ready + 2, #ARGV, 200 do
            redis.call('zadd', KEYS[3], unpack(ARGV, i, math.min(i + 199, #ARGV)))
        end

        return ready + (#ARGV - ready - 1) / 2
        EOD;

    /** @var string Message for a bulk push refused while its connection has a batch open. */
    private const string BATCH_OPEN_MESSAGE = 'A bulk of jobs cannot be pushed to [%s] while a pipeline or transaction is open on its connection,'
        . ' as GLIDE would run its script outside the batch; nothing was pushed.';

    /** @var string Message for a bulk push that failed. */
    private const string FAILED_MESSAGE = 'The bulk push to [%s] failed; some or all of its jobs may have been pushed.';

    /** @var bool Whether jobs are still being gathered. */
    private bool $open = true;

    /** @var list<string> Payloads of the jobs to push onto the queue itself, in order. */
    private array $ready = [];

    /** @var list<int|string> The time and payload of each delayed job, in order. */
    private array $delayed = [];

    /** @var list<\Closure(): void> Callbacks to run once the jobs are pushed, in order. */
    private array $pushed = [];

    /**
     * Determine whether jobs are still being gathered.
     *
     * A job whose push waits for a database transaction to commit is pushed on
     * its own once the bulk has closed.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Stop gathering jobs, so a job whose push waits for a database transaction
     * is pushed on its own, whether or not the bulk is pushed.
     *
     * @return void
     */
    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Gather a job to push onto the queue itself.
     *
     * @param  string  $payload
     * @return void
     */
    public function add(string $payload): void
    {
        $this->ready[] = $payload;
    }

    /**
     * Gather a delayed job.
     *
     * @param  int  $availableAt
     * @param  string  $payload
     * @return void
     */
    public function addDelayed(int $availableAt, string $payload): void
    {
        $this->delayed[] = $availableAt;
        $this->delayed[] = $payload;
    }

    /**
     * Run a callback once the gathered jobs are pushed.
     *
     * @param  \Closure(): void  $callback
     * @return void
     */
    public function afterPush(\Closure $callback): void
    {
        $this->pushed[] = $callback;
    }

    /**
     * Close the bulk and push the gathered jobs onto the queue with one script,
     * then run the callbacks waiting for the push.
     *
     * Nothing is sent when nothing was gathered. A push that fails, or one
     * while a pipeline or transaction is open on the connection, throws and
     * runs none of the callbacks.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  string  $queue
     * @return void
     *
     * @throws \LogicException
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    public function push(ValkeyGlideConnection $connection, string $queue): void
    {
        $this->close();

        $count = count($this->ready) + intdiv(count($this->delayed), 2);

        if ($count === 0) {
            return;
        }

        if ($connection->hasOpenBatch()) {
            throw new \LogicException(sprintf(self::BATCH_OPEN_MESSAGE, $queue));
        }

        $pushed = $connection->command('eval', [
            self::SCRIPT, 3, $queue, $queue . ':notify', $queue . ':delayed', count($this->ready), ...$this->ready, ...$this->delayed,
        ]);

        if ($pushed !== $count) {
            throw new ConnectionException(sprintf(self::FAILED_MESSAGE, $queue));
        }

        foreach ($this->pushed as $callback) {
            $callback();
        }
    }
}
