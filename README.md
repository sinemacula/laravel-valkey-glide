<h1 align="center">Laravel Valkey GLIDE Driver</h1>

<div align="center">

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-valkey-glide.svg)](https://packagist.org/packages/sinemacula/laravel-valkey-glide)
[![Build Status](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/maintainability)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/coverage)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-valkey-glide.svg)](https://packagist.org/packages/sinemacula/laravel-valkey-glide)

</div>

Laravel Valkey GLIDE is a Laravel-native Redis integration for `ext-valkey_glide`.

It adds a `valkey-glide` Redis client driver to Laravel so cache, queue, session, and direct Redis usage can run through
Valkey GLIDE using Laravel's existing Redis abstractions.

## Purpose

Managed Redis/Valkey platforms can drop sockets during maintenance, failover, scaling, or node replacement. This
package integrates GLIDE into Laravel so reconnect behavior is handled at the client/connection layer rather than in
application business code.

## What Is Implemented

- Service provider registration of the `valkey-glide` driver via `RedisManager::extend()`
- Connector implementing `Illuminate\Contracts\Redis\Connector`
- Connection wrapper extending `Illuminate\Redis\Connections\Connection`
- Laravel config normalization into GLIDE `connect()` arguments
- Optional single retry for idempotent commands on transient transport errors
- Key prefix compatibility at the key positions phpredis prefixes, including destination keys, key lists, the
  key-value map of `MSET` and `MSETNX` and the stream map of `XREAD` and `XREADGROUP`
- `pipeline()` and `transaction()`, plus phpredis-style `multi()` / `exec()` / `discard()` on the connection
- Laravel compatibility fallback for phpredis-style `SET` and `EVAL` command argument shapes
- Phpredis-compatible `setnx()`, `zrevrange()` and options-array `scan()`, as Laravel's locks and Horizon call them, and
  phpredis-compatible `hsetnx()` and options-array `zscan()`, `hscan()` and `sscan()`
- Laravel's `zadd()`, `zinterstore()` and `zunionstore()` argument forms, and exact `zincrby()` increments
- Float arguments sent as phpredis sends them, where GLIDE would write six significant digits
- Cache store for the `redis` driver that keeps `putMany()` safe on cluster connections and runs cache tags on
  standalone and cluster connections
- Laravel Pulse's `redis` ingest driver on standalone and cluster connections (see Laravel Pulse)
- External test lane for extension and real Redis connectivity validation

## Requirements

- PHP `^8.3`
- Laravel components with `illuminate/redis` and `illuminate/cache` `^12.45 || ^13.0`
- `ext-valkey_glide` in runtime environments that use this driver

`ext-valkey_glide` is declared in `suggest` so this package can still be installed in environments that do not run the
Valkey driver.

## Installation

```sh
composer require sinemacula/laravel-valkey-glide
```

## Laravel Configuration

Set Redis client in your environment:

```dotenv
REDIS_CLIENT=valkey-glide
```

Example single-node Redis connection:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'valkey-glide'),

    'options' => [
        'prefix' => env('REDIS_PREFIX', ''),
    ],

    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'database' => (int) env('REDIS_DB', 0),
        'name' => env('REDIS_CLIENT_NAME'),
        'tls' => env('REDIS_TLS', false),
    ],
],
```

### Key Prefix Precedence

A key prefix can be set in the shared `database.redis.options`, in a connection's own `options`, or on the connection
itself. The connector resolves it as Laravel's phpredis connector does, on every supported Laravel release:

- A standalone connection's own `prefix` takes precedence over its `options` and the shared `options`. Its `options`
  take precedence over the shared `options`. A `prefix` that is set counts even when it is an empty string, so
  `'prefix' => ''` on the connection leaves it unprefixed whatever the shared `options` say; a `null` prefix counts as
  unset.
- Only `prefix` is lifted this way. For every other key, such as `database` or `timeout`, the connection's `options`
  take precedence over the shared `options`, which take precedence over the connection's own keys.
- Horizon's `Horizon::use()` copies the app connection it is given and sets Horizon's prefix in the copy's `options`,
  so if that connection sets its own `prefix`, Horizon's connection uses that prefix, as it does on phpredis. To keep
  Horizon under `horizon:` (or `horizon.prefix`), set the app prefix in the shared or the connection's `options`
  instead.
- A cluster does not lift a node's `prefix`: as on phpredis, a prefix in the options takes precedence over one on the
  first node. Unlike phpredis, which ignores the nodes' own keys, the first node's `prefix` and nested `options` still
  apply (see Cluster Connections).

> [!NOTE]
> In 1.2.x and earlier, a `prefix` set on a standalone connection itself was overridden by a prefix in its own
> `options` or, failing that, in the shared `options` (Laravel's default config sets one there), and Horizon's
> standalone connection used `horizon:` (or `horizon.prefix`) even when the connection it was copied from set its own
> `prefix`. Such a connection now uses its own prefix, an empty one included. Where that differs from the prefix it
> used before, it reads and writes different keys after the upgrade: its cache entries, sessions, locks and queued
> jobs, and Horizon's data, are left under the old prefix. A connection without its own `prefix`, with a `null` one, or
> with no prefix in either `options` keeps its keys. Before upgrading, check each standalone connection that sets its
> own `prefix`, and each one Horizon uses. To stay on the keys it used, remove that `prefix`, or set it to the prefix
> the connection used (its `options` prefix, else the shared one); for Horizon, move the app prefix into the shared or
> the connection's `options`. To move to the connection's own prefix instead, drain its queues and Horizon first, then
> migrate the keys you need to the new prefix (`RENAME`, or `COPY` then `DEL`) and expect a cold cache. Cluster
> connections are not affected.

## Supported Config Mapping

The connector normalizes Laravel config into GLIDE connect arguments:

- `host` / `port` -> `addresses`
- `addresses` (array) -> `addresses`
- `tls` or `scheme=tls` -> `use_tls=true`
- `username` + `password` -> ACL credentials
- `iam` block -> IAM credentials + `use_tls=true` (see IAM Authentication)
- `database` -> `database_id`
- `name` -> `client_name`
- `read_from` -> `read_from` (replica read routing; see Read Routing)
- `client_az` -> `client_az` (availability zone for AZ-affinity routing)
- `context` -> `context` (TLS stream context; array or resource)
- `timeout` (seconds) -> `request_timeout` (milliseconds)
- `connection_timeout` (seconds) -> `advanced_config.connection_timeout` (milliseconds)
- `reconnect_strategy` -> `reconnect_strategy` (backoff for GLIDE's own reconnects; see Reconnect Strategy)
- `lazy_connect` -> `lazy_connect` (defer connecting until the first command)
- `periodic_checks` -> `periodic_checks` (cluster connections only; see Periodic Topology Checks)

Cluster-style configs are normalized into seed `addresses` and connected through the dedicated `ValkeyGlideCluster`
client via `connectToCluster()`.

## Runtime Behavior

Command execution goes through `ValkeyGlideConnection::command()`.

- Idempotent commands can retry once on recognized transient connection errors
- The retry runs on a fresh client; the replaced client is closed, and any error from closing it is ignored
- Nothing is retried while keys are watched for a transaction still to run, as the fresh client would not hold the
  `WATCH` (see WATCH)
- Before each command that reaches the server, a `WATCH` left over from an `UNWATCH` that failed, sent by `unwatch()`
  or when a transaction ended, is unwatched again (see WATCH)
- Non-idempotent commands are not retried
- Laravel command events are dispatched (`CommandExecuted`, `CommandFailed`); a cluster `scan()` with Laravel's options
  array dispatches one `scan` event per page, carrying the cursor it scans from, and one given GLIDE's own
  `ClusterScanCursor` reports the id it scans from in place of the object, so listeners can turn the parameters into
  strings
- `executeRaw()` is supported via `rawcommand`, with a route added on cluster connections (see Cluster Connections)
- Phpredis-style `SET`/`EVAL` argument shapes are normalized through `rawcommand`, including a `SET` with a single
  string flag such as `set($key, $value, 'NX')`, which GLIDE's own `set()` refuses
- `setnx()` returns `1` or `0`, as phpredis does through Laravel, so Laravel's lock without an expiry and Horizon's lock,
  which compare it with `1`, can be taken; inside a pipeline or transaction, call it on the batch
- `hsetnx()` returns `1` or `0` too, where GLIDE returns `true` or `false`; inside a pipeline or transaction, call it on
  the batch, where its result is GLIDE's `true` or `false`, as phpredis' pipelines return it
- `zrevrange()`, which GLIDE lacks, reads the range with `ZRANGE ... REV`; pass `true` or `['withscores' => true]` as its
  fourth argument for the scores
- The scores and members of `zadd()`, and the weights of `zunionstore()`, `zinterstore()`, `zunion()` and `zinter()`
  (and of `zdiffstore()`, which GLIDE takes a weights list for, though the server refuses it), whether passed by
  position or as `weights:`, are sent to GLIDE as strings, inside a pipeline or transaction too. `ext-valkey_glide`
  1.1.2 tracks every integer or float score or weight in a buffer of 20 entries, so one call with more than 20 overflows
  it ([valkey-glide-php#379](https://github.com/valkey-io/valkey-glide-php/issues/379)), and it frees its buffers twice
  when a `zadd()` member is not a string, such as the integer `123`
  ([valkey-glide-php#380](https://github.com/valkey-io/valkey-glide-php/issues/380)); either corrupts the heap and can
  abort the PHP worker. It also writes floats with six significant digits, so `1234567.5` was stored as `1234570`.
  Integers are sent as their digits, floats as phpredis sends them, with 17 significant digits so they read back
  exactly, infinities as `+inf` and `-inf`, and strings as they are; members are converted as phpredis converts them, so
  `zadd($key, 1, 123)` stores the member `'123'` where GLIDE returned `false`. A score, member or weight with no string
  form, such as an array, throws an `InvalidArgumentException` before anything is sent, inside a batch or outside one.
  `CommandExecuted` carries the strings sent
- `zincrby()` adds its increment exactly. GLIDE's `zIncrBy()` takes the increment as a float and writes it with six
  significant digits, so `1.23456789` was added as `1.23457`. A `zincrby()` with a numeric increment is sent as the
  `ZADD ... INCR` it is equivalent to, with the increment read as a float and sent as phpredis sends it, inside a
  pipeline or transaction too, and returns the new score as a float, as phpredis does. `CommandExecuted` names the
  `zAdd` sent. A `zincrby()` with an increment that is not numeric, or with named arguments, is left to GLIDE
- `zadd()` takes Laravel's forms, as Laravel's phpredis connection does: a trailing array of scores keyed by member, as
  in `zadd($key, ['a' => 1, 'b' => 2])`, and option strings among the first three arguments after the key, as in
  `zadd($key, 'NX', 1, 'a')`, which Laravel's tagged cache sends; GLIDE took neither. As there, a member among those
  three named after an option, such as `'ch'`, is read as the option. GLIDE's options array, as in
  `zadd($key, ['NX'], 1, 'a')`, is taken as it is
- `zinterstore()` and `zunionstore()` take Laravel's options array in place of the weights, as Laravel's phpredis
  connection does, as in `zunionstore($destination, $keys, ['weights' => [2, 1], 'aggregate' => 'max'])`, with `SUM`
  when it names no aggregate; GLIDE sent the array as the weights, which the server refused. An empty array is read as
  no weights, where GLIDE sent an empty `WEIGHTS`. GLIDE's own weights, keyed by integers in any order, as from
  `array_filter()`, and aggregate string are taken too. As with a phpredis pipeline, the `zadd()`, `zinterstore()` and
  `zunionstore()` of a pipeline or transaction take the client's own forms
- Float arguments are sent as phpredis sends them, where `ext-valkey_glide` 1.1.2 would write six significant digits,
  so `set($key, 19.999999)` stores `19.999999` rather than `20` and `incrbyfloat($key, 1.23456789)` adds `1.23456789`
  rather than `1.23457`. GLIDE 1.1.2 still writes the coordinates, radius, width and height of `geosearch()` and
  `geosearchstore()` with six significant digits
  ([valkey-io/valkey-glide-php#382](https://github.com/valkey-io/valkey-glide-php/issues/382)); send the command
  through `executeRaw()` where that precision matters
- `scan($cursor, ['match' => ..., 'count' => ...])` takes Laravel's options array and returns `[$cursor, $keys]`, as
  Laravel's phpredis connection does: start from `'0'` and stop once the cursor is `'0'` again. The `match` pattern is
  not prefixed, `count` defaults to 10, and a `type` option, such as `'zset'`, keeps only keys of that type. A scan
  that fails throws an `UnexpectedValueException` rather than returning an empty last page. On a cluster each primary
  is scanned in turn, and GLIDE's own `ClusterScanCursor` is still passed to GLIDE unchanged (see Cluster Connections)
- `zscan($key, $cursor, $options)`, `hscan(...)` and `sscan(...)` take the same options array and return
  `[$cursor, $members]`, with the same string cursor, defaults and failure (see Cache Tags)
- `disconnect()` delegates to GLIDE `close()`; an open pipeline or transaction ends without sending what it buffered, as
  GLIDE drops it on close, and any `WATCH` is forgotten. The next command, pipeline, transaction or subscription
  reconnects through the connector, as phpredis reconnects a closed client, since GLIDE answers every call on a closed
  client with `false`. A connection created without a connector throws a `ConnectionException` instead

With `ext-valkey_glide` 1.1.2, most commands that fail return `false` rather than throwing, including while a node is
unreachable and GLIDE is reconnecting (`Connection in recovery`); the extension prints the error to stderr. The driver
cannot tell that `false` from a reply, so it passes it on: a cache `get()` reads it as a miss, and a single write or
lock acquisition reports failure. Laravel's `putMany()` on a standalone connection queues its writes in a transaction: a
transaction that fails as a whole throws a `ConnectionException` (see Failures under Pipelines and Transactions), but
`putMany()` does not check the result of each write in it, so a write that fails inside the transaction still reports
success. The retry above applies only to errors the extension throws.

From 1.2 (in its release candidates), GLIDE records the error of a command that fails and returns `false`, which its
`getLastError()` reports; a `false` reply, such as a `SETNX` that sets nothing or a `SISMEMBER` that finds nothing,
records none. On a client that has `getLastError()`, the driver clears the client's last error before each command,
and a command that returns `false` with an error recorded throws a `SineMacula\Valkey\Exceptions\CommandException`
carrying GLIDE's message, such as `timed out` or `WRONGTYPE: Operation against a key holding the wrong kind of value`.
It goes through the retry above and dispatches `CommandFailed` as any error the extension throws does, so a cache
`get()` that fails throws rather than reading as a miss. `CommandException` is an `UnexpectedValueException`, which the
driver throws for a failed scan or tag write on 1.1.2, so code that catches those failures catches it too. The error
stays on the client until the next command clears it, so `Redis::getLastError()` reads it; `getLastError()` and
`clearLastError()` run on the client without clearing it first, or sending a leftover `UNWATCH` (see WATCH). A pipeline
or transaction whose `exec()` fails carries the error as its `ConnectionException`'s previous exception (see Failures
under Pipelines and Transactions). Some failures still return `false`, as GLIDE records nothing for them:

- `rawcommand()`, which runs `EVAL` and `EVALSHA`, `executeRaw()` and phpredis-style `SET` options such as
  `set($key, $value, 'EX', 60, 'NX')`; its `false` cannot be read as a failure, as a script that returns `false` gives
  `false` too
- a command that fails inside a pipeline or transaction, which gives `false` in its own place in the results

> [!NOTE]
> In 1.2.x and earlier, a bare `multi()` ... `exec()` on the connection went straight to GLIDE, so a transaction that
> failed as a whole returned `false`, and `RedisStore::putMany()` on a standalone connection reported success anyway.
> Such a transaction now throws a `ConnectionException`, so `Cache::putMany()`, `setMultiple()` and `flexible()` can
> throw where they returned `true`, and a bare `multi()` on a cluster connection whose keys span hash slots throws a
> `LogicException` before anything is sent (see Pipelines and Transactions).
>
> In 1.2.x and earlier, a bare `pipeline()` or `multi()`, on the connection or through `command('pipeline')` and
> `command('multi')`, returned the raw `\ValkeyGlide` or `\ValkeyGlideCluster` client, so commands chained on it, as in
> `Redis::pipeline()->set('a', 1)->get('a')->exec()`, were sent without the connection's prefix, and code that drove a
> batch from the connection (`multi()`, then commands, then `exec()`) dispatched a `CommandExecuted` event for every
> call. They now return a `ValkeyGlideBatch`: chained keys are prefixed, so with a prefix configured those chains read
> and write different keys than before (delete or rename the unprefixed keys if they matter), code that types the
> result as the GLIDE client or calls client methods such as `getOption()` on it no longer works, and one `pipeline` or
> `transaction` event replaces the per-command events (see Pipelines and Transactions).
>
> In 1.2.x and earlier, `hsetnx()` on the connection returned GLIDE's `true` or `false`; it now returns `1` or `0`, so
> code that compares it with `true` must compare it with `1`. Called on the connection while a pipeline or transaction
> is open, it now throws a `LogicException` rather than joining the batch; call it on the batch instead. `zscan()`,
> `hscan()` and `sscan()` with Laravel's options array threw an `ErrorException`, as GLIDE takes the cursor by reference.

## Prefix Compatibility

Prefixing is handled in the connection wrapper for supported command families:

- single-key commands (`GET`, `SET`, `HGET`, `HMSET`, `PSETEX`, `ZREMRANGEBYSCORE`, `ZREMRANGEBYRANK`,
  `ZREVRANGEBYSCORE`, `ZREVRANK`, `XADD`, `GEOADD`, etc.)
- all-key commands (`MGET`, `DEL`, `UNLINK`, `EXISTS`, `WATCH`, `TOUCH`, `SINTER`, etc.), including a single key-list
  argument such as `del(['a', 'b'])` or `sinter(['a', 'b'])`. GLIDE takes a key list only as the command's only
  argument: beside other keys, as in `del('a', ['b', 'c'])`, it sends the list as the key `Array`, outside the prefix,
  or drops the keys after it, so such a call, or a list of key lists, throws an `InvalidArgumentException` before
  anything is sent, inside a batch or outside one, as phpredis' typed signatures refuse it with a `TypeError`
- the keys of the key-value map `MSET` and `MSETNX` take, as in `mset(['a' => 1, 'b' => 2])`, and the streams of the
  map `XREAD` and `XREADGROUP` take
- two-key commands (`RENAME`, `LMOVE`, etc.)
- the destination key and list of keys of multi-key commands (`ZUNIONSTORE`, `ZINTER`, `PFMERGE`, `LMPOP`, `FCALL`,
  etc.), the keys of blocking pops (`BLPOP`, `BZPOPMIN`, etc.) and the key after the subcommand of `OBJECT`, `XGROUP`
  and `XINFO`
- `EVAL` / `EVALSHA` key segments, and the declared keys of `EVAL_RO` / `EVALSHA_RO`

`SCAN` `MATCH` patterns are not prefixed, as phpredis only prefixes them when asked to.

> [!NOTE]
> In 1.2.x and earlier, these were sent unprefixed while the matching reads were prefixed: `HMSET`, `PSETEX`,
> `ZREMRANGEBYSCORE`, `ZREMRANGEBYRANK`, `ZREVRANGEBYSCORE`, `ZREVRANK`, the key of `HSCAN`, `SSCAN` and `ZSCAN` (see
> Cache Tags), every `WATCH` key, every `EXISTS` key after the first, the key list of `DEL`, `UNLINK`, `EXISTS`, `TOUCH`
> and `SINTER`, the key list of `MGET` when sent through `command()` directly (the connection's own `mget()` already
> prefixed it), and the map keys of `MSET` and `MSETNX`. They are now prefixed inside and outside batches, as phpredis
> prefixes them. With a prefix configured, a key that `HMSET`, `PSETEX`, `MSET`, `MSETNX` or a `ZREMRANGEBY*` command
> wrote or trimmed unprefixed before is no longer read or changed by these commands; delete or rename it if it matters.
> `KeyPrefixer::mgetKeys()` is deprecated: its result must no longer be passed to `command('mget', ...)`, which now
> prefixes the list itself. A key list beside other keys was sent as it was, so GLIDE wrote or read the key `Array`
> instead of the listed keys; it now throws.
>
> The other multi-key commands, such as `ZUNIONSTORE`, `PFMERGE`, `LMOVE`, `BLPOP` and `XREAD`, and some single-key
> commands, such as the `GEO*`, stream and hash-field expiry commands, were sent unprefixed in 1.2.x and earlier too;
> they are now prefixed at every key position phpredis prefixes. A list of keys GLIDE would send as the key `Array` now
> throws for these commands as well, as in `sunionstore('destination', ['a', 'b'])` or `bzpopmin(['a'], 'b', 5)`; pass
> the keys as separate arguments.
>
> The most visible effect is on Laravel's Redis queue with `block_for` set. Its pop waits in
> `blpop([$queue.':notify'], $blockFor)` for the push script to add to the notify list, which the script prefixed while
> the `BLPOP` did not, so on a prefixed connection a blocking worker picked up a new job only once `block_for` ran out,
> or, with a `block_for` of `0`, never. It now wakes as soon as a job is pushed.

## Pipelines and Transactions

`pipeline()` and `transaction()` follow phpredis' semantics, with the differences listed below, so the pipelines and
transactions of Horizon's repositories, `Queue::bulk()` and `Bus::batch()`, `Cache::putMany()`, and Laravel's own
`RedisTagSet::flushStaleEntries()` run on this driver (see the Horizon gaps below). The `redis` cache store's tags do
not open a batch themselves (see Cache Tags):

```php
$results = Redis::pipeline(function ($pipe) {
    $pipe->set('a', 1);
    $pipe->incr('visits');
    $pipe->get('a');
}); // [true, 1, '1']

$results = Redis::transaction(fn ($tx) => $tx->incr('x')->incr('y')); // [1, 1]

$results = Redis::pipeline()->set('a', 1)->get('a')->exec(); // without a callback: [true, '1']
```

- The callback receives a `ValkeyGlideBatch` (`SineMacula\Valkey\Connections\ValkeyGlideBatch`). Every command called on
  it is prefixed exactly as it is outside a batch and buffered, or, if GLIDE cannot buffer it, run in call order or
  refused (see Commands GLIDE Cannot Buffer), and returns the batch, so calls can be chained. The buffered commands are
  sent together, and the results come back as a list in call order, shaped as phpredis shapes them (`false` for a
  missing `GET`, an associative array for `HMGET`).
- The batch is not a phpredis `\Redis` client, so a callback whose parameter is typed `\Redis` gets a `TypeError`.
  Type the parameter as the batch, as in `Redis::pipeline(function (ValkeyGlideBatch $pipe) { ... })`, or leave it
  untyped, so the callback works with every driver. Laravel Pulse's own Redis ingest types its callback that way; the
  package runs Pulse's ingest on this driver instead (see Laravel Pulse).
- Horizon's pipelines and transactions run, as do its lock, its dashboard's tag and monitored-job pages and clearing its
  metrics, which use `setnx()`, `zrevrange()` and `scan()` (see What Is Implemented). In 1.2.0 and earlier, Horizon's
  lock was never taken, and each attempt wrote its lock key with no expiry: if Horizon ran on one of those releases,
  delete `metrics:snapshot`, `monitor:time-to-clear` and any `notification:<signature>` under Horizon's prefix, or
  Horizon never takes those locks.
- An exception thrown by the callback discards everything still buffered, and is rethrown. Whatever ends a batch, such
  as that exception, a failed `exec()` or a refused command, is what the caller gets, even if the discard, or the
  `UNWATCH` that follows it, fails.
- The callback owns the batch while it runs: `exec()` or `discard()` called inside it throws a `LogicException`, except
  an `exec()` that closes a `multi()` or `transaction()` opened inside a pipeline, which returns the batch (see Nested
  Batches). Return from the callback to send the batch, or throw from it to discard it.
- A batch that an error ends inside its callback, such as a refused command in a transaction or a nested callback that
  threw, stays ended even if the callback catches the error: every later call on the batch, or on the connection, throws
  a `LogicException` rather than run outside the batch, and once the callback returns, the call throws a
  `LogicException` saying the batch was not executed. In a pipeline, commands already sent ahead of an immediate
  command such as `EVAL` stay applied; the commands it had not yet sent are discarded.
- `multi()`, `exec()` and `discard()` called on the connection itself, as `RedisStore::putMany()` does on a standalone
  connection, drive the same batch. `multi()` starts a transaction, and `multi(\ValkeyGlide::PIPELINE)` a pipeline, as
  with phpredis.
- Batch state belongs to each connection, so a Horizon pipeline on the `horizon` connection can run while a batch is
  open on the application's own connection.
- The connection's `hasOpenBatch()` reports whether a pipeline or transaction is open on it, from `pipeline()`,
  `transaction()` or `multi()` until the batch is sent or discarded. Code that calls the GLIDE client from `client()`
  directly bypasses the batch handling, so it checks this first.
- While a batch is open, every command called on the same connection is added to it and returns the batch, not only
  those called on the batch itself, as with phpredis. That includes any other code that uses the same connection name
  during `Queue::bulk()`, such as a cache store on that connection: its writes are added to the batch, while its reads,
  which go through the connection's typed `get()` and `mget()`, throw a `LogicException` (see Refused below). Give
  unrelated work its own connection name, and always `exec()` or `discard()` a batch started without a callback.
- Event listeners never run while a batch is open on the connection, so they use it as they would outside a batch:
  what a listener runs on the same connection is sent at once, never added to the batch, and its typed `get()` and
  `mget()` work. A listener that writes on every command it hears of, skipping its own command, therefore stops, as it
  does outside a batch. A batch that a listener starts and leaves open while a pipeline runs an immediate command (see
  below) is discarded, and that pipeline ends with a `LogicException`.
- Buffered commands dispatch no `CommandExecuted` event. That matches phpredis for callback batches, whose commands go
  to the raw client; where code drives a batch from the connection itself (`multi()`, then commands, then `exec()`, as
  `RedisStore::putMany()` does), phpredis dispatches an event for each call, while this driver dispatches only the batch
  event. Each batch sent to the server dispatches one, named `pipeline` or `transaction`, whose parameters are the
  buffered command names; a failed one, including a watched transaction that returns `false`, dispatches
  `CommandFailed`. Commands that run immediately (see below) dispatch their own events as usual. A batch has ended
  before the event for its final send is dispatched, and the pipeline is set aside while part of it is sent before an
  immediate command, and while that command runs, so their listeners never find it open. Anything that throws while
  part of a pipeline is sent before an immediate command, that command and its listeners included, ends the pipeline,
  so a listener that throws cannot leave the connection in batch mode.
- A batch is never retried: the retry described under Runtime Behavior does not apply to buffered commands or to the
  batch's `EXEC`.
- Every argument of a buffered command is copied into GLIDE's buffer, so a very large batch counts against PHP's
  `memory_limit` until it is sent.

### Commands GLIDE Cannot Buffer

`ext-valkey_glide` 1.1.2 has its own batch mode, but it leaves the client in unsafe states: an empty batch wedges the
client in batch mode, and some commands silently run at once instead of being buffered. The driver therefore counts
what it buffers, starts GLIDE's batch mode only when the first command is buffered, and handles each command GLIDE
cannot buffer itself:

- **Empty batches** return `[]` without reaching GLIDE's `exec()`. Horizon runs an empty pipeline on every worker pop,
  and an empty `RedisStore::putMany()` runs an empty transaction on a standalone connection; neither leaves the client
  stuck.
- **Commands GLIDE runs immediately**: `EVAL`, `EVALSHA` and their `_RO` forms, `SCRIPT`, `FUNCTION`, `PUBLISH`,
  `PUBSUB`, `rawCommand()` and `executeRaw()` (on the connection or the batch), the search (`FT.*`) commands, the
  connection password and IAM token methods (`updateConnectionPassword()`, `clearConnectionPassword()` and
  `refreshIamToken()`), and the client's own methods, such as `getOption()`, `getStatistics()`, `getLastError()` and
  the OpenTelemetry sampling methods; on a cluster also the keyless `PING`, `ECHO`, `INFO`, `DBSIZE`, `TIME`,
  `RANDOMKEY`, `FLUSHDB`, `FLUSHALL` and `CLIENT`.
  - In a pipeline, what is buffered before such a command is sent first, then the command runs on its own and its
    result takes its place in the list, so the results stay in call order; it costs extra round trips. What the event
    listeners of that send, or of the command, run on the same connection is sent at once and takes no place in the
    list (see the event listeners above).
  - In a transaction it would run outside the `MULTI` block, so the transaction is discarded and a `LogicException` is
    thrown. Use `pipeline()` for them.
  - An `incrbyfloat()` or `hincrbyfloat()` whose increment GLIDE would write with six significant digits, such as
    `1.23456789`, is sent through `rawcommand`, so it runs in call order in a pipeline and is refused in a transaction.
  - Unlike phpredis, where `getOption()` and the other methods of the client itself return their value at once in any
    mode, they take a place in a pipeline's results and end a transaction too; call them outside the batch.
- **Rewritten so they buffer**: a key list such as `del(['a', 'b'])` or `unlink([...])`, which GLIDE runs immediately,
  is sent as variadic keys, with each key as a string, and phpredis-style `SET` options such as
  `set($key, $value, 'EX', 60, 'NX')` or `set($key, $value, 'NX')` become GLIDE's options array, with the value sent
  as the string `rawcommand` would send outside a batch, so `false` is stored as `''` in both. An expiry converts only
  when it is a positive integer or its digits, such as `60` or `'60'`. `SET` options that cannot be converted, such as
  an unknown option, a float or `'7.5'` expiry, two different expiries such as `'EX', 60, 'PX', 1000` (of which GLIDE
  would keep one), or a value with no string form, run immediately, as above, through `rawcommand` as they do outside
  a batch, so the server judges them the same way in both.
- **Refused** with a `LogicException` before anything is sent: `SELECT`, `SCAN`, `HSCAN`, `SSCAN` and `ZSCAN` (GLIDE
  writes their cursor through a reference that may be gone by the time the batch is sent), the subscribe family
  (`subscribe()` and `psubscribe()` on the connection too), `WATCH` and `UNWATCH`, whether called on the batch or on
  the connection. The connection's own typed `get()` and `mget()` are refused too while a batch is open, as their
  value is not known until the batch runs; call them on the batch instead.
- **Arguments GLIDE rejects**: GLIDE checks some arguments itself and buffers nothing when it rejects them, for example
  the batch's `zadd($key, 'NX', 1, $member)` with a string option, where it expects `zadd($key, ['NX'], 1, $member)`
  (the connection's own `zadd()` converts it; see Runtime Behavior). The driver
  throws an `InvalidArgumentException` naming the command rather than return a result list with a slot missing. GLIDE
  reports `zrem()` and `zmscore()` given a key but no member, as from `zrem($key, ...$ids)` with no ids, and `zinter()`
  given an empty key list, as buffered without buffering them, so the driver refuses them the same way before they are
  sent; outside a batch they return `false`. An empty key list, as in `del([])` or `unlink([])`, is passed to GLIDE as
  it is, which refuses it, so it throws the same `InvalidArgumentException`; outside a batch it returns `false`. So
  does a native `SET` options array with an expiry GLIDE cannot read as a positive number, such as `['EX' => '60']`
  or `['EX' => 0]`. A key list beside other keys is refused before it is sent (see Prefix Compatibility).
- **Commands GLIDE throws for**, such as `sinter('key')`, which GLIDE 1.1.2 accepts only as `sinter(['key'])`, end the
  batch, discarding everything it still buffers, whether or not a callback owns it, as GLIDE may have buffered the
  command before it threw. The error is rethrown; a batch whose error was caught cannot be used again.
- **A client closed outside the driver**, or one already put in batch mode outside the driver, refuses batch mode, so
  the batch throws a `ConnectionException` and ends; a client `disconnect()` closed is replaced first (see Runtime
  Behavior). The driver then calls the client's `discard()`, which takes a client put in batch mode outside the driver
  out of it, dropping whatever was buffered there, and does nothing on a closed client; the next command takes the
  usual path.

### Nested Batches

`pipeline()` or `transaction()` called with a callback while a batch is open joins it: the callback runs against the
same batch, the batch is returned, and the results come from the outermost call. Only part of that matches phpredis,
and only for a transaction nested in a pipeline: its inner `exec()` returns the batch, as phpredis returns the client in
pipeline mode. Its results are flattened into the pipeline's list, where phpredis returns them as one nested array, so
code that reads the list by position after a nested transaction finds different positions. Under phpredis a pipeline
nested in a pipeline, or a transaction nested in a transaction, is sent by the inner call, which returns the results,
and the outer `exec()` then returns `false`, while a pipeline nested in a transaction is a fatal error. On this driver a
pipeline nested in a transaction stays part of the transaction. An exception thrown by a nested callback discards
everything the batch still buffers, the outer commands included, and is rethrown; in a pipeline, commands already sent
ahead of an immediate command such as `EVAL` stay applied. An outer callback that catches it cannot carry on with the
batch (see above). `pipeline()` and `transaction()` called on the batch itself behave the same way.

Without a callback, nesting follows phpredis:

- `transaction()` or `multi()` inside a pipeline opens a block that its own `exec()` closes; that `exec()` returns the
  batch, and the outermost `exec()` sends everything. The block's results are flattened into the list, as above.
- Any other nested `pipeline()`, `transaction()` or `multi()` changes nothing and returns the same batch, so the next
  `exec()` sends the batch.
- `discard()` outside a callback drops the whole batch, a `transaction()` or `multi()` still open inside the pipeline
  included, as phpredis drops the whole pipeline.

```php
$pipe = Redis::pipeline();
$pipe->set('a', 1);

Redis::transaction()->incr('a')->exec(); // returns the batch; nothing is sent yet

$pipe->get('a')->exec(); // [true, 2, '2'], where phpredis returns [true, [2], '2']
```

> [!WARNING]
> A transaction nested in a pipeline is flattened into the pipeline and is not atomic, because GLIDE cannot nest a
> `MULTI` block inside a pipeline, and its results are flattened into the pipeline's list. Under phpredis the inner
> transaction stays atomic and its results come back as one nested array. Laravel's `Queue::bulk()`, and so
> `Bus::batch()`, nests a transaction in a pipeline and pushes each job with `EVAL`, which GLIDE cannot buffer either:
> on this driver the jobs are pushed one at a time, in order, and not atomically. With `ext-valkey_glide` 1.1.2 a push
> that fails returns `false` rather than throwing, and Laravel does not check it, so that job is silently skipped while
> the rest are still pushed. A flattened transaction could not be guarded by `WATCH` either, so while keys are watched
> a transaction that would join a pipeline throws a `LogicException` instead.

### Failures

GLIDE 1.1.2 reports every failed batch as `false`, without saying why, and a batch that timed out may still have been
applied by the server. The driver never retries a batch, and:

- a failed pipeline throws a `ConnectionException`; some of its commands may have been applied
- a failed transaction throws a `ConnectionException`; its outcome is unknown, and it may have been committed
- a failed transaction guarded by `WATCH` returns `false`, as phpredis does when a watched key changed; on 1.1.2 a
  timeout looks the same, so `false` does not prove the transaction was not applied. From 1.2, GLIDE records an error
  for a timeout or a connection failure but not for an abort, so a watched transaction whose `exec()` fails with an
  error throws a `ConnectionException` instead, its outcome unknown
- a batch whose results do not line up with the commands it buffered throws a `ConnectionException`, rather than return
  results out of call order; the server ran it, so it has most likely been applied, and a watched transaction throws
  too rather than return `false`
- from 1.2, the error GLIDE records for a failed `exec()`, such as `timed out`, is the `ConnectionException`'s previous
  exception, a `CommandException`

A command that fails on the server, such as a `WRONGTYPE` error, gives `false` in its own place in the results, in a
pipeline and in a transaction alike, and the other commands still apply. An error the server raises while queueing a
transaction, such as a wrong number of arguments, fails the whole transaction instead.

### WATCH

Call `watch()` on the connection before the transaction; the keys are prefixed, and a transaction aborted by a change
to one of them returns `false`:

```php
Redis::watch('balance');

$results = Redis::transaction(fn ($tx) => $tx->decrby('balance', 10)->incrby('spent', 10));

if ($results === false) {
    // Aborted, or timed out and possibly committed: re-read `balance` and `spent` to learn
    // which before acting again.
}
```

Because a `false` may come from a transaction that timed out after the server committed it, do not simply retry a
write that is not idempotent, such as the debit above: re-read the state to see whether it was applied, or make the
write idempotent, for example by recording a unique operation id in the same transaction and checking for it first.

- `watch()` and `unwatch()` are refused inside a batch, where they would fail the whole transaction.
- A `WATCH` lives on the server connection, and GLIDE reconnects a dropped connection in the background on its own (see
  Reconnect Strategy), so if the connection drops between `watch()` and the transaction, after a server restart, a
  failover, an idle timeout or a network fault, the `WATCH` is silently lost and the transaction commits even if a
  watched key changed. GLIDE 1.1.2 does not tell the driver this happened, and phpredis would report the dropped
  connection instead. If a change must never be overwritten, also check for it inside the write itself, for example
  with a version field compared in a Lua script run outside the batch.
- The driver's own retry is off while keys are watched: a command that hits a transport error between `watch()` and
  the transaction throws, as it does on phpredis, rather than reconnect and drop the `WATCH`. Start the `WATCH` again.
- GLIDE's `discard()` leaves a `WATCH` in place on the server, where it would abort a later transaction, so a
  transaction that ends without being sent (discarded, its callback threw, or it was empty) calls `unwatch()`.
- An empty watched transaction returns `[]` without checking the watched keys, as GLIDE cannot send an empty batch.
- Pipelines leave a `WATCH` alone, but a transaction cannot join a pipeline while keys are watched: the pipeline would
  flatten it, and it would commit over a change to a watched key, so it throws a `LogicException` before anything
  changes. Under phpredis the nested transaction would stay atomic and abort.
- A `WATCH` that GLIDE refuses, returning `false` or, from 1.2, throwing the error it records, is not tracked. An
  `UNWATCH` that fails, returning `false` or throwing, leaves the keys tracked, as the server may still hold the
  `WATCH`; a transaction it then aborts returns `false`. That covers an `unwatch()` called on the connection, whose
  failure the caller still gets, and the `UNWATCH` sent when a transaction ends, committed on a cluster or not
  committed.
- Either way the `WATCH` is released, so the keys left tracked guard nothing, and the driver sends `UNWATCH` again
  before the next command on the connection, a `watch()`, `pipeline()`, `transaction()` or subscription included, once
  per command and never inside a batch, until it succeeds; the command runs either way. The client's own methods, such
  as `getOption()` and `getLastError()`, reach no server and do not send it, so it cannot replace the error
  `getLastError()` reads. Until an `UNWATCH` succeeds, the next transaction is still treated as watched, as the server
  may abort it, but the leftover keys do not turn the retry off, and a retry's fresh client drops them. A `watch()`
  whose `UNWATCH` first succeeds tracks only its own keys; if that `UNWATCH` fails but the `WATCH` succeeds, the
  leftover keys stay watched beside the new ones, as the caller's own, until the transaction ends.
- The `UNWATCH` a cluster sends after a committed transaction never reaches the caller when it fails, returning `false`
  or throwing: the transaction has committed, so its results are returned and its `CommandExecuted` event dispatched,
  rather than leading the caller to retry it and apply it twice. From 1.2 the error GLIDE records for it stays on the
  client, where `getLastError()` reads it, until the next command clears it.

### Cluster Batches

- A pipeline may span hash slots and nodes; GLIDE splits it per node and keeps the results in call order.
- A transaction runs on one node, so every key it touches, and every watched key, must hash to one slot. The driver
  checks this with the cluster's CRC16 key slot as each command is added, and on the first key in another slot it
  discards the transaction and throws a `LogicException` naming the command, the key and both slots, before anything is
  sent. While keys are watched, the transaction is bound to the watched keys' slot from the start, and the message names
  them; keys left over by a failed `UNWATCH` keep that slot until the driver sends one that succeeds. Give the keys a
  common hash tag (`{user:1}:name`, `{user:1}:email`). A command GLIDE refuses to buffer binds the transaction to no
  slot. Commands whose key positions the driver does not know, such as GLIDE's JSON commands, are left to the server,
  which fails a transaction whose keys cross slots.
- A cluster transaction that watched keys refuses, with a `LogicException` before anything is sent, every command whose
  keys the driver does not know or that has none. GLIDE sends a cluster transaction by its commands' own keys, or to
  any node when they have none, so such a command could take it to a node where the `WATCH` does not apply, and it
  would commit over a change to a watched key.
- A cluster transaction that watched keys always calls `unwatch()` afterwards, as `EXEC` only clears the watches on its
  own node.
- A transaction started with a bare `multi()` on the connection is checked the same way, so its keys must share a hash
  tag, or it throws. Laravel's own `RedisStore::putMany()` issues one for connections it does not know to be clusters;
  the `redis` cache store this package registers writes each value on its own on a cluster instead (see Cache Store),
  so `Cache::putMany()` takes keys in any slot.
- On Laravel 13, Horizon hash-tags its own `{horizon:}` prefix on a cluster, which keeps its keys in one slot. Horizon
  only supports clusters where Laravel's `Connection::hasHashTag()` exists, which Laravel 13 added; on Laravel 12 it
  connects to the cluster's first node as a standalone server. Keys that other nodes own then fail with `MOVED`,
  which GLIDE 1.1.2 returns as `false` rather than throwing, so Horizon silently loses those writes. On Laravel 12,
  point Horizon at a standalone server.

## Read Routing

GLIDE can route read commands to replicas. Set `read_from` on the connection to one of the following strategies (both
the string name and the integer constant are accepted):

| Strategy                           | Value | Behavior                                                |
|------------------------------------|-------|---------------------------------------------------------|
| `primary`                          | `0`   | All reads go to the primary (default)                   |
| `prefer_replica`                   | `1`   | Reads prefer replicas, falling back to the primary      |
| `az_affinity`                      | `2`   | Reads prefer replicas in the same AZ as `client_az`     |
| `az_affinity_replicas_and_primary` | `3`   | Same-AZ affinity across replicas and the primary        |

`client_az` is required for the AZ-affinity strategies.

Replica reads are eventually consistent: a read can miss a write the replica has not received yet. Cache tag flushes
and `cache:prune-stale-tags` read the primary instead (see Cache Tags).

```php
'default' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => (int) env('REDIS_PORT', 6379),
    'read_from' => env('REDIS_READ_FROM', 'prefer_replica'),
    'client_az' => env('REDIS_CLIENT_AZ'),
],
```

## Timeouts and TLS Context

The GLIDE core defaults the request timeout to 250ms, which can be too short for managed services during warm-up.
Configure timeouts in seconds (matching the phpredis convention); they are converted to milliseconds for GLIDE:

- `timeout` (seconds) -> `request_timeout`
- `connection_timeout` (seconds) -> `advanced_config.connection_timeout`

Pass a custom TLS stream context (for custom CA certificates or verification settings) via the per-connection `context`
key. Both a config-array and a pre-built `stream_context_create()` resource are accepted:

```php
'default' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'tls' => true,
    'timeout' => 3.0,
    'context' => [
        'ssl' => [
            'cafile' => '/path/to/ca.crt',
            'verify_peer' => true,
        ],
    ],
],
```

## Reconnect Strategy

GLIDE reconnects dropped sockets in the background with exponential backoff. Tune that backoff per connection with
`reconnect_strategy`; any key left out keeps its GLIDE default, and leaving the block out keeps GLIDE's behavior
unchanged:

| Key              | GLIDE default | Accepted values | Meaning                                                    |
|------------------|---------------|-----------------|------------------------------------------------------------|
| `num_of_retries` | `5`           | `1` - `100`     | Number of increasing delays before the delay stops growing |
| `factor`         | `100`         | `1` - `3600000` | Delay multiplier, in milliseconds                          |
| `exponent_base`  | `2`           | `1` - `3600000` | Base the delay grows by on each attempt                    |
| `jitter_percent` | `20`          | `0` - `100`     | Random spread on the increasing delays, as a percentage    |

The delay before attempt `n` is about `factor * exponent_base^n` milliseconds, spread by `jitter_percent`. With the
defaults that is 200ms, 400ms, 800ms, 1.6s, then 3.2s. A standalone client's first connection gives up after
`num_of_retries` retries or `connection_timeout`, whichever comes first. Once a connection drops, GLIDE retries at
once, then after each of the increasing delays, then keeps retrying at the last delay, `factor *
exponent_base^num_of_retries` milliseconds, without jitter, until the connection comes back.

That last delay must not exceed one hour (3600000ms), with GLIDE's defaults standing in for any key left out. GLIDE
itself accepts any product, saturating one that overflows, so a larger delay would leave a dropped connection waiting
months or years between attempts. An `exponent_base` of `1` gives a constant delay of `factor`.

The key names are the extension's: `num_of_retries`, not the GLIDE core's `number_of_retries`. Values must be integers;
digit-only strings, as read from the environment, are accepted. The extension silently ignores unknown keys and
non-integer values, so the driver throws an `InvalidArgumentException` for them instead, and for values out of range,
when the connection is created.

Set `lazy_connect` to `true` to defer connecting until the first command (GLIDE default: `false`). Booleans and their
environment forms (`'true'`, `'false'`, `'1'`, `'0'`) are accepted.

```php
'default' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => (int) env('REDIS_PORT', 6379),
    'reconnect_strategy' => [
        'num_of_retries' => 8,
        'factor' => 50,
        'jitter_percent' => 20,
    ],
    'lazy_connect' => env('REDIS_LAZY_CONNECT', false),
],
```

For clusters, set both keys on the first node or in the cluster's `options`, like the other connection keys.

## Cluster Connections

Cluster connections use the dedicated `ValkeyGlideCluster` client, which follows cluster topology and slot routing.
Configure a cluster the standard Laravel way:

```php
'redis' => [
    'client' => 'valkey-glide',

    'clusters' => [
        'default' => [
            ['host' => env('REDIS_HOST'), 'port' => (int) env('REDIS_PORT', 6379)],
        ],
    ],
],
```

Connection keys such as `prefix` can be set on the first node, in the shared `clusters.options`, or in an `options`
entry beside the cluster's nodes, which takes precedence over both, as it does for phpredis; on Laravel 13, Horizon sets
its cluster prefix that way. In full, from highest to lowest: the `options` entry beside the nodes, the first node's own
`options`, `clusters.options`, the shared `database.redis.options`, then the first node's own keys. Unlike a standalone
connection, a `prefix` on the first node does not take precedence over the options, as phpredis applies the options'
prefix to a cluster. phpredis ignores the nodes' own keys altogether; this driver keeps applying the first node's keys
and nested `options` as above, so a `prefix` there applies when no options set one.

> [!NOTE]
> In 1.2.x and earlier, an `options` entry listed after a cluster's nodes, as Horizon writes it, was ignored. It is now
> applied, and overrides the first node and `clusters.options`, so a `prefix`, `timeout`, `read_from` or other key set
> there takes effect on upgrade. A `prefix` set there moves the connection to different keys; check it before
> upgrading. An `options` entry listed before the nodes was taken as the first node instead, so its keys applied, but
> the first node's own keys, such as `password` or `username`, were ignored; they now apply.

Raw commands (`EVAL` / `EVALSHA`, phpredis-style `SET` with options) are routed to the primary that owns the affected
key's slot.

`ValkeyGlideCluster::rawcommand()` takes a route before the command, as phpredis' `RedisCluster::rawCommand()` does, and
`rawCommand()` calls are passed to it unchanged. `executeRaw()` takes no route, so on a cluster connection it adds one,
drawn from GLIDE's own routing tables (`base_routing()` in GLIDE's `cluster_routing.rs`):

- Keyed commands go to the primary that owns their first key's slot. That key is the first argument for most commands,
  the destination for `BITOP`, the first declared key for `EVAL`, `EVALSHA`, `FCALL` and the `*MPOP` family, and the
  first stream after `STREAMS` for `XREAD` and `XREADGROUP`.
- Commands that must reach every node, such as `SCRIPT LOAD`, `CONFIG SET`, `FUNCTION LOAD`, `DBSIZE`, `KEYS` and
  `FLUSHDB`, run on every node or every primary, and GLIDE combines the replies.
- Keyless commands, and commands whose key is missing or empty, go to a random node. So do subcommands GLIDE does not
  list, which it routes by the subcommand's name as if it were a key, and commands whose key is not valid UTF-8, which
  a GLIDE route cannot carry; GLIDE follows the node's `MOVED` redirect to the key's owner.

A multi-key raw command is not split across shards. When GLIDE picks the route for `MGET`, `MSET`, `DEL`, `EXISTS`,
`UNLINK`, `TOUCH` and similar commands itself, it sends each shard its own keys and combines the replies, but the
extension has no route that asks for that, so `executeRaw()` sends the whole command to the first key's primary. All of
its keys must share a slot, so give them a common hash tag (`{user:1}:name`, `{user:1}:email`), or the server replies
`CROSSSLOT`.

A raw `SCAN` through `executeRaw()` is refused, because a cursor `SCAN` walks a single node and would miss keys on the
others. Scan a cluster through the connection's `scan()` with Laravel's options array. It scans every primary in turn,
and its cursor is a string, as on a standalone server:

```php
$cursor = '0';

do {
    [$cursor, $keys] = Redis::connection()->scan($cursor, ['match' => 'user:*', 'count' => 100]);

    // ...
} while ($cursor !== '0');
```

Each page reads the primaries with `CLUSTER SLOTS` and sends a plain `SCAN` to one of them by its address, because
GLIDE's own cluster scan aborts the PHP process (`zend_mm_heap corrupted`, in `ext-valkey_glide` 1.1.2 and 1.2.0-rc1)
when a node refuses a page, such as with `NOPERM`. A refused page throws an `UnexpectedValueException` instead, and the
scan can resume from the cursor that page was given, on any connection to the cluster; a cursor not of the scan's shape
throws an `InvalidArgumentException`. A primary whose slots change mid-scan is scanned again from its start. The user
needs `CLUSTER SLOTS` as well as `SCAN`. A primary `CLUSTER SLOTS` reports no usable endpoint for, as under
`cluster-preferred-endpoint-type unknown-endpoint`, cannot be scanned this way and throws an `UnexpectedValueException`.
GLIDE's own `ClusterScanCursor` is still passed to GLIDE unchanged, with the failures above:

```php
$cursor = new ClusterScanCursor;

do {
    $keys = Redis::connection()->scan($cursor, 'user:*', 100);

    // ...

    $cursor = new ClusterScanCursor($cursor->getNextCursor());
} while (!$cursor->isFinished());
```

The `CLUSTER` commands GLIDE sends to the primary that owns a slot number (`COUNTKEYSINSLOT`, `GETKEYSINSLOT`,
`ADDSLOTS`, `DELSLOTS`, `DELSLOTSRANGE` and `SETSLOT`) are refused through `executeRaw()` too, because GLIDE's PHP routes
cannot name a slot, and on any other node they would read or change that node's own slots and keys. Send them through
`rawCommand()` with a route to that primary.

`FLUSHDB` and `FLUSHALL` called with no arguments or a phpredis-style `'ASYNC'` / `'SYNC'` flag (as Laravel's
`Cache::flush()` and `cache:clear` do) run on every primary, so the whole keyspace is cleared. Calls that pass an
explicit GLIDE route are sent unchanged.

`ValkeyGlideCluster::ping()`, `dbSize()`, `randomKey()`, `time()` and `echo()` take a route before their other
arguments and return `false` without one, so a call without one takes the route GLIDE gives the command: a bare
`ping()` (as health checks call it) runs on every primary and returns `true` once each one answers, `dbSize()` counts
the keys on every primary and returns their sum, `randomKey()` returns a key from one of the primaries, and `time()`
and `echo($message)` run on a random node. A call with more arguments takes the first as its route, as phpredis'
`RedisCluster` methods do, and is sent unchanged. Each node reports only itself to `INFO`, so a bare `info()` throws an
`InvalidArgumentException` before anything is sent; pass a route, as in `info('randomNode')` or
`info('randomNode', 'memory')`.

A GLIDE cluster connection's `isCluster()` returns `true`. From Laravel 13.5, the Redis queue and `Redis::funnel()`
check it and wrap a queue or limiter name that has no hash tag in one (`queues:{default}`), so the keys each of their
scripts touches share a slot. Laravel 12 and 13.0 to 13.4 do not, so on those give a cluster queue's name a hash tag
yourself (`'queue' => '{default}'`), as Laravel's queue documentation advises. Without one, every push to the queue,
not only a bulk one, fails with `CROSSSLOT`, and with `ext-valkey_glide` 1.1.2 the push returns `false` rather than
throwing.

### Cache Store

Laravel's `RedisStore::putMany()` writes in one `MULTI`/`EXEC` transaction unless the connection is one of Laravel's own
cluster connections, and a cluster transaction must keep every key in one hash slot: the driver refuses one whose keys
span slots with a `LogicException` before anything is sent (see Cluster Batches). The service provider registers the
`redis` cache driver with a store that writes each value on its own, and so not atomically, over a GLIDE cluster
connection, as Laravel does for its own cluster connections; over any other connection it behaves as Laravel's store,
transaction included (see Pipelines and Transactions). `putMany()` backs `Cache::putMany()`, `setMultiple()` and
`flexible()`. No configuration change is needed.

### Periodic Topology Checks

The cluster client periodically checks the cluster topology so it can follow slot migrations and failovers. Set
`periodic_checks` on a cluster connection to control this (both the string name and the `ValkeyGlideCluster` constant
are accepted):

| Setting                                  | Value | Behavior                                               |
|------------------------------------------|-------|--------------------------------------------------------|
| `enabled_default_configs` (or `enabled`) | `0`   | Periodic checks with GLIDE's default configuration     |
| `disabled`                               | `1`   | No periodic checks; slots refresh on `MOVED` redirects |

When `periodic_checks` is omitted, nothing is passed and the extension's default applies. The option is ignored for
standalone connections, whose `connect()` has no such parameter.

```php
'clusters' => [
    'default' => [
        [
            'host' => env('REDIS_HOST'),
            'port' => (int) env('REDIS_PORT', 6379),
            'periodic_checks' => env('REDIS_PERIODIC_CHECKS', 'disabled'),
        ],
    ],
],
```

> [!WARNING]
> In `ext-valkey_glide` 1.1.2, an unset or `enabled` value sends the GLIDE core a zero-second check interval instead of
> its 60-second default, so every idle cluster client re-checks the topology continuously and burns CPU
> ([valkey-glide-php#344](https://github.com/valkey-io/valkey-glide-php/issues/344)). Until a release includes the fix,
> set `'periodic_checks' => 'disabled'` on cluster connections.

## Cache Tags

`Cache::tags()` works on standalone and cluster connections with no configuration change. The `redis` cache driver's
store returns a tagged cache whose tag upkeep is written for GLIDE; over a phpredis or Predis connection it runs
Laravel's own code unchanged.

- Tagged writes record each key in the tag's sorted set, `tag:<name>:entries`, under the connection and cache
  prefixes. `increment()` and `decrement()` record their key with GLIDE's `['NX']` option; GLIDE ignores phpredis'
  `'NX'` string and writes nothing.
- `flush()` reads each tag's sorted set with `ZSCAN` and deletes the entries 1,000 at a time. On a standalone server
  each batch is deleted, and removed from the tags' sorted sets, in one `EVAL` script, as Laravel's own flush does over
  a standalone phpredis or Predis connection. On a cluster each batch is deleted with one `DEL`, which GLIDE splits
  across hash slots, then removed from each tag's sorted set with `ZREM`. An entry tagged while the flush runs may or
  may not be removed with it; one the flush does not reach stays recorded. A sorted set left empty is removed by the
  server. `CacheFlushing` and `CacheFlushed` are dispatched when the installed Laravel version dispatches them for tags.
- `flushStale()` removes expired entries from each tag's sorted set with `ZREMRANGEBYSCORE`, one command per tag.
- `cache:prune-stale-tags` finds the tag sets with a `SCAN` for sorted sets whose pattern carries the connection and
  cache prefixes, with glob characters in them escaped; on a cluster it scans every primary in turn, as `scan()` does
  (see Cluster Connections).
- None of these opens a pipeline or transaction on the connection (a standalone scan page goes to GLIDE as a pipeline of
  that one command). Called inside one on the store's connection, tagged writes and `flushStale()` join the batch, so
  their replies, failures included, come back in the batch's results rather than as the exception described below.
  `flush()`, reading a tag's entries and `cache:prune-stale-tags` throw a `LogicException` there before anything is
  sent, as `ZSCAN` and `SCAN` are refused in a batch (see Commands GLIDE Cannot Buffer); the cluster `SCAN`, which GLIDE
  would run at once, outside the batch, is refused the same way.

> [!NOTE]
> On a standalone server each batch of a tag flush is atomic: no other command runs between deleting its entries and
> removing their tag records, so a write of a key and its record that lands during the flush is either removed with the
> batch or kept with its record. A tagged write is itself two commands, and Laravel 12 and 13 before 13.30.1 record the
> key before writing the value, so a flush that runs between the two still leaves that value untracked, as it does with
> Laravel's own Lua flush. The script names every key it touches. Unlike Laravel's, it is given the tag members rather
> than stripping the prefix from each key with a Lua pattern, so prefixes with any characters work. A whole flush is not
> atomic, here or in Laravel: it runs one script per batch of 1,000. The script cannot run across hash slots, so on a
> cluster a key written again between a batch's `DEL` and its `ZREM` can lose its tag record, and stays cached until it
> expires. Laravel's own cluster connections instead delete the entries, then each tag's whole sorted set. Either way
> entries are deleted first, so an interrupted flush leaves only records of deleted keys, and running it again completes
> it.

With `ext-valkey_glide` 1.1.2 a failed command returns `false` (see Runtime Behavior), so on these paths, outside a
batch, a `false` reply throws an `UnexpectedValueException` naming the command and key, rather than reporting a partial
flush, a lost tag record or a partial prune as success. This includes server errors that phpredis would let pass, such
as `WRONGTYPE` on a tag's key. From 1.2 the connection throws GLIDE's recorded error instead, as a `CommandException`,
which is an `UnexpectedValueException` too (see Runtime Behavior).

The scans of `flush()` and `cache:prune-stale-tags` read the primary whatever the connection's `read_from`, so an entry
tagged moments before a flush is still flushed.

The connection prefixes the keys these paths use: `ZREMRANGEBYSCORE`, `ZREMRANGEBYRANK`, `ZREVRANGEBYSCORE` and
`ZREVRANK`, and the key (only) of `ZSCAN`, `SSCAN` and `HSCAN`. Earlier releases sent those seven commands unprefixed,
so on a prefixed connection they missed the keys the other commands wrote.

The member scans take Laravel's options array, as Laravel's phpredis connection does, and return the next cursor and
the page, so a loop written for Laravel's connections runs unchanged:

```php
$cursor = '0';

do {
    [$cursor, $members] = Redis::connection()->zscan('leaderboard', $cursor, ['match' => 'user:*', 'count' => 100]);

    // ...
} while ($cursor !== '0');
```

- Start from `'0'` (or `null`) and stop once the cursor is `'0'` again. The cursor is a string, where phpredis gives
  an integer, and `match` (default `*`) and `count` (default 10) are sent as given: the key is prefixed, the pattern,
  which matches members, is not.
- `zscan()` maps each member to its score as a float, as phpredis does, where GLIDE gives a string (`INF` or `-INF`
  for an infinite score); `hscan()` maps each field to its value; `sscan()` lists the members. A page can be empty
  before the scan is complete, so keep going until the cursor is `'0'`.
- A missing key returns `['0', []]`. A scan that fails, such as one of a key of another type, throws an
  `UnexpectedValueException` rather than returning an empty page, as the keyspace `scan()` does.
- On a cluster each member scan runs on a node that holds its key, so its cursor is a plain string; it needs no
  `ClusterScanCursor`.
- Inside a pipeline or transaction they throw a `LogicException` before anything is sent, as `ZSCAN`, `HSCAN` and
  `SSCAN` do (see Commands GLIDE Cannot Buffer).

GLIDE's own form, with the cursor written back by reference, still works through `command()`, and returns each score
as GLIDE gives it, a string:

```php
$cursor = '0';

do {
    $members = Redis::connection()->command('zscan', ['leaderboard', &$cursor, '*', 100]);

    // ...
} while ($cursor !== '0');
```

- The `SCAN` and `ZSCAN` calls dispatch `CommandExecuted` like any command; on a cluster each `SCAN` page dispatches
  one, named `scan`, carrying the cursor it scans from.
- The user running `cache:prune-stale-tags` needs `SCAN` permission. Without it the command throws an
  `UnexpectedValueException`, on a cluster as on a standalone server.
- On a standalone server the user running a tag `flush()` needs `EVAL` permission (the `@scripting` ACL category), as
  with Laravel's own flush over phpredis and Predis. Earlier releases flushed with `DEL` and `ZREM` only, so a user
  denied `EVAL`, or a server with `EVAL` disabled, could flush; now the refused script comes back as `false` and the
  flush throws an `UnexpectedValueException` naming `EVAL` before any entry is deleted. With 1.1.2 GLIDE reports the
  refusal only as `false`, the reply of every other failure, so the flush cannot tell it apart and does not fall back
  to `DEL` and `ZREM`. A flush on a cluster sends no script.

## Laravel Pulse

Pulse's `redis` ingest driver (`PULSE_INGEST_DRIVER=redis`) runs on valkey-glide connections. Pulse's own Redis ingest
only talks to the phpredis, Predis and Relay clients, so when Pulse is installed the service provider wraps it in
`SineMacula\Valkey\Pulse\ValkeyGlideIngest`, which runs Pulse's ingest, digest and trim on the connection
`pulse.ingest.redis.connection` names when that is a valkey-glide connection, and leaves every other connection, ingest
driver and custom `Ingest` binding to Pulse.

The entries go to Pulse's own stream in Pulse's own format, under the shared `database.redis.options.prefix` alone and
never the connection's own prefix, the same physical key Pulse uses over phpredis, so processes on either driver share
it. A command GLIDE fails throws an `UnexpectedValueException`. Pulse's ingest refuses a connection with an open
pipeline or transaction, so if the application batches on that connection, give Pulse its own
(`PULSE_REDIS_CONNECTION`).

## ElastiCache Serverless

AWS ElastiCache Serverless runs in cluster mode (single logical database, TLS required) and exposes the primary on port
6379 and the reader on port 6380. Configure it as a cluster with TLS, and optionally with replica read routing:

```php
'redis' => [
    'client' => 'valkey-glide',

    'clusters' => [
        'default' => [
            [
                'host' => env('REDIS_HOST'),
                'port' => (int) env('REDIS_PORT', 6379),
                'tls' => true,
                'read_from' => 'prefer_replica',
                'timeout' => 3.0,
                'periodic_checks' => 'disabled',
            ],
        ],
    ],
],
```

Keep the database index at `0` - cluster mode exposes only database 0. When using replica read routing, ensure the
security group allows both port 6379 (read/write) and 6380 (read-only).

With `ext-valkey_glide` 1.1.2, idle cluster clients use about 5% of a CPU core each against ElastiCache Serverless
unless periodic checks are disabled (see Periodic Topology Checks). Serverless serves the whole keyspace behind one
endpoint, and GLIDE still refreshes slots when it receives a `MOVED` redirect.

## IAM Authentication

ElastiCache IAM authentication is configured with an `iam` block on the connection (for clusters, on the first node).
GLIDE generates and refreshes the auth token itself, and TLS is enabled automatically:

```php
'iam' => env('REDIS_IAM_USERNAME') ? [
    'username' => env('REDIS_IAM_USERNAME'),
    'cluster_name' => env('REDIS_IAM_CLUSTER_NAME'),
    'region' => env('AWS_DEFAULT_REGION'),
    'refresh_interval' => 300,
] : null,
```

- `username`, `cluster_name`, and `region` are required
- `refresh_interval` (seconds) is optional and defaults to `300`
- IAM credentials take precedence over `password`

An `iam` value of `null` or `[]` is treated as absent, so the block can be switched off per environment as above. Any
other `iam` array that is missing a required key, or holds an empty value for one, throws an `InvalidArgumentException`
naming those keys rather than connecting without IAM credentials.

## Testing

The default suite is deterministic and requires no local Redis server:

```sh
composer test
```

CI runs this suite on the newest Laravel 12 and 13 releases and on the lowest ones `composer.json` allows, 12.45.0 and
13.0.0. Packagist security advisories cover those lowest releases, so Composer blocks installing them by default. Only
the job that tests them stops those advisories blocking `laravel/framework`, to prove the package still works there;
every other job keeps the blocking. Nothing here changes how an application's own Composer treats those releases, and
applications should run a patched one.

The external suite exercises the real `ext-valkey_glide` extension against a live Valkey/Redis server. It is excluded
from the default run and must be invoked explicitly:

```sh
composer test:external
```

External integration coverage includes:

- extension/client availability and connect/close behavior
- connector/connection roundtrip behavior and retry semantics
- command-prefix behavior, including list-style key commands (`MGET`) and the keys of the multi-key commands, on a
  standalone server and a cluster, inside and outside batches
- Laravel cache roundtrip behavior, physical key writes, locks, TTL edge cases, and cache tags on a standalone server
  and a cluster (tagged writes, `flush()`, `flushStale()` and `cache:prune-stale-tags`), including a standalone flush
  racing a second process that rewrites the flushed keys
- Laravel queue push/pop behavior, physical queue key writes, and a blocking pop waiting on the prefixed notify key
- Laravel session roundtrip behavior and physical session key writes
- phpredis-compatible `setnx()`, `hsetnx()`, `zrevrange()` and options-array `scan()`, `zscan()`, `hscan()` and
  `sscan()`, including Laravel's own `RedisTagSet::entries()`, on a standalone server and a cluster
- sorted-set scores, members and weights sent as strings, exact `zincrby()` increments, and Laravel's `zadd()`,
  `zinterstore()` and `zunionstore()` argument forms, on a standalone server and a cluster
- float values, members and score bounds, exact `incrbyfloat()` and `hincrbyfloat()` increments and Laravel cache
  floats, on a standalone server and a cluster
- pipelines and transactions on a standalone server and a cluster: result order, physical prefixed keys, empty and
  nested batches with and without callbacks, `EVAL` in a batch, `WATCH`, cross-slot transactions, closed clients,
  throwing event listeners and a 5,000-command pipeline
- Laravel's `Queue::bulk()`, the pipelined stale-entry flush of Laravel's own `RedisTagSet`, `Cache::putMany()` (a
  transaction on a standalone server, key by key across slots on a cluster), tagged writes and a refused tag flush
  inside a pipeline, and Laravel Horizon's own Redis repositories under the `horizon:` prefix (the Horizon cluster
  cases run on Laravel 13 only, as Horizon supports clusters only there)
- Laravel Pulse's `redis` ingest on a standalone server and a cluster, over connections with the shared prefix and with
  one of their own
- the key prefix a Laravel connection resolves to when the shared options, a connection's own options and the
  connection itself each set one, including an empty prefix and Horizon's connection, read back as physical keys on a
  standalone server and a cluster

The external suite reads connection details from optional environment variables:

- `VALKEY_GLIDE_TEST_HOST`
- `VALKEY_GLIDE_TEST_PORT`
- `VALKEY_GLIDE_TEST_TLS`
- `VALKEY_GLIDE_TEST_USERNAME`
- `VALKEY_GLIDE_TEST_PASSWORD`
- `VALKEY_GLIDE_TEST_CLUSTER_HOST` and `VALKEY_GLIDE_TEST_CLUSTER_PORT`: a seed node of a Valkey/Redis cluster; the
  cluster cases are skipped when either is unset

The cases of a refused cluster scan create their own uniquely named ACL user denied `SCAN` on every node of the
cluster, and delete it after the test; they are skipped when the cluster refuses `ACL SETUSER`.

The TLS and credential variables apply to the standalone server only: the cluster cases connect without TLS or
credentials, so they need a cluster that accepts plain, unauthenticated connections.

CI runs the external suite against a standalone server and a three-node cluster (the `external` job in
`.github/workflows/tests.yml`).

All available Composer scripts:

```sh
composer test                # PHPUnit suite in parallel via Paratest (excludes external)
composer test:external       # external suite against a live Valkey/Redis server
composer test:coverage       # suite with Clover coverage output
composer test:mutation       # Infection mutation gate (min MSI 90)
composer test:mutation:full  # full mutation suite without thresholds
composer check               # static analysis and lint via qlty
composer format              # format via qlty
composer smells              # duplication / complexity smells via qlty
composer bench               # PHPBench suite over the command / config hot paths
composer bench:ci            # PHPBench with CI artifact dump
composer bench:smoke         # single-rev pass to verify every subject runs
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of notable changes.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on branching, commits, code
quality, and pull requests.

## Security

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for the
disclosure policy and contact details.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
