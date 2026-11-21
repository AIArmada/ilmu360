# Laravel Dune — Architecture Reference

## Mapping Agent-Friendly Architecture Specification to Idiomatic Laravel

| Architectural Concept | Laravel Implementation | Why It Works for Agents |
| --- | --- | --- |
| **Framework Core (`platform/`)** | `app/Platform/` (`FeatureCatalog`, `FeatureModule`, `PlatformServiceProvider`, `ExtensionRegistry`, `platform:cache`) | Strict one-way boundary: `app/Platform/` never imports `app/Features/` or `app/Host/`. Memoized + cacheable via `bootstrap/cache/platform.php`. |
| **N1 · Feature** | `app/Features/<PascalCase>/` | Owned capability folder. Sibling features cannot import private classes (`Actions/`, `Models/`, `Persistence/`, `EntryPoints/`). Deleting a folder cleanly deletes the feature. |
| **N2 · Entrypoint** | `Routes/{web,api,console}.php` + thin `EntryPoints/{Http,Jobs,Console,Livewire,Filament}/` | Auto-loaded by `FeatureModule` without editing shared root route or provider files. |
| **N3 · Extension** | `Extensions/` + `feature.json` `"extensions"` + `ExtensionRegistry` | Domain plugs (widgets, agent tools, webhook handlers, report renderers) discovered automatically without central `switch`/`match` roots. |
| **N4 · State Owner** | One feature per `table:*` / `store:*` in `feature.json`; named `Actions/` or `Persistence/` writers | Concrete Actions injected directly by Laravel; Larastan `OwnedWriteRule` rejects unauthorized DB/Cache/Storage/Redis mutations. |
| **N5 · Boundary (Host)** | `app/Host/<Integration>/` implementing pure `app/Shared/Contracts/` interfaces | External SDKs, payment/LLM APIs, OS/process exec stay in the Host box; `app/Host/` never imports concrete features. |

## Target Directory Layout

```text
app/
├── Platform/                           # Framework core (never imports Features or Host)
│   ├── Console/{CacheCommand,ClearCommand}.php
│   ├── PlatformServiceProvider.php     # Auto-discovers Features + enables Model::shouldBeStrict()
│   ├── ExtensionRegistry.php       # Resolves auto-discovered N3 Extensions by slot
│   ├── FeatureCatalog.php          # Validated, memoized & cache-aware manifest loader
│   └── FeatureModule.php           # Base provider loading reserved routes/views/migrations
├── Features/
│   └── Orders/
│       ├── feature.json            # Declares schema, name, owns (table:*/store:*), extensions
│       ├── FeatureServiceProvider.php
│       ├── Actions/                # Concrete use-case actions (Eloquent + DB::transaction)
│       ├── Models/                 # Private Eloquent models with explicit literal $table
│       ├── EntryPoints/            # Thin Http, Jobs, Console, Livewire, Filament adapters
│       ├── Extensions/             # Optional domain plugs registered in feature.json
│       ├── Routes/                 # Optional web.php, api.php, console.php (explicit middleware)
│       ├── Api/                    # Optional public interfaces & final readonly DTOs
│       ├── Queries/                # Optional reusable read queries
│       ├── Persistence/            # Optional shared persistence helpers
│       ├── Views/                  # Optional Blade views (namespace: orders::*)
│       └── Database/Migrations/    # Optional feature migrations
├── Host/                           # Privileged external SDKs, gateways, OS/process adapters
│   └── Stripe/
├── Shared/
│   └── Contracts/                  # Pure interfaces, backed enums, final readonly DTOs
tests/
├── Arch/PlatformArchTest.php           # Pest architecture tests
└── Feature/Orders/                 # Normal Laravel test discovery
tools/
├── platform.json                       # Discovery mode, anti-slop toggles, expiry-dated exceptions
├── platform-check.php                  # Zero-boot PHP-Parser AST boundary & anti-slop checker
├── platform-phpstan.neon               # Standalone OwnedWriteRule PHPStan config
└── Platform/OwnedWriteRule.php         # Type-aware Eloquent/DB/Cache/Storage/Redis write checker
verify/
├── feature-map/                    # User-POV 4-H2 Feature Map (Layer 3)
└── cli/                            # Reusable app verification CLI
```

Create only directories a feature actually needs. Avoid speculative repositories, service layers, or interfaces with a single local caller (*Minimize Reader Load* & *Laziness Protocol*).

## Manifest Schema (`feature.json`)

```json
{
  "schema": 1,
  "name": "Orders",
  "owns": {
    "table:orders": [
      "App\\Features\\Orders\\Actions\\PlaceOrder",
      "App\\Features\\Orders\\Actions\\CancelOrder"
    ],
    "store:exports": [
      "App\\Features\\Orders\\Persistence\\WriteOrderExport"
    ]
  },
  "extensions": {
    "dashboard.widgets": [
      "App\\Features\\Orders\\Extensions\\PendingOrdersWidget"
    ]
  }
}
```

## Runtime Strictness & Performance Notes

- `PlatformServiceProvider` enables `Model::shouldBeStrict(! $isProduction)`, `DB::prohibitDestructiveCommands($isProduction)`, and `Date::use(CarbonImmutable::class)` automatically.
- `FeatureCatalog::load()` memoizes per process and reads `bootstrap/cache/platform.php` when `php artisan platform:cache` (or `php artisan optimize`) has run—eliminating per-request filesystem scans in production and under Laravel Octane.
- Feature route files declare their own `Route::middleware(...)`, `prefix(...)`, and `name(...)` groups explicitly; `FeatureModule` adds no hidden middleware.
- One owning feature does not eliminate concurrency: actions must still use `DB::transaction()`, row locks (`lockForUpdate()`), unique DB constraints, idempotency keys, and `afterCommit()` queue dispatching where required.
