# Phase 1 - Package Source And Dependency Alignment

State: `Not Started`

## Objective

Make the app resolve AIArmada packages from local first-party package source and align dependency constraints before domain rewrites begin.

## Entry Criteria

- Phase 0 is `Verified`.
- `status.md` blocker register is current.

## Checklist

- [ ] Add Composer path repository configuration for `/Users/Saiffil/Herd/commerce/packages/*`.
- [ ] Avoid installing `aiarmada/commerce` meta package initially.
- [ ] Run Composer dry-run or controlled update for existing installed packages.
- [ ] Align Laravel/Illuminate version constraints.
- [ ] Align Filament package constraints.
- [ ] Resolve `spatie/laravel-permission` conflict for `authz` and `filament-authz`.
- [ ] Resolve `spatie/laravel-sluggable` conflict for `references`.
- [ ] Record all package constraint changes as generic package improvements.
- [ ] Update `package-inventory.md` readiness states after Composer resolves.

## Verification

```bash
composer validate
composer update aiarmada/commerce-support aiarmada/affiliates aiarmada/signals aiarmada/filament-authz aiarmada/filament-signals --dry-run
composer show aiarmada/*
```

## Exit Criteria

- Composer can resolve local AIArmada packages.
- Existing installed package family can be moved to local source without runtime breakage.
- Dependency blockers B001-B004 are either `Ready` or have a documented generic package fix.

## Stop And Re-plan Triggers

- Resolving local packages requires broad third-party downgrades.
- A package constraint can only be fixed by app-specific behavior.
- Composer path setup would make CI or another developer environment non-reproducible.
