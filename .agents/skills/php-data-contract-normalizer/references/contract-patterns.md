# Contract Patterns

Use these patterns as defaults unless the task explicitly requires a contract change.

## DTO Pattern: Direct Mapping and Stable Keys

- `fromArray()` maps known keys directly into constructor parameters.
- Optional shape guards are narrow and explicit.
- `toArray()` returns stable public keys and normalized scalar values.

## VO Pattern: `from()` Normalizes, `make()` Validates

- `from()` delegates to the project's normalizer for raw input handling.
- `from()` returns `null` when normalization yields no valid value.
- `from()` delegates successful normalization to `make()`.
- `make()` enforces invariants and throws the project's validation exception type.

## VO Pattern: Context Pass-Through

- Pass `$context` into the normalizer when supported (for example, locale or region context).
- Do not remove context parameters to satisfy signature warnings without dependency verification.

## VO Pattern: Fail Fast by Type

- Keep `make()` strict on accepted types.
- Do not add hidden coercion layers that mask invalid upstream calls.

## Test Coverage Pattern

- DTO tests should cover `fromArray()` -> `toArray()` round-trip behavior.
- VO tests should cover both strict `make()` validation and `from()` normalization behavior.
- Any contract adjustment should include matching test-shape updates.
