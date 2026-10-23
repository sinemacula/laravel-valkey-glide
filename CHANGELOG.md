# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres
to [Semantic Versioning](https://semver.org/).

## [1.3.0](https://github.com/sinemacula/laravel-valkey-glide/compare/v1.2.0...v1.3.0) (2026-10-23)


### Features

* run laravel pulse's redis ingest on valkey-glide connections ([#110](https://github.com/sinemacula/laravel-valkey-glide/issues/110)) ([5e66120](https://github.com/sinemacula/laravel-valkey-glide/commit/5e661207026abf3225e6512e7d4f9bb1846a0587))
* support pipelines and transactions on valkey-glide connections ([#97](https://github.com/sinemacula/laravel-valkey-glide/issues/97)) ([4f832cd](https://github.com/sinemacula/laravel-valkey-glide/commit/4f832cd9dc7482cd38f9de6d1173dda6f5dfafd2))


### Bug Fixes

* add zincrby increments exactly and take laravel's sorted-set forms ([#105](https://github.com/sinemacula/laravel-valkey-glide/issues/105)) ([776e717](https://github.com/sinemacula/laravel-valkey-glide/commit/776e7173283bb4d6407ea338ac3ca7a4e367a1b0))
* default the connection timeout to two seconds ([#121](https://github.com/sinemacula/laravel-valkey-glide/issues/121)) ([f01a2c5](https://github.com/sinemacula/laravel-valkey-glide/commit/f01a2c5c5c5169de0ad1d4495bf1fa191a3d8dbc))
* flush tagged caches on valkey-glide connections ([#90](https://github.com/sinemacula/laravel-valkey-glide/issues/90)) ([ff8373a](https://github.com/sinemacula/laravel-valkey-glide/commit/ff8373a51dd9476f6b3f0b929bd36e93fadbc12c))
* give a standalone connection's own prefix precedence over its options ([#115](https://github.com/sinemacula/laravel-valkey-glide/issues/115)) ([c76c3e2](https://github.com/sinemacula/laravel-valkey-glide/commit/c76c3e2a99e71e790716f656334bab6a4ab407e0))
* hash-tag cluster queue keys before laravel 13.5 ([#117](https://github.com/sinemacula/laravel-valkey-glide/issues/117)) ([2b6d080](https://github.com/sinemacula/laravel-valkey-glide/commit/2b6d08059f5c2e30692e0bd66c5f7e7984a58251))
* keep nested transactions and queue bulk pushes atomic ([#112](https://github.com/sinemacula/laravel-valkey-glide/issues/112)) ([9e3e863](https://github.com/sinemacula/laravel-valkey-glide/commit/9e3e8637547f4e3d91b999029209cd5296251866))
* keep the redis cache store's putMany safe on cluster connections ([#88](https://github.com/sinemacula/laravel-valkey-glide/issues/88)) ([d3c514d](https://github.com/sinemacula/laravel-valkey-glide/commit/d3c514d089be6f581f2040b3f270d218435529a3))
* make standalone tag flushes atomic per chunk ([#109](https://github.com/sinemacula/laravel-valkey-glide/issues/109)) ([a98e762](https://github.com/sinemacula/laravel-valkey-glide/commit/a98e762ea74c8caa339439fd4ff06f7f37120409))
* prefix the destination keys, key lists and stream maps of multi-key commands ([#107](https://github.com/sinemacula/laravel-valkey-glide/issues/107)) ([957a654](https://github.com/sinemacula/laravel-valkey-glide/commit/957a654cbf8720b78d08d2771b9e7726b9354a29))
* read the cache tag upkeep's scans from the primary ([#111](https://github.com/sinemacula/laravel-valkey-glide/issues/111)) ([e6d14f5](https://github.com/sinemacula/laravel-valkey-glide/commit/e6d14f53f77d78894d3b1c1fa8b51d2b90205008))
* reconnect after disconnect() on the next command ([#101](https://github.com/sinemacula/laravel-valkey-glide/issues/101)) ([bdf16a4](https://github.com/sinemacula/laravel-valkey-glide/commit/bdf16a43a93bf50d22fedf4b3732945bed06fc70))
* register the redis driver on a redis manager resolved before the provider ([#92](https://github.com/sinemacula/laravel-valkey-glide/issues/92)) ([8d72c08](https://github.com/sinemacula/laravel-valkey-glide/commit/8d72c087137cd01bf9fa57a6a129dd4e5b1be9c3))
* report cluster connections through isCluster() ([#94](https://github.com/sinemacula/laravel-valkey-glide/issues/94)) ([6751b97](https://github.com/sinemacula/laravel-valkey-glide/commit/6751b971d5583859ca0f0efbbb4a3ca60cf5edb1))
* report the cluster scan cursor id in redis command events ([#95](https://github.com/sinemacula/laravel-valkey-glide/issues/95)) ([b3f2529](https://github.com/sinemacula/laravel-valkey-glide/commit/b3f2529bef3e0d0f3b38bfd4e5bfce389b57bfae))
* require laravel 12.45 or later ([#96](https://github.com/sinemacula/laravel-valkey-glide/issues/96)) ([87234ac](https://github.com/sinemacula/laravel-valkey-glide/commit/87234ace5eeb6fc9bdb2ef50cbb8868a67f74690))
* return hsetnx as an integer and accept laravel's options in member scans ([#104](https://github.com/sinemacula/laravel-valkey-glide/issues/104)) ([b714ccb](https://github.com/sinemacula/laravel-valkey-glide/commit/b714ccb695400b383cb38fe23c5cb5d334681906))
* return setnx as an integer, add zrevrange and accept laravel's scan options ([#93](https://github.com/sinemacula/laravel-valkey-glide/issues/93)) ([27534c6](https://github.com/sinemacula/laravel-valkey-glide/commit/27534c68600186ae855d704cf5f387efdfdc66a2))
* route a bare ping() to every primary on cluster connections ([#91](https://github.com/sinemacula/laravel-valkey-glide/issues/91)) ([b40928f](https://github.com/sinemacula/laravel-valkey-glide/commit/b40928f40e18d8c374ae69962db9d558efb9df73))
* route dbsize, randomkey, time and echo on cluster connections ([#100](https://github.com/sinemacula/laravel-valkey-glide/issues/100)) ([1948b5a](https://github.com/sinemacula/laravel-valkey-glide/commit/1948b5a0000a5e1cab1f72efb3a75b9ab8b0f2dd))
* run horizon on valkey-glide clusters before laravel 13.5 ([#113](https://github.com/sinemacula/laravel-valkey-glide/issues/113)) ([28a084f](https://github.com/sinemacula/laravel-valkey-glide/commit/28a084fb669f74524264c3d919a49b4a1f3eadf4))
* scan cluster primaries with routed scan instead of glide's cluster scan ([#108](https://github.com/sinemacula/laravel-valkey-glide/issues/108)) ([e3e5360](https://github.com/sinemacula/laravel-valkey-glide/commit/e3e5360d2a54d92de2cd934387d3373f48990e6a))
* send float arguments to glide as phpredis sends them ([#116](https://github.com/sinemacula/laravel-valkey-glide/issues/116)) ([081f231](https://github.com/sinemacula/laravel-valkey-glide/commit/081f2310af4ad207c558698b3d72b2f6bc26ade0))
* send sorted-set scores, members and weights to glide as strings ([#99](https://github.com/sinemacula/laravel-valkey-glide/issues/99)) ([57e7577](https://github.com/sinemacula/laravel-valkey-glide/commit/57e7577747d6413670f85edd526270f27d7451eb))
* support keys() on valkey-glide connections for laravel's queue inspection ([#114](https://github.com/sinemacula/laravel-valkey-glide/issues/114)) ([ca24bf6](https://github.com/sinemacula/laravel-valkey-glide/commit/ca24bf638c6cc470e2e49ad4803f00cfa3be43e1))
* throw the errors glide records for failed commands ([#106](https://github.com/sinemacula/laravel-valkey-glide/issues/106)) ([cc0e04c](https://github.com/sinemacula/laravel-valkey-glide/commit/cc0e04c89ab002860392757d2fbac6fdf3776367))
* unwatch a watch left over when an unwatch fails ([#98](https://github.com/sinemacula/laravel-valkey-glide/issues/98)) ([94b0a16](https://github.com/sinemacula/laravel-valkey-glide/commit/94b0a16ee6c01bcebdd81a4cd2bf16e86be74422))

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
