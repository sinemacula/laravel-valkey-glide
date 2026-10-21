# Contributing

Contributions are welcome via GitHub pull requests. This guide covers the expectations for working on this package.

## Requirements

- PHP 8.3+
- Composer 2
- `ext-pcntl` and `ext-posix`, which the `laravel/horizon` development dependency requires; where they are missing,
  install with `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`; the tests do not
  call them

## Getting Started

```bash
git clone git@github.com:sinemacula/laravel-valkey-glide.git
cd laravel-valkey-glide
composer install
```

## Development Workflow

### Branching

Branch from `master` using the appropriate prefix:

| Prefix      | Purpose                          |
|-------------|----------------------------------|
| `feature/`  | New functionality                |
| `bugfix/`   | Bug fixes                        |
| `hotfix/`   | Urgent production fixes          |
| `refactor/` | Refactoring without new features |
| `chore/`    | Tooling, CI, dependencies        |

### Commits

This project uses [Conventional Commits](https://www.conventionalcommits.org/). Prefix your commit messages accordingly:

```text
feat: add IAM credential support to the connector
fix: preserve the configured prefix on MGET key lists
test: cover the transient-error retry path
chore: update qlty configuration
```

### Code Quality

All code must pass static analysis before submission:

```bash
composer check    # Static analysis and lint checks via qlty (PHPStan, PHP-CS-Fixer, CodeSniffer)
composer format   # Format the codebase via qlty
composer smells   # Advisory code smells (duplication, complexity)
```

### Testing

Run the full test suite before submitting:

```bash
composer test            # Run the test suite in parallel using Paratest
composer test:coverage   # With clover coverage report
```

Single test file or method:

```bash
vendor/bin/phpunit tests/Unit/Support/ConfigTest.php
vendor/bin/phpunit --filter connectArgumentsEnablesTlsWhenConfigured tests/Unit/Support/ConfigTest.php
```

The external suite (`composer test:external`) needs `ext-valkey_glide` and a live Valkey server; see the README's
Testing section for its environment variables. CI runs it on every pull request, against a standalone server and a
cluster, so changes that touch the extension's behaviour should add or update an external test.

### Standards

- PHPStan level 8 compliance
- Full type hints on all public method parameters and return types
- PHPDoc on all methods and classes
- New code is expected to ship with tests covering the behavioural surface; the package's mutation-testing gate
  (`composer test:mutation`) is the enforced floor

## Pull Requests

- Keep changes minimal and scoped to a single concern
- Do not change static analysis or formatting configuration without prior discussion
- Include tests for new or changed behaviour
- Ensure `composer check` and `composer test` pass

## Security

If you discover a security vulnerability, please report it directly to Sine Macula rather than opening a public issue.
See [SECURITY.md](SECURITY.md) for details.

## License

By contributing, you agree that your contributions will be licensed under the [Apache License 2.0](LICENSE).
