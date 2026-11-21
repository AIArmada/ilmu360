# Converting an Existing Laravel Package (SemVer-Safe Playbook)

## 1. Snapshot the Public API Before Moving Any File

Run the read-only inventory script first:
```sh
python3 "$PKG_SKILL_DIR/scripts/package_inventory.py" --root "$PROJECT_ROOT" > /tmp/pkg-inventory.json
```

Identify every **SemVer-protected surface** downstream Laravel apps rely on:
- `composer.json` `extra.laravel.providers` and `extra.laravel.aliases`
- Public Facades (`src/Facades/*`) and main entry class methods
- Config file name and every key in `config/<pkg>.php`
- Publish tags (`--tag=<pkg>-config`, `--tag=<pkg>-migrations`, `--tag=<pkg>-views`)
- Artisan command signatures and options
- Public events, exceptions, interfaces, and Eloquent model classes consumers extend or type-hint

**Rule:** Do not break any of the above unless the user explicitly requested a major SemVer release. Keep public classes/facades as thin entry points delegating inward to clean `Actions/` and `Drivers/`.

## 2. Choose `single` vs. `modular`

- Stay **`layout: "single"`** if the package has 1–2 tightly related concerns or fewer than ~30 PHP classes in `src/`.
- Choose **`layout: "modular"`** if the package has 3+ independent capabilities, multiple distinct DB tables/stores, or cross-module coupling that agents repeatedly tangle.
- When migrating an existing `single` package up to `modular` later:
  1. Run `pkg_scaffold.py init --root "$PROJECT_ROOT" --layout modular --apply` (adds `src/Platform/` and sets `"layout": "modular"`).
  2. Move one capability slice at a time into `src/Features/<Capability>/` while keeping public Facades delegating to each feature’s `Api/` or concrete Action.

## 3. Ratchet Static Analysis Without Blocking Day 1

1. Run `pkg_scaffold.py init --root "$PROJECT_ROOT" --layout <single|modular> --apply`.
2. Wire `php tools/package-check.php --diff-base=main` + `composer arch:writes` into CI so new/touched files obey all boundaries and anti-slop rules immediately.
3. Run baseline `vendor/bin/pest` and `vendor/bin/phpstan analyse` before refactoring.

## 4. Refactor in Small Verifiable Units

1. **Fix package-safety hazards first:** Remove any `App\*` imports (replace with config/contracts), remove `env()` outside `config/`, remove any host-global mutations (`Model::unguard()`, `shouldBeStrict()`), remove `sleep()` hacks.
2. **Isolate external SDKs & HTTP clients:** Define pure interfaces in `src/Contracts/` and immutable DTOs in `src/Data/`; move vendor SDK / `Http::` / `Process::` implementations into `src/Drivers/`.
3. **Consolidate writes into concrete `Actions/`:** Move Eloquent/DB/Cache/Storage mutations out of Commands, Facades, Controllers, Jobs, and Observers into named `Actions/` (declared in `feature.json` if `modular`).
4. **Add `tests/Arch/PackageArchTest.php` and `workbench/` verification:** Test real service-provider boot, config publishing, stub migration execution, and driver fakes through `orchestra/testbench`.
5. **Ship consumer Boost assets:** Run `pkg_scaffold.py boost-docs --root "$PROJECT_ROOT" --apply` and fill in accurate usage snippets so downstream Laravel apps using Boost get high-trust guidance for your package.
6. **Switch CI from `--diff-base=main` to full `composer arch:check`** once all hotspots and temporary exceptions in `tools/architecture.json` are cleared.
