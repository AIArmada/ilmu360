# SpeakerSeeder Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `SpeakerSeeder` (117 archived production persons) becomes the sole speaker/person seed source; event seeders draw speakers only from it; embedded `event_links` are removed.

**Architecture:** Delete `PersonSeeder`, `SpeakerEventSeeder`, `ProductionSpeakerSeeder`; wire `SpeakerSeeder` into `DatabaseSeeder` in the old `PersonSeeder` slot; statically rewrite `EventSeeder` schedule attributions to `SpeakerSeeder` names and drop its `Person::create` fallback. `AdvancedEventSeeder`/`seedBulkEvents` already draw from whatever is in `persons`, so no change there.

**Tech Stack:** Laravel seeders (PHP 8.5), Pest 5, PHPStan level 6, Pint.

**Spec:** User decisions in session 2026-10-30: (1) remove the three seeders so `SpeakerSeeder` is the only human seed; (2) event seeder uses `SpeakerSeeder` persons only; (3) event info removed from `SpeakerSeeder`.

## Global Constraints

- UUID PKs / `foreignUuid`, no DB constraints/cascades, no `SoftDeletes` (use `KeepsDeletedModels`).
- Never create addressing seeders in `database/seeders`; keep calling bundled `AddressingSeeder`.
- All new/modified code passes PHPStan level 6 and Pint (`vendor/bin/pint --dirty`).
- Parallel Pest is the runner (`vendor/bin/pest --parallel`); TIA via `./pest --tia` wrapper only.

## Review Focus

- Rerun idempotency: re-seeding must not duplicate persons, names, terms, redirects, or events (schedule matching keys on person name + date).
- Schedule entries without a resolvable person must still seed as personless events (institution organizer via backfill), never create a `Person`.
- `ReferenceSeeder::syncAuthors` still creates non-speaker author `Person`s (`speaker_status=Inactive`) — conscious exception, not event speakers.
- `SpeakerSeederTest` counts (117/117/19/18) must hold after `event_links` removal.
- `InstitutionSeederTest` batch expectations must match the new `DatabaseSeeder` call list exactly.

---

### Task 1: Strip event info from `SpeakerSeeder`

**Files:**
- Modify: `database/seeders/SpeakerSeeder.php`
- Test: `tests/Feature/SpeakerSeederTest.php` (no change expected; counts hold)

**Interfaces:**
- Consumes: nothing new.
- Produces: `SPEAKERS` entries without `event_links` key; no `importEventLinks()`; `run()` calls 7 importers.

- [ ] **Step 1: Remove `event_links` data blocks via script.** Delete all 117 `event_links` entries: 30 single-line `            'event_links' => [],` lines and 87 multi-line blocks from `            'event_links' => [` to their matching 12-space `],`. Script must assert 117 removals, 0 remaining `'event_links'` occurrences.
- [ ] **Step 2: Remove the importer and its wiring.** Delete `importEventLinks()` method, its `$this->importEventLinks($speaker, $person)` call in `run()`, counters `$createdEventLinkCount` / `$skippedEventLinkCount` (declaration + table rows), unused imports `App\Enums\EventKeyPersonRole`, `App\Models\Event`, `App\Models\EventKeyPerson`. Update class docblock (drop "and event links" / "Event links resolve..." sentences).
- [ ] **Step 3: Verify.** `php -l database/seeders/SpeakerSeeder.php`; `grep -n "event_links\|importEventLinks\|EventKeyPersonRole\|Models\\\\Event" database/seeders/SpeakerSeeder.php` returns nothing. `vendor/bin/pest --parallel tests/Feature/SpeakerSeederTest.php` passes (117 persons / 117 addresses / 19 socials / 18 redirects / 0 involvements).

### Task 2: Delete the three seeders, wire `SpeakerSeeder` into `DatabaseSeeder`

**Files:**
- Delete: `database/seeders/PersonSeeder.php`, `database/seeders/SpeakerEventSeeder.php`, `database/seeders/ProductionSpeakerSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php:69-80`, `tests/Feature/InstitutionSeederTest.php:18-73`
- Delete: `tests/Feature/PersonSeederIdempotencyTest.php`

**Interfaces:**
- Consumes: Task 1 (`SpeakerSeeder` standalone-safe).
- Produces: default pipeline seeds persons exactly once via `SpeakerSeeder`.

- [ ] **Step 1: Delete the files.** `git rm database/seeders/PersonSeeder.php database/seeders/SpeakerEventSeeder.php database/seeders/ProductionSpeakerSeeder.php tests/Feature/PersonSeederIdempotencyTest.php`.
- [ ] **Step 2: Update `DatabaseSeeder`.** Replace `$this->seedWhenEmpty(Person::class, PersonSeeder::class);` with `$this->seedWhenEmpty(Person::class, SpeakerSeeder::class);` (keeps guard semantics, idempotent reruns). Remove `SpeakerEventSeeder::class,` from the primary-entities batch (same namespace, no import edits needed).
- [ ] **Step 3: Update `InstitutionSeederTest`.** Swap `use Database\Seeders\PersonSeeder;` → `use Database\Seeders\SpeakerSeeder;`, drop `use Database\Seeders\SpeakerEventSeeder;`, expect `[SpeakerSeeder::class]` batch, remove `SpeakerEventSeeder::class,` from the events batch.
- [ ] **Step 4: Verify.** `grep -rn "PersonSeeder::class\|SpeakerEventSeeder::class\|ProductionSpeakerSeeder::class" --include="*.php" app/ database/ tests/ config/ routes/` returns nothing. `php -l` both edited files. `vendor/bin/pest --parallel tests/Feature/InstitutionSeederTest.php tests/Feature/SpeakerSeederTest.php` passes.

### Task 3: `EventSeeder` schedule uses `SpeakerSeeder` persons only

**Files:**
- Modify: `database/seeders/EventSeeder.php` (`$schedule` persons, `resolveSchedulePerson()`)
- Test: existing `EventSeeder`-covering tests (rerun idempotency via `resolveExistingScheduleEvent` name+date match)

**Interfaces:**
- Consumes: Task 2 (`persons` table populated solely by `SpeakerSeeder` before `EventSeeder` runs).
- Produces: every schedule `person` value is a verbatim `SpeakerSeeder` `name`; no `Person` creation in the seeder.

- [ ] **Step 1: Statically remap schedule attributions.** Map the 46 distinct schedule `person` values to `SpeakerSeeder` names (order of first appearance → `SpeakerSeeder` file order round-robin; the single natural overlap `Ust Roslan Mohamed`→`Roslan Mohamed` falls out of the same rule if deterministically assigned — document the mapping in the commit message). Entries without `person` stay personless. Assert post-edit: every `'person' => '...'` value exists as a `SPEAKERS` `name`.
- [ ] **Step 2: Remove the create fallback.** In `resolveSchedulePerson()`, delete the `Person::query()->create([...])` branch; return `null` when no cached/existing person matches (event seeds personless, `backfillSeededEventRequiredFields()` assigns the institution organizer). Keep the `schedulePersonIds` cache and `GeneratePersonSlugAction::sync*` calls for existing persons.
- [ ] **Step 3: Verify.** `php -l`; script-assert all schedule names ⊆ SpeakerSeeder names and zero `Person::...create` in `EventSeeder.php`. `vendor/bin/pest --parallel` (affected: event/seeder tests) passes; seed twice in a scratch DB shows no duplicate events/persons.

### Task 4: Static analysis + formatting gate

- [ ] **Step 1: Run Pint.** `vendor/bin/pint --dirty --format agent` (or scoped to edited files if vendor present).
- [ ] **Step 2: Run PHPStan.** `vendor/bin/phpstan analyse --ansi`; zero new errors.
- [ ] **Step 3: Record results** in `tasks/todo.md` review section.
