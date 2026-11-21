---
name: laravel-dune-package
description: Build a fresh Laravel Composer package (src/ + Spatie package-skeleton-laravel) or convert an existing Laravel package to Dune architecture, supporting both focused single-capability packages and large multi-feature modular packages, with Orchestra Testbench verification and downstream Laravel Boost resources.
---

# Laravel Dune — Composer Packages (`src/`)

Build or convert reusable Laravel 11 / 12 / 13 packages (e.g., `spatie/package-skeleton-laravel` / `spatie/laravel-package-tools`) using Dune’s mechanical boundaries and strict package-safety laws. Read [references/package-architecture.md](references/package-architecture.md) for layout contracts and [references/converting-existing-package.md](references/converting-existing-package.md) when migrating an existing package.

## Step 1 — Pick the Right Package Layout (`single` vs. `modular`)

Do **not** force multi-feature folders onto a focused package, and do **not** let a large multi-domain package collapse into one tangled `src/` directory:

- **`layout: "single"` (Default for focused packages):** The package *is* the single capability. Uses `src/Contracts/`, `src/Data/`, `src/Actions/`, `src/Drivers/`, `src/Commands/` (or `src/EntryPoints/`), `src/Models/`. Zero `feature.json` or `src/Features/` boilerplate.
- **`layout: "modular"` (For large multi-capability packages, e.g., 3+ distinct domains or multiple owned tables/stores):** Uses `src/Platform/` (`FeatureCatalog`, `ExtensionRegistry`, `FeatureModule`) + `src/Features/<Capability>/` (`feature.json`, `Actions/`, `Models/`, `EntryPoints/`, `Extensions/`, `Api/`) alongside package-wide `src/Contracts/`, `src/Data/`, and `src/Drivers/`.
- You can start with `single` and promote cleanly to `modular` later via `pkg_scaffold.py promote --root "$PROJECT_ROOT" [--apply]` (which adds `<sourceDir>/Platform/`, updates `.ai/rules/package-architecture.md`, and merges `tools/architecture.json` while preserving reviewed `exceptions`).

## Mode A — Bootstrap a Fresh Package

Resolve `$PKG_SKILL_DIR` to `.ai/skills/laravel-dune-package` and `$PROJECT_ROOT` to the package root. Pass `--source-dir <dir>` if your package maps PSR-4 to a directory other than `src/` (e.g. `lib/`). Preview first, then apply:

```sh
# Focused single-capability package (default):
python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" init --root "$PROJECT_ROOT" --layout single
python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" init --root "$PROJECT_ROOT" --layout single --apply

# Custom PSR-4 directory (e.g. lib/):
python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" init --root "$PROJECT_ROOT" --layout single --source-dir lib --apply

# OR large multi-feature package:
python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" init --root "$PROJECT_ROOT" --layout modular --apply
python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" feature --root "$PROJECT_ROOT" --name Billing --apply

# Promote an existing 'single' package to 'modular' (prints diff in preview; preserves exceptions):
python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" promote --root "$PROJECT_ROOT"
python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" promote --root "$PROJECT_ROOT" --apply

# Add an isolated external driver / gateway (either layout):
python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" driver --root "$PROJECT_ROOT" --name Stripe --apply

# Scaffold downstream Laravel Boost consumer docs & skill:
python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" boost-docs --root "$PROJECT_ROOT" --apply
```

Wire Composer dev tools and scripts:
1. Ensure `nikic/php-parser:^5` is in `require-dev` (Spatie’s skeleton already ships `larastan/larastan`, `phpstan/extension-installer`, `orchestra/testbench`, `pestphp/pest`, `pestphp/pest-plugin-arch`, `phpunit.xml.dist`, `tests/Pest.php`, and `laravel/pint`).
2. **Important PHPStan trap:** If `phpstan/extension-installer` is installed, do **not** manually `includes:` `larastan/extension.neon` in `tools/package-phpstan.neon` (double-including Larastan fails PHPStan).
3. Add Composer scripts (`arch:boundaries`, `arch:writes`, `arch:check`) and wire `composer arch:check`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`, and `vendor/bin/pint --test` into CI.

## Mode B — Convert an Existing Laravel Package

1. Run the read-only package inventory before moving any class:
   ```sh
   python3 "$PKG_SKILL_DIR/scripts/package_inventory.py" --root "$PROJECT_ROOT" > /tmp/pkg-inventory.json
   ```
   Review every entry point (`extra.laravel` providers/aliases, `Facades/`, `config/*.php` keys, Artisan commands, migration stubs) to plan a clean replacement.
2. Run `pkg_scaffold.py init --root "$PROJECT_ROOT" --layout single` (or `modular` for large multi-domain packages, plus `--source-dir <dir>` if non-`src/`) with `--apply`:
   - Existing `tools/architecture.json` files are **merged to the canonical schema** (structural keys refreshed; reviewed `exceptions` and valid `antiSlop` booleans preserved; stale keys stripped).
   - Existing `.ai/rules/index.md` files are **merged safely** (appending the package row if absent, never clobbering existing project rules).
   - If other generated tooling files differ, `pkg_scaffold.py` reports **all** conflicts at once; inspect with `--diff` or refresh managed files with `--force`.
   - Start CI in ratcheting mode (`php tools/package-check.php --diff-base=main`).
3. Refactor cleanly and delete superseded code (see [references/converting-existing-package.md](references/converting-existing-package.md)):
   - Isolate external SDK/HTTP/OS calls into `Drivers/` implementing `Contracts/`.
   - Consolidate scattered DB/Cache/Storage writes into concrete `Actions/` (or `Features/<Cap>/Actions/` in `modular` layout).
   - Eliminate package-safety hazards (`App\*` imports, host-global mutations, `env()` outside `config/`, `sleep()`, `@phpstan-ignore`).
   - **No backward-compatibility shims, legacy wrappers, or leftover files:** Update callers and tests directly, then delete superseded classes, aliases, and dead config keys in the same change.

## Non-Negotiable Package-Safety Laws

- **Never import host app classes (`App\*`)** inside `src/` (or configured `sourceDir`). Read configurable model/table bindings from `config/<pkg>.php` or depend on package `Contracts/`.
- **Never mutate host-app globals:** Never call `Model::shouldBeStrict()`, `Model::unguard()`, `Model::preventLazyLoading()`, `DB::prohibitDestructiveCommands()`, or `Date::use(...)` in package code.
- **Keep `Contracts/` and `Data/` pure:** Only `interface`, `enum`, or `final readonly class` DTOs—no Eloquent models, Builders, `Illuminate\Http\*`, or internal Drivers/Actions.
- **No backward-compatibility layers or leftovers:** Migrate callers directly and delete replaced code in the same change.
- **Ship downstream Laravel Boost resources:** Keep `resources/boost/guidelines/core.blade.php` and `resources/boost/skills/<pkg>-development/SKILL.md` accurate whenever the public package API changes.

## Verify Before Declaring Done

```sh
composer arch:check
vendor/bin/phpstan analyse
vendor/bin/pest
vendor/bin/pint --test
```

Use `orchestra/testbench` (`workbench/` + `tests/Feature/`) to prove provider auto-discovery, publishable config/migrations, commands/routes, and driver swaps in a real booted Laravel container.
