<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use SineMacula\Valkey\Exceptions\CommandException;
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

    /** @var array<int, string> Commands GLIDE answers with false only when they fail, as their replies are never false. */
    private const array NEVER_FALSE_COMMANDS = [
        'APPEND',
        'DBSIZE',
        'DECR',
        'DECRBY',
        'DEL',
        'EXISTS',
        'HDEL',
        'HGETALL',
        'HINCRBY',
        'HINCRBYFLOAT',
        'HKEYS',
        'HLEN',
        'HMSET',
        'HSET',
        'HVALS',
        'INCR',
        'INCRBY',
        'INCRBYFLOAT',
        'KEYS',
        'LLEN',
        'LPUSH',
        'LRANGE',
        'LREM',
        'LTRIM',
        'MGET',
        'MSET',
        'PING',
        'PSETEX',
        'PTTL',
        'PUBLISH',
        'RPUSH',
        'SADD',
        'SCARD',
        'SETEX',
        'SMEMBERS',
        'SREM',
        'STRLEN',
        'TTL',
        'TYPE',
        'UNLINK',
        'ZCARD',
        'ZCOUNT',
        'ZRANGE',
        'ZRANGEBYSCORE',
        'ZREM',
        'ZREMRANGEBYRANK',
        'ZREMRANGEBYSCORE',
    ];

    /** @var array<int, string> Keyless commands a cluster client must run on every primary. */
    private const array ALL_PRIMARIES_COMMANDS = [
        'FLUSHALL',
        'FLUSHDB',
    ];

    /** @var array<string, int> Keyless commands a cluster client takes a leading route for, by the argument count of a call that carries none. */
    private const array CLUSTER_ROUTED_COMMANDS = [
        'DBSIZE'    => 0,
        'ECHO'      => 1,
        'PING'      => 0,
        'RANDOMKEY' => 0,
        'TIME'      => 0,
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

    /** @var string Message for a pipeline callback that returns with a multi() it opened still open. */
    private const string UNCLOSED_TRANSACTION_MESSAGE = 'A multi() opened inside the pipeline was not closed with exec() before the callback returned,'
        . ' so the transaction and the rest of the pipeline were discarded.';

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
    private const string UNSUPPORTED_KEY_LIST_MESSAGE = '[%s] was given a list of keys in a form GLIDE does not take as keys, such as a list beside other'
        . ' arguments, a list of lists, or a list where the command takes only separate keys, which GLIDE would send as the key "Array" or drop.';

    /** @var string Message for a cluster INFO called without a route. */
    private const string UNROUTED_CLUSTER_INFO_MESSAGE = '[INFO] on a cluster connection needs a route, as each node reports only itself;'
        . ' pass one first, such as info(\'randomNode\') or info(\'randomNode\', \'memory\').';

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

    /** @var string Message for a disconnected connection that has no connector to reconnect it. */
    private const string DISCONNECTED_MESSAGE = 'The connection was closed by disconnect() and has no connector to reconnect it.';

    /** @var string Message for a command whose false reply can only mean it failed, when GLIDE recorded no error. */
    private const string UNRECORDED_FAILURE_MESSAGE = '[%s] failed: GLIDE answered false, which it gives this command only when it fails, such as when the server'
        . ' cannot be reached, and recorded no error.';

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

    /** @var bool Whether disconnect() closed the client, which is then replaced before it is used again. */
    private bool $disconnected = false;

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

    /** @var \SineMacula\Valkey\Connections\SortedSetArguments Converter for the scores, members and weights of sorted-set commands. */
    private readonly SortedSetArguments $sortedSetArguments;

    /** @var \SineMacula\Valkey\Connections\ClusterScan Primary-by-primary keyspace scan for cluster clients. */
    private readonly ClusterScan $clusterScan;

    /** @var \SineMacula\Valkey\Connections\FloatArguments Converter for the float arguments GLIDE would write with six significant digits. */
    private readonly FloatArguments $floatArguments;

    /** @var \SineMacula\Valkey\Connections\BatchState|null The active pipeline or transaction, if any. */
    private ?BatchState $batch = null;

    /** @var list<string>|null Prefixed keys watched with WATCH, or null when nothing is watched. */
    private ?array $watchedKeys = null;

    /** @var bool Whether the watched keys are left over from an UNWATCH that failed once their WATCH was released, by unwatch() or a transaction that ended, rather than watched for one still to run. */
    private bool $watchLeftOver = false;

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
        $this->sortedSetArguments = new SortedSetArguments;
        $this->clusterScan        = new ClusterScan;
        $this->floatArguments     = new FloatArguments;
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
     * aborted. Inside an active pipeline, GLIDE cannot nest a MULTI, so what
     * the pipeline buffered is sent first, then the transaction runs on its own
     * when it closes, and its results, or an empty list when WATCH aborts it,
     * take one place in the pipeline's results; without a callback, its
     * `exec()` returns the pipeline, as phpredis does. A command GLIDE cannot
     * buffer, such as EVAL, still runs in call order there, splitting the
     * transaction where it runs, unless keys are watched. A failure inside it
     * ends the transaction alone.
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
     * that closes a `multi()` opened inside a pipeline runs that transaction
     * and returns the pipeline, as phpredis does in pipeline mode. Returns
     * false when no batch is active.
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
     * Add members to a sorted set, or update their scores, with Laravel's
     * argument forms.
     *
     * As with Laravel's phpredis connection, the scores may be given as a
     * trailing array keyed by member, as in `zadd($key, ['a' => 1])`, and the
     * options as strings among the first three arguments after the key, as in
     * `zadd($key, 'NX', 1, 'a')`, which Laravel's tagged cache sends; GLIDE
     * takes neither. An options array after the key, as GLIDE takes it, is
     * kept. The pipeline or transaction's own `zadd()` takes the client's form,
     * as a phpredis pipeline does. The key is taken as `command()` takes it.
     *
     * @param  mixed  $key
     * @param  mixed  ...$dictionary
     * @return mixed
     *
     * @throws \Throwable
     */
    public function zadd(mixed $key, mixed ...$dictionary): mixed
    {
        return $this->command('zadd', [$key, ...$this->sortedSetArguments->zaddArguments($dictionary)]);
    }

    /**
     * Store the intersection of sorted sets, with Laravel's options array.
     *
     * As with Laravel's phpredis connection, the weights and aggregate may be
     * given as an options array in place of the weights, as in
     * `zinterstore($destination, $keys, ['weights' => [2, 1], 'aggregate' =>
     * 'max'])`. GLIDE's own weights list and aggregate are taken too, by
     * position or by name. The destination and keys are taken as `command()`
     * takes them.
     *
     * @param  mixed  $dst
     * @param  mixed  $keys
     * @param  mixed  $weights
     * @param  mixed  $aggregate
     * @return mixed
     *
     * @throws \Throwable
     */
    public function zinterstore(mixed $dst, mixed $keys, mixed $weights = null, mixed $aggregate = null): mixed
    {
        return $this->command('zinterstore', [$dst, $keys, ...$this->sortedSetArguments->storeArguments($weights, $aggregate)]);
    }

    /**
     * Store the union of sorted sets, with Laravel's options array.
     *
     * This takes the same forms as `zinterstore()`.
     *
     * @param  mixed  $dst
     * @param  mixed  $keys
     * @param  mixed  $weights
     * @param  mixed  $aggregate
     * @return mixed
     *
     * @throws \Throwable
     */
    public function zunionstore(mixed $dst, mixed $keys, mixed $weights = null, mixed $aggregate = null): mixed
    {
        return $this->command('zunionstore', [$dst, $keys, ...$this->sortedSetArguments->storeArguments($weights, $aggregate)]);
    }

    /**
     * Scan the keyspace for one page of keys, with Laravel's options array.
     *
     * As with Laravel's phpredis connection, the scan starts from cursor '0'
     * (or `null`), takes the `match` pattern, unprefixed, and the `count` hint
     * from the options, and returns the next cursor and the page's keys. The
     * cursor is '0' again once the scan is complete. A `type` option, such as
     * `zset`, keeps only keys of that type. A failed scan throws, as it does
     * through phpredis, rather than reading as an empty last page.
     *
     * GLIDE takes the cursor by reference on a server. On a cluster each
     * primary is scanned in turn with a plain `SCAN` sent to its address (see
     * ClusterScan), as GLIDE's own cluster scan aborts the process when a node
     * answers with an error. A ClusterScanCursor, or GLIDE's own pattern, count
     * and type arguments, are passed to GLIDE unchanged.
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
        // Inside a batch the connection refuses SCAN before anything is sent,
        // so the scan can resume from the same cursor once the batch ends.
        if ($cursor instanceof \ClusterScanCursor || !is_array($options) || $this->batch !== null) {
            return $this->command('scan', func_get_args());
        }

        $cursor  = Cast::toNonEmptyString($cursor)                   ?? '0';
        $pattern = Cast::toNonEmptyString($options['match'] ?? null) ?? '*';
        $count   = Cast::toNonNegativeInt($options['count'] ?? null) ?? 10;
        $type    = Cast::toNonEmptyString($options['type'] ?? null);
        $filters = $type !== null ? [$pattern, $count, $type] : [$pattern, $count];

        // A client closed by disconnect() is replaced before the cluster scan
        // takes it; the standalone scan reconnects through command().
        $this->reconnectAfterDisconnect();

        if ($this->glideClient instanceof \ValkeyGlideCluster) {
            return $this->scanCluster($this->glideClient, $cursor, $filters);
        }

        $keys = $this->command('scan', [&$cursor, ...$filters]);

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
     * instead, and the batch is returned. The scores and members of ZADD, and
     * the weights of ZUNIONSTORE, ZINTERSTORE, ZUNION, ZINTER and ZDIFFSTORE,
     * are sent as strings, as GLIDE corrupts memory on some other values, and
     * one with no string form is refused before anything is sent. A ZINCRBY
     * with a numeric increment is sent as the ZADD ... INCR it is equivalent
     * to, as GLIDE writes its increment with six significant digits. Other
     * floats GLIDE would write so are sent as phpredis sends them, an
     * INCRBYFLOAT or HINCRBYFLOAT increment through the raw command.
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
        $this->assertClusterInfoRouted($normalizedMethod, $parameters);

        if (in_array(strtoupper($normalizedMethod), self::BATCH_CONTROL_COMMANDS, true)) {
            return $this->controlBatch($normalizedMethod, $parameters);
        }

        [$normalizedMethod, $parameters] = $this->sortedSetArguments->rewriteIncrement($normalizedMethod, $parameters);

        $parameters = $this->floatArguments->normalize($normalizedMethod, $this->sortedSetArguments->normalize($normalizedMethod, $parameters));

        if ($this->batch !== null) {
            return $this->queueBatchCommand($this->batch, $normalizedMethod, $parameters);
        }

        // A client-local method reaches no server, so it needs no leftover
        // UNWATCH, which could replace the error getLastError() reads.
        if (!$this->batchCommands->runsLocally($normalizedMethod)) {
            $this->unwatchLeftOverKeys();
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
        $this->reconnectAfterDisconnect();
        $this->unwatchLeftOverKeys();

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
     * Read one page of a sorted set's members with ZSCAN on the primary,
     * whatever the connection's read_from, as the next cursor and the members.
     *
     * The cache tag upkeep deletes what it scans, and a replica can lag the
     * primary or, with several replicas, cannot resume another's cursor. On a
     * standalone server the ZSCAN is sent as a pipeline of one command, which
     * GLIDE always sends to the primary; on a cluster it is sent through
     * rawcommand to the primary that owns the key's slot.
     *
     * @internal
     *
     * @param  string  $key
     * @param  string  $cursor
     * @param  int  $count
     * @return array{string, array<int, string>}
     *
     * @throws \Throwable
     */
    public function zscanOnPrimary(string $key, string $cursor, int $count): array
    {
        $parameters = [$key, &$cursor, '*', $count];

        $members = $this->runOnPrimary('zscan', $parameters, function (array $parameters) use (&$cursor, $count): mixed {
            if ($this->glideClient instanceof \ValkeyGlideCluster) {
                return $this->clusterZscanMembers($this->glideClient, $parameters[0], $cursor, $count);
            }

            $page = $this->inPipelineOfOne('zscan', $parameters);

            return is_array($page) ? array_map(strval(...), array_keys($page)) : $page;
        });

        return [$cursor, self::scannedKeys($members, $key, 'zscan')];
    }

    /**
     * Read one page of a standalone server's SCAN on the primary, whatever the
     * connection's read_from, as zscanOnPrimary() reads a sorted set.
     *
     * @internal
     *
     * @param  string  $cursor
     * @param  string  $pattern
     * @param  int  $count
     * @param  string  $type
     * @return array{string, array<int, string>}
     *
     * @throws \Throwable
     */
    public function scanOnPrimary(string $cursor, string $pattern, int $count, string $type): array
    {
        $keys = $this->runOnPrimary('scan', [&$cursor, $pattern, $count, $type], fn (array $parameters): mixed => $this->inPipelineOfOne('scan', $parameters));

        return [$cursor, self::scannedKeys($keys, $pattern)];
    }

    /**
     * Close the underlying GLIDE connection.
     *
     * An active pipeline or transaction ends, with the pipeline a transaction
     * is nested in, as GLIDE drops what it buffered when it closes, and the
     * keys the connection watched are forgotten, as the server drops the WATCH
     * with the connection. The next command, batch or subscription reconnects
     * through the connector, as phpredis reconnects a closed client.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function disconnect(): void
    {
        for ($batch = $this->batch; $batch !== null; $batch = $batch->pipeline) {
            $this->endBatch($batch);
        }

        $this->forgetWatchedKeys();

        $this->glideClient->close();

        $this->disconnected = true;
    }

    /**
     * Parse the command's parameters for event dispatching.
     *
     * GLIDE's own cluster scan takes a ClusterScanCursor, which cannot be cast
     * to a string, so listeners that format the parameters would fail on it.
     * The event carries the cursor id it scans from instead, as a standalone
     * scan carries its cursor; the client still receives the object.
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
     * A list beside other arguments, as in `del('a', ['b', 'c'])`, a list of
     * lists, or a list given to a command that takes only separate keys, as in
     * `sunionstore('destination', ['a', 'b'])`, would reach keys outside the
     * prefix, such as the key `Array`, so it is refused before anything is
     * sent, inside a batch or outside one.
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
     * Assert that a cluster INFO carries a route.
     *
     * `ValkeyGlideCluster::info()` takes a route before its sections and
     * returns false without one. Each node reports only its own memory, clients
     * and keyspace, and GLIDE's own route, every primary, returns each node's
     * report in one list, so no route would give the single report the call
     * expects. It is refused before anything is sent, inside a batch or outside
     * one, as phpredis' `RedisCluster::info()` refuses a call without a node.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private function assertClusterInfoRouted(string $method, array $parameters): void
    {
        if ($parameters === [] && strtoupper($method) === 'INFO' && $this->glideClient instanceof \ValkeyGlideCluster) {
            throw new \InvalidArgumentException(self::UNROUTED_CLUSTER_INFO_MESSAGE);
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
     * Scan the primaries of a cluster for one page of keys, from a cursor.
     *
     * A cursor not of the shape the cluster scan writes is refused before
     * anything is sent. As before any command, a WATCH left over from an
     * UNWATCH that failed is unwatched first and the error GLIDE last recorded
     * is cleared, and a page answered with false throws the error GLIDE
     * recorded for it, if any. The page dispatches one command event, named
     * `scan`, carrying the cursor it scans from with the filters, as a
     * standalone scan does.
     *
     * @param  \ValkeyGlideCluster  $client
     * @param  string  $cursor
     * @param  array{0: string, 1: int, 2?: string}  $filters
     * @return array{0: string, 1: array<int, string>}
     *
     * @throws \Throwable
     */
    private function scanCluster(\ValkeyGlideCluster $client, string $cursor, array $filters): array
    {
        $state = $this->clusterScan->resume($cursor);

        $this->unwatchLeftOverKeys();
        $this->clearLastError();

        $parameters = [$cursor, ...$filters];
        $startedAt  = microtime(true);

        try {
            [$next, $keys] = $this->clusterScan->page($client, $state, $filters[0], $filters[1], $filters[2] ?? null);

            $error = $keys === false ? $this->lastError() : null;

            if ($error !== null) {
                throw $error;
            }
        } catch (\Throwable $exception) {
            $this->dispatchCommandFailedEvent('scan', $parameters, $exception);

            throw $exception;
        }

        $this->dispatchCommandExecutedEvent('scan', $parameters, $startedAt);

        return [$next, self::scannedKeys($keys, $filters[0])];
    }

    /**
     * Return the keys of a scanned page, or throw for a failed scan.
     *
     * GLIDE answers an empty page with an empty array and a failed scan with
     * false, so the failure is raised rather than read as an empty page, which
     * would end the caller's scan loop early as though it had completed.
     *
     * @param  mixed  $keys
     * @param  string  $target
     * @param  string  $method
     * @return array<int, string>
     *
     * @throws \UnexpectedValueException
     */
    private static function scannedKeys(mixed $keys, string $target, string $method = 'scan'): array
    {
        if (!is_array($keys)) {
            throw new \UnexpectedValueException(sprintf('Expected array response from %s on [%s], received [%s].', $method, $target, get_debug_type($keys)));
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
            return $this->runInBatch($this->batch, $atomic, $callback);
        }

        $batch = $this->beginBatch($atomic);

        $this->runCallback($batch, $callback);
        $this->assertNotDiscardedByCallback($batch);
        $this->assertNoTransactionLeftOpen($batch);

        return $this->executeBatch($batch);
    }

    /**
     * Run a callback inside the active batch, which is then returned for its
     * owner to execute, unless it is a transaction inside a pipeline, which
     * runs on its own.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  bool  $atomic
     * @param  callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed  $callback
     * @return \SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function runInBatch(BatchState $batch, bool $atomic, callable $callback): ValkeyGlideBatch
    {
        if ($atomic && !$batch->atomic) {
            return $this->runNestedTransaction($batch, $callback);
        }

        $this->runCallback($batch, $callback);

        return $batch->proxy;
    }

    /**
     * Run a transaction callback inside a pipeline as its own transaction.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $pipeline
     * @param  callable(\SineMacula\Valkey\Connections\ValkeyGlideBatch): mixed  $callback
     * @return \SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function runNestedTransaction(BatchState $pipeline, callable $callback): ValkeyGlideBatch
    {
        $transaction = $this->beginNestedTransaction($pipeline);

        $this->runCallback($transaction, $callback);
        $this->assertNotDiscardedByCallback($transaction);

        return $this->executeNestedTransaction($transaction, $pipeline);
    }

    /**
     * Start a transaction inside a pipeline.
     *
     * GLIDE holds one batch at a time, so what the pipeline buffered is sent
     * first, as before a command GLIDE cannot buffer. The transaction is then
     * the active batch until it ends, when the pipeline is again.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $pipeline
     * @return \SineMacula\Valkey\Connections\BatchState
     *
     * @throws \Throwable
     */
    private function beginNestedTransaction(BatchState $pipeline): BatchState
    {
        $this->runAside($pipeline, 'multi', function () use ($pipeline): void {
            $this->flushBatch($pipeline);
        });

        return $this->beginBatch(true, $pipeline);
    }

    /**
     * Execute a transaction nested in a pipeline, and give its results, or an
     * empty list when WATCH aborted it, one place in the pipeline's results.
     *
     * It runs with the transaction set aside, so its listeners find no batch
     * open.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $transaction
     * @param  \SineMacula\Valkey\Connections\BatchState  $pipeline
     * @return \SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function executeNestedTransaction(BatchState $transaction, BatchState $pipeline): ValkeyGlideBatch
    {
        $this->runAside($transaction, 'exec', function () use ($transaction, $pipeline): void {
            $results = $this->executeBatch($transaction);

            $pipeline->append([$results === false ? [] : $results]);
        });

        return $pipeline->proxy;
    }

    /**
     * Assert that a pipeline callback closed every multi() it opened, ending
     * the transaction and the pipeline if it did not.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     *
     * @throws \LogicException
     */
    private function assertNoTransactionLeftOpen(BatchState $batch): void
    {
        if ($this->batch === $batch) {
            return;
        }

        $this->endBatchAfterFailure($this->batch ?? $batch);
        $this->endBatchAfterFailure($batch);

        throw new \LogicException(self::UNCLOSED_TRANSACTION_MESSAGE);
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
     * whole batch with everything it still holds, a transaction still open in
     * it included, and the exception is rethrown, even when the discard or its
     * UNWATCH fails. A batch that ends while a callback owns it stays the
     * active batch, so the connection refuses the callback's later calls rather
     * than run them outside it, until the last callback that owns it returns.
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
            $this->endBatchAfterFailure($this->batch ?? $batch);
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
     * @param  \SineMacula\Valkey\Connections\BatchState|null  $pipeline
     * @return \SineMacula\Valkey\Connections\BatchState
     */
    private function beginBatch(bool $atomic, ?BatchState $pipeline = null): BatchState
    {
        $this->reconnectAfterDisconnect();
        $this->unwatchLeftOverKeys();

        $batch       = new BatchState($this, $atomic, $pipeline);
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
     * Nesting follows phpredis: a `multi()` inside a pipeline opens a
     * transaction that its own `exec()` runs, returning the pipeline, while any
     * other nested `multi()` or `pipeline()` changes nothing, so the next
     * `exec()` runs the batch.
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

        if ($atomic && !$batch->atomic) {
            return $this->beginNestedTransaction($batch)->proxy;
        }

        return $batch->proxy;
    }

    /**
     * Handle an exec() called on the connection or the batch.
     *
     * The batch runs, unless a callback owns it; a transaction nested in a
     * pipeline then returns the pipeline, as phpredis does in pipeline mode.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return false|list<mixed>|\SineMacula\Valkey\Connections\ValkeyGlideBatch
     *
     * @throws \Throwable
     */
    private function execBatch(BatchState $batch): array|false|ValkeyGlideBatch
    {
        $this->assertOutsideCallback($batch);

        if ($batch->pipeline !== null) {
            return $this->executeNestedTransaction($batch, $batch->pipeline);
        }

        return $this->executeBatch($batch);
    }

    /**
     * Handle a discard() called on the connection or the batch.
     *
     * The active batch is dropped, which for a `multi()` opened inside a
     * pipeline is that transaction alone; inside a callback, which owns the
     * batch, it is refused.
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
     * refuses them. Keys left over from a transaction that has ended are
     * unwatched first; if that fails, they stay tracked beside the new keys, as
     * the caller's own, since the server may still hold them, and the next
     * transaction is treated as guarded by them all.
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
        $this->unwatchLeftOverKeys();

        $parameters = $this->prefixer->apply($method, $this->batchCommands->spreadKeyList($parameters));

        return $this->executeCommandWithRetry($method, $parameters, function (mixed $result) use ($method, $parameters): void {
            if ($result !== true) {
                return;
            }

            $this->watchedKeys   = array_merge($this->watchedKeys ?? [], $this->prefixer->keys($method, $parameters) ?? []);
            $this->watchLeftOver = false;
        });
    }

    /**
     * Stop watching every watched key.
     *
     * The keys are forgotten once GLIDE reports the UNWATCH done, before any
     * listener hears of it. The caller has released them, so an UNWATCH that
     * GLIDE fails, which 1.1.2 reports as false, or that throws, leaves them
     * over, as the server may still hold the WATCH: the failure still reaches
     * the caller, and the UNWATCH is sent again before the next command. Keys
     * already left over go with the same UNWATCH.
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

        $this->watchLeftOver = $this->watchedKeys !== null;

        return $this->executeCommandWithRetry($method, $parameters, function (mixed $result): void {
            if ($result !== true) {
                return;
            }

            $this->forgetWatchedKeys();
        });
    }

    /**
     * Send UNWATCH again for keys left over from an UNWATCH that failed.
     *
     * The caller's unwatch() released them, or the transaction they were
     * watched for has ended, so the WATCH the server may still hold guards
     * nothing, but it would abort the next transaction, which the driver would
     * treat as watched. It is sent before each command, outside any batch, so
     * it is never buffered, until GLIDE reports it done; one that fails again,
     * returning false or throwing, keeps the keys for the next command, and the
     * command runs regardless. Keys the caller watches again are no longer left
     * over, so it never clears a WATCH that a transaction still to run relies
     * on.
     *
     * @return void
     */
    private function unwatchLeftOverKeys(): void
    {
        if (!$this->watchLeftOver) {
            return;
        }

        try {
            $unwatched = $this->dispatchToClient('unwatch', []) === true;
        } catch (\Throwable) {
            $unwatched = false;
        }

        if (!$unwatched) {
            return;
        }

        $this->forgetWatchedKeys();
    }

    /**
     * Forget the watched keys, and that they were left over.
     *
     * @return void
     */
    private function forgetWatchedKeys(): void
    {
        $this->watchedKeys   = null;
        $this->watchLeftOver = false;
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
     * the MULTI block, so the transaction is discarded and refused, unless it
     * is nested in a pipeline and unwatched, where it runs as it would in the
     * pipeline. Both run with the batch set aside.
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

        $this->runAside($batch, $method, function () use ($batch, $method, $parameters): void {
            $this->flushBatch($batch);
            $this->assertNoBatchLeftByListener($method);

            $this->executeCommandWithRetry($method, $parameters, static function (mixed $result) use ($batch): void {
                $batch->append([$result]);
            });
        });

        return $batch->proxy;
    }

    /**
     * Run sends to the client with the batch set aside, then resume it.
     *
     * The sends' listeners use the connection as they would outside a batch:
     * what they run is sent at once rather than joining the batch, where it
     * would start GLIDE's batch again under the send and be heard of only as
     * another send. A batch a listener leaves open is ended, as the batch
     * cannot take it back, and a client a listener disconnects is replaced
     * before the batch resumes. Anything that throws along the way, a send, a
     * listener, or the reconnect, ends the batch, whether or not a callback
     * owns it.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  string  $method
     * @param  \Closure(): void  $sends
     * @return void
     *
     * @throws \Throwable
     */
    private function runAside(BatchState $batch, string $method, \Closure $sends): void
    {
        $this->batch = null;

        try {
            $sends();

            $this->assertNoBatchLeftByListener($method);
            $this->reconnectAfterDisconnect();
        } catch (\Throwable $exception) {
            if ($this->batch !== null) {
                $this->endBatchAfterFailure($this->batch);
            }

            $this->resumeBatch($batch);
            $this->endBatchAfterFailure($batch);

            throw $exception;
        }

        $this->resumeBatch($batch);
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
     * stay tracked as left over, as they do when it fails outside a failure.
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
     * returns, so the connection refuses the callback's later calls. A
     * transaction nested in a pipeline hands the pipeline back.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return void
     */
    private function releaseBatch(BatchState $batch): void
    {
        if ($this->batch !== $batch || !$batch->isClosed() || $batch->isInCallback()) {
            return;
        }

        $this->batch = $batch->pipeline;

        if ($batch->pipeline === null) {
            return;
        }

        $this->releaseBatch($batch->pipeline);
    }

    /**
     * Assert that a command GLIDE cannot buffer is not run in a transaction,
     * other than an unwatched one nested in a pipeline, discarding the
     * transaction if it is.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  string  $method
     * @return void
     *
     * @throws \LogicException
     */
    private function assertNotAtomic(BatchState $batch, string $method): void
    {
        if (!$batch->atomic || ($batch->pipeline !== null && $this->watchedKeys === null)) {
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
            throw $this->failBatch($batch, $commands, false, $outcome === null, $this->lastError());
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
     * then ran the EXEC, or GLIDE recorded an error for it, as it does from 1.2
     * for a failure but not for a WATCH abort, and it throws instead. The batch
     * has ended before any listener hears of it, so a listener that throws
     * cannot leave the connection in batch mode.
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
            $error     = $this->lastError();
            $aborted   = $watched && $outcome === false && $error === null;
            $exception = $this->failBatch($batch, $commands, $aborted, $outcome === null, $error);

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
     * order. The client's last error is cleared first, so what it records
     * afterwards is the exec()'s own. An exec() that throws ends the batch, and
     * its exception is rethrown even if the batch's cleanup throws too.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @return bool|null
     *
     * @throws \Throwable
     */
    private function executeNativeBatch(BatchState $batch): ?bool
    {
        $this->clearLastError();

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
     * The error GLIDE recorded for the exec(), from 1.2, is the exception's
     * previous exception. A cleanup that throws as the batch ends does not
     * replace the failure.
     *
     * @param  \SineMacula\Valkey\Connections\BatchState  $batch
     * @param  list<string>  $commands
     * @param  bool  $watched
     * @param  bool  $misaligned
     * @param  \SineMacula\Valkey\Exceptions\CommandException|null  $error
     * @return \SineMacula\Valkey\Exceptions\ConnectionException
     */
    private function failBatch(BatchState $batch, array $commands, bool $watched, bool $misaligned, ?CommandException $error): ConnectionException
    {
        $this->endBatchAfterFailure($batch);

        $exception = new ConnectionException(match (true) {
            $misaligned     => sprintf(self::MISALIGNED_BATCH_MESSAGE, $this->resolveBatchEventName($batch)),
            $watched        => self::WATCHED_TRANSACTION_FAILED_MESSAGE,
            !$batch->atomic => self::PIPELINE_FAILED_MESSAGE,
            default         => self::TRANSACTION_FAILED_MESSAGE,
        }, previous: $error);

        $this->dispatchCommandFailedEvent($this->resolveBatchEventName($batch), $commands, $exception);

        return $exception;
    }

    /**
     * End the batch, clearing GLIDE's batch mode and any WATCH it leaves.
     *
     * GLIDE's discard() is client-side only and leaves WATCH in place on the
     * server, so a transaction that ends without a committed EXEC unwatches. On
     * a cluster, EXEC only clears the watches on its own node, so every watched
     * cluster transaction unwatches. When that UNWATCH fails, the server may
     * still hold the WATCH and abort the next transaction, so the keys stay
     * tracked, as left over, and the UNWATCH is sent again before the next
     * command. A batch that ends while a callback owns it stays the active
     * batch until the callback returns.
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

        // The transaction the keys were watched for has ended, so a WATCH that
        // outlives its UNWATCH guards nothing.
        $this->watchLeftOver = true;

        if (!$committed) {
            $this->unwatchEndedTransaction();

            return;
        }

        $this->endCommittedWatch();
    }

    /**
     * Send UNWATCH for a transaction that ended without a committed EXEC,
     * forgetting the keys once GLIDE reports it done.
     *
     * An UNWATCH that throws reaches the caller, unless a failure that ended
     * the batch is being thrown.
     *
     * @return void
     *
     * @throws \Throwable
     */
    private function unwatchEndedTransaction(): void
    {
        if ($this->dispatchToClient('unwatch', []) !== true) {
            return;
        }

        $this->forgetWatchedKeys();
    }

    /**
     * Clear the WATCH of a transaction whose EXEC committed.
     *
     * A committed standalone EXEC has cleared the WATCH. On a cluster, EXEC
     * only clears the watches on its own node, so UNWATCH is sent as it is for
     * keys left over: one that fails, returning false or throwing, does not
     * reach the caller, as the transaction has committed and a caller told
     * otherwise could apply it again, and the keys stay over for the next
     * command.
     *
     * @return void
     */
    private function endCommittedWatch(): void
    {
        if ($this->glideClient instanceof \ValkeyGlideCluster) {
            $this->unwatchLeftOverKeys();

            return;
        }

        $this->forgetWatchedKeys();
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
     * be left over from an UNWATCH that keeps failing. The slot is only
     * resolved here, and bound once GLIDE has buffered the command, so a
     * command GLIDE refuses binds the transaction to nothing.
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
     * @param  (\Closure(): mixed)|null  $invoke
     * @return mixed
     *
     * @throws \Throwable
     */
    private function executeCommandWithRetry(string $method, array $parameters, ?\Closure $onResult = null, ?\Closure $invoke = null): mixed
    {
        $this->reconnectAfterDisconnect();

        $attempt = 0;

        while (true) {
            $startedAt = microtime(true);

            try {
                $result = $this->invokeReportingErrors($method, $parameters, $invoke);
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
     * Invoke a command, throwing the error GLIDE records when it fails.
     *
     * From 1.2, GLIDE records the error of a command that fails and returns
     * false, which getLastError() reports until it is cleared, while a false
     * reply, such as a SETNX that sets nothing, records none. The error is
     * cleared before the command, so one left by an earlier call is not taken
     * for its own, and a false reply with an error recorded is thrown, so the
     * retry and the CommandFailed event handle it as any other failure. GLIDE
     * 1.1.2 records no errors, and raw commands record none on 1.2, so a false
     * reply with no error recorded is thrown only from a command whose reply is
     * never false, such as EXISTS or INCRBY, where it can only mean the command
     * failed; from any other command, such as a GET that finds nothing, it is
     * returned as it is. A client-local method, getLastError() itself among
     * them, reaches no server and records nothing, so it runs as it is, leaving
     * the error in place. The command is sent by the given invoker, if any,
     * instead of invokeCommand().
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  (\Closure(): mixed)|null  $invoke
     * @return mixed
     *
     * @throws \SineMacula\Valkey\Exceptions\CommandException
     * @throws \Throwable
     */
    private function invokeReportingErrors(string $method, array $parameters, ?\Closure $invoke = null): mixed
    {
        if ($this->batchCommands->runsLocally($method)) {
            return $this->invokeCommand($method, $parameters);
        }

        $this->clearLastError();

        $result = $invoke !== null ? $invoke() : $this->invokeCommand($method, $parameters);
        $error  = $result === false ? ($this->lastError() ?? $this->unrecordedFailure($method)) : null;

        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    /**
     * Return the error for a false reply GLIDE recorded no error for, when the
     * command's reply is never false.
     *
     * @param  string  $method
     * @return \SineMacula\Valkey\Exceptions\CommandException|null
     */
    private function unrecordedFailure(string $method): ?CommandException
    {
        $command = strtoupper($method);

        return in_array($command, self::NEVER_FALSE_COMMANDS, true)
            ? new CommandException(sprintf(self::UNRECORDED_FAILURE_MESSAGE, $command))
            : null;
    }

    /**
     * Clear the error GLIDE last recorded, on a client that records them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    private function clearLastError(): void
    {
        if (!$this->recordsLastError()) {
            return;
        }

        $this->dispatchToClient('clearLastError', []);
    }

    /**
     * Return the error GLIDE recorded since it was last cleared, if any.
     *
     * @return \SineMacula\Valkey\Exceptions\CommandException|null
     *
     * @throws \Throwable
     */
    private function lastError(): ?CommandException
    {
        $error = $this->recordsLastError() ? $this->dispatchToClient('getLastError', []) : null;

        return is_string($error) ? new CommandException($error) : null;
    }

    /**
     * Determine whether the client records the errors of failed commands, as
     * GLIDE does from 1.2 with getLastError().
     *
     * @return bool
     */
    private function recordsLastError(): bool
    {
        return method_exists($this->glideClient, 'getLastError');
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

        return $normalizedMethod === 'DBSIZE'
            ? $this->invokeDbSize($parameters)
            : $this->dispatchToClient($method, $this->routeClusterCommand($normalizedMethod, $parameters));
    }

    /**
     * Count the keys with a raw DBSIZE, on the route a cluster call carries or
     * the one GLIDE gives it.
     *
     * GLIDE's dbSize() aborts the process when the command fails, as it frees a
     * buffer on the stack (valkey-glide-php#384), so it is sent through
     * rawcommand, which takes the same routes and returns false instead.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     *
     * @phpstan-ignore throws.unusedType
     */
    private function invokeDbSize(array $parameters): mixed
    {
        if (!$this->glideClient instanceof \ValkeyGlideCluster) {
            return $this->glideClient->rawcommand('DBSIZE', ...$parameters);
        }

        $arguments = $this->routeClusterCommand('DBSIZE', $parameters);
        $route     = array_shift($arguments);

        return $this->glideClient->rawcommand($route, 'DBSIZE', ...$arguments);
    }

    /**
     * Add a route to a keyless cluster command that carries none.
     *
     * `ValkeyGlideCluster::ping()`, `dbSize()`, `randomKey()`, `time()` and
     * `echo()` take a route before their other arguments and return false
     * without one, so a bare `ping()`, as health checks call it, would report a
     * healthy cluster as down. A call with only the arguments the command takes
     * besides its route, such as `echo($message)`, takes the route GLIDE gives
     * the command, as `executeRaw()` does: `PING` and `DBSIZE` go to every
     * primary, which GLIDE answers with one reply, summing the key counts;
     * `RANDOMKEY` goes to every primary too, and GLIDE returns a key from the
     * first that has one; `TIME` and `ECHO` go to a random node. Any other call
     * is taken to carry its route first, as phpredis' `RedisCluster` methods
     * take one, and is passed through untouched.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private function routeClusterCommand(string $method, array $parameters): array
    {
        $arity = self::CLUSTER_ROUTED_COMMANDS[$method] ?? null;

        if ($arity === null || count($parameters) !== $arity || !array_is_list($parameters) || !$this->glideClient instanceof \ValkeyGlideCluster) {
            return $parameters;
        }

        return [$this->router->route([$method]), ...$parameters];
    }

    /**
     * Determine whether a command must run through rawcommand for
     * compatibility.
     *
     * Scripts take phpredis' argument shape, and so do SET options GLIDE's own
     * set() cannot take or refuses, as in `set($key, $value, 'EX', 60, 'NX')`
     * or `set($key, $value, 'NX')`. GLIDE has no `keys()`, which Laravel's
     * Redis queue calls to list its queues on any connection but a phpredis
     * cluster connection. GLIDE would write an INCRBYFLOAT or HINCRBYFLOAT
     * increment with six significant digits.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return bool
     */
    private function shouldInvokeAsRawCommand(string $method, array $parameters): bool
    {
        if (in_array($method, ['EVAL', 'EVALSHA', 'KEYS'], true) || $this->floatArguments->isInexactIncrement($method, $parameters)) {
            return true;
        }

        return $method === 'SET' && (count($parameters) >= 4 || (count($parameters) === 3 && is_string(end($parameters))));
    }

    /**
     * Execute a command through rawcommand with Redis protocol arguments.
     *
     * Cluster clients require a leading route argument, which the cluster
     * router resolves as it does for executeRaw(): a keyed script or SET goes
     * to the primary that owns its key's slot, a keyless one, or one whose key
     * cannot travel in a GLIDE route, to `randomNode`, and `KEYS` to every
     * primary, as each holds only its own slots' keys, and GLIDE joins their
     * replies into one list. The parameters are already prefixed, so the route
     * names the key the command targets. Standalone clients use the existing
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
            return $this->glideClient->rawcommand($this->router->route([$method, ...$values]), $method, ...$values);
        }

        return $this->glideClient->rawcommand($method, ...$values);
    }

    /**
     * Send a scan to the primary with the given invoker, as command() sends a
     * command: refused inside a pipeline or transaction, and outside one after
     * a WATCH left over from an UNWATCH that failed is unwatched.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @param  \Closure(array<array-key, mixed>): mixed  $invoke
     * @return mixed
     *
     * @throws \Throwable
     */
    private function runOnPrimary(string $method, array $parameters, \Closure $invoke): mixed
    {
        if ($this->batch !== null) {
            return $this->command($method, $parameters);
        }

        $this->unwatchLeftOverKeys();

        $parameters = $this->prefixer->apply($method, $parameters);

        return $this->executeCommandWithRetry($method, $parameters, invoke: fn (): mixed => $invoke($parameters));
    }

    /**
     * Send a command to a standalone server as a pipeline of one command, which
     * GLIDE sends to the primary whatever the connection's read_from.
     *
     * GLIDE writes a scan's cursor back through its reference when exec() runs.
     * A command GLIDE refuses buffers nothing, and a command or exec() that
     * throws leaves the client in batch mode, so the pipeline is discarded
     * then.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws \Throwable
     */
    private function inPipelineOfOne(string $method, array $parameters): mixed
    {
        $pipeline = $this->dispatchToClient('pipeline', []);

        if ($pipeline !== $this->glideClient) {
            return $pipeline;
        }

        try {
            $buffered = $this->dispatchToClient($method, $parameters);

            if ($buffered === false) {
                $this->discardPipelineOfOne();

                return $buffered;
            }

            $results = $this->dispatchToClient('exec', []);
        } catch (\Throwable $exception) {
            $this->discardPipelineOfOne();

            throw $exception;
        }

        return is_array($results) ? $results[0] ?? false : false;
    }

    /**
     * Discard a pipeline of one command, dropping a failure to discard it so a
     * failure being thrown is the one the caller sees.
     *
     * @return void
     */
    private function discardPipelineOfOne(): void
    {
        try {
            $this->dispatchToClient('discard', []);
        } catch (\Throwable) {
            // The command's own failure, if any, is the one to report.
        }
    }

    /**
     * Send a cluster ZSCAN through rawcommand to the primary that owns the
     * key's slot, writing the next cursor back, and give the page's members.
     *
     * @param  \ValkeyGlideCluster  $client
     * @param  mixed  $key
     * @param  string  $cursor
     * @param  int  $count
     * @return array<int, string>|false
     */
    private function clusterZscanMembers(\ValkeyGlideCluster $client, mixed $key, string &$cursor, int $count): array|false
    {
        // A key a route cannot carry goes to a random node; see routeKey().
        $reply = $client->rawcommand($this->router->routeKey($key), 'ZSCAN', $key, $cursor, 'COUNT', $count);

        if (!is_array($reply) || !is_scalar($reply[0] ?? null) || !is_array($reply[1] ?? null)) {
            return false;
        }

        $cursor = (string) $reply[0];

        return array_map(strval(...), array_column(array_chunk($reply[1], 2), 0));
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
     * Determine whether the current attempt should be retried.
     *
     * Nothing is retried while keys are watched: the reconnect would replace
     * the client, and with it the server connection that holds the WATCH, so
     * the next transaction would commit unguarded. The error is rethrown
     * instead, as phpredis surfaces a dropped connection, and the caller starts
     * the WATCH again. Keys left over from a transaction that has ended guard
     * nothing, so they do not hold the retry back.
     *
     * @param  int  $attempt
     * @param  string  $method
     * @param  \Throwable  $exception
     * @return bool
     */
    private function shouldRetryAttempt(int $attempt, string $method, \Throwable $exception): bool
    {
        if ($attempt > 0 || ($this->watchedKeys !== null && !$this->watchLeftOver)) {
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
     * one. The successor holds no WATCH, so keys left over from an ended
     * transaction, the only ones a retry runs with, are forgotten.
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
            $this->forgetWatchedKeys();
        }

        return true;
    }

    /**
     * Replace the client that disconnect() closed before it is used again.
     *
     * GLIDE answers every call on a closed client with false, not an error, so
     * nothing would reach the server and no retry would run. The client is
     * recreated through the connector instead, and any command may follow, as
     * nothing was sent on the closed one. A reconnect that fails leaves the
     * connection disconnected, so the next call tries again.
     *
     * @return void
     *
     * @throws \SineMacula\Valkey\Exceptions\ConnectionException
     *
     * @imperative
     */
    private function reconnectAfterDisconnect(): void
    {
        if (!$this->disconnected) {
            return;
        }

        if ($this->connector === null) {
            throw new ConnectionException(self::DISCONNECTED_MESSAGE);
        }

        $this->glideClient  = ($this->connector)();
        $this->disconnected = false;
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
