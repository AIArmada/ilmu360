<laravel-boost-guidelines>
=== .ai/addressing rules ===

# Addressing & Geography Guidelines

This application uses `aiarmada/addressing` natively. Treat the package's current migrations, models, country profiles, and actions as canonical: direct country/state/city IDs plus role-based address-area assignments. Do not invent fixed admin-area columns, aliases, or backward-compatibility shims, and never assume one country's profile maps to another's fixed columns.

## Canonical address data

| Data | Storage |
|------|---------|
| Country | `addresses.country_id` (UUID) |
| State or federal territory | `addresses.state_id` (UUID) |
| City | `addresses.city_id` (UUID, optional) |
| Administrative and postal areas | `address_area_assignments` rows (`address_id`, `address_area_id`, `role`, `is_primary`, `metadata`) |

Use `Address::areaAssignments()`, `AddressAreaAssignment`, `AddressLocationData::areaAssignments`, `SyncAddressAreaAssignmentsAction`, and the configured `CountryAddressProfile`.

For Malaysia, the profile defines roles such as `administrative_division`, `administrative_district`, `administrative_subdivision`, and `postal_locality`. The selected state remains `state_id`; an address-area assignment is not a substitute for the state relation.

Denormalized text and provider/navigation fields may also be stored when useful: `line1`, `line2`, `postcode`, `state`, `city`, `country`, `country_code`, and the package's geo/provider fields.

## Form and validation

Use the package's country → state → optional city flow, then the area roles from the selected country's profile. Resolve options via package profile/provider APIs and persist via `SyncAddressAreaAssignmentsAction`; do not duplicate hierarchy rules in callers.

## Forbidden legacy keys

- Fixed `admin_area_1_id` through `admin_area_4_id` columns.
- Form-only aliases such as `state_area_id`.
- Removed geography keys/relations: `district_id`, `subdistrict_id`, `district()`, `subdistrict()`, `stateArea()`, `districtArea()`, `subdistrictArea()`.
- Integer geography tables or integer geography foreign keys.
- Dual API keys or caller-side remapping of obsolete keys.

`area_assignments`, `areaAssignments`, `AddressAreaAssignment`, and configured role names are current package APIs, not legacy consumers.

## Seed and import

- Countries: `php artisan address:seed-countries` or the package country seeder.
- Malaysia states/cities: `AIArmada\Addressing\Database\Seeders\MalaysiaGeographySeeder`.
- Administrative/postal areas: import `address_areas` through package/import actions, then sync role-based assignments.

## Snapshots

Historical moments use `AddressSnapshot` or event-location snapshots; do not point historical records at mutable live address state.

## Verification

```bash
# Application code must not use removed fixed-column or legacy geography APIs.
rg -n "admin_area_[1-4]_id|state_area_id|\\bdistrict_id\\b|\\bsubdistrict_id\\b|stateArea\\(|districtArea\\(|subdistrictArea\\(" app/ tests/ database/ resources/ --glob '!**/storage/**'
```

=== .ai/brand rules ===

## Branding

The application is being rebranded to **ilmu360°** (ilmu360 with a degree sign after 360).

=== .ai/database rules ===

# Database Guidelines

- **Primary keys**: `uuid('id')->primary()`. **Foreign keys**: `foreignUuid('col')` only — UUIDs end-to-end, no integer geography FKs.
- **Geography**: package addressing storage only — `country_id`, `state_id`, `city_id` plus role-based `address_area_assignments` (see `.ai/guidelines/addressing.blade.php`).
- **Never** DB-level constraints or cascades: no `->constrained()`, no `->cascadeOnDelete()`, no FK constraints; enforce integrity in application logic (models/actions/services).
- **Migrations**: keep safe/idempotent; no `down()` required.
- **No SoftDeletes**: never use Laravel's `SoftDeletes` trait or `$table->softDeletes()`. This application uses `spatie/laravel-deleted-models` (`KeepsDeletedModels` trait) instead, which stores a full copy of the deleted model in a separate `deleted_models` table.

## Verification

- Ensure no constraints/cascades slipped in: `rg -n -- "constrained\(|cascadeOnDelete\(" packages/*/database`
- Ensure no SoftDeletes slipped in: `rg -n -- "softDeletes\(\)|SoftDeletes" database/ app/Models/`

=== .ai/friendly rules ===

# Friendliness Audit (Package Extensibility)

## Extension Seams

- Put stable extension seams (contracts, hooks, resolvers, support classes) in `commerce-support` when multiple packages benefit.
- When a capability may grow variants, prefer contracts over hard-coded branching.
- Use tagged registrars or contributor interfaces for optional integrations instead of service-provider branching.

## Contracts

- Every resolvable concern gets a contract (`Contracts/`); default implementations live in `Services/` or `Resolvers/`.
- Ship null-object resolvers (`Null*Resolver`) with the contract so downstream code never checks "is this installed?".
- Separate workflow policy from implementation (e.g. `EventLifecycleWorkflow` interface + `DefaultEventLifecycleWorkflow`).

## Actions

- Extract reusable Actions for orchestration spanning transactions, side effects, normalization, or multiple entrypoints; keep trivial single-step handlers inline.
- Reuse existing Actions before creating new ones; merge Action/Service duplicates (one Backfill/Sync implementation, not two).

## Services

- Every service needs a clear role and (usually) a contract — contract-less or numerous services are catch-all smells; split them into Actions behind a thin service facade.

## Support Folder

- Don't mix policy, integration wiring, and normalization in `Support/`; split into `Support/Policy/`, `Support/Integration/`, `Support/Normalization/` as categories emerge.

## Verification

- Duplicate orchestration: `rg -n "function (handle|execute|process)" packages/*/src/Actions packages/*/src/Services`
- Services without contracts: `rg -l "class.*Service" packages/*/src/Services | xargs rg -L "implements"`
- Null-object adoption: `rg "Null.*Resolver|Null.*Dispatcher" packages/`

=== .ai/general rules ===

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

Always use `spatie/eloquent-sortable` (never manual sort columns): model `implements Sortable` + `SortableTrait` with `order_column_name => 'order_column'`, `sort_when_creating => true`; migration `$table->unsignedInteger('order_column')->nullable()`; `order_column` in `$fillable`; query via `->ordered()` (incl. relationships) — never `orderBy('order_column')` or manual values. Used by: `Tag` (scoped by type), `Topic`, `EventType`.

---

# Unified Tag System (Spatie Tags + TagType)

All tagging uses Spatie's native polymorphic `taggables` — no custom pivot. Types (`App\Enums\TagType` → label/color/icon/description/order): `domain` (Aqidah/Syariah/Akhlak…), `discipline` (Tafsir/Sirah/Fiqh…), `source` (Quran/Hadith/Turath…), `issue` (Rasuah/Kepimpinan…).

- Storage: `type` stays a **string** (Spatie's `tagsWithType()` needs strict string match); use `$tag->type_enum` for the enum.
- Native API only: `attachTag(s)` / `syncTags` / `detachTag(s)`, `Tag::ofType(TagType::X|'x')` / `getWithType(…)` / `$event->tagsWithType(…)`, `Tag::ordered()`.
- Status: `pending` (user-created Discipline/Issue) vs `verified` (pre-seeded Domain/Source); event approval auto-verifies attachments; always query/dropdown with both (`whereIn('status', ['verified', 'pending'])`), like Speaker/Institution/Venue.
- Sorting: `order_column`, scoped per type, auto-assigned. No extra fields (`is_active`, `is_system`, `description`, `weight`, `is_primary`).

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

=== .ai/lifecycle rules ===

# Lifecycle Audit (Status & Timestamp Columns)

## Core Rules

- Every model with a status/state machine has a `status` column (string-backed enum); never use `is_*` booleans for derivable state, and never bury operational lifecycle events in JSON or booleans.
- Each terminal status transition records a dedicated `timestampTz` column (`published_at`, `cancelled_at`, `archived_at`); keep scheduled deadlines (`expires_at`, `registration_opens_at`) separate from transition times.

## Naming

- Status column: `status` (not `state`/`status_code`/`is_active`); timestamps: `{status_name}_at` (`confirmed_at`, `refunded_at`, …); visibility: `visibility` enum (not `is_public`/`is_visible`); deadlines: `{purpose}_at` (`registration_opens_at`, `check_in_closes_at`).

## Transition Integrity

- Centralise status→timestamp mapping in the transition method/trait: on X→Y set `y_at = now()` plus `last_state_change_at = now()`; cast lifecycle timestamps as `'immutable_datetime'`.

## Migration Pattern

- Phase 1: add nullable `status` + `*_at`, backfill. Phase 2: drop old booleans (`is_active`, `is_public`). Phase 3: make `status` NOT NULL.

## Verification

- Boolean anti-patterns: `rg -n "is_active|is_public|is_archived|registration_required|waitlist_enabled|approval_required" packages/*/database/migrations`
- `*_at` coverage: `rg -n "timestampTz\('.*_at'\)" packages/*/database/migrations`
- `state` vs `status`: `rg -n "\bstate\b" packages/*/src/Models`

=== .ai/livewire rules ===

# Livewire 4 Documentation Reference

This project uses Livewire 4. Invoke the `livewire-development` skill for any Livewire task (components, reactivity, validation, loading states, testing) and use `search-docs` for API details. Always follow the project's existing component format first.

=== .ai/media rules ===

# Media Management Guidelines (Spatie Medialibrary v11 + Filament v5)

Media architecture already implemented app-wide. Follow exactly when adding/modifying media features.

## Core Stack

- `spatie/laravel-medialibrary` v11 + `filament/spatie-laravel-media-library-plugin` v5
- Config: `config/media-library.php`; upload policy: `app/Providers/AppServiceProvider.php` (static boot guards — keep for Octane)
- Naming: `app/Support/Media/MediaFileNamer.php`; paths: `app/Support/Media/MediaPathGenerator.php`

## Global Upload Policy (Do Not Bypass)

All `SpatieMediaLibraryFileUpload` fields inherit: size from `media-library.max_file_size` (10MB), `maxParallelUploads(2)`, `appendFiles()`, immutable cache header (`public, max-age=31536000, immutable`), filename `<slug-or-model-base>-<8-char-ulid>.<ext>`, human `name` from model + collection label, `custom_properties` = `collection` + `original_file_name`.

## Naming & Paths

- Storage base priority: `slug` → `name` → `title` → `label` → morph alias/class basename. Display name: `<Collection Label> - <Subject Label>` (fallback: original filename). Labels: poster→Event Poster, cover→Cover Image, logo→Logo, avatar→Avatar, main→Main Image, gallery→Gallery Image, qr→QR Code, evidence→Evidence File.
- Directory: `{model_type_plural}/{uuid_shard}/{model_uuid}/{collection}/` (e.g. `events/019c/019c4228-…/poster/`); sharding avoids hot directories and groups by owner+collection.

## Media Library Config (`config/media-library.php`)

- `version_urls`, lazy loading (`default_loading_attribute_value`, `force_lazy_loading`), queued conversions (+ after commit), `FileBaseFileRemover`, image optimizers (JPEG/PNG/SVG/GIF/WebP/AVIF), generators (image/webp/avif/pdf/svg/video).
- Custom `file_namer` (`MediaFileNamer`) and `path_generator` (`MediaPathGenerator`).

## Model Collection Matrix (Canonical)

- **Event**: `cover` (16:9, required) + `poster` (3:4 portrait, required) + `gallery` — jpeg/png/webp, responsive, cover/poster single-file w/ placeholder. Conversions: `thumb` 1920×1080 crop webp+sharpen10 (cover,gallery); `card`/`preview` max-1920 webp (cover,poster).
- **Institution**: `logo` (jpeg/png/webp/svg, single, placeholder) + `cover` (responsive, single, placeholder) + `gallery` (responsive, multi). Conversions: `thumb` 1080² webp+sharpen10 (logo); `banner` 1920×1080 crop webp (cover); `gallery_thumb` 1920×1080 crop webp+sharpen10.
- **Speaker**: `avatar` (single, placeholder) + `main`/`cover` (responsive, single, placeholder) + `gallery` (multi) — jpeg/png/webp. Conversions: `thumb`/`profile` 1080² webp (+sharpen10 on thumb), `card` 1080×1440 (avatar); `main_thumb` 1080²+sharpen10, `display` 1080×1440 crop (main); `banner` 1920×1080 (cover); `gallery_thumb` 1920×1080+sharpen10.
- **Venue**: `main`/`cover` (responsive, single, placeholder) + `gallery` (multi). Conversions: `thumb` 1920×1080 crop+sharpen10 (all); `banner` 1920×1080 (main,cover).
- **Series**: `cover` (responsive, single) + `gallery` (multi). Conversion: `thumb` 1920×1080 crop+sharpen10 (both).
- **Reference**: `front_cover`/`back_cover` (responsive, single) + `gallery` (multi). Conversions: `thumb` 1080×1440 crop+sharpen10 (covers); `gallery_thumb` 1920×1080+sharpen10.
- **DonationChannel**: `qr` (single) → `thumb` 1080² webp. **Report**: `evidence` (jpeg/png/webp/pdf, multi, max 8) → `thumb` 1080² webp.

## Event Aspect Ratio Contract

- `cover` = primary website/app visual, always 16:9 (submit/contribution/admin forms, APIs, MCP images). `poster` = shareable flyer, always 3:4 portrait (same surfaces). MCP: separate cover/poster tools, fixed ratios — no generic ratio selector.

## Card Image Fallback (`Event::getCardImageUrlAttribute`)

Event cover (`card`/`preview`/`thumb`) → poster (same) → institution logo `thumb` → global placeholder. Use for cards, previews, and social images.

## Filament Integration

- Forms (Events, Institutions, Speakers, Venues, Series, References, DonationChannels, Reports): `SpatieMediaLibraryFileUpload` + `->collection()`, `->image()`/`->imageEditor()`, `->responsiveImages()`, `->conversion(thumb|banner|gallery_thumb|preview)`; galleries/evidence `->multiple()->reorderable()`. Public submit (`components/pages/submit-event/create.blade.php`): cover 16:9 + poster 3:4 + gallery. Quick-create schemas (`Institution`/`Speaker`/`VenueFormSchema`) then `$schema?->model($model)->saveRelationships()`.
- Tables/infolists: conversion-specific `SpatieMediaLibraryImageColumn`/`SpatieMediaLibraryImageEntry` (never full originals in grids).

## Frontend Consumption

- Event detail (`Livewire/Pages/Events/Show.php` + blade): gallery from `poster` + `gallery` via `getAvailableUrl(['preview','thumb'])` w/ original fallback; slider + thumbnails; related-events + share modal use `card_image_url`.
- Elsewhere: conversion URLs (`profile`, `banner`, `gallery_thumb`, …); eager-load `media` on all list/detail queries (`->with('media')`).

## Maintenance & Optimization

- Scheduled: daily `media-library:clean --delete-orphaned --force`; weekly `media-library:regenerate --only-missing --with-responsive-images --force`. Structure migration `app:media:migrate-structure` (`app/Console/Commands/MigrateMediaToNewStructure.php`; `--dry-run`, `--force`) restructures legacy paths/names.
- Rules: conversion URLs for UI (grids/cards/galleries/previews); responsive images on major collections; strict per-collection MIME; singular assets as `singleFile()`. Indexes: `media.order_column`, `media_model_collection_order_index`, `media_collection_created_at_index`.
- Tests: `MediaConversionsTest` + `SubmitEventMediaTest` cover MIME acceptance, conversion registration, fallbacks, custom config, submit uploads.

## AI Checklist for New Media Features

1. Collection + conversions in model. 2. `SpatieMediaLibraryFileUpload` w/ explicit collection/conversion. 3. Conversion-specific columns/entries. 4. Conversion URLs on frontend + eager-load `media`. 5. Tests for MIME/conversions/fallbacks. 6. Never ad-hoc filenames, never originals in lists, never drop MIME rules or Octane boot guards.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel-octane/core rules ===

# Laravel Octane

This application uses Laravel Octane, a long-running PHP server. The application bootstraps once and handles many requests within the same process.

- Never store request-specific state in singletons or static properties, because it can leak across requests.
- Use `config('octane.server')` to detect the active driver (`swoole`, `roadrunner`, or `frankenphp`).
- Prefer scoped bindings (`$this->app->scoped()`) over singletons for per-request services.

When working on Octane-specific features (concurrency, shared tables, memory, driver configuration, testing), invoke `octane-development` for detailed rules.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== spatie/laravel-activitylog/core rules ===

# spatie/laravel-activitylog

Activity logging package for Laravel. Logs model events and manual activities to a database table.

## Key Concepts

- **Activity**: An Eloquent model (`Spatie\Activitylog\Models\Activity`) storing log entries with subject, causer, event, attribute_changes, and properties.
- **Subject**: The model being acted upon (polymorphic `subject_type`/`subject_id`).
- **Causer**: The model that caused the action, typically the authenticated user (polymorphic `causer_type`/`causer_id`).
- **LogOptions**: Fluent configuration object returned by `getActivitylogOptions()` on models using the `LogsActivity` trait.
- **ActivityEvent**: Enum with cases `Created`, `Updated`, `Deleted`, `Restored`.
- **`attribute_changes`** column: stores `{"attributes": {...}, "old": {...}}` for tracked model changes.
- **`properties`** column: stores custom user data set via `withProperties()`.

## Traits

### `LogsActivity`

Add to models to automatically log create/update/delete events. Optionally implement `getActivitylogOptions()` to configure which attributes to track (defaults to logging events without attribute changes).

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Article extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

### `CausesActivity`

Add to user/causer models. Provides `activitiesAsCauser()` relationship.

### `HasActivity`

Combines `LogsActivity` and `CausesActivity`. Provides `activities()`, `activitiesAsSubject()`, and `activitiesAsCauser()`.

## Manual Logging

```php
activity()
    ->performedOn($article)
    ->causedBy($user)
    ->event(ActivityEvent::Updated)
    ->withProperties(['key' => 'value'])
    ->log('Article was updated');
```

## LogOptions Methods

| Method | Description |
|--------|-------------|
| `logFillable()` | Log all fillable attributes |
| `logAll()` | Log all attributes |
| `logOnly(array)` | Log specific attributes |
| `logExcept(array)` | Exclude attributes |
| `logOnlyDirty()` | Only log changed attributes |
| `dontLogEmptyChanges()` | Skip logging when no tracked attributes changed |
| `dontLogIfAttributesChangedOnly(array)` | Ignore updates that only change these attributes |
| `useLogName(string)` | Set custom log name |
| `setDescriptionForEvent(Closure)` | Custom description per event |
| `useAttributeRawValues(array)` | Store raw (uncast) values |

## Querying Activities

```php
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Enums\ActivityEvent;

Activity::forEvent(ActivityEvent::Created)->get();
Activity::causedBy($user)->get();
Activity::forSubject($article)->get();
Activity::inLog('orders')->get();
```

## Setting the causer

Override the causer for a block of code:

```php
use Spatie\Activitylog\Facades\Activity;

Activity::defaultCauser($admin, function () {
    // all activities here are caused by $admin
});

// or set globally for the rest of the request
Activity::defaultCauser($admin);
```

## Disabling Logging

```php
activity()->withoutLogging(function () {
    // no activities logged here
});
```

## Accessing Changes and Properties

```php
$activity = Activity::latest()->first();

// Tracked model changes (set automatically by LogsActivity)
$activity->attribute_changes; // Collection: {"attributes": {...}, "old": {...}}

// Custom user data (set via withProperties)
$activity->properties; // Collection
$activity->getProperty('key'); // single value
```

## Custom Activity Model

Set `activity_model` in `config/activitylog.php` to a class that extends `Model` and implements `Spatie\Activitylog\Contracts\Activity`. Use a custom model for custom table names or database connections.

## Customizing Actions

The package uses action classes (`LogActivityAction`, `CleanActivityLogAction`) that can be extended and swapped via config:

```php
// config/activitylog.php
'actions' => [
    'log_activity' => \App\Actions\CustomLogActivityAction::class,
    'clean_log' => \App\Actions\CustomCleanAction::class,
],
```

Custom action classes must extend the originals. Override protected methods (`save()`, `beforeActivityLogged()`, `resolveDescription()`, etc.) to customize behavior.

## Configuration

Key config options in `config/activitylog.php`:
- `enabled`: Master on/off switch (env: `ACTIVITYLOG_ENABLED`)
- `clean_after_days`: Days to keep records for `activitylog:clean` command
- `default_log_name`: Default log name (string)
- `default_auth_driver`: Auth driver for causer resolution
- `include_soft_deleted_subjects`: Include soft-deleted subjects
- `activity_model`: Custom Activity model class
- `default_except_attributes`: Globally excluded attributes
- `actions.log_activity`: Action class for logging activities
- `actions.clean_log`: Action class for cleaning old activities

=== pestphp/pest-plugin-agent/core rules ===

## Pest Agent Plugin

`vendor/bin/pest --agent="<code>"` runs a one-off Pest assertion without creating a test file — the fastest way to verify that a change actually works (a route response, a model relationship, a rendered page, a form submission, mail firing, a screenshot, JavaScript errors, and so on).

### ALWAYS load the skill first

Whenever the user asks you to check, verify, confirm, or "make sure" something **works** — and it can be exercised on a route, page, form, model, job, mail, notification, or screenshot — you **MUST** load the **`pest-plugin-agent` skill before doing anything else**. Do not reach for a shell command, a throwaway test file, or manual reasoning first. This includes prompts like "verify the login form works", "did my change break X", "screenshot the homepage", "check this route returns 200", "make sure the mail fires", "is the form working", or any behavioral check after a Blade, Livewire, CSS, or JS change. Load the skill, then follow it exactly.

### NEVER fight shell escaping — use SINGLE outer quotes

Inline the snippet, but wrap it in **single** quotes, not double. Single quotes tell the shell to interpret nothing, so `$variables`, `\App\Models\User`, backticks, and `!` all pass through to PHP literally — **there is nothing to escape.** Use double quotes for PHP string literals inside:

```bash
vendor/bin/pest --agent='$user = \App\Models\User::factory()->create(); visit("/login")->type("email", $user->email)->press("Log in")->assertPathIs("/dashboard");'
```

Double outer quotes are the trap the shell springs on you — `--agent="…$user…"` makes the shell interpolate `$user` to nothing. Never do that, and never hand-escape `\$`.

The one thing single quotes can't contain is a literal single quote (an apostrophe in the PHP). Only then, fall back to a file: **Write** the snippet to a `.php` file (plain body statements — no `<?php`, no `use`, fully qualified class names) and run `vendor/bin/pest --agent="$(cat /path/to/snippet.php)"`. `"$(cat …)"` passes the contents verbatim without re-parsing. The plugin resolves the test suite's `uses`/namespace itself, so the file's location does not matter (a scratch/temp path is fine — it need not live under `tests/`).

### Browser checks require the browser plugin — ask before installing

Whenever the request can only be answered in a real browser — "does login work", "is the page responsive", "screenshot the homepage", "check the mobile layout", "does the button click through", "are there JS/console errors", or any visual/interaction check — the `visit()` browser API is needed. It comes from a **separate** package, `pestphp/pest-plugin-browser`, which is powered by Playwright.

If `visit()` is undefined (or the package is not installed), **do not install it silently — ask the user for permission first**, since it pulls in Node/Playwright dependencies and downloads browser binaries. Explain that the browser check needs it and confirm before running these commands:

```bash
composer require pestphp/pest-plugin-browser --dev   # the browser plugin (needs Node.js)
npm install playwright@latest                         # Playwright driver
npx playwright install                                # download the browser binaries
```

Once the user approves and it's installed, add `tests/Browser/Screenshots` to `.gitignore` so captured screenshots aren't committed. Browser assertions then run through the same `vendor/bin/pest --agent='…'` flow:

```bash
vendor/bin/pest --agent='visit("/login")->type("email", "test@example.com")->type("password", "password")->press("Log in")->assertPathIs("/dashboard");'
vendor/bin/pest --agent='visit("/")->on()->mobile()->screenshot(fullPage: false, filename: "home-mobile");'
```

For full usage — backend examples, browser testing, screenshots, responsive checks, combining frontend and backend assertions, RefreshDatabase guidance, and pitfalls — load the **`pest-plugin-agent` skill**.

=== spatie/guidelines-skills/core rules ===

# Project Coding Guidelines

- This codebase follows Spatie's coding guidelines.
- Always activate the `spatie-laravel-php` skill when writing, editing, reviewing, or formatting Laravel or PHP code.
- Always activate the `spatie-javascript` skill when writing, editing, reviewing, or formatting JavaScript or TypeScript code.
- Always activate the `spatie-version-control` skill when creating commits, branches, or managing Git operations.
- Always activate the `spatie-security` skill when configuring security, signing commits, reviewing authentication, or setting up servers and databases.

</laravel-boost-guidelines>

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

When the user types `/graphify`, invoke the `skill` tool with `skill: "graphify"` before doing anything else.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- Dirty graphify-out/ files are expected after hooks or incremental updates; dirty graph files are not a reason to skip graphify. Only skip graphify if the task is about stale or incorrect graph output, or the user explicitly says not to use it.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
