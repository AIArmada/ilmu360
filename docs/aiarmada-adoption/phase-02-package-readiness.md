# Phase 2 - Package Readiness Hardening

State: `Not Started`

## Objective

Make adopted packages safe to use as first-party domain owners by adding verification gates, migration safety checks, and generic extension seams.

## Entry Criteria

- Phase 1 dependency resolution is `Verified` or package-specific blockers are assigned.
- Target package list for phases 3-6 is confirmed.

## Checklist

- [ ] Define package test harness strategy.
- [ ] Add package tests for packages that will receive generic code changes.
- [ ] Document when app-level tests intentionally cover a package instead of package-local tests.
- [ ] Audit package migrations for UUID primary keys.
- [ ] Audit package migrations for forbidden constraints/cascades.
- [ ] Audit package code for `SoftDeletes`.
- [ ] Fix `commerce-support` migration stub behavior for `audits` and `webhook_calls`.
- [ ] Audit route-bearing package providers for route registration safety.
- [ ] Add PHPStan/Pint gates for modified packages.
- [ ] Update `agent-work-queue.md` packet states.

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
