# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres
to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-06-18

Initial release of `sinemacula/laravel-valkey-glide` - a Laravel-native Redis driver and adapter for the
Valkey GLIDE client (`ext-valkey_glide`).

### Added

- Service provider that registers the `valkey-glide` Redis client driver via `RedisManager::extend()`.
- Connector implementing `Illuminate\Contracts\Redis\Connector` for single-node and cluster-style configuration.
- Connection wrapper extending `Illuminate\Redis\Connections\Connection`, dispatching the `CommandExecuted` and
  `CommandFailed` events.
- Normalization of Laravel Redis configuration into GLIDE `connect()` arguments, including TLS, ACL credentials,
  IAM credentials, database selection, and client naming.
- Optional single retry for idempotent commands on transient transport errors.
- Key-prefix compatibility for single-key, all-key, two-key, and `EVAL`/`EVALSHA` command families, including
  key-list handling for `MGET`.
- Laravel compatibility fallback for phpredis-style `SET` and `EVAL` command argument shapes via `rawcommand`.
- External test lane for extension availability and live Valkey/Redis connectivity validation.

[1.0.0]: https://github.com/sinemacula/laravel-valkey-glide/releases/tag/v1.0.0
