---
name: laravel-dune-verify
description: Create or run a Layer 3 runtime verification harness for a Laravel app, combining a user-POV 4-H2 Feature Map, a reusable verification CLI, Laravel Boost MCP inspection tools, and cache/behavior proof checks.
---

# Laravel Dune — Runtime Verification & Feature Map

"It compiles" is not evidence. This skill builds and operates Layer 3 of the Poteto Trust Pyramid for Laravel apps—giving coding agents a repeatable, low-token way to drive the real application like a user, inspect side effects via **Laravel Boost MCP**, and capture durable proof artifacts.

## 1. Interview the repo (not the user)

Inspect the codebase and Boost `application-info` to determine:
- **Surface:** Blade/Livewire, Inertia (React/Vue/Svelte), Filament, JSON API, Artisan CLI, or hybrid.
- **Launch:** Exact local boot command (`php artisan serve`, Sail, Herd, or Vite + PHP), disposable database/sqlite path, migrations, seeders, and how to confirm the app is *usable* (not merely listening).
- **Drive:** Prefer existing harnesses (Pest Browser / Playwright / Dusk / HTTP curl / Artisan CLI) and stable handles (ARIA roles, accessible names, `data-testid` / `data-component`—**never** generated CSS/Tailwind utility chains or brittle DOM positions).
- **Observe:** HTTP responses, ARIA snapshots, screenshots with visible app chrome, DB rows read back via Boost `database-query`, and zero errors in Boost `last-error` + `browser-logs`.
- **Isolate:** Disposable SQLite or dedicated test DB + unique port so concurrent agents never corrupt a user's session.

## 2. Create the 6-part verification harness

Place the Feature Map in `verify/feature-map/` and the reusable CLI in `verify/cli/verify-app` (adapted from [assets/verify-cli-template.sh](assets/verify-cli-template.sh)):

1. **Launch:** Boots from a clean/seeded DB to a usable ready state.
2. **Doctor:** Single read-only command verifying app URL, git revision, DB connection, and auth/seed readiness before driving.
3. **Drive:** Composable, `--help`-documented commands returning JSON where possible, with `--dry-run` on destructive steps and prescriptive errors.
4. **Evidence:** Captures *(a) the user action*, *(b) the resulting UI/HTTP state*, and *(c) a read-only second-view cross-check* of persisted state (reopening the view or querying via Boost `database-query`).
5. **Cleanup:** Stops only processes started by this run; **proof artifacts must survive teardown.**
6. **Helpers:** Every helper script is executable and directly invoked with literal commands.

## 3. Seed the 4-H2 Feature Map

Create `verify/feature-map/README.md` plus one file per core user-facing feature following [references/feature-map-template.md](references/feature-map-template.md). Every feature file uses strictly four H2 headings:
1. `## Sub-features`
2. `## How to get to it (user POV)`
3. `## Driving it with <harness>` (begins with `Preconditions:`, pairs actions with exact commands & observable outcomes)
4. `## Gotchas` (traps, wrong surfaces; **never weaken a journey to make it pass**)

Keep internal PHP class names and code paths out of the Feature Map—agents discover code at runtime.

## 4. Mandatory verification checklist for every PR

1. Run `composer arch:check` (AST boundaries + Larastan `OwnedWriteRule`) and normal PHPStan.
2. Run affected Pest/PHPUnit feature tests + `tests/Arch/ArchitectureTest.php`.
3. Drive the changed user journey through `verify/cli/verify-app` (or HTTP/Browser harness) and verify persistence from a second read-only view.
4. Check Boost MCP `last-error` and `browser-logs` to prove zero uncaught exceptions or console errors.
5. On route, config, or feature-manifest changes, verify `php artisan optimize` (including `platform:cache`, `route:cache`, `config:cache`) boots cleanly, then run `php artisan optimize:clear`.
6. **Prove the harness itself:** Before handing off a new verification skill/CLI, run Launch → Doctor → Drive 1 feature → Capture evidence → Cleanup and confirm proof files survived cleanup.
