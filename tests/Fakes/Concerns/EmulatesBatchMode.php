<?php

declare(strict_types = 1);

namespace Tests\Fakes\Concerns;

/**
 * Emulate the batch mode of ext-valkey_glide 1.1.2 in the client fakes.
 *
 * The emulation keeps the extension's hazards, so tests prove the connection
 * defends against them: commands are buffered and return the client until
 * exec(); multi() and pipeline() inside a batch, or on a closed client, are
 * refused; an exec() with nothing buffered returns false and leaves the client
 * in batch mode until discard(); scripts, raw commands and the other immediate
 * commands run at once, and del() with a single key-list argument runs at once
 * but still returns the client; a cluster batch refuses its keyless routed
 * commands, which return false without their route even outside a batch; a SET
 * whose options are a string, or whose options array holds an expiry that is
 * not a positive integer or a float of at least one, such as `['EX' => '60']`,
 * is refused, in batch mode or outside it; the variadic del(), unlink() and
 * watch() refuse an integer first key, which their key-list form accepts; an
 * empty key list is refused by the key-list methods; a WATCH buffered inside a
 * MULTI makes its exec() fail; zrem() and zmscore() without a member, and
 * zinter() with an empty key list, return false, or the client in batch mode,
 * where they buffer nothing; sinter() given a single key that is not a list
 * throws a TypeError, after buffering it in batch mode; set() given a fourth
 * argument that is not an array throws a TypeError, and given more than four
 * arguments, as in the phpredis form `set($key, $value, 'EX', 60, 'NX')`, an
 * ArgumentCountError; and a closed client returns false for everything. Where
 * 1.1.2 returns null in breach of its own declared return type, the fakes
 * return what a PHP override can: true from a nested multi() or pipeline(), a
 * value that is neither the client nor the false of a closed client, and false
 * from a buffered unwatch(). A fake that receives more exec() and discard()
 * calls than any test sends throws, so a connection that loops on them fails a
 * test instead of hanging it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait EmulatesBatchMode
{
    /** @var list<string> Methods GLIDE runs immediately, even in batch mode. */
    private const array IMMEDIATE_METHODS = [
        'clearconnectionpassword',
        'eval',
        'eval_ro',
        'evalsha',
        'evalsha_ro',
        'getoption',
        'getstatistics',
        'publish',
        'pubsub',
        'rawcommand',
        'refreshiamtoken',
        'setoption',
        'updateconnectionpassword',
    ];

    /** @var list<string> Prefixes of the method families GLIDE runs immediately, even in batch mode. */
    private const array IMMEDIATE_PREFIXES = ['ft', 'function', 'getcache', 'script'];

    /** @var list<string> Keyless methods a cluster batch refuses. */
    private const array CLUSTER_REFUSED_METHODS = ['client', 'dbsize', 'echo', 'flushall', 'flushdb', 'info', 'ping', 'randomkey', 'time'];

    /** @var array<string, int> Keyless cluster methods that return false with fewer arguments than this, as their route is missing. */
    private const array CLUSTER_ROUTED_METHOD_ARITY = ['dbsize' => 1, 'echo' => 2, 'flushall' => 1, 'flushdb' => 1, 'info' => 1, 'ping' => 1, 'randomkey' => 1, 'time' => 1];

    /** @var array<string, int> Methods that buffer nothing, yet return the client in batch mode, with fewer arguments than this. */
    private const array MEMBER_ARITY = ['zmscore' => 2, 'zrem' => 2];

    /** @var list<string> Variadic key methods that refuse an integer first key. */
    private const array VARIADIC_KEY_METHODS = ['del', 'unlink', 'watch'];

    /** @var list<string> Key-list methods that refuse an empty key list. */
    private const array KEY_LIST_METHODS = ['del', 'exists', 'sinter', 'touch', 'unlink', 'watch'];

    /** @var list<string> Options of the native set() that take an expiry. */
    private const array SET_EXPIRY_OPTIONS = ['EX', 'EXAT', 'PX', 'PXAT'];

    /** @var int exec() and discard() calls past which the fake reports the connection as looping. */
    private const int BATCH_CALL_BUDGET = 1000;

    /** @var list<string> Methods called on the fake, in call order. */
    private array $callOrder = [];

    /** @var bool Whether the fake is in batch mode. */
    private bool $batching = false;

    /** @var bool Whether the batch is an atomic MULTI. */
    private bool $atomicBatch = false;

    /** @var list<array{string, mixed}> Buffered methods with their default results. */
    private array $buffered = [];

    /** @var bool Whether close() has been called. */
    private bool $closed = false;

    /** @var array<string, true> Methods refused, as for invalid arguments, while batching. */
    private array $refusedInBatch = [];

    /**
     * Refuse a method while batching, as GLIDE does when it rejects a command's
     * arguments without buffering it.
     *
     * @param  string  $method
     * @return void
     */
    public function refuseInBatch(string $method): void
    {
        $this->refusedInBatch[strtolower($method)] = true;
    }

    /**
     * Return every method called on the fake, in call order.
     *
     * @return list<string>
     */
    public function callOrder(): array
    {
        return $this->callOrder;
    }

    /**
     * Determine whether the fake is in batch mode.
     *
     * @return bool
     */
    public function isBatching(): bool
    {
        return $this->batching;
    }

    /**
     * Throw the errors ext-valkey_glide 1.1.2 throws for the arguments of its
     * native set(), which takes an options array as its only option argument.
     *
     * @param  array<int, mixed>  $arguments
     * @return void
     *
     * @throws \ArgumentCountError
     * @throws \TypeError
     */
    private function assertNativeSetArguments(array $arguments): void
    {
        if (count($arguments) > 4) {
            throw new \ArgumentCountError(sprintf('%s::set() expects at most 4 arguments, %d given', static::class, count($arguments)));
        }

        if (array_key_exists(3, $arguments) && !is_array($arguments[3])) {
            throw new \TypeError(sprintf('%s::set(): Argument #4 must be of type array, %s given', static::class, get_debug_type($arguments[3])));
        }
    }

    /**
     * Determine whether ext-valkey_glide 1.1.2 refuses the options array of its
     * native set(), as it does for an expiry that is not an integer or a float,
     * or that is below one once truncated.
     *
     * @param  array<int|string, mixed>  $options
     * @return bool
     */
    private function refusesSetExpiry(array $options): bool
    {
        foreach ($options as $option => $expiry) {
            if (!is_string($option) || !in_array(strtoupper($option), self::SET_EXPIRY_OPTIONS, true)) {
                continue;
            }

            if ((!is_int($expiry) && !is_float($expiry)) || (int) $expiry < 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assert that the fake has not received more exec() and discard() calls
     * than any test sends, which means the connection is looping on them.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \LogicException
     */
    private function assertWithinBatchBudget(string $method): void
    {
        if (!in_array($method, ['exec', 'discard'], true)) {
            return;
        }

        $calls = count(array_filter($this->callOrder, static fn (string $called): bool => $called === 'exec' || $called === 'discard'));

        if ($calls > self::BATCH_CALL_BUDGET) {
            throw new \LogicException(sprintf('exec() and discard() were called %d times on one client; the connection is looping.', $calls));
        }
    }

    /**
     * Record a command and apply batch mode or the configured behavior.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @param  mixed  $default
     * @return mixed
     *
     * @throws \Throwable
     */
    private function handleCommand(string $method, array $arguments, mixed $default): mixed
    {
        $normalizedMethod = strtolower($method);

        $this->recordCall($normalizedMethod, $arguments);

        if (array_key_exists($normalizedMethod, $this->exceptions)) {
            throw $this->exceptions[$normalizedMethod];
        }

        if ($this->refusesCall($normalizedMethod, $arguments)) {
            return false;
        }

        if ($this->buffersNothing($normalizedMethod, $arguments)) {
            return $this->resolveUnbufferedResult();
        }

        if ($this->refusesKeyAfterBuffering($normalizedMethod, $arguments)) {
            $this->bufferThenRefuseKey($normalizedMethod, $arguments[0] ?? null, $default);
        }

        if (!$this->batching || $this->runsImmediately($normalizedMethod, $arguments)) {
            return $this->resolveBatchFreeResult($normalizedMethod, $arguments, $default);
        }

        if ($this->isRefusedInBatch($normalizedMethod)) {
            return false;
        }

        $this->buffered[] = [$normalizedMethod, $default];

        return $this;
    }

    /**
     * Resolve the result of a command that is not buffered.
     *
     * A key-list del() in batch mode runs at once but still returns the client,
     * so its slot is lost from exec().
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @param  mixed  $default
     * @return mixed
     */
    private function resolveBatchFreeResult(string $method, array $arguments, mixed $default): mixed
    {
        $result = array_key_exists($method, $this->returns) ? $this->returns[$method] : $default;

        return $this->batching && $method === 'del' ? $this : $result;
    }

    /**
     * Determine whether GLIDE runs a method immediately in batch mode.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @return bool
     */
    private function runsImmediately(string $method, array $arguments): bool
    {
        if (in_array($method, self::IMMEDIATE_METHODS, true)) {
            return true;
        }

        foreach (self::IMMEDIATE_PREFIXES as $prefix) {
            if (str_starts_with($method, $prefix)) {
                return true;
            }
        }

        return ($method === 'del' || $method === 'unlink') && count($arguments) === 1 && is_array($arguments[0] ?? null);
    }

    /**
     * Determine whether a keyless cluster method was called without its route,
     * which ext-valkey_glide 1.1.2 answers with false.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @return bool
     */
    private function lacksClusterRoute(string $method, array $arguments): bool
    {
        return $this instanceof \ValkeyGlideCluster
            && count($arguments) < (self::CLUSTER_ROUTED_METHOD_ARITY[$method] ?? 0);
    }

    /**
     * Determine whether the client refuses a call outright, as it does when it
     * is closed or rejects the call's arguments.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @return bool
     */
    private function refusesCall(string $method, array $arguments): bool
    {
        return $this->closed || $this->lacksClusterRoute($method, $arguments) || $this->refusesKeys($method, $arguments);
    }

    /**
     * Resolve the result of a call that buffers nothing: the client in batch
     * mode, or false outside it.
     *
     * @return false|self
     */
    private function resolveUnbufferedResult(): false|self
    {
        return $this->batching ? $this : false;
    }

    /**
     * Determine whether ext-valkey_glide 1.1.2 refuses a key that is not a list
     * only after buffering the command, as `sInter()` does.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @return bool
     */
    private function refusesKeyAfterBuffering(string $method, array $arguments): bool
    {
        return $method === 'sinter' && count($arguments) === 1 && !is_array($arguments[0] ?? null);
    }

    /**
     * Determine whether a variadic key method was given an integer first key,
     * or a key-list method an empty key list, which ext-valkey_glide 1.1.2
     * refuses with false.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @return bool
     */
    private function refusesKeys(string $method, array $arguments): bool
    {
        $first = $arguments[0] ?? null;

        return (in_array($method, self::VARIADIC_KEY_METHODS, true) && is_int($first))
            || (in_array($method, self::KEY_LIST_METHODS, true) && $first === []);
    }

    /**
     * Determine whether ext-valkey_glide 1.1.2 neither buffers nor sends a
     * call, though it returns the client in batch mode.
     *
     * @param  string  $method
     * @param  array<int|string, mixed>  $arguments
     * @return bool
     */
    private function buffersNothing(string $method, array $arguments): bool
    {
        return count($arguments) < (self::MEMBER_ARITY[$method] ?? 0)
            || ($method === 'zinter' && ($arguments[0] ?? null) === []);
    }

    /**
     * Throw the TypeError ext-valkey_glide 1.1.2 throws for a key that is not a
     * list, once it has buffered the command in batch mode.
     *
     * @param  string  $method
     * @param  mixed  $key
     * @param  mixed  $default
     * @return never
     *
     * @throws \TypeError
     */
    private function bufferThenRefuseKey(string $method, mixed $key, mixed $default): never
    {
        if ($this->batching) {
            $this->buffered[] = [$method, $default];
        }

        throw new \TypeError(sprintf('%s::%s(): Argument #1 ($key) must be of type array, %s given', static::class, $method, get_debug_type($key)));
    }

    /**
     * Determine whether GLIDE refuses a method in batch mode.
     *
     * @param  string  $method
     * @return bool
     */
    private function isRefusedInBatch(string $method): bool
    {
        return $method === 'select'
            || array_key_exists($method, $this->refusedInBatch)
            || ($this instanceof \ValkeyGlideCluster && in_array($method, self::CLUSTER_REFUSED_METHODS, true));
    }

    /**
     * Enter batch mode.
     *
     * @param  string  $method
     * @param  bool  $atomic
     * @return bool|self
     */
    private function enterBatch(string $method, bool $atomic): bool|self
    {
        $this->recordCall($method, []);

        if ($this->closed) {
            return false;
        }

        if ($this->batching) {
            return true;
        }

        $this->batching    = true;
        $this->atomicBatch = $atomic;

        return $this;
    }

    /**
     * Send the buffered commands and return their results.
     *
     * A configured `exec` return replaces the results; otherwise each buffered
     * command yields its configured return or its default.
     *
     * @return array<int, mixed>|false
     *
     * @throws \Throwable
     */
    private function executeBatch(): array|false
    {
        $this->recordCall('exec', []);

        if (array_key_exists('exec', $this->exceptions)) {
            throw $this->exceptions['exec'];
        }

        if (!$this->batching || $this->buffered === []) {
            return false;
        }

        $buffered = $this->buffered;
        $watched  = $this->atomicBatch && in_array('watch', array_column($buffered, 0), true);

        $this->clearBatch();

        if ($watched) {
            return false;
        }

        $configured = $this->returns['exec'] ?? null;

        if (is_array($configured)) {
            return array_values($configured);
        }

        if ($configured === false) {
            return false;
        }

        return array_map(
            fn (array $command): mixed => array_key_exists($command[0], $this->returns) ? $this->returns[$command[0]] : $command[1],
            $buffered,
        );
    }

    /**
     * Leave batch mode, dropping the buffered commands, unless a configured
     * exception is thrown first.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    private function discardBatch(): bool
    {
        $this->recordCall('discard', []);

        if (array_key_exists('discard', $this->exceptions)) {
            throw $this->exceptions['discard'];
        }

        if (!$this->batching) {
            return false;
        }

        $this->clearBatch();

        return true;
    }

    /**
     * Mark the client closed, dropping any batch.
     *
     * @return void
     */
    private function markClosed(): void
    {
        $this->closed = true;

        $this->clearBatch();
    }

    /**
     * Reset the batch state.
     *
     * @return void
     */
    private function clearBatch(): void
    {
        $this->batching    = false;
        $this->atomicBatch = false;
        $this->buffered    = [];
    }
}
