# Laravel Package Architecture Reference (`single` vs. `modular`)

## Why Packages Differ from Laravel Apps

A reusable Composer package (e.g., built from `spatie/package-skeleton-laravel`) runs **inside someone else’s Laravel application**:
- Root source lives in `src/` under `VendorName\PackageName\` (not `App\` → `app/`).
- Registration happens via `composer.json` `extra.laravel.providers` and `Spatie\LaravelPackageTools\PackageServiceProvider::configurePackage(Package $package)` (not `bootstrap/providers.php`).
- A package **must never mutate host globals** (`Model::shouldBeStrict()`, `Model::unguard()`, `DB::prohibitDestructiveCommands()`, `Date::use(...)`).
- A package **must never import `App\*`**—host models/tables come from `config/<pkg>.php` or contracts.
- Verification uses **`orchestra/testbench`** (`workbench/` + `vendor/bin/testbench` + Pest) rather than `php artisan serve`.
- Packages also **publish downstream AI context** via Laravel Boost’s package convention: `resources/boost/guidelines/core.blade.php` and `resources/boost/skills/<pkg>-development/SKILL.md`.

---

## Layout 1 — Focused Single-Capability Package (`"layout": "single"`)

Default when the package owns one cohesive capability (the package itself *is* the Feature):

```text
my-package/
├── config/<pkg>.php                   # Sole permitted location for env()
├── database/migrations/*.php.stub     # Publishable migrations (if stateful)
├── resources/boost/
│   ├── guidelines/core.blade.php      # Auto-loaded by Laravel Boost in consumer apps
│   └── skills/<pkg>-development/SKILL.md
├── src/
│   ├── <Pkg>ServiceProvider.php       # Extends PackageServiceProvider
│   ├── <Pkg>.php                      # Public entry class (+ optional Facades/)
│   ├── Contracts/                     # Pure public & driver interfaces
│   ├── Data/                          # Pure final readonly DTOs & backed enums
│   ├── Actions/                       # Concrete single-writer use cases (if stateful)
│   ├── Models/                        # Private models (explicit $table or config-backed getTable())
│   ├── Commands/ (or EntryPoints/)    # Thin CLI / HTTP / Jobs / Listeners delegating to Actions
│   └── Drivers/                       # External HTTP/SDK/OS adapters implementing Contracts/
├── workbench/                         # Orchestra Testbench host app sandbox
├── tests/Arch/PackageArchTest.php     # Pest 4 arch() boundary rules
└── tools/{architecture.json, package-check.php, package-phpstan.neon, Architecture/PackageOwnedWriteRule.php}
```

Create only directories the package actually needs (e.g., omit `Models/` and `database/` for stateless packages).

---

## Layout 2 — Large Multi-Feature Package (`"layout": "modular"`)

Use when a package spans **3+ independent capabilities**, multiple owned tables/stores, or multiple parallel contributors/agents (e.g., media pipelines, billing suites, admin panels):

```text
my-suite-package/
├── src/
│   ├── <Pkg>ServiceProvider.php       # Calls FeatureCatalog & boots feature modules + ExtensionRegistry
│   ├── Platform/                      # Package-local FeatureCatalog, ExtensionRegistry, FeatureModule (never imports Features/Drivers)
│   ├── Contracts/                     # Package-wide public interfaces & driver contracts
│   ├── Data/                          # Package-wide immutable DTOs & enums
│   ├── Drivers/                       # External SDK / storage / webhook adapters
│   └── Features/
│       └── Billing/
│           ├── feature.json           # Declares schema, name, owns (table:*/store:*), extensions
│           ├── FeatureModule.php      # Implements Vendor\Pkg\Platform\FeatureModule
│           ├── Actions/               # Named concrete write actions
│           ├── Models/                # Private models (explicit $table or config-backed getTable())
│           ├── EntryPoints/           # Thin Commands, Http, Jobs, Listeners
│           ├── Extensions/            # Auto-discovered domain plugs
│           └── Api/                   # Cross-feature interfaces & final readonly DTOs
├── workbench/
├── tests/Arch/PackageArchTest.php
└── tools/{architecture.json, package-check.php, package-phpstan.neon, Architecture/PackageOwnedWriteRule.php}
```

### Wiring Modular Features in `PackageServiceProvider`

Keep `configurePackage(Package $package)` declarative for config/views/migrations/commands, then register modular features inside `packageRegistered()` and `packageBooted()` using `FeatureCatalog::load(dirname(__DIR__), __NAMESPACE__)`—never touching host app globals.

---

## Composer Scripts & PHPStan Integration

Merge into the package’s `composer.json`:

```json
{
  "scripts": {
    "arch:boundaries": "@php tools/package-check.php",
    "arch:boundaries:diff": "@php tools/package-check.php --diff-base=main",
    "arch:writes": "@php vendor/bin/phpstan analyse -c tools/package-phpstan.neon --autoload-file=tools/Architecture/PackageOwnedWriteRule.php --no-progress",
    "arch:check": [
      "@arch:boundaries",
      "@arch:writes"
    ]
  }
}
```

**PHPStan `extension-installer` note:** Spatie’s skeleton includes `phpstan/extension-installer`, which loads `larastan/larastan` automatically. Keep `tools/package-phpstan.neon` free of manual `includes: [../vendor/larastan/larastan/extension.neon]`. If a package does *not* install `phpstan/extension-installer`, add that include line to `tools/package-phpstan.neon`.

**Custom PSR-4 source directory & custom checker notes:**
- If your package maps its PSR-4 root to a directory other than `src/` (for example `lib/`), pass `--source-dir lib` to `pkg_scaffold.py init`; it persists `"sourceDir": "lib"` in `tools/architecture.json`, updates `tools/package-phpstan.neon`, and scopes `.ai/rules/package-architecture.md` accordingly.
- `arch:boundaries:diff` requires a checker that implements `--diff-base` ratchet mode. The starter `tools/package-check.php` explicitly rejects unknown CLI flags with exit code `2`; if you maintain a custom checker without ratchet support, omit `arch:boundaries:diff` instead of passing a flag that PHP’s `getopt()` would silently ignore.
