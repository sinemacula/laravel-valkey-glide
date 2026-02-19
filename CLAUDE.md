# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laravel Valkey GLIDE — a Sine Macula package providing a Laravel-native Redis driver backed by `ext-valkey_glide`.
Enables Laravel cache, queue, session, and direct Redis usage through Valkey GLIDE with resilient reconnect behavior
during transient disconnects.

This is an **integration layer only** — it must not become a generic Valkey client, infrastructure tool, or Laravel
fork.

## Commands

```bash
composer install                              # Install dependencies
composer check -- --all --no-cache --fix      # Lint + static analysis + auto-fix (via qlty)
composer check                                # Lint + static analysis without auto-fix
composer format                               # Auto-format code (via qlty)
composer test                                 # Run tests in parallel (paratest)
composer test-coverage                        # Tests with Clover coverage output
vendor/bin/phpunit tests/Unit/Support/ConfigTest.php                    # Single test file
vendor/bin/phpunit --filter testMethodName tests/Unit/Support/ConfigTest.php  # Single test method
```

## Architecture

**Namespace:** `SineMacula\Valkey\` → `src/`, **Tests:** `Tests\` → `tests/`

The package has four components wired through Laravel's service container:

1. **RedisServiceProvider** — Registers the `valkey-glide` driver with Laravel's `RedisManager` via `extend()`. Uses
   `resolving()` hook to handle both eager and lazy Redis manager instantiation.

2. **ValkeyGlideConnector** — Implements `Illuminate\Contracts\Redis\Connector`. Builds `ValkeyGlideConnection`
   instances from Laravel Redis config arrays. Validates extension availability, sets up credentials (
   password/username/IAM), selects database, and rejects unsupported prefix config. Accepts injectable `$clientFactory`,
   `$extensionLoader`, and `$classResolver` callables for testing.

3. **ValkeyGlideConnection** — Extends `Illuminate\Redis\Connections\Connection` (`@mixin \ValkeyGlide`). Wraps commands
   with a single-retry path: only idempotent read commands (GET, MGET, HGETALL, EXISTS, etc.) are retried after
   transient connection errors (connection reset, broken pipe, readonly failover, etc.). Retry uses configurable
   backoff (default 25ms + up to 15ms jitter). Accepts injectable `$sleepCallback` and `$randomIntGenerator` for
   deterministic testing.

4. **Config** — Static utility for merging Laravel config arrays, normalizing addresses/ports, resolving credentials,
   building `connect()` arguments, and resolving ValkeyGlide extension constants with fallback defaults.

**Key constraint:** Redis key prefixing is not supported by `ext-valkey_glide`. Non-empty `prefix` config throws
`ConnectionException`.

## Testing

- **Unit tests** (`tests/Unit/`): No external dependencies. Use `FakeValkeyGlide` (extends `\ValkeyGlide`) with response
  queueing and call tracking.
- **Integration tests** (`tests/Integration/`): Use `orchestra/testbench` for Laravel application context.
- Test support classes live in `tests/Support/` (`FakeValkeyGlide`, `FakeRedisManager`, `FakeApplication`).
- Coverage excludes `src/Exceptions/`.

## Code Standards

- `declare(strict_types = 1)` on every file
- PHPStan level 8 (maximum strictness)
- PSR-12 + custom rules via PHP-CS-Fixer and PHPCS (config in `.qlty/configs/`)
- Prefer `final class` and `private` methods; use `#[\Override]` on overridden methods
- Nesting limit: 1 level deep (exceptionally 2); use `match` over `if/else if` chains
- Array types always specify structure: `array<string, mixed>`
- Docblocks required for public methods with `@param`, `@return`, `@throws`

## AGENTS.md Workflow

AGENTS.md defines a mandatory skill chain for PHP changes. For any code change, follow in order: self-review → test
author → complexity refactor → data contract normalizer → naming normalizer → style enforcer → documenter → attribute
enricher → quality remediator → tests. If quality remediator changes code, rerun the chain (max 3 passes). Manual
approval required for quality suppression, static analysis config changes, or breaking changes.

## Git Conventions

- Trunk-based: all branches from `master`, merge via PR, no direct pushes to `master`
- Branch naming: `feature/issue-123-short-description`, `bugfix/...`, `hotfix/...`, `refactor/...`
- Conventional Commits; reference GitHub issues (`Refs #123`, `Closes #123`)
- Never mention AI tools in commits or code comments
