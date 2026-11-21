# Laravel Dune — Tooling, Composer & CI Reference

## Requirements

- PHP 8.2+ (tested on PHP 8.5), Laravel 11 / 12 / 13, Python 3.9+ (stdlib only for scaffolding scripts).
- Dev dependencies:
  ```sh
  composer require --dev 'nikic/php-parser:^5' 'phpstan/phpstan:^2.3' 'larastan/larastan:^3.14'
  ```
- Optional (recommended): `pestphp/pest` for `tests/Arch/ArchitectureTest.php` and `laravel/boost --dev` for `.ai/` guidelines, rules, skills, and MCP tools.

## Composer Scripts

Merge these scripts into `composer.json` without overwriting existing scripts:

```json
{
  "scripts": {
    "arch:boundaries": "@php tools/architecture-check.php",
    "arch:boundaries:diff": "@php tools/architecture-check.php --diff-base=main",
    "arch:writes": "@php vendor/bin/phpstan analyse -c tools/architecture-phpstan.neon --autoload-file=tools/Architecture/OwnedWriteRule.php --no-progress",
    "arch:check": [
      "@arch:boundaries",
      "@arch:writes"
    ]
  }
}
```

Also keep your normal `phpstan.neon` analysis (level 6–9) running in CI alongside `arch:writes`, because `tools/architecture-phpstan.neon` intentionally uses `customRulesetUsed: true` to isolate ownership diagnostics cleanly.

## Ratcheting Mode (`--diff-base`)

During incremental migration of an existing Laravel app:
- `php tools/architecture-check.php --diff-base=main` enforces **all** structural & anti-slop checks on every file inside `app/Platform/`, `app/Features/`, `app/Host/`, and `app/Shared/`, while applying anti-slop rules (`sleep()`, `env()`, `Model::unguard()`, suppressions, comment hygiene) **only to git-modified legacy files**—stopping new slop without requiring a giant big-bang rewrite on Day 1.
- Once migration finishes, switch CI to full `php tools/architecture-check.php`.

## Exception Governance (`tools/architecture.json`)

Only `feature-private` and `feature-external` may receive temporary exceptions, and **every** exception must specify exact non-wildcard values and a future expiry date:

```json
{
  "rule": "feature-external",
  "file": "app/Features/Orders/Actions/PlaceOrder.php",
  "target": "App\\Models\\User",
  "reason": "Users feature migration scheduled in slice 3",
  "owner": "@team-core",
  "expires": "2026-12-31"
}
```

Wildcards, expired dates, and stale unused exceptions all cause `tools/architecture-check.php` to fail with exit code `2`. Exceptions never disable `OwnedWriteRule`.

## Production Deployment & Caching

`PlatformServiceProvider` registers `platform:cache` and `platform:clear` with Laravel's `optimize` lifecycle:
```sh
php artisan optimize      # Runs config:cache, event:cache, route:cache, view:cache, AND platform:cache
php artisan optimize:clear
```
Always test cached routes/config/platform manifests in CI or locally before releasing, and clear temporary local caches afterward so PHPUnit/Pest environment variables aren't masked by `bootstrap/cache/config.php`.
