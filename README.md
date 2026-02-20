# Laravel Valkey GLIDE Driver

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-valkey-glide.svg)](https://packagist.org/packages/sinemacula/laravel-valkey-glide)
[![Build Status](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/tests.yml)
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
- Key prefix compatibility for supported command families
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

Cluster-style configs are normalized into seed `addresses` using `connectToCluster()`.

## Runtime Behavior

Command execution goes through `ValkeyGlideConnection::command()`.

- Idempotent commands can retry once on recognized transient connection errors
- Non-idempotent commands are not retried
- Laravel command events are dispatched (`CommandExecuted`, `CommandFailed`)
- `executeRaw()` is supported via `rawcommand`
- `disconnect()` delegates to GLIDE `close()`

## Prefix Compatibility

Prefixing is handled in the connection wrapper for supported command families:

- single-key commands (`GET`, `SET`, `HGET`, etc.)
- all-key commands (`MGET`, `DEL`, etc.)
- two-key commands (`RENAME`, etc.)
- `EVAL` / `EVALSHA` key segments

## Testing

Default tests (deterministic, no local Redis required):

```sh
composer test
```

Coverage report:

```sh
composer test-coverage
```

External extension/Redis tests:

```sh
composer test-external
```

Optional env vars for external tests:

- `VALKEY_GLIDE_TEST_HOST`
- `VALKEY_GLIDE_TEST_PORT`
- `VALKEY_GLIDE_TEST_TLS`
- `VALKEY_GLIDE_TEST_USERNAME`
- `VALKEY_GLIDE_TEST_PASSWORD`

## Development

```sh
composer install
composer format
composer check -- --all --no-cache --fix
composer test
```

## Contributing

Contributions are welcome via GitHub pull requests.

## Security

If you discover a security issue, please contact Sine Macula directly rather than opening a public issue.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
