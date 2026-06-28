# Phase 3 - Foundation Adoption

State: `Not Started`

## Objective

Move foundational AIArmada packages to local path source and remove brittle app-level package overrides.

## Target Packages

- `commerce-support`
- `authz`
- `filament-authz`
- `signals`
- `filament-signals`
- `affiliates`
- `growth`

## Checklist

- [ ] Move existing installed packages to local path resolution.
- [ ] Adopt `authz` as the canonical permission package.
- [ ] Rework app permission hooks to package contracts/config.
- [ ] Adopt local `signals` and `filament-signals`.
- [ ] Remove app provider overrides for package views/routes once package loading is correct.
- [ ] Adopt `affiliates` source and reconcile share attribution behavior.
- [ ] Adopt `growth` only if telemetry/growth workflows are enabled.
- [ ] Verify Signals tracking remains curated and privacy-first.

## Verification

```bash
composer show aiarmada/commerce-support aiarmada/authz aiarmada/filament-authz aiarmada/signals aiarmada/filament-signals aiarmada/affiliates
vendor/bin/pest --parallel --compact --filter=Authz
vendor/bin/pest --parallel --compact --filter=Signals
vendor/bin/pest --parallel --compact --filter=Affiliate
vendor/bin/phpstan analyse --ansi
```

## Exit Criteria

- Foundation packages resolve from local source.
- No brittle sibling-path package overrides remain in app providers.
- Existing authz/signals/affiliate app behavior is either package-backed or intentionally removed.

## Stop And Re-plan Triggers

- Authz upgrade breaks panel access semantics.
- Signals events become blanket click logging instead of curated workflow tracking.
- Affiliate package cannot represent zero-value share outcomes generically.
