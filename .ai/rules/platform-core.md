---
paths:
  - app/Platform/**
---

# Platform Core

## Strict One-Way Dependency
`app/Platform/**` MUST NEVER import or reference any class under `App\Features\*` or `App\Host\*`. Use generic contracts, PHP attributes, or manifest metadata (`feature.json`) instead.

## Zero Per-Request Disk Globs in Production
`FeatureCatalog::load()` MUST memoize in-memory per process and read from `bootstrap/cache/platform.php` when present. Any change to discovery or manifest loading must keep `php artisan platform:cache` and `php artisan optimize` compatible.

## Narrow Architecture Exceptions Only
Low-level framework mechanics (reflection, runtime strictness switches, service-provider wiring) belong here rather than in product features. Changes to `app/Platform/**` are architecture changes and require explicit review plus regression tests proving both passing and failing boundary cases.
