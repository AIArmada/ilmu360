# Converting an Existing Laravel Package

## 1. Snapshot the Package Surface Before Moving Any File

Run the read-only inventory script first:
```sh
python3 "$PKG_SKILL_DIR/scripts/package_inventory.py" --root "$PROJECT_ROOT" > /tmp/pkg-inventory.json
```

Review the current package surface so every entry point is accounted for during migration:
- `composer.json` `extra.laravel.providers` and `extra.laravel.aliases`
- Public Facades (`Facades/*`) and main entry classes
- Config file and keys in `config/<pkg>.php`
- Publish tags (`--tag=<pkg>-config`, `--tag=<pkg>-migrations`, `--tag=<pkg>-views`)
- Artisan command signatures and options
- Public events, exceptions, interfaces, and Eloquent models

**Rule:** Make clean, direct replacements. Do **not** introduce backward-compatibility shims, pass-through wrappers, dual paths, or leftover dead files—update callers and tests directly and delete superseded classes in the same change.

## 2. Choose `single` vs. `modular`

- Stay **`layout: "single"`** if the package has 1–2 tightly related concerns or fewer than ~30 PHP classes in `<sourceDir>/`.
- Choose **`layout: "modular"`** if the package has 3+ independent capabilities, multiple distinct DB tables/stores, or cross-module coupling that agents repeatedly tangle.
- When promoting an existing `single` package up to `modular` later:
  1. Preview and apply promotion:
     ```sh
     python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" promote --root "$PROJECT_ROOT"
     python3 "$PKG_SKILL_DIR/scripts/pkg_scaffold.py" promote --root "$PROJECT_ROOT" --apply
     ```
     This adds `<sourceDir>/Platform/`, updates `.ai/rules/package-architecture.md`, and updates `tools/architecture.json` (`"layout": "modular"`) to the canonical schema while preserving reviewed `exceptions`.
  2. Move one capability slice at a time into `<sourceDir>/Features/<Capability>/`, update callers directly to each feature’s `Api/` or concrete Action, and delete the old files immediately.

## 3. Ratchet Static Analysis Without Blocking Day 1

1. Run `pkg_scaffold.py init --root "$PROJECT_ROOT" --layout <single|modular> --apply` (pass `--source-dir <dir>` if the package maps PSR-4 to a directory other than `src/`). Existing `tools/architecture.json` `exceptions` and existing `.ai/rules/index.md` rows are merged cleanly; stale unknown config keys are stripped. Pass `--diff` to inspect any other conflicting generated files or `--force` to refresh managed tooling.
2. Wire `php tools/package-check.php --diff-base=main` + `composer arch:writes` into CI so new/touched files obey all boundaries and anti-slop rules immediately.
3. Run baseline `vendor/bin/pest` and `vendor/bin/phpstan analyse` before refactoring.

## 4. Refactor in Small Verifiable Units

1. **Fix package-safety hazards first:** Remove any `App\*` imports (replace with config/contracts), remove `env()` outside `config/`, remove any host-global mutations (`Model::unguard()`, `shouldBeStrict()`), remove `sleep()` hacks.
2. **Isolate external SDKs & HTTP clients:** Define pure interfaces in `Contracts/` and immutable DTOs in `Data/`; move vendor SDK / `Http::` / `Process::` implementations into `Drivers/`.
3. **Consolidate writes into concrete `Actions/`:** Move Eloquent/DB/Cache/Storage mutations out of Commands, Facades, Controllers, Jobs, and Observers into named `Actions/` (declared in `feature.json` if `modular`), and delete superseded classes/helpers immediately.
4. **Add `tests/Arch/PackageArchTest.php` and `workbench/` verification:** Test real service-provider boot, config publishing, stub migration execution, and driver fakes through `orchestra/testbench`.
5. **Ship consumer Boost assets:** Run `pkg_scaffold.py boost-docs --root "$PROJECT_ROOT" --apply` and fill in accurate usage snippets so downstream Laravel apps using Boost get high-trust guidance for your package.
6. **Switch CI from `--diff-base=main` to full `composer arch:check`** once all hotspots and temporary exceptions in `tools/architecture.json` are cleared.
