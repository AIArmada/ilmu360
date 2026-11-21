---
name: laravel-dune-convert
description: Convert an existing conventional or partly modular Laravel application to Laravel Dune through behavior-preserving vertical slices, with read-only inventory, durable ownership mapping, route parity verification, and ratcheted static analysis.
---

# Convert an Existing Laravel App to Laravel Dune

Migrate an existing Laravel 11 / 12 / 13 codebase (brownfield or vibe-coded greenfield) into **Laravel Dune** without breaking routes, middleware order, authorization, tenancy, transactions, or queues. Read [references/migration-playbook.md](references/migration-playbook.md) for Livewire, Filament, Inertia, Observers, Policies, and Auth recipes. (Converting a reusable Composer package on `src/` instead of an app? Use **`laravel-dune-package`** in Convert mode.)

## Phase 0 — Establish a Verified Baseline

Resolve `$CONVERT_SKILL_DIR` to `.ai/skills/laravel-dune-convert`, `$ARCH_SKILL_DIR` to `.ai/skills/laravel-dune`, and `$PROJECT_ROOT` to the Laravel app root.

1. Use **Laravel Boost MCP** first (`application-info`, `database-schema`, `database-connections`) and read `.ai/rules/index.md`. Use `search-docs` for any version-specific Laravel/Livewire/Filament/Inertia API.
2. Run the read-only inventory and ownership mapper (neither boots Laravel):
   ```sh
   python3 "$CONVERT_SKILL_DIR/scripts/inventory.py" --root "$PROJECT_ROOT" > /tmp/arch-inventory.json
   python3 "$CONVERT_SKILL_DIR/scripts/ownership_map.py" --root "$PROJECT_ROOT" --format markdown
   ```
3. Capture baseline verification artifacts in a safe local environment (uncached):
   ```sh
   php artisan optimize:clear
   php artisan route:list --json > /tmp/arch-routes-before.json
   php artisan test --compact
   ```
4. If the app already uses Laravel Boost, run `infer-conventions` first to capture existing non-default conventions before restructuring.

## Phase 1 — Bootstrap Platform with Ratcheting CI

1. Install the Platform core and static-analysis tools using `laravel-dune`'s scaffold script (`--no-example` during conversion so no dummy `/status` endpoint is added):
   ```sh
   python3 "$ARCH_SKILL_DIR/scripts/arch_scaffold.py" init --root "$PROJECT_ROOT"
   python3 "$ARCH_SKILL_DIR/scripts/arch_scaffold.py" init --root "$PROJECT_ROOT" --apply
   ```
2. Register `App\Platform\PlatformServiceProvider::class` once in `bootstrap/providers.php`.
3. Install `nikic/php-parser:^5`, `phpstan/phpstan:^2.3`, `larastan/larastan:^3.14` and wire `arch:boundaries:diff` (`php tools/architecture-check.php --diff-base=main`) plus `arch:writes` into Composer & CI.
   - **Why `--diff-base=main`:** Enforces full architecture rules on every new/migrated `app/Features/**` slice AND stops new anti-slop regressions in touched legacy files without failing on 500 untouched legacy files on Day 1.

## Phase 2 — Convert Tested Vertical Slices (One Capability per PR)

Pick the cleanest leaf capability first (fewest cross-table joins), then work inward. For **each** vertical slice:

1. **Scaffold the Feature folder without dummy endpoints:**
   ```sh
   python3 "$ARCH_SKILL_DIR/scripts/arch_scaffold.py" feature --root "$PROJECT_ROOT" --name Orders --no-example --apply
   ```
2. **Claim durable ownership in `feature.json`:** Assign each table (`table:*`) and cache/disk store (`store:*`) to its single owning Feature. List the exact concrete `Actions\` / `Persistence\` classes that perform writes.
3. **Move Models & pin explicit `$table`:** Move owned models to `app/Features/<Name>/Models/`, declare literal `protected $table = '...';`, and update factory/policy registrations using installed Laravel APIs.
4. **Extract concrete named Actions:** Move write logic out of fat controllers, Jobs, Observers, and Livewire/Filament hooks into concrete `final readonly class` Actions owning their `DB::transaction(...)` and actor/tenant authorization checks.
5. **Move thin EntryPoints & Routes atomically:** Move controllers/jobs/commands/routes into `EntryPoints/` and `Routes/{web,api,console}.php` (declaring middleware, prefixes, and names explicitly) and **remove the old route registrations in the same slice** so Laravel cannot silently overwrite duplicate routes.
6. **Isolate external SDKs into `app/Host/`:** If the slice calls third-party APIs (Stripe, Slack, OpenAI, S3 webhooks) or OS processes, extract a pure interface to `app/Shared/Contracts/` and move the SDK implementation into `app/Host/<Vendor>/`.
7. **Expose `Api/` only for real cross-feature callers:** Migrate external callers to `App\Features\<Name>\Api\*` interfaces & `final readonly` DTOs, then **delete superseded classes, aliases, and pass-through wrappers in the same wave** (*Migrate Callers Then Delete Legacy APIs*).
   - If an unavoidable legacy reference must wait for a later slice, record an exact non-wildcard, expiry-dated `feature-external` exception in `tools/architecture.json` (never silence `OwnedWriteRule`).

## Phase 3 — Prove Behavioral Parity on Every Slice

```sh
php artisan optimize:clear
php artisan route:list --json > /tmp/arch-routes-after.json
python3 "$CONVERT_SKILL_DIR/scripts/compare_routes.py" /tmp/arch-routes-before.json /tmp/arch-routes-after.json
php tools/architecture-check.php --diff-base=main
composer arch:writes
php artisan test --compact
php artisan platform:cache && php artisan route:cache && php artisan config:cache
php artisan optimize:clear
```

Also inspect Boost MCP (`last-error`, `browser-logs`, `database-query`) after exercising the real endpoint/flow.

## Phase 4 — Close Out Full-App Conversion

Once all in-scope capabilities are migrated:
1. Remove all temporary migration exceptions from `tools/architecture.json` (stale exceptions fail CI automatically).
2. Switch CI from `--diff-base=main` to full `composer arch:check` (`php tools/architecture-check.php` + `OwnedWriteRule`).
3. Run `laravel-dune-correct` to sweep narrative comments and promote any recurring migration pitfalls into permanent checks.
4. Run `php artisan boost:update` and verify the full test suite + cached boot (`php artisan optimize && php artisan optimize:clear`).
