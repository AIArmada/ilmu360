## Workflow Orchestration

### 1. Plan Node Default
Enter plan mode for ANY non-trivial task (3+ steps or architectural decisions); write detailed specs upfront. If something goes sideways, STOP and re-plan — don't keep pushing. Use plan mode for verification, not just building.

### 2. Subagent Strategy
Use subagents liberally (research, exploration, parallel analysis; one tack each) to keep the main context clean; throw more compute at complex problems.

### 3. Self-Improvement Loop
After ANY user correction, update `tasks/lessons.md` with the preventive pattern; iterate ruthlessly. Review lessons at session start.

### 4. Verification Before Done
Never mark complete without proof: diff vs main when relevant, ask "would a staff engineer approve?", run tests, check logs. **Run tests once** — one run must capture everything (save output to a file for expensive suites); never re-run just to re-read output.

### 5. UI Tracking Review
When a task changes UI behavior/navigation/forms/filters/tabs/actions/state, evaluate product event tracking: curated high-signal intent/workflow transitions (not blanket clicks); server-side Signals for backend-confirmed outcomes; centralized Signals UI helper for frontend-only intent. Skip only purely cosmetic changes that alter no behavior, entry points, or paths.

### 6. Demand Elegant (Balanced)
For non-trivial changes ask "is there a more elegant way?" — if hacky, re-implement knowing what you know now. Skip for simple obvious fixes.

### 7. Autonomous Bug Fixing
Just fix reported bugs (logs, errors, failing tests — including CI) with zero hand-holding or context switches.

### 8. Laravel Actions Where Appropriate
Prefer Actions for reusable orchestration (validation-adjacent normalization, transactions, side effects, multiple entrypoints); extend existing Actions before creating new ones. Invoke the `laravel-actions` skill for entrypoint patterns and testing.

### 9. Spatie Laravel Data Adoption
Use `spatie/laravel-data` as a boundary-contract layer, not a default: API response DTOs for large/nested/reused payloads or public-mobile consistency; controller→action payloads only when large/nested/reused/shared. Start output-only (keys/nullability/nesting/status/errors unchanged + parity tests); treat input/validation refactors as behavior changes (contract tests first). Not for Livewire/Filament form state, simple readonly DTOs, tiny endpoints, or dynamic catalog/config payloads (prefer arrays/readonly objects) unless proven beneficial. When in doubt, fewer Data classes on stable boundaries.

## Task Management
1. *Plan First*: plan to `tasks/todo.md`. 2. *Verify Plan*: check in before implementing. 3. *Track Progress*: mark items done. 4. *Explain Changes*: summary each step. 5. *Document Results*: review section in todo. 6. *Capture Lessons*: `tasks/lessons.md` after corrections.

## Core Principles
- *Simplicity First*: smallest possible change. *No Laziness*: root causes, no temp fixes, senior standards. *Minimal Impact*: touch only what's necessary.

---------

# Filament Form Data Handling with Enums

Filament deserializes enums in form closures but passes strings after submit — match the context:

| Context | Data | Compare with |
|---|---|---|
| Field closures (`->disabled()`, `->visible()`, `->required()`, `->hidden()`, `->afterStateUpdated()`, `->reactive()`, validation reading `Get $get`) | Enum objects | `EventAgeGroup::Children` directly |
| `submit()`/`action()`/validated state | Strings | `EventAgeGroup::Children->value` |
| Database queries | Backing values | `->value` (e.g. `where('age_group', …->value)`) |

```php
->disabled(function (Get $get): bool {
    $ageGroups = $get('age_group') ?? []; // [EventAgeGroup::AllAges, …] — objects, so ->value never matches here
    return in_array(EventAgeGroup::Children, $ageGroups, true)
        || in_array(EventAgeGroup::AllAges, $ageGroups, true);
});
```

Unsure? Log `$get('field')` + `array_map('gettype', …)` and check `storage/logs/laravel.log`.

---

# Model Sorting with Spatie Eloquent Sortable

Always use `spatie/eloquent-sortable` (never manual sort columns): model `implements Sortable` + `SortableTrait` with `order_column_name => 'order_column'`, `sort_when_creating => true`; migration `$table->unsignedInteger('order_column')->nullable()`; `order_column` in `$fillable`; query via `->ordered()` (incl. relationships) — never `orderBy('order_column')` or manual values. No current app models use it — reintroduce per model when ordering is needed.

---

# Event Classification (Package Taxonomies)

All event classification uses the events package taxonomy store — `EventTaxonomy` + `EventTerm` + `EventClassification`, read via `$event->classifications()`. There is no Spatie-tags layer: no `Tag` model, no `taggables` writes, no `/tags` alias (removed; it 404s).

- Vocabulary: `App\Enums\EventTaxonomyCode` (`domain`, `discipline`, `source`, `issue` → label/description/color/icon) plus `EventCategoryCatalog` (hierarchical event categories); seed terms live in `App\Enums\TaxonomyTerm\*TermCode`.
- Sync: app `SyncEventClassificationsAction` (vocabulary adapter) → package synchronizer; payload keys `domain_tags` / `discipline_tags` / `source_tags` / `issue_tags` (+ `taxonomy_term_ids`, `event_category_ids`).
- Catalog: `/taxonomy-terms/{type}` is the canonical options endpoint (via `FrontendCatalogService::taxonomyTerms()`); user-created terms are `firstOrCreate`d active with `sort_order` 0.

---

# Testing Best Practices

Default to parallel Pest (`vendor/bin/pest --parallel`, + `--filter=…`/`--compact`) — parallel is isolation-safe, never sequential `php artisan test`. Follow `.ai/rules/tests.md` for the toolchain (TIA, agent probes, PHPStan, Rector); invoke `testing-best-practices` when designing tests and `pest-testing` for Pest syntax.

---

# Static Analysis (Runtime Extensions + PHPStan 6)

Behavior safety beats analysis convenience: never remove a seemingly-undefined method (e.g. `->closeOnSelect()`, `->quickAdd()`) without checking `macro()`/`hasMacro()`, mixins/traits, and plugin extensions (e.g. Filament quick-add) — if runtime-provided, keep it and silence PHPStan narrowly (stub/focused ignore + documented reason); remove only when no implementation source or feature dependency exists. All new/modified code must pass PHPStan level 6 (`vendor/bin/phpstan analyse --ansi`): real fixes over ignores, no new errors, no broad baselines.

---

# Timezone Handling (Critical)

Store UTC; resolve viewer tz per-request via `UserTimezoneResolver`; format via `UserDateTimeFormatter::format($date, 'h:i A')` / `::translatedFormat($date, 'l, j F Y')` — never `->format()` in public views (unless storage-tz output is intended) and never hardcode region tz (e.g. `Asia/Kuala_Lumpur`) in public query/filter logic. Date-only filters (`starts_after`, …): parse user-local date → UTC day boundaries (`parseUserDateToUtc`). Prayer labels (`Selepas Jumaat/Maghrib/Tarawih`): `prayer_display_text` keyword + `prayer_reference` mapping; `Tarawih` is label-only, not a `PrayerReference` value.

---

# Query Safety Notes

Qualify columns in reused scopes (`events.is_active`, not `is_active`) to survive joins (esp. SQLite tests). Public listings: explicitly constrain `status = approved` even with broader reusable scopes.

---

# OpenAI Developer Docs MCP

Always use the OpenAI developer documentation MCP server (`openaiDeveloperDocs`) if you need to work with the OpenAI API, ChatGPT Apps SDK, Codex, Responses API, or any other OpenAI product — without the user having to explicitly ask.

---

# Git Safety

Never run destructive git commands (`clean -fdx`, `reset --hard`, `checkout -- .`, `push --force`/`--delete`, `stash drop`/`clear`) or multi-commit operations without explicit per-command approval — ask first. Safe: `git stash` / `stash pop`.
