# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres
to [Semantic Versioning](https://semver.org/).

## [1.2.0](https://github.com/sinemacula/laravel-valkey-glide/compare/v1.1.1...v1.2.0) (2026-10-21)


### Features

* close replaced clients on reconnect and expose GLIDE's reconnect strategy ([#82](https://github.com/sinemacula/laravel-valkey-glide/issues/82)) ([d72a0f6](https://github.com/sinemacula/laravel-valkey-glide/commit/d72a0f6aa715b7da21b5118e6e6b1dc00649be5d))
* let cluster connections configure periodic topology checks ([#78](https://github.com/sinemacula/laravel-valkey-glide/issues/78)) ([bf750cb](https://github.com/sinemacula/laravel-valkey-glide/commit/bf750cb619b9d03229ba215ddba83caa264cdaa9))


### Bug Fixes

* reject an incomplete iam block instead of dropping IAM auth ([#84](https://github.com/sinemacula/laravel-valkey-glide/issues/84)) ([85f0cb4](https://github.com/sinemacula/laravel-valkey-glide/commit/85f0cb43b5a34b0c8aedf175990b2e28ac58416c))
* route executeRaw on cluster connections ([#80](https://github.com/sinemacula/laravel-valkey-glide/issues/80)) ([86a43d4](https://github.com/sinemacula/laravel-valkey-glide/commit/86a43d46bebafeddadb173aecdaa2464703ffa32))

## [1.1.1](https://github.com/sinemacula/laravel-valkey-glide/compare/v1.1.0...v1.1.1) (2026-10-17)


### Bug Fixes

* run FLUSHDB and FLUSHALL on every cluster primary ([#72](https://github.com/sinemacula/laravel-valkey-glide/issues/72)) ([9d88483](https://github.com/sinemacula/laravel-valkey-glide/commit/9d88483b001ea43fa3a4c3e36cd078636dd16dab))

## [1.1.0](https://github.com/sinemacula/laravel-valkey-glide/compare/v1.0.0...v1.1.0) (2026-07-22)


### Features

* support Laravel 13 ([#39](https://github.com/sinemacula/laravel-valkey-glide/issues/39)) ([76842bb](https://github.com/sinemacula/laravel-valkey-glide/commit/76842bbf8c3986555a0620e13b276969844e7298))

## 1.0.0 (2026-06-18)


### Features

* cluster client support and read-routing passthroughs ([#15](https://github.com/sinemacula/laravel-valkey-glide/issues/15)) ([27efb9e](https://github.com/sinemacula/laravel-valkey-glide/commit/27efb9ed3300bb1dc84ae30f43f5632795cd7cdd))
