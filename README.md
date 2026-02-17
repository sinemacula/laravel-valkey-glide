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

Run checks and tests:

```sh
composer check -- --all --no-cache --fix
composer test
composer test-coverage
composer format
```

## Notes

Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna
aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

## Contributing

Contributions are welcome and will be fully credited. We accept contributions via pull requests on GitHub.

## Security

If you discover any security related issues, please email instead of using the issue tracker.
