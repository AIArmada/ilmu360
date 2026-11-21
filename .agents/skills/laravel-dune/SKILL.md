---
name: laravel-dune
description: Bootstrap Laravel Dune in a fresh app or build and evolve capabilities in an adopted Laravel codebase (Features, EntryPoints, Extensions, State Owners, Host contracts, and mechanical CI checks).
---

# Laravel Dune — Feature-Owned Architecture

Build feature-owned, agent-friendly Laravel applications using Laravel's native container, Eloquent, queues, Boost MCP, and deterministic static analysis. Read [references/architecture.md](references/architecture.md) for the full contract and [references/tooling.md](references/tooling.md) for Composer/CI integration.

## When to use this skill

- Initializing a fresh Laravel 11 / 12 / 13 project with Laravel Dune.
- Adding a new Feature, Action, EntryPoint, Extension, Api contract, or Host integration in an already-adopted app.
- For converting an existing conventional Laravel app, use the **`laravel-dune-convert`** skill instead.
- For building or converting a reusable Laravel Composer package (`src/` + Spatie skeleton), use the **`laravel-dune-package`** skill instead.

## Bootstrap a fresh Laravel app

Resolve `$SKILL_DIR` to `.ai/skills/laravel-dune` (or your agent's installed skill copy) and `$PROJECT_ROOT` to the Laravel root. Preview first, then apply:

```sh
python3 "$SKILL_DIR/scripts/arch_scaffold.py" init --root "$PROJECT_ROOT"
python3 "$SKILL_DIR/scripts/arch_scaffold.py" init --root "$PROJECT_ROOT" --apply
python3 "$SKILL_DIR/scripts/arch_scaffold.py" feature --root "$PROJECT_ROOT" --name Orders
python3 "$SKILL_DIR/scripts/arch_scaffold.py" feature --root "$PROJECT_ROOT" --name Orders --apply
```

Complete adoption in the same task:

1. Register `App\Platform\PlatformServiceProvider::class` **once** in `bootstrap/providers.php`. By default (`discovery: "auto"`), `PlatformServiceProvider` discovers every `app/Features/*/FeatureServiceProvider.php` automatically with zero shared-root edits. (Only use `--discovery native` if the project explicitly prefers listing every feature provider manually.)
2. Install dev dependencies compatible with the locked Laravel/PHP versions:
   ```sh
   composer require --dev 'nikic/php-parser:^5' 'phpstan/phpstan:^2.3' 'larastan/larastan:^3.14'
   ```
3. Add Composer scripts (`arch:boundaries`, `arch:writes`, `arch:check`) and wire `composer arch:check` plus Pest/PHPUnit tests into CI (see [references/tooling.md](references/tooling.md)).
4. Run `php artisan boost:update` so Laravel Boost indexes `.ai/guidelines/laravel-dune.blade.php`, `.ai/rules/`, and `.ai/skills/`.
5. Remove or replace the example `GetStatus` / `StatusController` stub when implementing real behavior.

## Build features the Laravel Dune way

1. **Use Boost MCP first:** Check `.ai/rules/index.md`, run `search-docs` for version-accurate Laravel/Livewire/Filament/Inertia/Pest APIs, and inspect `database-schema` before touching persistence.
2. **Keep the shortest path concrete:** Write `final readonly class` Actions in `app/Features/<Name>/Actions/` and let Laravel inject them directly into thin controllers, jobs, commands, Livewire, or Filament adapters.
3. **Declare durable ownership:** Claim every `table:<name>` and `store:<name>` once in `app/Features/<Name>/feature.json` and list the local `Actions\` / `Persistence\` classes permitted to mutate it. Multiple named actions (`PlaceOrder`, `CancelOrder`) may share ownership of the same table—never build a god-class `OrderWriter`.
4. **Explicit model tables & no hidden Observer writes:** Declare literal `protected $table = '...';` on every concrete feature model. Keep mutation logic in named Actions; observers/listeners only delegate to Actions.
5. **Use `Extensions/` instead of shared switches:** For repeatable domain plugs (widgets, agent tools, report renderers, webhook handlers), place classes in `app/Features/<Name>/Extensions/`, list them under `"extensions": { "<slot>": [...] }` in `feature.json`, and resolve via `App\Platform\ExtensionRegistry`.
6. **Keep `Api/` and `Shared/Contracts/` pure:** Only create an `Api/` interface + `final readonly` DTO when another feature calls this capability. Never leak Eloquent models, Builders, or `Illuminate\*` types across feature boundaries.
7. **Keep third-party SDKs and OS/process calls in `app/Host/`:** Define a pure interface in `app/Shared/Contracts/`, implement it in `app/Host/<Integration>/`, and bind it in `AppServiceProvider`.
8. **Respect anti-slop bans:** No `sleep()`/`usleep()`, no `env()` outside `config/`, no `Model::unguard()`, no inline `@phpstan-ignore`, and no narrative comments outside the 5-item Comment-Sicko keep leash.

## Verify before declaring done

```sh
composer arch:check
php artisan test --compact
php artisan platform:cache && php artisan route:cache && php artisan config:cache
php artisan optimize:clear
```

Confirm zero errors in Boost `last-error` / `browser-logs` and prove observable behavior (including authorization, tenancy, rollback, and retry idempotency).
