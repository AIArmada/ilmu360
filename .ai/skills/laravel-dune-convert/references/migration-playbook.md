# Laravel Migration Playbook (Slice-by-Slice Edge Cases)

## 1. Choosing Slice Order
- **Start with leaf capabilities** (e.g., `Webhooks`, `Notifications`, `Exports`, `Invitations`) that write 1–2 tables and have minimal inbound callers.
- **Keep `Auth` / `Users` / `Tenancy` late or in a dedicated `Identity` feature:** Laravel's `config/auth.php`, Sanctum, Fortify, Breeze, or Jetstream reference the authenticatable model by FQCN. When moving `App\Models\User` to `App\Features\Identity\Models\User`, update `config/auth.php`, `database/factories/`, policies, and morph maps (`Relation::enforceMorphMap`) in one atomic PR.

## 2. Polymorphic Relations & Morph Maps
Moving an Eloquent model changes its FQCN! Before moving any model involved in `morphTo` / `morphMany` / Spatie Permissions / Activitylog / Notifications:
- Inspect `database-schema` via Boost MCP for `*_type` columns containing literal `'App\\Models\\...'` class names.
- Define an explicit `Relation::enforceMorphMap([...])` mapping stable short aliases (e.g. `'order' => Order::class`) and backfill existing rows if needed before moving the model class.

## 3. Eloquent Observers & Model Hooks
- Platform bans hidden multi-table mutations inside `Observer` classes or `Model::booted()` callbacks because agents reading a controller or action cannot see them.
- **Recipe:** Move the side effect into a named Action (e.g. `PlaceOrder` calls `RecordAuditLog` or dispatches an after-commit event). If a legacy observer must stay temporarily for declarative single-model defaults (like generating a ULID), keep zero DB/store writes inside it; if it triggers another feature, make it delegate to a declared Action or after-commit domain event.

## 4. Livewire, Volt & Filament
- **Livewire / Volt:** Moving a component class into `app/Features/<Name>/EntryPoints/Livewire/` changes its auto-discovered tag name unless registered explicitly in `FeatureServiceProvider::boot()` via `Livewire::component('orders.create', CreateOrder::class)`. Route all persistence inside component actions through the feature's concrete `Actions\` class—never call `$this->model->save()` directly inside a Livewire component!
- **Filament:** Register Resources/Pages/Widgets in the panel provider using `discoverResources()` or explicit `->resources([...])`. Override Filament's `handleRecordCreation` / `handleRecordUpdate` / `handleRecordDeletion` to delegate directly to the owning Feature's concrete `Actions\` class so `OwnedWriteRule` passes cleanly.

## 5. Inertia (React / Vue / Svelte) & Wayfinder
- Keep Inertia controllers thin inside `app/Features/<Name>/EntryPoints/Http/`.
- Pass immutable DTO arrays or JsonResources to `Inertia::render(...)`—never leak raw Eloquent models with sensitive attributes or lazy-loaded relations (`Model::shouldBeStrict()` will immediately catch accidental N+1s!).
- Re-run Wayfinder / Ziggy type generation after moving routes and verify route names stayed identical via `compare_routes.py`.

## 6. Policies, Factories, Commands, Events & Queues
- **Policies:** Register moved policies explicitly in `FeatureServiceProvider::boot()` via `Gate::policy(Order::class, OrderPolicy::class)` so Laravel doesn't look in `App\Policies\`.
- **Factories:** Declare `protected static function newFactory(): OrderFactory` on feature models (or configure `Factory::useNamespace(...)`) so `Order::factory()` keeps working in Pest/PHPUnit tests.
- **Jobs:** Move job classes into `EntryPoints/Jobs/`, keep `handle()` thin (delegate to Action), and check serialized queue payloads before deploying (drain queues or deploy compatibility aliases only for in-flight jobs if zero-downtime queues are active).
- **Commands & Schedules:** Register feature commands in `FeatureServiceProvider::boot()` (`$this->commands([...])`) and schedules in `Routes/console.php`.
- **Listeners:** Pick ONE listener registration mechanism (explicit `Event::listen` in `FeatureServiceProvider` OR Laravel auto-discovery—never both, or listeners will fire twice!). Verify invocation counts in tests.

## 7. Migrations & Seeders
- **Leave existing `database/migrations/` history in place**—never move or rename already-run migration files.
- Place *new* feature migrations either in `database/migrations/` or `app/Features/<Name>/Database/Migrations/` (auto-loaded by `FeatureModule`), keeping timestamps globally unique.
