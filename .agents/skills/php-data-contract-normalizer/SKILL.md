---
name: php-data-contract-normalizer
description: >
  Normalize DTO hydration and VO normalization patterns so behavior stays
  explicit, fail-fast, and contract-stable without hidden coercion. Use when
  DTO/VO `from*`, `make`, `toArray`, or normalization flows are changed.
---

# PHP Data Contract Normalizer

## Purpose

Keep DTO and VO contract behavior simple, explicit, and predictable.

End goal: DTOs and VOs should map input directly into typed construction paths, preserve public contracts, and fail fast
when services provide invalid types.

## Use This Skill When

- Adding or modifying DTO classes
- Adding or modifying value object classes
- Updating DTO `fromArray()` / `toArray()` behavior
- Updating VO `from()` / `make()` / `value()` behavior
- Reviewing hydration/normalization for hidden coercion

## References

- `references/contract-patterns.md`: Load for canonical DTO/VO contract patterns.

## Core Contract Rules

### 1. Direct DTO Constructor Mapping

- `fromArray()` should map array keys directly to constructor arguments
- Keep DTO hydration logic straightforward and readable
- Preserve existing serialization keys and round-trip contracts

### 2. Explicit VO Construction Flow

- `from()` should normalize raw input and then delegate to `make()`
- `make()` should validate normalized input and throw the existing validation exception type when invalid
- Keep VO normalization and validation steps visible and easy to follow

### 3. Preserve Supported Context Parameters

- If a VO `from()` method accepts `$context`, keep context pass-through when the underlying normalizer supports or
  depends on it
- Use your project's normalization facade/abstraction for VO normalization calls; avoid calling low-level type
  normalizers directly from VOs
- Do not remove context plumbing from existing contracts to satisfy a tool warning
- If facade/magic method signatures appear narrower than runtime behavior, verify installed dependencies before changing
  call patterns

### 4. Fail Fast via Types

- Allow PHP type errors to surface when invalid argument types are passed to DTO constructors or VO factory methods
- Do not add defensive pre-validation layers that suppress invalid service usage
- Simple shape guards are allowed when they preserve an existing default contract (for example, `is_array(...)? ... :
  []` for optional array fields)

### 5. No Hidden Coercion Helpers by Default

- Do not add `resolve*` or similar helper methods solely to coerce mixed input
- Do not add complex casting or normalization pipelines during hydration
- Do not broaden accepted input shapes unless explicitly approved
- Keep normalization transparent so misuse is visible immediately

### 6. Constructor and Finality Stability

- DTOs must be declared `final` at the class level
- Do not apply `final` to constructors; enforce finality on DTO classes
- Preserve existing VO constructor access patterns unless quality gates require minimal visibility changes
- When VO factories rely on `new static()`, constructor visibility must remain compatible with late static binding
  unless an explicit architectural decision changes that pattern

### 7. Scope Boundaries

- DTOs are transport structures, not normalization services
- VOs are invariant enforcers, not workflow orchestration layers
- Avoid embedding business-specific branching in DTO/VO normalization logic
- Keep changes scoped to contract correctness and clarity

## Hard Guardrails

- Preserve runtime behavior and public contracts unless explicit approval exists
- Do not change array keys, normalized output formats, or round-trip semantics without explicit approval
- Do not remove relevant, supported context parameters from DTO/VO normalization flows without explicit approval
- Do not introduce complex, hidden, or multi-step input coercion in DTO/VO normalization
- Avoid speculative refactors and broad churn

## Workflow

1. Identify changed DTO/VO files and their contract expectations
2. Verify DTO mapping and VO construction flows stay direct and explicit
3. Ensure each DTO class is declared `final` (class-level)
4. Preserve supported context pass-through for VO normalization paths
5. Remove hidden coercion that broadens DTO/VO input acceptance
6. Keep only lightweight guards that preserve existing contract defaults
7. Confirm DTO `toArray()` and VO `value()` outputs remain deterministic
8. Run quality gates and tests when executable PHP changes are made
9. Return the standard outcome contract

## Output Contract

- `status`: `completed-no-changes` | `changed` | `blocked` | `approval-required`
- `changed_files`: explicit list, or `[]`
- `rerun_required`: `true` | `false`
- `approval_items`: explicit list, or `[]`
- `blockers`: explicit list, or `[]`
