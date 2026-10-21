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
- Key prefix compatibility for supported command families, including key-list handling for `MGET`
- Laravel compatibility fallback for phpredis-style `SET` and `EVAL` command argument shapes
- Cache store for the `redis` driver that keeps `putMany()` safe on cluster connections and runs cache tags on
  standalone and cluster connections
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

    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'database' => (int) env('REDIS_DB', 0),
        'name' => env('REDIS_CLIENT_NAME'),
        'prefix' => env('REDIS_PREFIX', ''),
        'tls' => env('REDIS_TLS', false),
    ],
],
```

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
- Non-idempotent commands are not retried
- Laravel command events are dispatched (`CommandExecuted`, `CommandFailed`); a cluster `scan()` reports the id its
  `ClusterScanCursor` scans from in place of the cursor, so listeners can turn the parameters into strings
- `executeRaw()` is supported via `rawcommand`, with a route added on cluster connections (see Cluster Connections)
- Phpredis-style `SET`/`EVAL` argument shapes are normalized through `rawcommand`
- `disconnect()` delegates to GLIDE `close()`

With `ext-valkey_glide` 1.1.2, most commands that fail return `false` rather than throwing, including while a node is
unreachable and GLIDE is reconnecting (`Connection in recovery`); the extension prints the error to stderr. The driver
cannot tell that `false` from a reply, so it passes it on: a cache `get()` reads it as a miss, and a single write or lock
acquisition reports failure. Laravel's `putMany()` on a standalone connection queues its writes in a transaction and
does not check the transaction's result, so it can still report success. The retry above applies only to errors the
extension throws. `getLastError()`, which the extension gains after 1.1.2 (in the 1.2.0 release candidates), is what
would let the driver raise these as exceptions.

## Prefix Compatibility

Prefixing is handled in the connection wrapper for supported command families:

- single-key commands (`GET`, `SET`, `HGET`, etc.)
- all-key commands (`MGET`, `DEL`, etc.)
- two-key commands (`RENAME`, etc.)
- `EVAL` / `EVALSHA` key segments

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
read the tags' sorted sets the same way (see Cache Tags), so a cache store whose flushes must see the latest tagged
writes should use a connection whose `read_from` is `primary`.

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
others. Scan a cluster with GLIDE's `ClusterScanCursor` through the connection's `scan()`:

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

`ValkeyGlideCluster::ping()` takes a route before its optional message and returns `false` without one, so a bare
`ping()` (as health checks call it) runs on every primary instead and returns `true` once each one answers. A `ping()`
with arguments takes the first as its route, as phpredis' `RedisCluster::ping()` does, and is sent unchanged.

A GLIDE cluster connection's `isCluster()` returns `true`. From Laravel 13.5, the Redis queue and `Redis::funnel()`
check it and wrap a queue or limiter name that has no hash tag in one (`queues:{default}`), so the keys each of their
scripts touches share a slot. Laravel 12 and 13.0 to 13.4 do not, so on those give a cluster queue's name a hash tag
yourself (`'queue' => '{default}'`), as Laravel's queue documentation advises.

### Cache Store

Laravel's `RedisStore::putMany()` writes in one `MULTI`/`EXEC` transaction unless the connection is one of Laravel's own
cluster connections, and a cluster refuses a transaction whose keys hash to different slots (`CROSSSLOT`) while
`putMany()` still reports success. The service provider registers the `redis` cache driver with a store that writes
each value on its own over a GLIDE cluster connection, as Laravel does for its own cluster connections; over any other
connection it behaves as Laravel's store, transaction included. `putMany()` backs `Cache::putMany()`, `setMultiple()`
and `flexible()`. No configuration change is needed.

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
- `flush()` reads each tag's sorted set with `ZSCAN`, deletes the entries 1,000 at a time with one `DEL` (on a cluster
  GLIDE splits it across hash slots), then removes those entries from the tags' sorted sets with `ZREM`. An entry
  tagged while the flush runs may or may not be removed with it; one the flush does not reach stays recorded. A sorted
  set left empty is removed by the server. `CacheFlushing` and `CacheFlushed` are dispatched when the installed Laravel
  version dispatches them for tags.
- `flushStale()` removes expired entries from each tag's sorted set with `ZREMRANGEBYSCORE`, one command per tag.
- `cache:prune-stale-tags` finds the tag sets with a `SCAN` for sorted sets whose pattern carries the connection and
  cache prefixes, with glob characters in them escaped; on a cluster it walks every primary with a `ClusterScanCursor`.

> [!NOTE]
> A tag flush is not atomic. Over a standalone phpredis or Predis connection Laravel 12.30 and later delete each batch
> of entries and their tag records in one Lua script, which cannot run across hash slots. Here each batch is a `DEL`
> followed by a `ZREM` per tag, so a key written again between the two can lose its tag record. Laravel's own cluster
> connections instead delete the entries, then each tag's whole sorted set. Entries are deleted first, so an interrupted
> flush leaves only records of deleted keys, and running it again completes it.

With `ext-valkey_glide` 1.1.2 a failed command returns `false` (see Runtime Behavior), so on these paths a `false` reply
throws an `UnexpectedValueException` naming the command and key, rather than reporting a partial flush, a lost tag
record or a partial prune as success. This includes server errors that phpredis would let pass, such as `WRONGTYPE` on
a tag's key.

> [!NOTE]
> `flush()` reads the tags' sorted sets with `ZSCAN`, which follows the connection's `read_from` (see Read Routing).
> With replica reads, an entry tagged within the replication lag before a flush can be missing from the scan, so it
> stays cached until it expires or the tag is flushed again. If a flush must remove entries tagged moments earlier,
> give the cache store a connection whose `read_from` is `primary`.

The connection prefixes the keys these paths use: `ZREMRANGEBYSCORE`, `ZREMRANGEBYRANK`, `ZREVRANGEBYSCORE` and
`ZREVRANK`, and the key (only) of `ZSCAN`, `SSCAN` and `HSCAN`. Earlier releases sent those seven commands unprefixed,
so on a prefixed connection they missed the keys the other commands wrote. A member scan writes its cursor back by
reference, so call it through `command()` with a reference in the argument list:

```php
$cursor = '0';

do {
    $members = Redis::connection()->command('zscan', ['leaderboard', &$cursor, '*', 100]);

    // ...
} while ($cursor !== '0');
```

The phpredis-style `zscan($key, $cursor, $options)` shape is not supported.

- The `SCAN` and `ZSCAN` calls dispatch `CommandExecuted` like any command. On a cluster the event carries the id the
  `ClusterScanCursor` scans from, not the cursor itself.
- The user running `cache:prune-stale-tags` needs `SCAN` permission. On a cluster, with 1.1.2 a `SCAN` refused by an
  ACL aborts the PHP process; on a standalone server the refusal comes back as `false` and the command throws an
  `UnexpectedValueException`.

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

The external suite exercises the real `ext-valkey_glide` extension against a live Valkey/Redis server. It is excluded
from the default run and must be invoked explicitly:

```sh
composer test:external
```

External integration coverage includes:

- extension/client availability and connect/close behavior
- connector/connection roundtrip behavior and retry semantics
- command-prefix behavior, including list-style key commands (`MGET`)
- Laravel cache roundtrip behavior, physical key writes, locks, TTL edge cases, and cache tags on a standalone server
  and a cluster (tagged writes, `flush()`, `flushStale()` and `cache:prune-stale-tags`)
- Laravel queue push/pop behavior and physical queue key writes
- Laravel session roundtrip behavior and physical session key writes

The external suite reads connection details from optional environment variables:

- `VALKEY_GLIDE_TEST_HOST`
- `VALKEY_GLIDE_TEST_PORT`
- `VALKEY_GLIDE_TEST_TLS`
- `VALKEY_GLIDE_TEST_USERNAME`
- `VALKEY_GLIDE_TEST_PASSWORD`
- `VALKEY_GLIDE_TEST_CLUSTER_HOST` and `VALKEY_GLIDE_TEST_CLUSTER_PORT`: a seed node of a Valkey/Redis cluster; the
  cluster cases are skipped when either is unset

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
