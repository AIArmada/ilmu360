# Phase 1 - Package Source And Dependency Alignment

State: `Verified`

## Objective

Make the app resolve AIArmada packages from local first-party package source and align dependency constraints before domain rewrites begin.

## Entry Criteria

- Phase 0 is `Verified`.
- `status.md` blocker register is current.

## Checklist

- [x] Add Composer path repository configuration for `/Users/Saiffil/Herd/commerce/packages/*`.
- [x] Avoid installing `aiarmada/commerce` meta package initially.
- [x] Run Composer dry-run or controlled update for existing installed packages.
- [x] Align Laravel/Illuminate version constraints (`^13.14` → `^13.15`).
- [x] Align Filament package constraints (`^5.0` → `^5.6.7`).
- [x] Resolve `spatie/laravel-permission` conflict for `authz` and `filament-authz`. (Upgraded to v8; packages already compatible.)
- [x] Resolve `spatie/laravel-sluggable` conflict for `references`. (Constraint bump to `^4.0.2`; no code changes.)
- [x] Record all package constraint changes as generic package improvements.
- [x] Update `package-inventory.md` readiness states after Composer resolves.

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
