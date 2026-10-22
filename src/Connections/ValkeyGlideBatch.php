<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

/**
 * A pipeline or transaction in progress on a Valkey GLIDE connection.
 *
 * This is what `pipeline()` and `transaction()` hand to their callback, or
 * return when called without one. It stands in for the phpredis client a
 * Laravel pipeline callback receives: every command called on it is prefixed by
 * the connection exactly as outside a batch and buffered, or, for the commands
 * GLIDE cannot buffer, run in call order in a pipeline or refused, and returns
 * the batch so calls can be chained. The results come back from `exec()`, or
 * from `pipeline()` or `transaction()` when a callback is given, as a list in
 * call order.
 *
 * @method self append(mixed ...$arguments)
 * @method self bitcount(mixed ...$arguments)
 * @method self bitop(mixed ...$arguments)
 * @method self dbsize(mixed ...$arguments)
 * @method self decr(mixed ...$arguments)
 * @method self decrby(mixed ...$arguments)
 * @method self del(mixed ...$arguments)
 * @method self echo(mixed ...$arguments)
 * @method self eval(mixed ...$arguments)
 * @method self evalsha(mixed ...$arguments)
 * @method self exists(mixed ...$arguments)
 * @method self expire(mixed ...$arguments)
 * @method self expireat(mixed ...$arguments)
 * @method self fcall(mixed ...$arguments)
 * @method self flushall(mixed ...$arguments)
 * @method self flushdb(mixed ...$arguments)
 * @method self get(mixed ...$arguments)
 * @method self getbit(mixed ...$arguments)
 * @method self getdel(mixed ...$arguments)
 * @method self getex(mixed ...$arguments)
 * @method self getrange(mixed ...$arguments)
 * @method self getset(mixed ...$arguments)
 * @method self hdel(mixed ...$arguments)
 * @method self hexists(mixed ...$arguments)
 * @method self hget(mixed ...$arguments)
 * @method self hgetall(mixed ...$arguments)
 * @method self hincrby(mixed ...$arguments)
 * @method self hincrbyfloat(mixed ...$arguments)
 * @method self hkeys(mixed ...$arguments)
 * @method self hlen(mixed ...$arguments)
 * @method self hmget(mixed ...$arguments)
 * @method self hmset(mixed ...$arguments)
 * @method self hset(mixed ...$arguments)
 * @method self hsetnx(mixed ...$arguments)
 * @method self hstrlen(mixed ...$arguments)
 * @method self hvals(mixed ...$arguments)
 * @method self incr(mixed ...$arguments)
 * @method self incrby(mixed ...$arguments)
 * @method self incrbyfloat(mixed ...$arguments)
 * @method self info(mixed ...$arguments)
 * @method self lindex(mixed ...$arguments)
 * @method self linsert(mixed ...$arguments)
 * @method self llen(mixed ...$arguments)
 * @method self lpop(mixed ...$arguments)
 * @method self lpos(mixed ...$arguments)
 * @method self lpush(mixed ...$arguments)
 * @method self lrange(mixed ...$arguments)
 * @method self lrem(mixed ...$arguments)
 * @method self lset(mixed ...$arguments)
 * @method self ltrim(mixed ...$arguments)
 * @method self mget(mixed ...$arguments)
 * @method self mset(mixed ...$arguments)
 * @method self msetnx(mixed ...$arguments)
 * @method self multi(mixed ...$arguments)
 * @method self persist(mixed ...$arguments)
 * @method self pexpire(mixed ...$arguments)
 * @method self pexpireat(mixed ...$arguments)
 * @method self pfadd(mixed ...$arguments)
 * @method self pfcount(mixed ...$arguments)
 * @method self ping(mixed ...$arguments)
 * @method self psetex(mixed ...$arguments)
 * @method self pttl(mixed ...$arguments)
 * @method self publish(mixed ...$arguments)
 * @method self randomkey(mixed ...$arguments)
 * @method self rawcommand(mixed ...$arguments)
 * @method self rename(mixed ...$arguments)
 * @method self renamenx(mixed ...$arguments)
 * @method self rpop(mixed ...$arguments)
 * @method self rpush(mixed ...$arguments)
 * @method self sadd(mixed ...$arguments)
 * @method self scard(mixed ...$arguments)
 * @method self sdiff(mixed ...$arguments)
 * @method self set(mixed ...$arguments)
 * @method self setbit(mixed ...$arguments)
 * @method self setex(mixed ...$arguments)
 * @method self setnx(mixed ...$arguments)
 * @method self setrange(mixed ...$arguments)
 * @method self sinter(mixed ...$arguments)
 * @method self sismember(mixed ...$arguments)
 * @method self smembers(mixed ...$arguments)
 * @method self smove(mixed ...$arguments)
 * @method self spop(mixed ...$arguments)
 * @method self srem(mixed ...$arguments)
 * @method self strlen(mixed ...$arguments)
 * @method self sunion(mixed ...$arguments)
 * @method self time(mixed ...$arguments)
 * @method self touch(mixed ...$arguments)
 * @method self ttl(mixed ...$arguments)
 * @method self type(mixed ...$arguments)
 * @method self unlink(mixed ...$arguments)
 * @method self zadd(mixed ...$arguments)
 * @method self zcard(mixed ...$arguments)
 * @method self zcount(mixed ...$arguments)
 * @method self zincrby(mixed ...$arguments)
 * @method self zinter(mixed ...$arguments)
 * @method self zinterstore(mixed ...$arguments)
 * @method self zrange(mixed ...$arguments)
 * @method self zrangebyscore(mixed ...$arguments)
 * @method self zrank(mixed ...$arguments)
 * @method self zrem(mixed ...$arguments)
 * @method self zremrangebyrank(mixed ...$arguments)
 * @method self zremrangebyscore(mixed ...$arguments)
 * @method self zrevrangebyscore(mixed ...$arguments)
 * @method self zrevrank(mixed ...$arguments)
 * @method self zscore(mixed ...$arguments)
 * @method self zunionstore(mixed ...$arguments)
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValkeyGlideBatch
{
    /** @var string Message for a batch used after it was executed or discarded. */
    private const string INACTIVE_MESSAGE = 'This pipeline or transaction has already been executed or discarded.';

    /**
     * Create a batch bound to its connection and state.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @param  \SineMacula\Valkey\Connections\BatchState  $state
     * @return void
     *
     * @internal
     */
    public function __construct(

        /** The connection that buffers and runs the batch's commands. */
        private ValkeyGlideConnection $connection,

        /** The state of the batch this object stands for. */
        private BatchState $state,
    ) {}

    /**
     * Add a command to the batch.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return self
     *
     * @throws \Throwable
     */
    public function __call(string $method, array $parameters): self
    {
        $this->assertActive();

        $this->connection->command($method, $parameters);

        return $this;
    }

    /**
     * Add a reverse range of a sorted set's members by index to the batch.
     *
     * GLIDE has no ZREVRANGE, so the range is read with ZRANGE ... REV, as the
     * connection's `zrevrange()` reads it.
     *
     * @param  string  $key
     * @param  int|string  $start
     * @param  int|string  $end
     * @param  mixed  $scores
     * @return self
     *
     * @throws \Throwable
     */
    public function zrevrange(string $key, int|string $start, int|string $end, mixed $scores = null): self
    {
        $this->assertActive();

        $this->connection->zrevrange($key, $start, $end, $scores);

        return $this;
    }

    /**
     * Run a pipeline callback against the batch, or nest a bare pipeline in it.
     *
     * The commands the callback adds join the batch, and their results the
     * batch's results, as with `pipeline()` on the connection inside a batch.
     *
     * @param  (callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed)|null  $callback
     * @return self
     *
     * @throws \Throwable
     */
    public function pipeline(?callable $callback = null): self
    {
        $this->assertActive();

        $this->connection->pipeline($callback);

        return $this;
    }

    /**
     * Run a transaction callback against the batch, or open a nested multi() in
     * it.
     *
     * As with `transaction()` on the connection inside a batch, a transaction
     * in a pipeline runs on its own, and its results take one place in the
     * pipeline's results.
     *
     * @param  (callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed)|null  $callback
     * @return self
     *
     * @throws \Throwable
     */
    public function transaction(?callable $callback = null): self
    {
        $this->assertActive();

        $this->connection->transaction($callback);

        return $this;
    }

    /**
     * Run a raw command, as `executeRaw()` on the connection does.
     *
     * GLIDE cannot buffer a raw command, so it runs in call order, after what
     * the batch has buffered; a transaction refuses it.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return self
     *
     * @throws \Throwable
     */
    public function executeRaw(array $parameters): self
    {
        $this->assertActive();

        $this->connection->executeRaw($parameters);

        return $this;
    }

    /**
     * Execute the batch and return its results in call order.
     *
     * Inside a callback the batch runs when the callback returns, so this is
     * refused, unless it closes a `multi()` or `transaction()` opened inside a
     * pipeline, which runs that transaction and returns the pipeline, as
     * phpredis does in pipeline mode.
     *
     * @return false|list<mixed>|self
     *
     * @throws \Throwable
     */
    public function exec(): array|false|self
    {
        $this->assertActive();

        return $this->connection->exec();
    }

    /**
     * Discard every command the batch still holds.
     *
     * A `multi()` or `transaction()` still open inside a pipeline is dropped on
     * its own, and the pipeline goes on. Inside a callback, throw from the
     * callback to discard the batch instead.
     *
     * @return bool
     *
     * @throws \LogicException
     * @throws \Throwable
     *
     * @imperative
     */
    public function discard(): bool
    {
        $this->assertActive();

        return $this->connection->discard();
    }

    /**
     * Assert that the batch is still active.
     *
     * @return void
     *
     * @throws \LogicException
     */
    private function assertActive(): void
    {
        if ($this->state->isClosed()) {
            throw new \LogicException(self::INACTIVE_MESSAGE);
        }
    }
}
