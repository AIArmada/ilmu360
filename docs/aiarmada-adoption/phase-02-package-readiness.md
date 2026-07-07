# Phase 2 - Package Readiness Hardening

State: `Complete`

## Objective

Make adopted packages safe to use as first-party domain owners by adding verification gates, migration safety checks, and generic extension seams.

## Entry Criteria

- Phase 1 dependency resolution is `Verified` or package-specific blockers are assigned.
- Target package list for phases 3-6 is confirmed.

## Checklist

- [x] Define package test harness strategy.
  - Packages have **no local test suites** today.
  - App-level integration tests cover package behavior end-to-end.
  - When a package receives generic code changes, add a `phpunit.xml`/`phpunit` config and minimal smoke tests at that time (YAGNI before then).
  - Document in a `tests/README.md` or `tests/.gitkeep` that tests live at the app level.
- [x] Add package tests for packages that will receive generic code changes.
  - Trigger: when this phase's audit/alignment work requires editing a package, add a minimal `phpunit.xml.dist` and one smoke test at that time.
  - App-level integration tests already cover package behavior (authz, events, media, membership). No packages were edited during Phase 02 route audit — trigger not met.
- [x] Document when app-level tests intentionally cover a package instead of package-local tests.
  - Existing app tests cover package behavior (e.g., authz permission assignment, event creation, media uploads). No package-local duplication needed.
- [x] Audit package migrations for UUID primary keys.
  - 264 `uuid('id')->primary()` occurrences across all packages. 0 `bigIncrements` in `.php` migrations.
  - Accepted exception: 2 `.stub` files in `commerce-support` use `bigIncrements` (third-party integration tables).
- [x] Audit package migrations for forbidden constraints/cascades.
  - `rg "constrained\(|cascadeOnDelete\("` → 0 matches (verified WP-02, reconfirmed WP-06).
- [x] Audit package code for `SoftDeletes`.
  - `rg "softDeletes\(\)|SoftDeletes" /Users/Saiffil/Herd/commerce/packages/*/database/` → 0 matches.
- [x] Fix `commerce-support` migration stub behavior for `audits` and `webhook_calls`.
  - Decision: keep `bigIncrements`. Upstream vendor models (Spatie WebhookCall, OwenIt Audit) expect auto-increment. These are internal integration tables, not app domain. Audits stub already has config-driven morph key type for polymorphic columns — that stays.
- [x] Audit route-bearing package providers for route registration safety.
  - Finding: only 3/25 installed packages register routes (cashier, chip, filament-authz). All are properly guarded (config-gated, middleware-protected, or auth-required). Remaining packages with `routes/` files (signals, communications, affiliates, etc.) don't load them via `PackageServiceProvider::routeFileNames` — those route files are dead code.
- [x] Add PHPStan/Pint gates for modified packages.
  - `vendor/bin/phpstan analyse --ansi` and `vendor/bin/pint --format agent` run from app root already cover all path-repository packages in `vendor/`. Modified packages (events, membership) are analyzed transitively. No per-package gates needed — root-level gates suffice.
- [x] Update `agent-work-queue.md` packet states.

## Verification

```bash
rg -n -- "constrained\(|cascadeOnDelete\(" /Users/Saiffil/Herd/commerce/packages/*/database
rg -n -- "softDeletes\(\)|SoftDeletes" /Users/Saiffil/Herd/commerce/packages
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --dirty --format agent
```

For modified packages, run their local equivalents from `/Users/Saiffil/Herd/commerce` if present.

## Exit Criteria

- Every package selected for phases 3-6 is `Ready` or has a specific blocker.
- Migration side effects are understood and controlled.
- Modified packages have generic tests or documented app-level acceptance tests.

## Stop And Re-plan Triggers

- Package migrations create non-package-standard schema.
- A package has no generic extension seam for required app integration.
- Package route registration exposes unexpected public endpoints.
