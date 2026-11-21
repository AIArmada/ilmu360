---
paths:
  - app/**
  - tests/**
  - verify/**
---

# Verification, Hygiene & Anti-Slop Governance

## Comment-Sicko Policy (~99% Comment Ban)
Only five comment types may remain in committed code:
1. Legal or license headers.
2. Verified external vendor/framework quirks we cannot reshape (proven on a live path).
3. Purely stylistic formatter ignores (`// prettier-ignore`). Never keep correctness/type suppressions (`@phpstan-ignore*`, `phpcs:ignore`).
4. Public API contract docblocks (`Api/`, `Shared/Contracts/`, PHPStan generic templates).
5. Issue or RFC links explaining an unavoidable external constraint.

Delete all narrating comments, commented-out code, workaround justifications, and over-rotated PR feedback (`// X said never do this`). Convert `DO NOT REMOVE` comments into types, lints, or tests.

## Banned Shortcuts
- No `sleep()`, `usleep()`, `time_nanosleep()`, or `Sleep::sleep()` synchronization hacks in `app/`.
- No `env()` calls outside `config/`.
- No `Model::unguard()` or disabling mass-assignment protection in `app/`.
- No service-locator `app(...)` / `resolve(...)` inside Feature Actions when constructor DI works.

## Proof Before Done ("Prove It Works")
- Always run `composer arch:check` (AST boundaries + Larastan `OwnedWriteRule` + normal PHPStan) and affected Pest/PHPUnit tests.
- Test observable behavior (HTTP status/JSON, persisted DB state read back, authorization/tenant isolation, rollback on failure, idempotency on retry)—never pin tests to private helper calls.
- Verify route/config/platform caches (`php artisan route:cache`, `config:cache`, `platform:cache`) on structural changes and clear temporary caches after.
- Use Laravel Boost MCP (`last-error`, `browser-logs`, `database-query`) to confirm zero silent runtime errors.

## Rule → Enforcement Table (Updated via `laravel-dune-correct`)

| Mistake Class | Mechanical Enforcer | Layer |
| --- | --- | --- |
| Cross-feature private import | `php tools/architecture-check.php` (`feature-private`) | L2 AST |
| `app/Platform` / `app/Host` / `app/Shared` importing feature | `php tools/architecture-check.php` (`layer-direction`) | L2 AST |
| Unregistered DB / Cache / Storage / Redis write | PHPStan `OwnedWriteRule` (`architecture.writeOwner`) | L2 Type |
| Missing literal `$table` on feature model | `php tools/architecture-check.php` (`model-table`) | L2 AST |
| Mutable Laravel / Eloquent leak in `Api/` or `Contracts/` | `php tools/architecture-check.php` (`api-shape`, `api-leak`) | L2 AST |
| `sleep()` / `env()` / `Model::unguard()` / `@phpstan-ignore` | `php tools/architecture-check.php` (`banned-footgun`, `no-suppression`) | L2 AST |
| Narrative / workaround comment slop | `php tools/architecture-check.php` (`comment-hygiene`) + `laravel-dune-correct` | L2/L4 |
| Behavioral regressions & broken routes | `verify/feature-map/` + Pest feature & arch tests + Boost MCP | L3 Verify |
