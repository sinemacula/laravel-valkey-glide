# Laravel Valkey GLIDE Driver

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-valkey-glide.svg)](https://packagist.org/packages/sinemacula/laravel-valkey-glide)
[![Build Status](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-valkey-glide/actions/workflows/tests.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-valkey-glide/coverage)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-valkey-glide.svg)](https://packagist.org/packages/sinemacula/laravel-valkey-glide)

Temporary repository documentation for the Sine Macula Laravel Valkey GLIDE integration package. This README will be
expanded as the package implementation stabilizes.

## Purpose

This package provides a Laravel-native Redis client driver backed by `ext-valkey_glide`.
It is intended to let Laravel cache, queue, session, and Redis usage run through Valkey GLIDE with resilient
reconnect behavior.

## Current Status

The repository is in early scaffolding. Public APIs and configuration details are still evolving.

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna
aliqua.

## Installation (Planned)

```sh
composer require sinemacula/laravel-valkey-glide
```

Requirements:

- PHP 8.3+
- `ext-valkey_glide`
- Laravel with Redis support

## Laravel Configuration (Draft)

Set your Redis client to `valkey-glide` in environment configuration:

```dotenv
REDIS_CLIENT=valkey-glide
```

Further configuration examples will be added once connector and adapter classes are implemented.

## Development

Install dependencies:

```sh
composer install
```

Run checks and default tests:

```sh
composer check -- --all --no-cache --fix
composer test
composer test-coverage
composer format
```

## Testing

Default test runs are deterministic and do not require a local Redis/Valkey server:

```sh
composer test
```

Live extension tests are opt-in and intended for CI or explicit local validation:

```sh
VALKEY_GLIDE_LIVE_TESTS=1 composer test-live
```

Optional live network checks require environment configuration:

- `VALKEY_GLIDE_TEST_HOST`
- `VALKEY_GLIDE_TEST_PORT`
- `VALKEY_GLIDE_TEST_TLS`
- `VALKEY_GLIDE_TEST_USERNAME`
- `VALKEY_GLIDE_TEST_PASSWORD`

## Notes

- Prefix compatibility is applied in the Laravel connection wrapper for supported command families.
- Live tests are excluded from `composer test` by default.

## Contributing

Contributions are welcome and will be fully credited. We accept contributions via pull requests on GitHub.

## Security

If you discover any security related issues, please email instead of using the issue tracker.
