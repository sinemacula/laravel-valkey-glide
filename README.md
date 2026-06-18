# Laravel Valkey GLIDE Driver

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-valkey-glide.svg)](https://packagist.org/packages/sinemacula/laravel-valkey-glide)
[![Build Status](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/maintainability)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/coverage)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-valkey-glide.svg)](https://packagist.org/packages/sinemacula/laravel-valkey-glide)

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
- External test lane for extension and real Redis connectivity validation

## Requirements

- PHP `^8.3`
- Laravel components with `illuminate/redis` `^11.0 || ^12.0`
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
- `iam` block -> IAM credentials + `use_tls=true`
- `database` -> `database_id`
- `name` -> `client_name`
- `read_from` -> `read_from` (replica read routing; see Read Routing)
- `client_az` -> `client_az` (availability zone for AZ-affinity routing)
- `context` -> `context` (TLS stream context; array or resource)
- `timeout` (seconds) -> `request_timeout` (milliseconds)
- `connection_timeout` (seconds) -> `advanced_config.connection_timeout` (milliseconds)

Cluster-style configs are normalized into seed `addresses` and connected through the dedicated `ValkeyGlideCluster`
client via `connectToCluster()`.

## Runtime Behavior

Command execution goes through `ValkeyGlideConnection::command()`.

- Idempotent commands can retry once on recognized transient connection errors
- Non-idempotent commands are not retried
- Laravel command events are dispatched (`CommandExecuted`, `CommandFailed`)
- `executeRaw()` is supported via `rawcommand`
- Phpredis-style `SET`/`EVAL` argument shapes are normalized through `rawcommand`
- `disconnect()` delegates to GLIDE `close()`

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
            ],
        ],
    ],
],
```

Keep the database index at `0` - cluster mode exposes only database 0. When using replica read routing, ensure the
security group allows both port 6379 (read/write) and 6380 (read-only).

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
- Laravel cache roundtrip behavior, physical key writes, locks, and TTL edge cases
- Laravel queue push/pop behavior and physical queue key writes
- Laravel session roundtrip behavior and physical session key writes

The external suite reads connection details from optional environment variables:

- `VALKEY_GLIDE_TEST_HOST`
- `VALKEY_GLIDE_TEST_PORT`
- `VALKEY_GLIDE_TEST_TLS`
- `VALKEY_GLIDE_TEST_USERNAME`
- `VALKEY_GLIDE_TEST_PASSWORD`

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
