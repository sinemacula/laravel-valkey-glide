<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use SineMacula\Valkey\Exceptions\ConnectionException;
use SineMacula\Valkey\Support\Cast;

// The adapter mirrors a broad Redis command surface plus retry, event, and
// normalisation helpers, and drives the pipeline and transaction state machine,
// whose steps share the client, the retry path and the WATCH bookkeeping, so it
// exceeds the default method ceiling by design; the methods stay small and
// cohesive, the stateless command classification lives in BatchCommands, and
// the per-batch state in BatchState. Mirrors the @SuppressWarnings below.
// phpcs:disable SineMacula.Metrics.MaxMethodCount.TooManyMethods
/**
 * Laravel Redis connection adapter backed by the Valkey GLIDE client.
 *
 * Pipelines and transactions are tracked by the connection rather than left to
 * GLIDE's batch mode, which ext-valkey_glide 1.1.2 leaves in unsafe states: an
 * empty batch wedges the client, and scripts and raw commands run immediately
 * instead of being buffered. The connection counts what it buffers, starts
 * GLIDE's batch lazily, and runs or refuses each command that GLIDE cannot
 * buffer.
 *
 * @mixin \ValkeyGlide
 *
 * @method \SineMacula\Valkey\Connections\ValkeyGlideBatch multi(int $mode = \ValkeyGlide::MULTI)
 * @method mixed watch(mixed ...$keys)
 * @method mixed unwatch()
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1448")
 */
final class ValkeyGlideConnection extends Connection
{
    /** @var array<int, string> Commands safe to retry exactly once. */
    private const array IDEMPOTENT_RETRYABLE_COMMANDS = [
        'ECHO',
        'EXISTS',
        'GET',
        'GETBIT',
        'GETRANGE',
        'HGET',
        'HGETALL',
        'HEXISTS',
        'HKEYS',
        'HLEN',
        'MGET',
        'PING',
        'PTTL',
        'RANDOMKEY',
        'SCARD',
        'SISMEMBER',
        'SMEMBERS',
        'STRLEN',
        'TIME',
        'TTL',
        'TYPE',
        'ZCARD',
        'ZRANGE',
        'ZRANGEBYSCORE',
        'ZRANK',
        'ZSCORE',
    ];

    /** @var array<int, string> Keyless commands a cluster client must run on every primary. */
    private const array ALL_PRIMARIES_COMMANDS = [
        'FLUSHALL',
        'FLUSHDB',
    ];

    /** @var array<int, string> phpredis-style flush mode flags. */
    private const array FLUSH_MODE_FLAGS = [
        'ASYNC',
        'SYNC',
    ];

    /** @var list<string> Batch control commands the connection handles itself. */
    private const array BATCH_CONTROL_COMMANDS = [
        'DISCARD',
        'EXEC',
        'MULTI',
        'PIPELINE',
        'UNWATCH',
        'WATCH',
    ];

    /** @var string Message for a command a batch cannot carry. */
    private const string REFUSED_IN_BATCH_MESSAGE = '[%s] cannot be used inside a pipeline or transaction, as GLIDE would refuse it, block on it,'
        . ' or write to a freed iterator.';

    /** @var string Message for a call GLIDE would report buffered without buffering it. */
    private const string UNBUFFERED_IN_BATCH_MESSAGE = 'GLIDE would not buffer [%s] with these arguments, although it reports it buffered, so it was not added to the batch.';

    /** @var string Message for a command GLIDE cannot buffer inside a transaction. */
    private const string IMMEDIATE_IN_TRANSACTION_MESSAGE = '[%s] cannot run inside a transaction, as GLIDE sends it at once, outside the MULTI block,'
        . ' so the transaction would not be atomic; use pipeline() instead.';

    /** @var string Message for a client-local method called inside a transaction. */
    private const string LOCAL_IN_TRANSACTION_MESSAGE = '[%s] cannot be called inside a transaction, as GLIDE runs it at once on the client'
        . ' instead of adding it to the MULTI block; call it before or after the transaction.';

    /** @var string Message for a multi() mode GLIDE does not accept. */
    private const string UNSUPPORTED_MULTI_MODE_MESSAGE = 'Unsupported multi() mode [%s]; use \ValkeyGlide::MULTI or \ValkeyGlide::PIPELINE.';

    /** @var string Message for a command a watched cluster transaction cannot check against the watched slot. */
    private const string UNGUARDED_WATCH_MESSAGE = 'A cluster transaction that watches keys cannot carry [%s], as its keys are unknown or absent,'
        . ' so GLIDE could send the transaction to a node where the WATCH does not guard it.';

    /** @var string Message for a transaction joining a pipeline while keys are watched. */
    private const string WATCHED_TRANSACTION_IN_PIPELINE_MESSAGE = 'A transaction cannot join a pipeline while keys are watched, as the pipeline flattens it'
        . ' and the WATCH could not guard it; run the transaction outside the pipeline.';

    /** @var string Message for a bare pipeline command given arguments it would ignore. */
    private const string PIPELINE_ARGUMENTS_MESSAGE = 'pipeline() takes a callback only as a method of the connection or the batch, not through command().';

    /** @var string Message for a cluster transaction whose keys span hash slots. */
    private const string CROSS_SLOT_MESSAGE = 'A cluster transaction must keep every key in one hash slot, but [%s] key [%s] is in slot [%d], not slot [%d];'
        . ' use a hash tag, such as {user:1}, so they share one.';

    /** @var string Message for a cluster transaction key outside the slot of the watched keys. */
    private const string WATCHED_CROSS_SLOT_MESSAGE = 'A cluster transaction must keep every key in the hash slot of the keys it watches, but [%s] key [%s]'
        . ' is in slot [%d], not slot [%d] of the watched keys [%s]; watch only keys in that slot, or call unwatch() first if they are'
        . ' still watched after a failed unwatch().';

    /** @var string Message for a batch whose exec() results do not match what it buffered. */
    private const string MISALIGNED_BATCH_MESSAGE = 'The %s was sent and has most likely been applied, but its results do not match its commands,'
        . ' so they could not be returned in call order.';

    /** @var string Message for a pipeline whose exec() failed. */
    private const string PIPELINE_FAILED_MESSAGE = 'The pipeline failed; some of its commands may have been applied.';

    /** @var string Message for an unwatched transaction whose exec() failed. */
    private const string TRANSACTION_FAILED_MESSAGE = 'The transaction failed and its outcome is unknown; it may have been committed.';

    /** @var string Message for a watched transaction whose exec() failed. */
    private const string WATCHED_TRANSACTION_FAILED_MESSAGE = 'The watched transaction was aborted or its outcome is unknown; a watched key may have changed,'
        . ' or it may have timed out and still been committed.';

    /** @var string Message for a key-list command given a key list in a form GLIDE does not take as keys. */
    private const string UNSUPPORTED_KEY_LIST_MESSAGE = '[%s] takes its keys as separate arguments or as a single list of keys, not a list beside other'
        . ' arguments or a list of lists, which GLIDE would send as the key "Array" or drop.';

    /** @var string Message for a command GLIDE refused to buffer. */
    private const string BUFFER_REFUSED_MESSAGE = 'GLIDE refused to buffer [%s], most likely due to its arguments, so it was not added to the batch.';

    /** @var string Message for a client that refused batch mode. */
    private const string BATCH_MODE_REFUSED_MESSAGE = 'Unable to start the %s; GLIDE refused batch mode, so the connection may be closed.';

    /** @var string Message for a batch that ended inside its callback. */
    private const string ENDED_IN_CALLBACK_MESSAGE = 'The batch ended inside its callback, so it was not executed; any of its commands not yet sent were discarded.';

    /** @var string Message for a connection call made inside a callback whose batch has ended. */
    private const string ENDED_BATCH_MESSAGE = '[%s()] cannot be called once the pipeline or transaction has ended inside its callback; return or throw from the callback.';

    /** @var string Message for a batch a listener left open while a pipeline ran a command GLIDE cannot buffer. */
    private const string LEFT_OPEN_BY_LISTENER_MESSAGE = 'A listener left a pipeline or transaction open while [%s] ran in a pipeline, so both were ended.';

    /** @var string Message for an exec() called inside the callback that owns the batch. */
    private const string EXEC_IN_CALLBACK_MESSAGE = 'exec() cannot be called inside a pipeline or transaction callback; the batch runs when the callback returns.';

    /** @var string Message for a discard() called inside the callback that owns the batch. */
    private const string DISCARD_IN_CALLBACK_MESSAGE = 'discard() cannot be called inside a pipeline or transaction callback; throw from the callback to discard the batch.';

    /** @var string Message for a connection method refused while a batch is active. */
    private const string ACTIVE_BATCH_MESSAGE = '[%s()] cannot be called while a pipeline or transaction is active; %s.';

    /** @var array<int, string> Error fragments treated as transient transport faults. */
    private const array TRANSIENT_ERROR_FRAGMENTS = [
        'connection reset by peer',
        'connection closed',
        'connection lost',
        'protocol error, got',
        'reply-type byte',
        'reply type byte',
        'socket',
        'broken pipe',
        'eof',
        'read error on connection',
        'error while reading',
        'went away',
        'temporarily unavailable',
    ];

    /** @var \ValkeyGlide|\ValkeyGlideCluster Active GLIDE client instance. */
    protected \ValkeyGlide|\ValkeyGlideCluster $glideClient;

    /** @var (\Closure(): (\ValkeyGlide|\ValkeyGlideCluster))|null Reconnection client factory. */
    protected ?\Closure $connector;

    /** @var array<string, mixed> Connection-level configuration. */
    protected array $config;

    /** @var \SineMacula\Valkey\Connections\KeyPrefixer Key prefixer instance for command parameter normalization. */
    private readonly KeyPrefixer $prefixer;

    /** @var \SineMacula\Valkey\Connections\ClusterRouter Route resolver for raw commands, and hash slot resolver for transactions, on cluster clients. */
    private readonly ClusterRouter $router;

    /** @var \Closure(int, int): int Random integer generator callback. */
    private readonly \Closure $randomIntGenerator;

    /** @var \Closure(int): void Sleep callback. */
    private readonly \Closure $sleepCallback;

    /** @var \SineMacula\Valkey\Connections\BatchCommands Classifier for commands issued while a batch is active. */
    private readonly BatchCommands $batchCommands;

    /** @var \SineMacula\Valkey\Connections\BatchState|null The active pipeline or transaction, if any. */
    private ?BatchState $batch = null;

    /** @var list<string>|null Prefixed keys watched with WATCH, or null when nothing is watched. */
    private ?array $watchedKeys = null;

    /** @var array<string, true> Cluster scan cursor ids this connection returned and has not yet resumed from. */
    private array $clusterScanCursors = [];

    /**
     * Create a new Valkey GLIDE Laravel connection wrapper.
     *
     * @param  \ValkeyGlide|\ValkeyGlideCluster  $client
     * @param  (\Closure(): (\ValkeyGlide|\ValkeyGlideCluster))|null  $connector
     * @param  array<string, mixed>  $config
     * @return void
     */
    public function __construct(\ValkeyGlide|\ValkeyGlideCluster $client, ?\Closure $connector = null, array $config = [])
    {
        $this->glideClient        = $client;
        $this->connector          = $connector;
        $this->config             = $config;
        $this->prefixer           = new KeyPrefixer($this->resolvePrefix($config['prefix'] ?? null));
        $this->router             = new ClusterRouter;
        $this->randomIntGenerator = $this->resolveRandomIntGenerator($config['random_int'] ?? null);
        $this->sleepCallback      = $this->resolveSleepCallback($config['sleep'] ?? null);
        $this->batchCommands      = new BatchCommands;
    }

    /**
     * Get the underlying GLIDE client instance.
     *
     * @return \ValkeyGlide|\ValkeyGlideCluster
     */
    #[\Override]
    public function client(): \ValkeyGlide|\ValkeyGlideCluster
    {
        return $this->glideClient;
    }

    /**
     * Determine if the connection is a cluster connection.
     *
     * Laravel 13.5 added isCluster() to its base connection, and its Redis
     * queue and concurrency limiter use it to hash-tag their keys into a single
     * slot. Laravel 12 and 13.0 to 13.4 do not define it, so the method carries
     * no #[\Override] attribute.
     *
     * @return bool
     *
     * @phpstan-ignore method.missingOverride
     */
    public function isCluster(): bool
    {
        return $this->glideClient instanceof \ValkeyGlideCluster;
    }

    /**
     * Returns the value of the given key.
     *
     * Refused while a pipeline or transaction is active, as the value is only
     * known once the batch runs; call `get()` on the batch instead.
     *
     * @param  string  $key
     * @return string|null
     *
     * @throws \Throwable
     */
    public function get(string $key): ?string
    {
        $this->assertNoActiveBatch('get', 'call get() on the batch instead');

        /** @var false|string $result */
        $result = $this->command('get', [$key]);

        return $result !== false ? $result : null;
    }

    /**
     * Get the values of all the given keys.
     *
     * Refused while a pipeline or transaction is active, as the values are only
     * known once the batch runs; call `mget()` on the batch instead.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string|null>
     *
     * @throws \LogicException
     * @throws \UnexpectedValueException
     */
    public function mget(array $keys): array
    {
        $this->assertNoActiveBatch('mget', 'call mget() on the batch instead');

        return $this->normalizeMgetResults($this->command('mget', [$keys]));
    }

    /**
     * Run commands in a pipeline, or start one.
     *
     * With a callback, the callback is handed the batch, the commands it adds
     * are sent together, and their results are returned as a list in call
     * order. Without one, the batch is returned, to be run with its `exec()`.
     * Commands GLIDE cannot buffer, such as EVAL, run in call order: what was
     * buffered before them is sent first, and their result takes its place in
     * the list. Inside an active pipeline or transaction, the callback runs
     * against that batch and the batch is returned; its results come from the
     * outermost call. Without a callback, the active batch is returned as it
     * is, as phpredis does.
     *
     * @param  (callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed)|null  $callback
     * @return ($callback is null ? \SineMacula\Valkey\Connections\ValkeyGlideBatch : list<mixed>|\SineMacula\Valkey\Connections\ValkeyGlideBatch)
     *
     * @throws \Throwable
     */
    public function pipeline(?callable $callback = null): array|ValkeyGlideBatch
    {
        return $this->runBatch(false, $callback);
    }

    /**
     * Run commands in a MULTI/EXEC transaction, or start one.
     *
     * This behaves like `pipeline()`, but the commands run atomically, so any
     * command GLIDE cannot buffer is refused, and on a cluster every key must
     * share one hash slot. Returns false when a transaction guarded by WATCH is
     * aborted. Inside an active pipeline the transaction joins it and is no
     * longer atomic, as GLIDE cannot nest a MULTI inside a pipeline; without a
     * callback, its `exec()` then returns the batch, as phpredis does. While
     * keys are watched it cannot join a pipeline, where the WATCH could not
     * guard it.
     *
     * @param  (callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed)|null  $callback
     * @return ($callback is null ? \SineMacula\Valkey\Connections\ValkeyGlideBatch : false|list<mixed>|\SineMacula\Valkey\Connections\ValkeyGlideBatch)
     *
     * @throws \Throwable
     */
    public function transaction(?callable $callback = null): array|false|ValkeyGlideBatch
    {
        return $this->runBatch(true, $callback);
    }

    /**
     * Execute the active pipeline or transaction.
     *
     * This is the `exec()` of phpredis-style code that drives a batch from the
     * connection, as `RedisStore::putMany()` does after `multi()`. An `exec()`
     * that closes a `multi()` opened inside a pipeline returns the batch, as
     * phpredis does in pipeline mode. Returns false when no batch is active.
     *
     * @return false|list<mixed>|\SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    public function exec(): array|false|ValkeyGlideBatch
    {
        $this->assertBatchNotEnded('exec');

        return $this->batch !== null ? $this->execBatch($this->batch) : false;
    }

    /**
     * Discard the active pipeline or transaction.
     *
     * Returns false when no batch is active.
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
        $this->assertBatchNotEnded('discard');

        return $this->batch !== null && $this->discardBatch($this->batch);
    }

    /**
     * Set the given key if it does not exist.
     *
     * GLIDE answers with a bool; the result is returned as 1 or 0, as phpredis
     * returns it through Laravel, since Laravel's and Horizon's locks compare
     * it with 1. Refused while a pipeline or transaction is active, as the
     * result is only known once the batch runs; call `setnx()` on the batch
     * instead.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return int
     *
     * @throws \LogicException
     * @throws \Throwable
     */
    public function setnx(string $key, mixed $value): int
    {
        $this->assertNoActiveBatch('setnx', 'call setnx() on the batch instead');

        return $this->command('setnx', [$key, $value]) === true ? 1 : 0;
    }

    /**
     * Set the given hash field if it does not exist.
     *
     * GLIDE answers with a bool; the result is returned as 1 or 0, as phpredis
     * returns it through Laravel. Refused while a pipeline or transaction is
     * active, as the result is only known once the batch runs; call `hsetnx()`
     * on the batch instead.
     *
     * @param  string  $key
     * @param  mixed  $field
     * @param  mixed  $value
     * @return int
     *
     * @throws \LogicException
     * @throws \Throwable
     */
    public function hsetnx(string $key, mixed $field, mixed $value): int
    {
        $this->assertNoActiveBatch('hsetnx', 'call hsetnx() on the batch instead');

        return $this->command('hsetnx', [$key, $field, $value]) === true ? 1 : 0;
    }

    /**
     * Return a range of a sorted set's members by index, from the highest score
     * to the lowest.
     *
     * GLIDE has no ZREVRANGE, so the range is read with ZRANGE ... REV, which
     * returns the same members in the same order. Scores are included when
     * `$scores` is true or an options array whose `withscores` is true, as
     * phpredis takes it.
     *
     * @param  string  $key
     * @param  int|string  $start
     * @param  int|string  $end
     * @param  mixed  $scores
     * @return mixed
     *
     * @throws \Throwable
     */
    public function zrevrange(string $key, int|string $start, int|string $end, mixed $scores = null): mixed
    {
        $withScores = is_array($scores) ? ($scores['withscores'] ?? false) : $scores;

        return $this->command('zRange', [$key, $start, $end, ['rev' => true, 'withscores' => (bool) $withScores]]);
    }

    /**
     * Scan the keyspace for one page of keys, with Laravel's options array.
     *
     * As with Laravel's phpredis connection, the scan starts from cursor '0'
     * (or `null`), takes the `match` pattern, unprefixed, and the `count` hint
     * from the options, and returns the next cursor and the page's keys. The
     * cursor is '0' again once the scan is complete. GLIDE takes the cursor by
     * reference on a server and as a ClusterScanCursor on a cluster, so each is
     * translated here. A failed scan throws, as it does through phpredis,
     * rather than reading as an empty last page. A ClusterScanCursor, or
     * GLIDE's own pattern, count and type arguments, are passed to GLIDE
     * unchanged.
     *
     * @param  mixed  $cursor
     * @param  mixed  $options
     * @param  mixed  ...$arguments
     * @return ($cursor is \ClusterScanCursor ? mixed : ($options is array ? array{0: string, 1: array<int, string>} : mixed))
     *
     * @throws \Throwable
     */
    public function scan(mixed $cursor, mixed $options = [], mixed ...$arguments): mixed
    {
        // Inside a batch the connection refuses SCAN, which it does before a
        // cluster cursor id is used up, so the scan can resume once it ends.
        if ($cursor instanceof \ClusterScanCursor || !is_array($options) || $this->batch !== null) {
            return $this->command('scan', func_get_args());
        }

        $cursor  = Cast::toNonEmptyString($cursor)                   ?? '0';
        $pattern = Cast::toNonEmptyString($options['match'] ?? null) ?? '*';
        $count   = Cast::toNonNegativeInt($options['count'] ?? null) ?? 10;

        if ($this->glideClient instanceof \ValkeyGlideCluster) {
            return $this->scanCluster($cursor, $pattern, $count);
        }

        $keys = $this->command('scan', [&$cursor, $pattern, $count]);

        return [$cursor, self::scannedKeys($keys, $pattern)];
    }

    /**
     * Scan a sorted set for one page of members and their scores, with
     * Laravel's options array.
     *
     * As with Laravel's phpredis connection, the scan starts from cursor '0'
     * (or `null`), takes the `match` pattern and the `count` hint from the
     * options, and returns the next cursor and the page, which maps each member
     * to its score as a float, where GLIDE returns a string. The cursor is '0'
     * again once the scan is complete. The key is prefixed; the pattern, which
     * matches members, is not. GLIDE writes the cursor back through a
     * reference, which a method call cannot carry, so GLIDE's own form goes
     * through `command()` with the cursor by reference. A failed scan throws,
     * as the keyspace `scan()` does, rather than reading as an empty last page,
     * and like the SCAN family the scan is refused inside a pipeline or
     * transaction.
     *
     * @param  string  $key
     * @param  mixed  $cursor
     * @param  array<array-key, mixed>  $options
     * @return array{0: string, 1: array<array-key, float>}
     *
     * @throws \Throwable
     */
    public function zscan(string $key, mixed $cursor, array $options = []): array
    {
        [$cursor, $members] = $this->scanMembers('zscan', $key, $cursor, $options);

        /** @var array<array-key, string> $members */
        return [$cursor, array_map(self::score(...), $members)];
    }

    /**
     * Scan a hash for one page of fields and their values, with Laravel's
     * options array.
     *
     * The page maps each field to its value. The cursor, the options and a
     * failed scan are handled as `zscan()` handles them.
     *
     * @param  string  $key
     * @param  mixed  $cursor
     * @param  array<array-key, mixed>  $options
     * @return array{0: string, 1: array<array-key, mixed>}
     *
     * @throws \Throwable
     */
    public function hscan(string $key, mixed $cursor, array $options = []): array
    {
        return $this->scanMembers('hscan', $key, $cursor, $options);
    }

    /**
     * Scan a set for one page of members, with Laravel's options array.
     *
     * The page lists the members. The cursor, the options and a failed scan are
     * handled as `zscan()` handles them.
     *
     * @param  string  $key
     * @param  mixed  $cursor
     * @param  array<array-key, mixed>  $options
     * @return array{0: string, 1: array<array-key, mixed>}
     *
     * @throws \Throwable
     */
    public function sscan(string $key, mixed $cursor, array $options = []): array
    {
        return $this->scanMembers('sscan', $key, $cursor, $options);
    }

    /**
     * Execute a Redis command with one safe retry on transient disconnects.
     *
     * While a pipeline or transaction is active, the command is added to it
     * instead, and the batch is returned.
     *
     * @param  mixed  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    #[\Override]
    public function command(mixed $method, array $parameters = []): mixed
    {
        $normalizedMethod = $this->normalizeCommandMethod($method);

        $this->assertBatchNotEnded($normalizedMethod);
        $this->assertSupportedKeyList($normalizedMethod, $parameters);

        if (in_array(strtoupper($normalizedMethod), self::BATCH_CONTROL_COMMANDS, true)) {
            return $this->controlBatch($normalizedMethod, $parameters);
        }

        if ($this->batch !== null) {
            return $this->queueBatchCommand($this->batch, $normalizedMethod, $parameters);
        }

        return $this->executeCommandWithRetry(
            $normalizedMethod,
            $this->prefixer->apply($normalizedMethod, $parameters),
        );
    }

    /**
     * Subscribe to channels and normalize callback payload arguments.
     *
     * @param  mixed  $channels
     * @param  \Closure(mixed, mixed): void  $callback
     * @param  mixed  $method
     * @return void
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     *
     * @phpstan-ignore method.childParameterType
     */
    #[\Override]
    public function createSubscription(mixed $channels, \Closure $callback, mixed $method = 'subscribe'): void
    {
        $normalizedMethod = $this->normalizeSubscriptionMethod($method);

        $this->assertNoActiveBatch($normalizedMethod, 'subscribe outside the batch');

        $this->invokeSubscription($normalizedMethod, $this->normalizeSubscriptionChannels($channels), $callback);
    }

    /**
     * Determine whether a pipeline or transaction is open on this connection.
     *
     * True from `pipeline()`, `transaction()` or `multi()` until the batch is
     * sent or discarded, including while its callback runs and after an error
     * ended it there. Code that sends a command to the client directly, rather
     * than through the connection, checks this first, as GLIDE would otherwise
     * run the command at once, outside the batch, or buffer it.
     *
     * @return bool
     */
    public function hasOpenBatch(): bool
    {
        return $this->batch !== null;
    }

    /**
     * Get the key prefix this connection applies to command keys.
     *
     * GLIDE has no client-side prefix, so code that builds key patterns, such
     * as a SCAN MATCH pattern, reads the prefix here. Empty when none is
     * configured.
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefixer->prefix();
    }

    /**
     * Execute a raw command against the underlying GLIDE client.
     *
     * A cluster client's rawcommand() takes a route before the command, so one
     * is resolved from the command.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    public function executeRaw(array $parameters): mixed
    {
        if ($this->glideClient instanceof \ValkeyGlideCluster) {
            $parameters = [$this->router->route($parameters), ...array_values($parameters)];
        }

        return $this->command('rawcommand', $parameters);
    }

    /**
     * Close the underlying GLIDE connection.
     *
     * An active pipeline or transaction ends, as GLIDE drops what it buffered
     * when it closes, and the keys the connection watched are forgotten, as the
     * server drops the WATCH with the connection.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function disconnect(): void
    {
        if ($this->batch !== null) {
            $this->endBatch($this->batch);
        }

        $this->watchedKeys = null;

        $this->glideClient->close();
    }

    /**
     * Parse the command's parameters for event dispatching.
     *
     * A cluster scan takes a ClusterScanCursor, which cannot be cast to a
     * string, so listeners that format the parameters would fail on it. The
     * event carries the cursor id it scans from instead, as a standalone scan
     * carries its cursor; the client still receives the object.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    #[\Override]
    protected function parseParametersForEvent(array $parameters): array
    {
        return array_map(
            static fn (mixed $parameter): mixed => $parameter instanceof \ClusterScanCursor ? $parameter->getCursor() : $parameter,
            $parameters,
        );
    }

    /**
     * Assert that a key-list command's keys are in a form GLIDE takes as keys.
     *
     * A list beside other arguments, as in `del('a', ['b', 'c'])`, or a list of
     * lists, would reach keys outside the prefix, such as the key `Array`, so
     * it is refused before anything is sent, inside a batch or outside one.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private function assertSupportedKeyList(string $method, array $parameters): void
    {
        if ($this->prefixer->hasUnsupportedKeyList($method, $parameters)) {
            throw new \InvalidArgumentException(sprintf(self::UNSUPPORTED_KEY_LIST_MESSAGE, strtoupper($method)));
        }
    }

    /**
     * Normalize an MGET response, giving null for each missing value.
     *
     * @param  mixed  $results
     * @return array<int, string|null>
     *
     * @throws \UnexpectedValueException
     */
    private function normalizeMgetResults(mixed $results): array
    {
        if (!is_array($results)) {
            throw new \UnexpectedValueException(sprintf('Expected array response from mget, received [%s].', get_debug_type($results)));
        }

        return array_map(
            static fn (false|string $value): ?string => $value !== false ? $value : null,
            $results,
        );
    }

    /**
     * Scan every primary of a cluster for one page of keys, from a cursor id.
     *
     * Each call builds a ClusterScanCursor from the id the last one returned,
     * since passing the same cursor again rescans the first node. GLIDE aborts
     * the process when it resumes from an id it never returned, or from one
     * whose scan has ended, so only an id this connection returned is resumed,
     * and only once. The scan goes through command(), so its command events
     * carry the id it scans from, as parseParametersForEvent() reports it.
     *
     * @param  string  $cursor
     * @param  string  $pattern
     * @param  int  $count
     * @return array{0: string, 1: array<int, string>}
     *
     * @throws \InvalidArgumentException
     */
    private function scanCluster(string $cursor, string $pattern, int $count): array
    {
        if ($cursor !== '0' && !isset($this->clusterScanCursors[$cursor])) {
            throw new \InvalidArgumentException(sprintf('Cannot resume a cluster scan from cursor [%s]: only a cursor this connection returned can be resumed, once.', $cursor));
        }

        unset($this->clusterScanCursors[$cursor]);

        $iterator = new \ClusterScanCursor($cursor);
        $keys     = self::scannedKeys($this->command('scan', [$iterator, $pattern, $count]), $pattern);
        $next     = $iterator->getNextCursor();

        $this->clusterScanCursors[$next] = true;

        return [$next, $keys];
    }

    /**
     * Return the keys of a scanned page, or throw for a failed scan.
     *
     * GLIDE answers an empty page with an empty array and a failed scan with
     * false, so the failure is raised rather than read as an empty page, which
     * would end the caller's scan loop early as though it had completed.
     *
     * @param  mixed  $keys
     * @param  string  $pattern
     * @return array<int, string>
     *
     * @throws \UnexpectedValueException
     */
    private static function scannedKeys(mixed $keys, string $pattern): array
    {
        if (!is_array($keys)) {
            throw new \UnexpectedValueException(sprintf('Expected array response from %s on [%s], received [%s].', 'scan', $pattern, get_debug_type($keys)));
        }

        /** @var array<int, string> $keys */
        return $keys;
    }

    /**
     * Read a score GLIDE returns as a string as the float phpredis returns.
     *
     * The server writes a score as a number PHP's float cast reads, or as an
     * infinity, such as 'inf' or '-inf', which phpredis reads as infinity, as
     * C's atof() does, and the cast as 0.
     *
     * @param  string  $score
     * @return float
     */
    private static function score(string $score): float
    {
        return match ($score) {
            'inf', '+inf' => INF,
            '-inf'        => -INF,
            default       => (float) $score,
        };
    }

    /**
     * Scan a set, sorted set or hash for one page of members, from a cursor.
     *
     * The scan goes through command(), so its key is prefixed, it is refused
     * inside a batch, and GLIDE writes the next cursor back through the
     * reference. GLIDE answers a missing key with an empty page and a failed
     * scan with false, which throws.
     *
     * @param  string  $method
     * @param  string  $key
     * @param  mixed  $cursor
     * @param  array<array-key, mixed>  $options
     * @return array{0: string, 1: array<array-key, mixed>}
     *
     * @throws \UnexpectedValueException
     */
    private function scanMembers(string $method, string $key, mixed $cursor, array $options): array
    {
        $cursor  = Cast::toNonEmptyString($cursor)                   ?? '0';
        $pattern = Cast::toNonEmptyString($options['match'] ?? null) ?? '*';
        $count   = Cast::toNonNegativeInt($options['count'] ?? null) ?? 10;

        $members = $this->command($method, [$key, &$cursor, $pattern, $count]);

        if (!is_array($members)) {
            throw new \UnexpectedValueException(sprintf('Expected array response from %s on [%s], received [%s].', $method, $key, get_debug_type($members)));
        }

        return [$cursor, $members];
    }

    /**
     * Subscribe the callback to the channels with GLIDE's subscribe method.
     *
     * @param  string  $method
     * @param  array<int, string>  $channels
     * @param  \Closure(mixed, mixed): void  $callback
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private function invokeSubscription(string $method, array $channels, \Closure $callback): void
    {
        $handler = $this->newMessageHandler($callback);

        match ($method) {
            'subscribe'  => $this->glideClient->subscribe($channels, $handler),
            'psubscribe' => $this->glideClient->psubscribe($channels, $handler),
            default      => throw new \InvalidArgumentException(sprintf('Unsupported subscription method [%s].', $method)),
        };
    }

    /**
     * Run a pipeline or transaction, or start one.
     *
     * Inside an active batch, a callback runs against that batch, which is then
     * returned for its owner to execute, and a call without one opens a nested
     * batch as a bare `multi()` or `pipeline()` would.
     *
     * @param  bool  $atomic
     * @param  (callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed)|null  $callback
     * @return ($atomic is true ? false|list<mixed>|\SineMacula\Valkey\Connections\ValkeyGlideBatch : list<mixed>|\SineMacula\Valkey\Connections\ValkeyGlideBatch)
     *
     * @throws \Throwable
     */
    private function runBatch(bool $atomic, ?callable $callback): array|false|ValkeyGlideBatch
    {
        $this->assertBatchNotEnded($atomic ? 'transaction' : 'pipeline');

        if ($callback === null) {
            return $this->openBatch($atomic);
        }

        if ($this->batch !== null) {
            $batch = $this->batch;

            $this->assertJoinKeepsTheWatch($batch, $atomic);
            $this->runCallback($batch, $callback);

            return $batch->proxy;
        }

        $batch = $this->beginBatch($atomic);

        $this->runCallback($batch, $callback);
        $this->assertNotDiscardedByCallback($batch);

        return $this->executeBatch($batch);
    }

    /**
     * Assert that the callback left its batch to be executed.
     *
     * A callback that catches the exception of a command that ended its batch
     * cannot have the batch executed as if nothing happened.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     *
     * @throws \LogicException
     */
    private function assertNotDiscardedByCallback(BatchState $batch): void
    {
        if ($batch->isClosed()) {
            throw new \LogicException(self::ENDED_IN_CALLBACK_MESSAGE);
        }
    }

    /**
     * Run a callback against the batch, which owns the batch while it runs.
     *
     * A callback that throws, even one nested in another batch, discards the
     * whole batch with everything it still holds, and the exception is
     * rethrown, even when the discard or its UNWATCH fails. A batch that ends
     * while a callback owns it stays the active batch, so the connection
     * refuses the callback's later calls rather than run them outside it, until
     * the last callback that owns it returns.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed  $callback
     * @return void
     *
     * @throws \Throwable
     */
    private function runCallback(BatchState $batch, callable $callback): void
    {
        $batch->enterCallback();

        try {
            $callback($batch->proxy);
        } catch (\Throwable $exception) {
            $this->endBatchAfterFailure($batch);

            throw $exception;
        } finally {
            $batch->leaveCallback();
            $this->releaseBatch($batch);
        }
    }

    /**
     * Start tracking a new pipeline or transaction.
     *
     * GLIDE's own batch mode is started lazily, by the first command that it
     * buffers. A cluster transaction is bound to the hash slot of the keys
     * already watched.
     *
     * @param  bool  $atomic
     * @return \SineMacula\Valkey\Connections\BatchState
     */
    private function beginBatch(bool $atomic): BatchState
    {
        $batch       = new BatchState($this, $atomic);
        $this->batch = $batch;

        $batch->claimSlot($this->resolveTransactionSlot($batch, 'watch', $this->watchedKeys));

        return $batch;
    }

    /**
     * Handle a batch control command called on the connection.
     *
     * This is how phpredis-style code such as `RedisStore::putMany()` drives a
     * transaction: `multi()` starts one, or opens a nested batch, and returns
     * the batch; `exec()` and `discard()` act on the active batch.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    private function controlBatch(string $method, array $parameters): mixed
    {
        return match (strtoupper($method)) {
            'MULTI'    => $this->openBatch($this->resolveMultiMode($parameters)),
            'PIPELINE' => $this->openBarePipeline($parameters),
            'EXEC'     => $this->exec(),
            'DISCARD'  => $this->discard(),
            'WATCH'    => $this->watchKeys($method, $parameters),
            default    => $this->unwatchKeys($method, $parameters),
        };
    }

    /**
     * Start a pipeline for a bare pipeline() call, or join the active batch.
     *
     * A callback given through `command()` would be ignored, so arguments are
     * refused; `pipeline()` on the connection or the batch takes one.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return \SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \InvalidArgumentException
     */
    private function openBarePipeline(array $parameters): ValkeyGlideBatch
    {
        if ($parameters !== []) {
            throw new \InvalidArgumentException(self::PIPELINE_ARGUMENTS_MESSAGE);
        }

        return $this->openBatch(false);
    }

    /**
     * Resolve whether a bare multi() starts a transaction or a pipeline.
     *
     * As with GLIDE and phpredis, `multi()` and `multi(\ValkeyGlide::MULTI)`
     * start a transaction and `multi(\ValkeyGlide::PIPELINE)` a pipeline.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    private function resolveMultiMode(array $parameters): bool
    {
        $mode = array_values($parameters)[0] ?? \ValkeyGlide::MULTI;

        return match ($mode) {
            \ValkeyGlide::MULTI    => true,
            \ValkeyGlide::PIPELINE => false,
            default                => throw new \InvalidArgumentException(sprintf(self::UNSUPPORTED_MULTI_MODE_MESSAGE, is_int($mode) ? $mode : get_debug_type($mode))),
        };
    }

    /**
     * Start a batch for a bare multi() or pipeline() call, or open one nested
     * in the active batch.
     *
     * Nesting follows phpredis: a `multi()` inside a pipeline opens a block
     * that its own `exec()` closes, returning the batch, while any other nested
     * `multi()` or `pipeline()` changes nothing, so the next `exec()` runs the
     * batch.
     *
     * @param  bool  $atomic
     * @return \SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \LogicException
     */
    private function openBatch(bool $atomic): ValkeyGlideBatch
    {
        $batch = $this->batch;

        if ($batch === null) {
            return $this->beginBatch($atomic)->proxy;
        }

        $this->assertJoinKeepsTheWatch($batch, $atomic);

        if ($atomic && !$batch->atomic) {
            $batch->openNestedMulti();
        }

        return $batch->proxy;
    }

    /**
     * Assert that a transaction joining the active batch keeps the guard of the
     * watched keys.
     *
     * A pipeline flattens a transaction that joins it, so the watched keys
     * could not abort it; the transaction is refused before it changes the
     * batch.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  bool  $atomic
     * @return void
     *
     * @throws \LogicException
     */
    private function assertJoinKeepsTheWatch(BatchState $batch, bool $atomic): void
    {
        if ($atomic && !$batch->atomic && $this->watchedKeys !== null) {
            throw new \LogicException(self::WATCHED_TRANSACTION_IN_PIPELINE_MESSAGE);
        }
    }

    /**
     * Handle an exec() called on the connection or the batch.
     *
     * An exec() that closes a multi() opened inside a pipeline returns the
     * batch, as phpredis does in pipeline mode; otherwise the batch runs,
     * unless a callback owns it.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return false|list<mixed>|\SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function execBatch(BatchState $batch): array|false|ValkeyGlideBatch
    {
        if ($batch->hasNestedMulti()) {
            $batch->closeNestedMulti();

            return $batch->proxy;
        }

        $this->assertOutsideCallback($batch);

        return $this->executeBatch($batch);
    }

    /**
     * Handle a discard() called on the connection or the batch.
     *
     * The whole batch is dropped, a `multi()` still open inside a pipeline
     * included, as phpredis drops the whole pipeline; inside a callback, which
     * owns the batch, it is refused.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return bool
     *
     * @throws \LogicException
     *
     * @imperative
     */
    private function discardBatch(BatchState $batch): bool
    {
        if ($batch->isInCallback()) {
            throw new \LogicException(self::DISCARD_IN_CALLBACK_MESSAGE);
        }

        $this->endBatch($batch);

        return true;
    }

    /**
     * Assert that no callback owns the batch, as the batch then runs when the
     * callback returns.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     *
     * @throws \LogicException
     */
    private function assertOutsideCallback(BatchState $batch): void
    {
        if ($batch->isInCallback()) {
            throw new \LogicException(self::EXEC_IN_CALLBACK_MESSAGE);
        }
    }

    /**
     * Watch keys ahead of a transaction.
     *
     * The keys are tracked so that a transaction they abort returns false, and
     * so that every path that ends the transaction without EXEC also clears
     * them, as GLIDE's discard() does not. They are tracked once GLIDE has
     * watched them, before any listener hears of it, and not at all when GLIDE
     * refuses them.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    private function watchKeys(string $method, array $parameters): mixed
    {
        $this->assertNoActiveBatch($method, 'call watch() before starting the transaction');

        $parameters = $this->prefixer->apply($method, $this->batchCommands->spreadKeyList($parameters));

        return $this->executeCommandWithRetry($method, $parameters, function (mixed $result) use ($method, $parameters): void {
            if ($result !== true) {
                return;
            }

            $this->watchedKeys = array_merge($this->watchedKeys ?? [], $this->prefixer->keys($method, $parameters) ?? []);
        });
    }

    /**
     * Stop watching every watched key.
     *
     * The keys are forgotten once GLIDE reports the UNWATCH done, before any
     * listener hears of it. An UNWATCH that GLIDE fails, which 1.1.2 reports as
     * false, or that throws, keeps them, as the server may still hold the
     * WATCH, just as a WATCH that GLIDE refuses is not tracked.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    private function unwatchKeys(string $method, array $parameters): mixed
    {
        $this->assertNoActiveBatch($method, 'call unwatch() outside the transaction');

        return $this->executeCommandWithRetry($method, $parameters, function (mixed $result): void {
            if ($result !== true) {
                return;
            }

            $this->watchedKeys = null;
        });
    }

    /**
     * Add a command to the active batch.
     *
     * The command is prefixed as it would be outside a batch, reshaped into a
     * form GLIDE buffers, and then buffered, run immediately, or refused. Only
     * buffered commands are counted, and no events are dispatched for them.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return \SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function queueBatchCommand(BatchState $batch, string $method, array $parameters): ValkeyGlideBatch
    {
        $this->assertBufferable($method, $parameters);

        $parameters = $this->batchCommands->rewrite($method, $this->prefixer->apply($method, $parameters));

        if ($this->runsImmediatelyInBatch($method, $parameters)) {
            return $this->runImmediately($batch, $method, $parameters);
        }

        $slot = $this->resolveTransactionSlot($batch, $method, $this->prefixer->keys($method, $parameters));

        $this->openNativeBatch($batch);
        $this->bufferCommand($batch, $method, $parameters);

        $batch->claimSlot($slot);

        return $batch->proxy;
    }

    /**
     * Assert that a batch can carry a command.
     *
     * A call that GLIDE would report buffered without buffering it is refused
     * before it is sent, as it would leave a slot that the batch's results
     * never fill, and the batch would then fail after the server applied it.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return void
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    private function assertBufferable(string $method, array $parameters): void
    {
        if ($this->batchCommands->isRefused($method)) {
            throw new \LogicException(sprintf(self::REFUSED_IN_BATCH_MESSAGE, strtoupper($method)));
        }

        if ($this->batchCommands->isUnbuffered($method, $parameters)) {
            throw new \InvalidArgumentException(sprintf(self::UNBUFFERED_IN_BATCH_MESSAGE, strtoupper($method)));
        }
    }

    /**
     * Buffer a command in the open native batch and count it.
     *
     * A buffered command returns the client, so a false return means GLIDE
     * rejected its arguments without buffering it, as it does for a numeric
     * string TTL, and the batch carries on without it. A command that throws
     * ends the batch, as GLIDE may have buffered it before it threw, as
     * `sInter()` does for a key that is not a list, and the batch would then
     * hold a command it never counted.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return void
     *
     * @throws \InvalidArgumentException
     * @throws \Throwable
     */
    private function bufferCommand(BatchState $batch, string $method, array $parameters): void
    {
        try {
            $result = $this->dispatchToClient($method, $parameters);
        } catch (\Throwable $exception) {
            $this->endBatchAfterFailure($batch);

            throw $exception;
        }

        if ($result === false) {
            throw new \InvalidArgumentException(sprintf(self::BUFFER_REFUSED_MESSAGE, strtoupper($method)));
        }

        $batch->queue($method);
    }

    /**
     * Determine whether GLIDE would run a command immediately rather than
     * buffer it.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     */
    private function runsImmediatelyInBatch(string $method, array $parameters): bool
    {
        return $this->batchCommands->runsImmediately($method, $this->glideClient instanceof \ValkeyGlideCluster)
            || $this->shouldInvokeAsRawCommand(strtoupper($method), $parameters);
    }

    /**
     * Run a command GLIDE cannot buffer.
     *
     * In a pipeline, what is buffered is sent first, then the command runs on
     * its own and its result joins the list in call order; the next buffered
     * command starts GLIDE's batch again. In a transaction it would run outside
     * the MULTI block, so the transaction is discarded and refused. The
     * pipeline is set aside while the send and the command run, so their
     * listeners use the connection as they would outside a batch: what they run
     * is sent at once rather than joining the pipeline, where it would start
     * GLIDE's batch again under the command and be heard of only as another
     * send. A batch a listener leaves open is ended, as the pipeline cannot
     * take it back. Anything that throws along the way, the command, the send,
     * or a listener of either, ends the pipeline, whether or not a callback
     * owns it.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return \SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function runImmediately(BatchState $batch, string $method, array $parameters): ValkeyGlideBatch
    {
        $this->assertNotAtomic($batch, $method);

        $this->batch = null;

        try {
            $this->flushBatch($batch);
            $this->assertNoBatchLeftByListener($method);

            $this->executeCommandWithRetry($method, $parameters, static function (mixed $result) use ($batch): void {
                $batch->append([$result]);
            });

            $this->assertNoBatchLeftByListener($method);
        } catch (\Throwable $exception) {
            if ($this->batch !== null) {
                $this->endBatchAfterFailure($this->batch);
            }

            $this->resumeBatch($batch);
            $this->endBatchAfterFailure($batch);

            throw $exception;
        }

        $this->resumeBatch($batch);

        return $batch->proxy;
    }

    /**
     * Assert that no listener left a batch open while a pipeline was set aside;
     * the caller ends the batch it left.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \LogicException
     */
    private function assertNoBatchLeftByListener(string $method): void
    {
        if ($this->batch !== null) {
            throw new \LogicException(sprintf(self::LEFT_OPEN_BY_LISTENER_MESSAGE, strtoupper($method)));
        }
    }

    /**
     * End a batch while a failure is being thrown.
     *
     * A cleanup that throws in turn, such as a discard() or unwatch() that
     * fails, is dropped, so the failure that ended the batch, such as the
     * exception of its callback, a failed exec() or a refused command, is the
     * one the caller sees. The batch has closed before any cleanup is sent, so
     * a cleanup that throws cannot leave it open; keys whose UNWATCH failed
     * stay tracked, as they do when it fails outside a failure.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     */
    private function endBatchAfterFailure(BatchState $batch): void
    {
        try {
            $this->endBatch($batch);
        } catch (\Throwable) {
            // The failure being thrown is the one to report, not the cleanup's.
        }
    }

    /**
     * Make a pipeline set aside while a command ran the active batch again,
     * unless it has ended and no callback owns it.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     */
    private function resumeBatch(BatchState $batch): void
    {
        $this->batch = $batch;

        $this->releaseBatch($batch);
    }

    /**
     * Stop treating a batch as the active batch once it has ended and no
     * callback owns it.
     *
     * A batch that ends inside its callback stays active until the callback
     * returns, so the connection refuses the callback's later calls.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     */
    private function releaseBatch(BatchState $batch): void
    {
        if ($this->batch !== $batch || !$batch->isClosed() || $batch->isInCallback()) {
            return;
        }

        $this->batch = null;
    }

    /**
     * Assert that a command GLIDE cannot buffer is not run in a transaction,
     * discarding the transaction if it is.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  string  $method
     * @return void
     *
     * @throws \LogicException
     */
    private function assertNotAtomic(BatchState $batch, string $method): void
    {
        if (!$batch->atomic) {
            return;
        }

        $this->endBatchAfterFailure($batch);

        $message = $this->batchCommands->runsLocally($method) ? self::LOCAL_IN_TRANSACTION_MESSAGE : self::IMMEDIATE_IN_TRANSACTION_MESSAGE;

        throw new \LogicException(sprintf($message, strtoupper($method)));
    }

    /**
     * Send what the native batch holds, so a command can run after it with
     * GLIDE's batch mode closed.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     * @throws \Throwable
     */
    private function flushBatch(BatchState $batch): void
    {
        if (!$batch->hasQueuedCommands()) {
            $this->discardNativeBatch($batch);

            return;
        }

        $commands  = $batch->queuedCommands();
        $startedAt = microtime(true);
        $outcome   = $this->executeNativeBatch($batch);

        if ($outcome !== true) {
            throw $this->failBatch($batch, $commands, false, $outcome === null);
        }

        $this->dispatchCommandExecutedEvent($this->resolveBatchEventName($batch), $commands, $startedAt);
    }

    /**
     * Leave GLIDE's batch mode without sending anything, if it was started.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     *
     * @throws \Throwable
     */
    private function discardNativeBatch(BatchState $batch): void
    {
        if ($batch->hasOpenNativeBatch()) {
            $this->dispatchToClient('discard', []);
        }

        $batch->markNativeBatchClosed();
    }

    /**
     * Execute the batch and return its results in call order.
     *
     * A batch with nothing buffered never reaches GLIDE's exec(), which would
     * leave the client stuck in batch mode. When exec() fails, a pipeline or an
     * unwatched transaction throws, as some or all of it may still have been
     * applied; a watched transaction returns false, as phpredis does, unless
     * exec() returned results that do not match its commands, as the server
     * then ran the EXEC, and it throws instead. The batch has ended before any
     * listener hears of it, so a listener that throws cannot leave the
     * connection in batch mode.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return false|list<mixed>
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     * @throws \Throwable
     */
    private function executeBatch(BatchState $batch): array|false
    {
        if (!$batch->hasQueuedCommands()) {
            $this->endBatch($batch);

            return $batch->results();
        }

        $watched   = $batch->atomic && $this->watchedKeys !== null;
        $commands  = $batch->queuedCommands();
        $startedAt = microtime(true);
        $outcome   = $this->executeNativeBatch($batch);

        if ($outcome !== true) {
            $aborted   = $watched && $outcome === false;
            $exception = $this->failBatch($batch, $commands, $aborted, $outcome === null);

            if ($aborted) {
                return false;
            }

            throw $exception;
        }

        $this->endBatch($batch, true);
        $this->dispatchCommandExecutedEvent($this->resolveBatchEventName($batch), $commands, $startedAt);

        return $batch->results();
    }

    /**
     * Send the commands buffered in the native batch with GLIDE's exec().
     *
     * exec() is never retried, as a batch that timed out may still have been
     * applied. Returns true once the results are recorded, false when exec()
     * failed, and null when it returned a result list that does not match what
     * was buffered: the batch was sent, but its results cannot be put in call
     * order. An exec() that throws ends the batch, and its exception is
     * rethrown even if the batch's cleanup throws too.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return bool|null
     *
     * @throws \Throwable
     */
    private function executeNativeBatch(BatchState $batch): ?bool
    {
        try {
            $results = $this->dispatchToClient('exec', []);
        } catch (\Throwable $exception) {
            $this->endBatchAfterFailure($batch);

            throw $exception;
        }

        if (!is_array($results)) {
            return false;
        }

        if (count($results) !== count($batch->queuedCommands())) {
            return null;
        }

        $batch->markNativeBatchExecuted($results);

        return true;
    }

    /**
     * End the batch after a failed exec() and report the failure.
     *
     * One `CommandFailed` event is dispatched for the batch, named `pipeline`
     * or `transaction`, once the batch has ended. GLIDE 1.1.2 reports every
     * failure as false, so a WATCH abort cannot be told apart from a timeout or
     * a closed connection, and a batch that timed out may still have been
     * applied; results that do not match the batch's commands mean it was sent.
     * A cleanup that throws as the batch ends does not replace the failure.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  list<string>  $commands
     * @param  bool  $watched
     * @param  bool  $misaligned
     * @return \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function failBatch(BatchState $batch, array $commands, bool $watched, bool $misaligned): ConnectionException
    {
        $this->endBatchAfterFailure($batch);

        $exception = new ConnectionException(match (true) {
            $misaligned     => sprintf(self::MISALIGNED_BATCH_MESSAGE, $this->resolveBatchEventName($batch)),
            $watched        => self::WATCHED_TRANSACTION_FAILED_MESSAGE,
            !$batch->atomic => self::PIPELINE_FAILED_MESSAGE,
            default         => self::TRANSACTION_FAILED_MESSAGE,
        });

        $this->dispatchCommandFailedEvent($this->resolveBatchEventName($batch), $commands, $exception);

        return $exception;
    }

    /**
     * End the batch, clearing GLIDE's batch mode and any WATCH it leaves.
     *
     * GLIDE's discard() is client-side only and leaves WATCH in place on the
     * server, so a transaction that ends without a committed EXEC unwatches. On
     * a cluster, EXEC only clears the watches on its own node, so every watched
     * cluster transaction unwatches. The watched keys stay tracked when that
     * UNWATCH fails and no EXEC committed, as the server may still hold the
     * WATCH and abort the next transaction. A batch that ends while a callback
     * owns it stays the active batch until the callback returns.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  bool  $committed
     * @return void
     *
     * @throws \Throwable
     */
    private function endBatch(BatchState $batch, bool $committed = false): void
    {
        if ($batch->isClosed()) {
            return;
        }

        $batch->close();
        $this->releaseBatch($batch);

        $this->discardNativeBatch($batch);

        if (!$batch->atomic || $this->watchedKeys === null) {
            return;
        }

        // A committed standalone EXEC has already cleared the WATCH, so the
        // short-circuit sends UNWATCH only in the other cases.
        $unwatched = (!$committed || $this->glideClient instanceof \ValkeyGlideCluster)
            && $this->dispatchToClient('unwatch', []) === true;

        if (!$committed && !$unwatched) {
            return;
        }

        $this->watchedKeys = null;
    }

    /**
     * Start GLIDE's own batch mode on the client, unless it is already open.
     *
     * A client that refuses batch mode ends the batch, which holds nothing yet,
     * so later commands take the usual path. GLIDE refuses it when the client
     * is closed, or already in batch mode outside the driver, and then keeps
     * that batch mode until discard(), so the batch ends as an open one, which
     * discards the client; that does nothing on a closed client. A cleanup that
     * throws does not replace the refusal.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function openNativeBatch(BatchState $batch): void
    {
        if ($batch->hasOpenNativeBatch()) {
            return;
        }

        $client = $this->glideClient;
        $opened = $this->dispatchToClient($batch->atomic ? 'multi' : 'pipeline', []) === $client;

        $batch->markNativeBatchOpen();

        if (!$opened) {
            $this->endBatchAfterFailure($batch);

            throw new ConnectionException(sprintf(self::BATCH_MODE_REFUSED_MESSAGE, $this->resolveBatchEventName($batch)));
        }
    }

    /**
     * Resolve the hash slot a cluster transaction is bound to once it carries a
     * command's keys.
     *
     * A cluster transaction runs on one node, so every key it touches, and
     * every key watched for it, must share a slot. Keys whose positions are
     * unknown, given as null, are left for the server to judge, unless keys are
     * watched: GLIDE then sends the transaction by a command's own key, or to
     * any node when it has none, so a command whose keys are unknown or absent
     * could take it to a node the WATCH does not guard, and is refused. While
     * keys are watched, the transaction is bound to their slot from the start,
     * so a key in another slot is reported against the watched keys, which may
     * be left over from an unwatch() that failed. The slot is only resolved
     * here, and bound once GLIDE has buffered the command, so a command GLIDE
     * refuses binds the transaction to nothing.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  string  $method
     * @param  list<string>|null  $keys
     * @return int|null
     *
     * @throws \LogicException
     */
    private function resolveTransactionSlot(BatchState $batch, string $method, ?array $keys): ?int
    {
        if (!$batch->atomic || !$this->glideClient instanceof \ValkeyGlideCluster) {
            return null;
        }

        if (($keys ?? []) === [] && $this->watchedKeys !== null) {
            $this->endBatchAfterFailure($batch);

            throw new \LogicException(sprintf(self::UNGUARDED_WATCH_MESSAGE, strtoupper($method)));
        }

        $bound = $batch->claimedSlot();

        foreach ($keys ?? [] as $key) {
            $slot = $this->router->slot($key);
            $bound ??= $slot;

            if ($slot !== $bound) {
                $message = $this->describeCrossSlotKey($method, $key, $slot, $bound);

                $this->endBatchAfterFailure($batch);

                throw new \LogicException($message);
            }
        }

        return $bound;
    }

    /**
     * Describe a cluster transaction key outside the slot the transaction is
     * bound to, naming the watched keys when they hold that slot.
     *
     * @param  string  $method
     * @param  string  $key
     * @param  int  $slot
     * @param  int  $bound
     * @return string
     */
    private function describeCrossSlotKey(string $method, string $key, int $slot, int $bound): string
    {
        $command = strtoupper($method);

        if ($this->watchedKeys !== null && $command !== 'WATCH') {
            return sprintf(self::WATCHED_CROSS_SLOT_MESSAGE, $command, $key, $slot, $bound, implode(', ', $this->watchedKeys));
        }

        return sprintf(self::CROSS_SLOT_MESSAGE, $command, $key, $slot, $bound);
    }

    /**
     * Resolve the event name for the batch's exec().
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return string
     */
    private function resolveBatchEventName(BatchState $batch): string
    {
        return $batch->atomic ? 'transaction' : 'pipeline';
    }

    /**
     * Assert that no pipeline or transaction is active.
     *
     * @param  string  $method
     * @param  string  $advice
     * @return void
     *
     * @throws \LogicException
     */
    private function assertNoActiveBatch(string $method, string $advice): void
    {
        $this->assertBatchNotEnded($method);

        if ($this->batch !== null) {
            throw new \LogicException(sprintf(self::ACTIVE_BATCH_MESSAGE, strtolower($method), $advice));
        }
    }

    /**
     * Assert that the active batch, if any, has not ended inside the callback
     * that owns it.
     *
     * Once it has, the callback's later calls on the connection are refused,
     * rather than run outside the batch while it reports that nothing ran.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \LogicException
     */
    private function assertBatchNotEnded(string $method): void
    {
        if ($this->batch?->isClosed() === true) {
            throw new \LogicException(sprintf(self::ENDED_BATCH_MESSAGE, strtolower($method)));
        }
    }

    /**
     * Call a method on the GLIDE client.
     *
     * This must stay an unqualified `call_user_func_array()` in this namespace.
     * Unqualified, the call resolves at runtime to the internal function, which
     * invokes GLIDE with coercive typing, so the integers Laravel and Horizon
     * pass, as in `zremrangebyscore('k', 0, $now)`, are accepted. A direct or
     * fully qualified call is compiled under this file's strict_types, and
     * GLIDE then throws a TypeError for them.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     *
     * @phpstan-ignore throws.unusedType
     */
    private function dispatchToClient(string $method, array $parameters): mixed
    {
        return call_user_func_array([$this->glideClient, $method], $parameters);
    }

    /**
     * Execute a normalized command with one retry for transient failures.
     *
     * The result is handed to the given callback, if any, before listeners hear
     * of the command, so state that follows it is kept even when one throws.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  (\Closure(mixed): void)|null  $onResult
     * @return mixed
     *
     * @throws \Throwable
     */
    private function executeCommandWithRetry(string $method, array $parameters, ?\Closure $onResult = null): mixed
    {
        $attempt = 0;

        while (true) {
            $startedAt = microtime(true);

            try {
                $result = $this->invokeCommand($method, $parameters);
            } catch (\Throwable $exception) {
                if ($this->shouldRetryAttempt($attempt, $method, $exception)) {
                    $attempt++;
                    $this->sleepBeforeRetry();

                    continue;
                }

                $this->dispatchCommandFailedEvent($method, $parameters, $exception);

                throw $exception;
            }

            if ($onResult !== null) {
                $onResult($result);
            }

            $this->dispatchCommandExecutedEvent($method, $parameters, $startedAt);

            return $result;
        }
    }

    /**
     * Invoke a command on the underlying GLIDE client.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    private function invokeCommand(string $method, array $parameters): mixed
    {
        $normalizedMethod = strtoupper($method);

        if ($this->shouldInvokeAsRawCommand($normalizedMethod, $parameters)) {
            return $this->invokeAsRawCommand($normalizedMethod, $parameters);
        }

        if ($this->shouldInvokeOnAllPrimaries($normalizedMethod, $parameters)) {
            return $this->invokeOnAllPrimaries($method, $parameters);
        }

        return $this->dispatchToClient($method, $this->routeClusterPing($normalizedMethod, $parameters));
    }

    /**
     * Add a route to a ping on a cluster client that carries none.
     *
     * `ValkeyGlideCluster::ping()` takes a route before the optional message
     * and returns false without one, so a bare `ping()`, as health checks call
     * it, would report a healthy cluster as down. It goes to every primary, the
     * route GLIDE gives `PING` and the one `executeRaw()` adds. Any arguments
     * are taken as a route, as phpredis' `RedisCluster::ping()` takes them, and
     * passed through untouched.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function routeClusterPing(string $method, array $parameters): array
    {
        if ($method !== 'PING' || $parameters !== [] || !$this->glideClient instanceof \ValkeyGlideCluster) {
            return $parameters;
        }

        return [$this->router->route(['PING'])];
    }

    /**
     * Determine whether a command must run through rawcommand for
     * compatibility.
     *
     * Scripts take phpredis' argument shape, and so do SET options GLIDE's own
     * set() cannot take or refuses, as in `set($key, $value, 'EX', 60, 'NX')`
     * or `set($key, $value, 'NX')`.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     */
    private function shouldInvokeAsRawCommand(string $method, array $parameters): bool
    {
        if ($method === 'EVAL' || $method === 'EVALSHA') {
            return true;
        }

        return $method === 'SET' && (count($parameters) >= 4 || (count($parameters) === 3 && is_string(end($parameters))));
    }

    /**
     * Execute a command through rawcommand with Redis protocol arguments.
     *
     * Cluster clients require a leading route argument so the command lands on
     * the primary that owns the key's slot. Standalone clients use the existing
     * two-argument form (no route).
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     *
     * @phpstan-ignore throws.unusedType
     */
    private function invokeAsRawCommand(string $method, array $parameters): mixed
    {
        $values = array_values($parameters);

        if ($this->glideClient instanceof \ValkeyGlideCluster) {
            return $this->glideClient->rawcommand($this->resolveClusterRoute($method, $values), $method, ...$values);
        }

        return $this->glideClient->rawcommand($method, ...$values);
    }

    /**
     * Determine whether a flush must be routed to every cluster primary.
     *
     * Only the phpredis-style call shapes Laravel uses qualify: no arguments,
     * or a single `'ASYNC'` / `'SYNC'` / boolean flag. Any other arguments are
     * treated as an explicit GLIDE route and passed through untouched, so a
     * caller targeting a specific node is never widened to all primaries.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     */
    private function shouldInvokeOnAllPrimaries(string $method, array $parameters): bool
    {
        if (!$this->glideClient instanceof \ValkeyGlideCluster || !in_array($method, self::ALL_PRIMARIES_COMMANDS, true)) {
            return false;
        }

        if ($parameters === []) {
            return true;
        }

        return array_is_list($parameters) && count($parameters) === 1 && $this->isFlushModeFlag($parameters[0]);
    }

    /**
     * Determine whether a value is a phpredis-style flush mode flag.
     *
     * Booleans follow GLIDE's semantics, where `true` requests an asynchronous
     * flush on both the standalone and cluster clients.
     *
     * @param  mixed  $value
     * @return bool
     */
    private function isFlushModeFlag(mixed $value): bool
    {
        return is_bool($value)
            || (is_string($value) && in_array(strtoupper($value), self::FLUSH_MODE_FLAGS, true));
    }

    /**
     * Execute a keyless flush on every primary of a cluster.
     *
     * Without a route, GLIDE does not fan the command out across the cluster,
     * so a flush would not clear every slot. Laravel calls `flushdb()` with no
     * arguments (cache store flush) or with phpredis' `'ASYNC'` flag, which
     * GLIDE takes as a boolean after the route.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     *
     * @phpstan-ignore throws.unusedType
     */
    private function invokeOnAllPrimaries(string $method, array $parameters): mixed
    {
        $flag  = $parameters[0] ?? null;
        $async = $flag === true || (is_string($flag) && strtoupper($flag) === 'ASYNC');

        return call_user_func([$this->glideClient, $method], 'allPrimaries', $async);
    }

    /**
     * Resolve the cluster route for a raw command.
     *
     * Keyed write/script commands (EVAL, EVALSHA, phpredis-style
     * SET-with-options) must land on the primary that owns the key's hash slot.
     * Using `primarySlotKey` tells GLIDE to compute the slot from the supplied
     * key and route there, preserving the same per-key semantics as standalone.
     * Fan-out routes (`allPrimaries`, `allNodes`) would execute the write on
     * every shard. A keyless call, or one whose key is empty or not valid UTF-8
     * and so cannot travel in a GLIDE route, goes to `randomNode`, and GLIDE
     * follows the node's `MOVED` redirect to the key's owner.
     *
     * The parameters array is already prefix-normalised before this method
     * runs, so the key used for routing is identical to the key the command
     * targets.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $values
     * @return array{type: string, key: string}|string
     */
    private function resolveClusterRoute(string $method, array $values): array|string
    {
        $keyIndex = match ($method) {
            'SET'             => 0,
            'EVAL', 'EVALSHA' => $this->hasScriptKeys($values) ? 2 : null,
            default           => null,
        };

        return $this->router->routeKey($keyIndex !== null ? ($values[$keyIndex] ?? null) : null);
    }

    /**
     * Determine whether a phpredis-style script call names at least one key.
     *
     * @param  array<int, mixed>  $values
     * @return bool
     */
    private function hasScriptKeys(array $values): bool
    {
        $keyCount = Cast::toNonNegativeInt($values[1] ?? null);

        return $keyCount !== null && $keyCount > 0;
    }

    /**
     * Determine whether the current attempt should be retried.
     *
     * Nothing is retried while keys are watched: the reconnect would replace
     * the client, and with it the server connection that holds the WATCH, so
     * the next transaction would commit unguarded. The error is rethrown
     * instead, as phpredis surfaces a dropped connection, and the caller starts
     * the WATCH again.
     *
     * @param  int  $attempt
     * @param  string  $method
     * @param  \Throwable  $exception
     * @return bool
     */
    private function shouldRetryAttempt(int $attempt, string $method, \Throwable $exception): bool
    {
        if ($attempt > 0 || $this->watchedKeys !== null) {
            return false;
        }

        if (!$this->shouldRetryCommand($method, $exception)) {
            return false;
        }

        return $this->reconnectClient();
    }

    /**
     * Dispatch a command failed event.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  \Throwable  $exception
     * @return void
     */
    private function dispatchCommandFailedEvent(string $method, array $parameters, \Throwable $exception): void
    {
        $this->events?->dispatch(
            new CommandFailed(
                $method,
                $this->parseParametersForEvent($parameters),
                $exception,
                $this,
            ),
        );
    }

    /**
     * Dispatch a command executed event.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  float  $startedAt
     * @return void
     */
    private function dispatchCommandExecutedEvent(string $method, array $parameters, float $startedAt): void
    {
        $executionTimeMs = round((microtime(true) - $startedAt) * 1000, 2);

        $this->events?->dispatch(
            new CommandExecuted(
                $method,
                $this->parseParametersForEvent($parameters),
                $executionTimeMs,
                $this,
            ),
        );
    }

    /**
     * Wrap subscription callbacks to always receive ($message, $channel).
     *
     * @param  \Closure(mixed, mixed): void  $callback
     * @return \Closure(mixed...): void
     */
    private function newMessageHandler(\Closure $callback): \Closure
    {
        return static function (mixed ...$arguments) use ($callback): void {
            $argumentCount = count($arguments);

            $message = $argumentCount >= 1 ? $arguments[$argumentCount - 1] : null;
            $channel = $argumentCount >= 2 ? $arguments[$argumentCount - 2] : null;

            $callback($message, $channel);
        };
    }

    /**
     * Recreate the client via the configured connector callback.
     *
     * The replaced client is closed only once its successor exists, so a failed
     * reconnect leaves the connection holding a client rather than a closed
     * one.
     *
     * @return bool
     *
     * @imperative
     */
    private function reconnectClient(): bool
    {
        if ($this->connector === null) {
            return false;
        }

        $previousClient    = $this->glideClient;
        $this->glideClient = ($this->connector)();

        if ($previousClient !== $this->glideClient) {
            $this->closeReplacedClient($previousClient);
        }

        return true;
    }

    /**
     * Close a client discarded after a transport failure.
     *
     * Its connection is usually already broken, so a failing close is expected,
     * and it must not replace the command error being retried.
     *
     * @param  \ValkeyGlide|\ValkeyGlideCluster  $client
     * @return void
     */
    private function closeReplacedClient(\ValkeyGlide|\ValkeyGlideCluster $client): void
    {
        try {
            $client->close();
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
        }
    }

    /**
     * Normalize command method names to non-empty strings.
     *
     * @param  mixed  $method
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeCommandMethod(mixed $method): string
    {
        $normalizedMethod = Cast::toNonEmptyString($method);

        if ($normalizedMethod !== null) {
            return $normalizedMethod;
        }

        throw new \InvalidArgumentException(sprintf('Unsupported command method type [%s].', get_debug_type($method)));
    }

    /**
     * Normalize subscription method names to non-empty lowercase strings.
     *
     * @param  mixed  $method
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeSubscriptionMethod(mixed $method): string
    {
        $normalizedMethod = Cast::toNonEmptyString($method);

        if ($normalizedMethod !== null) {
            return strtolower($normalizedMethod);
        }

        throw new \InvalidArgumentException(sprintf('Unsupported subscription method type [%s].', get_debug_type($method)));
    }

    /**
     * Sleep briefly before retry to avoid hot-loop retries.
     *
     * @return void
     */
    private function sleepBeforeRetry(): void
    {
        $baseDelay = Cast::toNonNegativeInt($this->config['retry_delay_ms'] ?? null)  ?? 25;
        $maxJitter = Cast::toNonNegativeInt($this->config['retry_jitter_ms'] ?? null) ?? 15;

        if ($maxJitter > 0) {
            $randomInt = $this->randomIntGenerator;

            try {
                $baseDelay += $randomInt(0, $maxJitter);
            } catch (\Throwable) {
                $baseDelay = max(0, $baseDelay);
            }
        }

        if ($baseDelay <= 0) {
            return;
        }

        $sleepCallback = $this->sleepCallback;
        $sleepCallback($baseDelay * 1000);
    }

    /**
     * Determine if a failed command is safe to retry once.
     *
     * @param  string  $method
     * @param  \Throwable  $exception
     * @return bool
     */
    private function shouldRetryCommand(string $method, \Throwable $exception): bool
    {
        if ($this->connector === null) {
            return false;
        }

        if (!in_array(strtoupper($method), self::IDEMPOTENT_RETRYABLE_COMMANDS, true)) {
            return false;
        }

        return $this->isTransientConnectionError($exception);
    }

    /**
     * Classify whether an exception is a transient transport failure.
     *
     * @param  \Throwable  $exception
     * @return bool
     */
    private function isTransientConnectionError(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach (self::TRANSIENT_ERROR_FRAGMENTS as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize channel input to a non-empty list of channel names.
     *
     * @param  mixed  $channels
     * @return array<int, string>
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeSubscriptionChannels(mixed $channels): array
    {
        if (is_string($channels)) {
            $source = [$channels];
        } elseif (is_array($channels)) {
            $source = $channels;
        } else {
            throw new \InvalidArgumentException(sprintf('Unsupported subscription channel type [%s].', get_debug_type($channels)));
        }

        $normalized = [];

        foreach ($source as $channel) {
            if (!is_scalar($channel) && !$channel instanceof \Stringable) {
                continue;
            }

            $channelName = (string) $channel;

            if ($channelName === '') {
                continue;
            }

            $normalized[] = $channelName;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one valid subscription channel is required.');
        }

        return $normalized;
    }

    /**
     * Resolve the configured key prefix.
     *
     * @param  mixed  $prefix
     * @return string
     */
    private function resolvePrefix(mixed $prefix): string
    {
        if (!is_scalar($prefix) && !$prefix instanceof \Stringable) {
            return '';
        }

        return (string) $prefix;
    }

    /**
     * Resolve the configured random integer generator callback.
     *
     * @param  mixed  $randomInt
     * @return \Closure(int, int): int
     */
    private function resolveRandomIntGenerator(mixed $randomInt): \Closure
    {
        if (is_callable($randomInt)) {
            return \Closure::fromCallable($randomInt);
        }

        return static fn (int $min, int $max): int => random_int($min, $max);
    }

    /**
     * Resolve the configured sleep callback.
     *
     * @param  mixed  $sleep
     * @return \Closure(int): void
     */
    private function resolveSleepCallback(mixed $sleep): \Closure
    {
        if (is_callable($sleep)) {
            return \Closure::fromCallable($sleep);
        }

        return static function (int $microseconds): void {
            usleep($microseconds);
        };
    }
}
