---
paths:
  - app/Features/**
---

# Feature Modules

## Owned Capability Folder & Manifest
- Every directory under `app/Features/<PascalCase>/` must contain `feature.json` (schema v1) and `FeatureServiceProvider.php` extending `App\Platform\FeatureModule`.
- Declare every owned `table:<name>` and `store:<name>` in `feature.json` with the exact local `Actions\` or `Persistence\` FQCNs permitted to mutate it. Never register an `EntryPoints\` class as a write owner.
- Multiple named actions in the same feature (e.g. `PlaceOrder`, `CancelOrder`) may write the same owned table—do not create a god-class `OrderWriter`.

## Concrete Actions by Default
- Use concrete `final readonly class` Actions resolved automatically by Laravel's container. Do not create a redundant interface or repository for single-feature use cases.
- Actions own their `DB::transaction(...)` boundaries, explicit actor/tenant authorization checks (so jobs and CLI cannot bypass HTTP FormRequests), and idempotency on retried operations.

## Models Stay Private & Explicit
- Every concrete Eloquent model under `Models/` MUST declare an explicit literal `protected $table = '...';` matching a `table:*` owned by this feature.
- Never import another feature's `Models/`, `Actions/`, `Persistence/`, or `EntryPoints/`. Cross-feature reads/writes go strictly through the owning feature's `Api/` namespace.
- Keep mutation side effects out of Eloquent Observers and `booted()` hooks; observers/listeners may only delegate to a declared named Action.

## Thin EntryPoints & Isolated Routes
- `EntryPoints/{Http,Jobs,Console,Livewire,Filament}/` only parse/validate transport input, authorize the actor, call the Action/Query, and format the response.
- Feature route files (`Routes/web.php`, `Routes/api.php`, `Routes/console.php`) MUST declare their own middleware, URI prefixes, and route name groups explicitly.
- If overriding `FeatureServiceProvider::boot()`, always call `parent::boot()`.
- For repeatable domain plugs (widgets, tools, renderers, handlers), add an isolated class under `Extensions/` and register it with `ExtensionRegistry` instead of branching in a shared root file.
