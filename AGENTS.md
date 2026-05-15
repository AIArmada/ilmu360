<laravel-boost-guidelines>
=== .ai/brand rules ===

## Branding

The application is being rebranded to **ilmu360°** (ilmu360 with a degree sign after 360).

=== .ai/database rules ===

# Database Guidelines

- **Primary keys**: `uuid('id')->primary()`.
- **Foreign keys**: `foreignUuid('col')` only.
- **Intentional geography exception**: `countries`, `states`, `cities`, `districts`, and `subdistricts` use integer IDs (`id` / `foreignId`) by design. Keep all geography references (`country_id`, `state_id`, `city_id`, `district_id`, `subdistrict_id`) as integers.
- **Never** add DB-level constraints or cascades: no `->constrained()`, no `->cascadeOnDelete()`, no FK constraints.
- **Cascades/integrity**: enforce in application logic (models/actions/services).
- **Migrations**: keep safe/idempotent; no `down()` required.
- **No SoftDeletes**: never use Laravel's `SoftDeletes` trait or `$table->softDeletes()` in migrations. This application uses `spatie/laravel-deleted-models` (`KeepsDeletedModels` trait) instead, which stores a full copy of the deleted model in a separate `deleted_models` table.
- Ensure no constraints/cascades slipped in: `rg -n -- "constrained\(|cascadeOnDelete\(" packages/*/database`

## Verification

- Ensure no constraints/cascades slipped in: `rg -n -- "constrained\(|cascadeOnDelete\(" packages/*/database`
- Ensure no SoftDeletes slipped in: `rg -n -- "softDeletes\(\)|SoftDeletes" database/ app/Models/`

=== .ai/general rules ===

## Workflow Orchestration

### 1. Plan Node Default

Enter plan mode for ANY non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, STOP and re-plan immediately – don't keep pushing
- Use plan mode for verification steps, not just building
- Write detailed specs upfront to reduce ambiguity

### 2. Subagent Strategy

- Use subagents liberally to keep main context window clean
- Offload research, exploration, and parallel analysis to subagents
- For complex problems, throw more compute at it via subagents
- One tack per subagent for focused execution

### 3. Self-Improvement Loop

- After ANY correction from the user: update 'tasks/lessons.md' with the pattern
- Write rules for yourself that prevent the same mistake
- Ruthlessly iterate on these lessons until mistake rate drops
- Review lessons at session start for relevant project

### 4. Verification Before Done

- Never mark a task complete without proving it works
- Diff behavior between main and your changes when relevant
- Ask yourself: "Would a staff engineer approve this?"
- Run tests, check logs, demonstrate correctness

### 5. UI Tracking Review

- Whenever a task changes UI behavior, navigation, forms, filters, tabs, table actions, buttons, or stateful interactions, explicitly evaluate whether product event tracking should be added or updated
- Prefer curated high-signal tracking over blanket click logging; track user intent and meaningful workflow transitions, not every cosmetic interaction
- For backend-confirmed outcomes, prefer server-side Signals events so the data represents what actually happened
- For frontend-only intent, use the centralized Signals UI event helper instead of one-off JavaScript handlers
- Purely cosmetic changes can skip tracking only when they do not alter user behavior, entry points, or interaction paths

### 6. Demand Elegant (Balanced)

- For non-trivial changes: pause and ask "Is there a more elegant way?"
- If a fix feels hacky: "Knowing everything I know now, implement the elegant solution"
- Skip this for simple, obvious fixes – don't over-engineer
- Challenge your own work before presenting it

### 7. Autonomous Bug Fixing

- When given a bug report: just fix it. Don't ask for hand-holding
- Point at logs, errors, failing tests – then resolve them
- Zero context switching required from the user
- Go fix failing CI tests without being told how

### 8. Laravel Actions Where Appropriate

- Prefer Laravel Actions for reusable workflow orchestration that spans validation-adjacent normalization, transactions, side effects, or multiple entrypoints
- Do not force every mutation into an action; trivial single-call controller or Livewire handlers can stay inline
- Before adding a new action, check whether the behavior is already covered by an existing action and extend that path instead of duplicating orchestration
- Keep controllers and Livewire components focused on HTTP/UI concerns when a workflow is substantial enough to extract

### 9. Spatie Laravel Data Adoption

- Use `spatie/laravel-data` as a boundary-contract layer, not as a default application pattern.
- Prefer Data objects for API response DTOs when controllers build large nested arrays, the same payload shape is reused across endpoints, or public/mobile contracts need stronger consistency.
- Consider Data objects for controller-to-action payloads only when the request state is large, nested, reused, or shared across multiple entrypoints.
- Start with output-only refactors when introducing Data on existing public APIs. Keep keys, nullability, nesting, status codes, and error shapes unchanged, and lock parity with focused tests.
- Treat input and validation refactors as externally observable behavior changes unless proven otherwise. Add request/response contract tests before widening Data usage on writes.
- Do not adopt Data broadly in Livewire or Filament form state by default. Prefer native array state unless a specific component proves a clear hydration or reuse benefit.
- Do not rewrite simple internal readonly DTOs or tiny mutation endpoints just for consistency.
- For dynamic catalog/config payloads and small one-off arrays, prefer plain arrays or readonly PHP objects over Data classes.
- When in doubt, use fewer Data classes and place them on stable boundaries such as public/mobile API serializers.

## Task Management

1. *Plan First*: Write plan to 'tasks/todo.md' with checkable items
2. *Verify Plan*: Check in before starting implementation
3. *Track Progress*: Mark items complete as you go
4. *Explain Changes*: High-level summary at each step
5. *Document Results*: Add review section to 'tasks/todo.md'
6. *Capture Lessons*: Update 'tasks/lessons.md' after corrections

## Core Principles

- *Simplicity First*: Make every change as simple as possible. Impact minimal code.
- *No Laziness*: Find root causes. No temporary fixes. Senior developer standards.
- *Minimat Impact*: Changes should only touch what's necessary. Avoid introducing bugs.

---------

# Filament Form Data Handling with Enums

## Critical: Enum Serialization/Deserialization in Filament Forms

### Context

When working with PHP Backed Enums in Filament forms, understanding how Filament handles enum serialization and deserialization is crucial for writing correct conditional logic.

### The Behavior

**Inside Form Field Closures** (e.g., `->disabled()`, `->visible()`, `->required()`, etc.):
- When you use `$get('field_name')` to retrieve form data, Filament automatically **deserializes string values back into enum instances**.
- Arrays will contain **enum objects**, not strings.
- **Use enum instances directly for comparison**: `EventAgeGroup::Children` (NOT `->value`)

**Example:**
```php
// ✅ CORRECT - Use enum instances directly
->disabled(function (Get $get): bool {
    $ageGroups = $get('age_group') ?? [];
    // $ageGroups contains: [EventAgeGroup::AllAges, EventAgeGroup::Adults]
    return in_array(EventAgeGroup::Children, $ageGroups, true) || 
           in_array(EventAgeGroup::AllAges, $ageGroups, true);
})

// ❌ WRONG - Using ->value will NOT match
->disabled(function (Get $get): bool {
    $ageGroups = $get('age_group') ?? [];
    // This will always return false because 'children' string !== EventAgeGroup::Children enum object
    return in_array(EventAgeGroup::Children->value, $ageGroups, true);
})
```

**In Submit/Action Methods** (e.g., `submit()`, `action()`, after validation):
- Form data is **serialized** and contains **string values**.
- Arrays will contain **strings**, not enum objects.
- **Use `->value` property for comparison**: `EventAgeGroup::Children->value`

**Example:**
```php
public function submit(): void
{
    $validated = $this->form->getState();
    $ageGroups = $validated['age_group'] ?? [];
    
    // $ageGroups contains: ['all_ages', 'adults'] (strings)
    
    // ✅ CORRECT - Use ->value for string comparison
    if (in_array(EventAgeGroup::Children->value, $ageGroups, true) || 
        in_array(EventAgeGroup::AllAges->value, $ageGroups, true)) {
        $validated['children_allowed'] = true;
    }
}
```

### Debugging Tip

If you're unsure what format the data is in, add logging:

```php
\Log::info('Form data debug', [
    'data' => $get('field_name'),
    'types' => array_map('gettype', (array)$get('field_name')),
]);
```

Then check `storage/logs/laravel.log` to see if you're dealing with enum objects or strings.

### Summary Table

| Context | Data Format | Comparison Method | Example |
|---------|-------------|-------------------|---------|
| Form field closures (`->disabled()`, `->visible()`, etc.) | Enum objects | Use enum directly | `in_array(EventAgeGroup::Children, $data, true)` |
| Submit/action methods, validated data | Strings | Use `->value` | `in_array(EventAgeGroup::Children->value, $data, true)` |
| Database queries | Strings (stored as backing values) | Use `->value` | `where('age_group', EventAgeGroup::Children->value)` |

### When This Matters

- Conditional form logic: `->disabled()`, `->visible()`, `->required()`, `->hidden()`
- Field dependencies: `->afterStateUpdated()`, `->reactive()`
- Any closure receiving `Get $get` parameter
- Validation rules that check other fields

### Key Takeaway

**Filament automatically converts between enum objects (for PHP logic) and strings (for storage/transport).** Always check your context to know which format you're working with.

---

# Model Sorting with Spatie Eloquent Sortable

## Overview

This application uses `spatie/eloquent-sortable` for consistent model ordering. Always use this package instead of manually managing sort columns.

## Implementation Pattern

### Model Setup

```php
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class MyModel extends Model implements Sortable
{
    use SortableTrait;

    public array $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    protected $fillable = [
        'name',
        'order_column', // Always include in fillable
    ];
}
```

### Migration

```php
Schema::create('my_models', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->unsignedInteger('order_column')->nullable(); // Always nullable
    $table->timestamps();
});
```

### Querying Sorted Records

```php
// Use the ->ordered() scope provided by the trait
$records = MyModel::ordered()->get();

// In relationships
public function items(): HasMany
{
    return $this->hasMany(Item::class)->ordered();
}
```

### Key Rules

1. **Column name**: Always use `order_column` for consistency across models
2. **Nullable**: The column should be nullable (SortableTrait handles auto-assignment)
3. **No manual sorting**: Don't manually set `order_column` values; let the trait manage it
4. **Use `->ordered()` scope**: Always use the provided scope instead of `->orderBy('order_column')`

### Models Using Sortable

- `Tag` (inherited from Spatie Tags, scoped by type)
- `Topic`
- `EventType`

---

# Unified Tag System Architecture

## Overview

This application uses **Spatie Tags** with a **TagType enum** for organizing tags by category. All tag functionality uses Spatie's native polymorphic `taggables` table.

## TagType Enum

Located at `App\Enums\TagType`, provides metadata for each tag type:

```php
TagType::Domain->label();       // "Domain"
TagType::Domain->color();       // "primary"
TagType::Domain->icon();        // "heroicon-o-academic-cap"
TagType::Domain->description(); // "Core Islamic knowledge areas..."
TagType::Domain->order();       // 10
```

## Type Storage & Access

The `type` column stores string values ('domain', 'discipline', 'source', 'issue') to maintain compatibility with Spatie's native methods:

```php
$tag->type;        // Returns: 'domain' (string)
$tag->type_enum;   // Returns: TagType::Domain (enum instance)
```

**Why not cast to enum?** Spatie's `tagsWithType()` method does strict string comparison, so the type must remain a string in the model. Use the `type_enum` accessor when you need enum functionality.

## Tag Types

| Type | Value | Purpose |
|------|-------|---------|
| Domain | `domain` | Core Islamic knowledge areas (Aqidah, Syariah, Akhlak) |
| Discipline | `discipline` | Specific fields of study (Tafsir, Sirah, Fiqh, etc.) |
| Source | `source` | Reference sources (Quran, Hadith, Turath, etc.) |
| Issue | `issue` | Contemporary themes/topics (Rasuah, Kepimpinan, etc.) |

## Usage

### Tagging Events

```php
// Attach tags to an event
$event->attachTag($tag);
$event->attachTags([$tag1, $tag2]);

// Sync tags (replaces all existing tags)
$event->syncTags([$tag1, $tag2]);

// Detach tags
$event->detachTag($tag);
$event->detachTags();
```

### Querying Tags

```php
// Get all tags of a specific type (verified + pending)
$domainTags = Tag::ofType(TagType::Domain)->whereIn('status', ['verified', 'pending'])->get();
$issueTags = Tag::ofType('issue')->whereIn('status', ['verified', 'pending'])->get();

// Spatie's native method (use with status filter)
$domainTags = Tag::getWithType('domain')->filter(fn($tag) => in_array($tag->status, ['verified', 'pending']));

// Get event's tags of specific type
$domainTags = $event->tagsWithType('domain');

// Get all tags ordered
$tags = Tag::ordered()->get();
```

### Tag Status & Moderation

- Tags have a `status` column with values: `'pending'`, `'verified'`
- Pre-seeded tags are `'verified'` (Domain, Source types are pre-seeded only)
- User-created tags (Discipline, Issue) are created as `'pending'`
- When an event is approved, all attached pending tags are auto-verified
- Show both `'verified'` and `'pending'` tags in form dropdowns (similar to Speaker/Institution/Venue)

### Tag Sorting

- Tags use Spatie Eloquent Sortable with `order_column`
- Sorting is scoped by `type` (tags within same type are ordered independently)
- Auto-assigns order when created

## Key Principles

1. **Use native Spatie methods**: `attachTag()`, `syncTags()`, `tagsWithType()`, etc.
2. **No custom pivot**: Everything uses `taggables` table (polymorphic)
3. **Type-based organization**: Use `TagType` enum for categorization and metadata
4. **Status-based moderation**: User-created tags start as `'pending'`, auto-verify on event approval
5. **Keep it simple**: No extra fields like `is_active`, `is_system`, `description`, `weight`, or `is_primary`

---

# Testing Best Practices

## Running Tests

Always use **parallel execution** for faster test runs:

```bash

# Run all tests in parallel

vendor/bin/pest --parallel

# Run specific tests in parallel

vendor/bin/pest --parallel --filter=SubmitEvent

# Run tests with compact output in parallel

vendor/bin/pest --parallel --compact
```

### Why Parallel?

- **Speed**: Tests run significantly faster by utilizing multiple CPU cores
- **Efficiency**: Reduces CI/CD pipeline time
- **Best practice**: Pest's parallel mode handles database isolation automatically

### Alternative Commands

While `php artisan test` can be used, prefer `vendor/bin/pest --parallel` for optimal performance:

```bash

# ❌ Slower (sequential)

php artisan test --filter=SubmitEvent

# ✅ Faster (parallel)

vendor/bin/pest --parallel --filter=SubmitEvent
```

### Key Points

- Parallel execution is safe for all tests (Pest handles isolation)
- No need to modify existing tests to support parallel mode
- Default behavior - no additional configuration required

---

# Static Analysis Safety for Runtime Extensions

When a method looks "undefined" in static analysis, do not remove it until you verify its source.

## Required Verification Before Removal

1. Search for runtime extensions first:
   - `macro()` / `hasMacro()` in service providers
   - package mixins/traits
   - plugin-specific extensions (for example Filament add-ons like quick-add select)
2. Confirm if the method is intentionally runtime-provided (for example `Select::macro(...)`).
3. If runtime-provided, preserve behavior and fix static analysis with a narrow rule (stub or focused ignore pattern), instead of deleting the method call.
4. Only remove a method when you have confirmed there is no implementation source and no feature dependency.

## Practical Rule

- Behavior safety takes priority over static-analysis convenience.
- Never remove feature methods such as `->closeOnSelect()` or `->quickAdd()` without source verification and impact check.

---

# PHPStan Level 6 Compliance

All new and modified code must be written to pass PHPStan at level 6.

## Required Standard

1. Do not introduce new PHPStan errors.
2. Prefer real fixes (types, generics, return shapes, null-handling, narrowing) over broad ignores.
3. Avoid adding baseline suppressions unless there is a verified runtime-extension limitation that cannot be modeled safely.
4. If a suppression is unavoidable, keep it as narrow as possible (specific file + message pattern) and document why.

## Verification Command

Run and pass:

```bash
vendor/bin/phpstan analyse --ansi
```

---

# Timezone Handling (Critical)

## Core Rules

- Store all timestamps in UTC at the database layer.
- Resolve viewer timezone at request-time using `App\Support\Timezone\UserTimezoneResolver`.
- For display formatting in Blade/Livewire, use `App\Support\Timezone\UserDateTimeFormatter`.
- Do not hardcode region timezones (for example `Asia/Kuala_Lumpur`) in public query/filter logic.

## Display Rules

- Prefer:
    - `UserDateTimeFormatter::format($date, 'h:i A')`
    - `UserDateTimeFormatter::translatedFormat($date, 'l, j F Y')`
- Avoid direct `->format()` / `->translatedFormat()` in public-facing views unless you intentionally need storage timezone output.

## Date Filter Rules

- For date-only filters (`starts_after`, `starts_before`, etc.), parse input as user-local date and convert to UTC boundaries before querying:
    - start boundary => startOfDay in user timezone -> UTC
    - end boundary => endOfDay in user timezone -> UTC
- Use `UserDateTimeFormatter::parseUserDateToUtc(...)` for this conversion.

## Prayer-Time Filter Notes

- Advanced search may use prayer-relative labels (for example `Selepas Jumaat`, `Selepas Maghrib`, `Selepas Tarawih`).
- Use `prayer_display_text` keyword matching and `prayer_reference` mapping where applicable.
- `Tarawih` is label-based (text matching), not a `PrayerReference` enum value.

---

# Query Safety Notes

## Qualified Columns in Scopes

- When scopes are reused inside joined queries, qualify columns by table name to avoid ambiguous-column failures (especially in SQLite tests).
- Example: in `Event::active()`, use `events.is_active` instead of plain `is_active`.

## Public Listing Visibility

- If a public page is expected to show only approved records, explicitly constrain `status = approved` even when using broader reusable scopes.

---

# OpenAI Developer Docs MCP

Always use the OpenAI developer documentation MCP server (`openaiDeveloperDocs`) if you need to work with the OpenAI API, ChatGPT Apps SDK, Codex, Responses API, or any other OpenAI product — without the user having to explicitly ask.

=== .ai/livewire rules ===

- Installation: https://livewire.laravel.com/docs/4.x/installation
- Quickstart: https://livewire.laravel.com/docs/4.x/quickstart
- Upgrading: https://livewire.laravel.com/docs/4.x/upgrading

- Components: https://livewire.laravel.com/docs/4.x/components
- Nesting: https://livewire.laravel.com/docs/4.x/nesting
- Understanding Nesting: https://livewire.laravel.com/docs/4.x/understanding-nesting
- Pages: https://livewire.laravel.com/docs/4.x/pages

- Properties: https://livewire.laravel.com/docs/4.x/properties
- Computed Properties: https://livewire.laravel.com/docs/4.x/computed-properties
- Actions: https://livewire.laravel.com/docs/4.x/actions

- Forms: https://livewire.laravel.com/docs/4.x/forms
- Validation: https://livewire.laravel.com/docs/4.x/validation
- Uploads: https://livewire.laravel.com/docs/4.x/uploads

- Lifecycle Hooks: https://livewire.laravel.com/docs/4.x/lifecycle-hooks
- Events: https://livewire.laravel.com/docs/4.x/events

- Lazy Loading: https://livewire.laravel.com/docs/4.x/lazy
- Islands: https://livewire.laravel.com/docs/4.x/islands
- Loading States: https://livewire.laravel.com/docs/4.x/loading-states
- Hydration: https://livewire.laravel.com/docs/4.x/hydration

- #[Async]: https://livewire.laravel.com/docs/4.x/attribute-async
- #[Computed]: https://livewire.laravel.com/docs/4.x/attribute-computed
- #[Defer]: https://livewire.laravel.com/docs/4.x/attribute-defer
- #[Isolate]: https://livewire.laravel.com/docs/4.x/attribute-isolate
- #[Js]: https://livewire.laravel.com/docs/4.x/attribute-js
- #[Json]: https://livewire.laravel.com/docs/4.x/attribute-json
- #[Layout]: https://livewire.laravel.com/docs/4.x/attribute-layout
- #[Lazy]: https://livewire.laravel.com/docs/4.x/attribute-lazy
- #[Locked]: https://livewire.laravel.com/docs/4.x/attribute-locked
- #[Modelable]: https://livewire.laravel.com/docs/4.x/attribute-modelable
- #[On]: https://livewire.laravel.com/docs/4.x/attribute-on
- #[Reactive]: https://livewire.laravel.com/docs/4.x/attribute-reactive
- #[Renderless]: https://livewire.laravel.com/docs/4.x/attribute-renderless
- #[Session]: https://livewire.laravel.com/docs/4.x/attribute-session
- #[Title]: https://livewire.laravel.com/docs/4.x/attribute-title
- #[Transition]: https://livewire.laravel.com/docs/4.x/attribute-transition
- #[Url]: https://livewire.laravel.com/docs/4.x/attribute-url
- #[Validate]: https://livewire.laravel.com/docs/4.x/attribute-validate

- @island: https://livewire.laravel.com/docs/4.x/directive-island
- @persist: https://livewire.laravel.com/docs/4.x/directive-persist
- @placeholder: https://livewire.laravel.com/docs/4.x/directive-placeholder
- @teleport: https://livewire.laravel.com/docs/4.x/directive-teleport

- wire:model: https://livewire.laravel.com/docs/4.x/wire-model
- wire:bind: https://livewire.laravel.com/docs/4.x/wire-bind

- wire:click: https://livewire.laravel.com/docs/4.x/wire-click
- wire:submit: https://livewire.laravel.com/docs/4.x/wire-submit
- wire:confirm: https://livewire.laravel.com/docs/4.x/wire-confirm

- wire:loading: https://livewire.laravel.com/docs/4.x/wire-loading
- wire:dirty: https://livewire.laravel.com/docs/4.x/wire-dirty
- wire:offline: https://livewire.laravel.com/docs/4.x/wire-offline
- wire:cloak: https://livewire.laravel.com/docs/4.x/wire-cloak
- wire:show: https://livewire.laravel.com/docs/4.x/wire-show

- wire:navigate: https://livewire.laravel.com/docs/4.x/wire-navigate
- wire:current: https://livewire.laravel.com/docs/4.x/wire-current

- wire:init: https://livewire.laravel.com/docs/4.x/wire-init
- wire:poll: https://livewire.laravel.com/docs/4.x/wire-poll
- wire:intersect: https://livewire.laravel.com/docs/4.x/wire-intersect
- wire:ignore: https://livewire.laravel.com/docs/4.x/wire-ignore
- wire:replace: https://livewire.laravel.com/docs/4.x/wire-replace

- wire:ref: https://livewire.laravel.com/docs/4.x/wire-ref
- wire:stream: https://livewire.laravel.com/docs/4.x/wire-stream
- wire:text: https://livewire.laravel.com/docs/4.x/wire-text
- wire:transition: https://livewire.laravel.com/docs/4.x/wire-transition
- wire:sort: https://livewire.laravel.com/docs/4.x/wire-sort

- Navigate: https://livewire.laravel.com/docs/4.x/navigate
- URL: https://livewire.laravel.com/docs/4.x/url
- Redirecting: https://livewire.laravel.com/docs/4.x/redirecting

- Pagination: https://livewire.laravel.com/docs/4.x/pagination
- Teleport: https://livewire.laravel.com/docs/4.x/teleport
- Morphing: https://livewire.laravel.com/docs/4.x/morphing
- Styles: https://livewire.laravel.com/docs/4.x/styles

- JavaScript: https://livewire.laravel.com/docs/4.x/javascript
- Alpine.js: https://livewire.laravel.com/docs/4.x/alpine

- Synthesizers: https://livewire.laravel.com/docs/4.x/synthesizers
- Security: https://livewire.laravel.com/docs/4.x/security
- CSP (Content Security Policy): https://livewire.laravel.com/docs/4.x/csp

- Testing: https://livewire.laravel.com/docs/4.x/testing
- Troubleshooting: https://livewire.laravel.com/docs/4.x/troubleshooting

- Contribution Guide: https://livewire.laravel.com/docs/4.x/contribution-guide
- Downloads: https://livewire.laravel.com/docs/4.x/downloads

=== .ai/media rules ===

# Media Management Guidelines (Spatie Medialibrary v11 + Filament v5)

This document defines the media architecture that is already implemented across this application.  
When adding or modifying media features, follow these rules exactly.

## Core Stack

- Package: `spatie/laravel-medialibrary` v11
- Filament integration: `filament/spatie-laravel-media-library-plugin` v5
- Main config: `config/media-library.php`
- Global upload policy: `app/Providers/AppServiceProvider.php`
- Naming strategy: `app/Support/Media/MediaFileNamer.php`
- Storage path strategy: `app/Support/Media/MediaPathGenerator.php`

## Global Upload Policy (Do Not Bypass)

All `SpatieMediaLibraryFileUpload` fields are globally configured in `AppServiceProvider`.

### Implemented defaults

- Max upload size is derived from `config('media-library.max_file_size')` (10MB default).
- `maxParallelUploads(2)` to balance UX and server load.
- `appendFiles()` so additional uploads do not replace unintentionally.
- Immutable cache header for uploaded files:
  - `CacheControl: public, max-age=31536000, immutable`
- Storage filename pattern:
  - `<slug-or-model-base>-<8-char-ulid>.<ext>`
- Human-readable media `name` is generated from model + collection label.
- `custom_properties` always store:
  - `collection`
  - `original_file_name`

### Octane safety

- Boot-time configuration is protected by static guards (for example `$mediaUploadConfigured`) in `AppServiceProvider`.
- Keep these guards to avoid duplicate macro/config registration in long-lived workers.

## Naming Rules (Natural, Consistent, Searchable)

Implemented in `MediaFileNamer`.

### Storage base name priority

1. `slug`
2. `name`
3. `title`
4. `label`
5. Morph alias / class basename fallback

### Human display name labels

- `poster` => `Event Poster`
- `cover` => `Cover Image`
- `logo` => `Logo`
- `avatar` => `Avatar`
- `main` => `Main Image`
- `gallery` => `Gallery Image`
- `qr` => `QR Code`
- `evidence` => `Evidence File`

The final media name format is:
- `<Collection Label> - <Subject Label>`
- Fallback to original filename label if subject is not available.

## Directory Strategy (Long-Term Scalability)

Implemented in `MediaPathGenerator`.

Directory format:
- `{model_type_plural}/{uuid_shard}/{model_uuid}/{collection}/`

Example:
- `events/019c/019c4228-.../poster/`
- `institutions/01b2/01b2c1d4-.../gallery/`

Why:
- Avoids giant hot directories.
- Keeps files grouped by owner and collection.
- Makes bulk cleanup and debugging easier.

## Media Library Config Decisions

Configured in `config/media-library.php`.

### Performance-centric defaults

- `version_urls => true` (cache busting without stale assets)
- `default_loading_attribute_value => 'lazy'`
- `force_lazy_loading => true`
- `queue_conversions_by_default => true`
- `queue_conversions_after_database_commit => true`
- `file_remover_class => FileBaseFileRemover` (safe for shared directory structures)
- Image optimizers enabled (JPEG, PNG, SVG, GIF, WebP, AVIF)
- Generators enabled for image, webp, avif, pdf, svg, video

### Custom classes

- `file_namer => App\Support\Media\MediaFileNamer::class`
- `path_generator => App\Support\Media\MediaPathGenerator::class`

## Model Collection Matrix (Canonical)

### Event (`app/Models/Event.php`)

- `cover`: image/jpeg,image/png,image/webp, responsive, single file, fallback placeholder, required 16:9 website/mobile-app cover
- `poster`: image/jpeg,image/png,image/webp, responsive, single file, fallback placeholder, required 4:5 portrait external-distribution poster
- `gallery`: image/jpeg,image/png,image/webp, responsive, multi file
- Conversions:
  - `thumb`: 600x400 cropped webp sharpen(10) on `cover`,`poster`,`gallery`
  - `card`: max 960x1200 webp on `cover`,`poster`
  - `preview`: max 1400x1800 webp on `cover`,`poster`

### Institution (`app/Models/Institution.php`)

- `logo`: jpeg,png,webp,svg, single file, fallback placeholder
- `cover`: jpeg,png,webp, responsive, single file, fallback placeholder
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 100x100 webp sharpen(10) on `logo`
  - `banner`: width 1200 webp on `cover`
  - `gallery_thumb`: 368x232 webp sharpen(10) on `gallery`

### Speaker (`app/Models/Speaker.php`)

- `avatar`: jpeg,png,webp, single file, fallback placeholder
- `main`: jpeg,png,webp, responsive, single file, fallback placeholder
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 80x80 webp sharpen(10) on `avatar`
  - `profile`: 400x400 webp on `avatar`
  - `banner`: width 1200 webp on `main`
  - `gallery_thumb`: 368x232 webp sharpen(10) on `gallery`

### Venue (`app/Models/Venue.php`)

- `main`: jpeg,png,webp, responsive, single file, fallback placeholder
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 368x232 webp sharpen(10) on `main`,`gallery`
  - `banner`: width 1200 webp on `main`

### Series (`app/Models/Series.php`)

- `cover`: jpeg,png,webp, responsive, single file
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 368x232 webp sharpen(10) on `cover`,`gallery`

### Reference (`app/Models/Reference.php`)

- `cover`: jpeg,png,webp, responsive, single file
- Conversion:
  - `thumb`: 200x280 webp sharpen(10) on `cover`

### DonationChannel (`app/Models/DonationChannel.php`)

- `qr`: jpeg,png,webp, single file
- Conversion:
  - `thumb`: 200x200 webp on `qr`

### Report (`app/Models/Report.php`)

- `evidence`: jpeg,png,webp,pdf, multi file
- Conversion:
  - `thumb`: 200x200 webp on `evidence`

## Filament Form Integration Pattern

### Admin resources

All major resources already use `SpatieMediaLibraryFileUpload`:
- `Events`, `Institutions`, `Speakers`, `Venues`, `Series`, `References`, `DonationChannels`, `Reports`

Common implemented options:
- `->collection('...')`
- `->image()` and `->imageEditor()` for image collections
- `->responsiveImages()` where needed
- `->conversion('thumb'|'banner'|'gallery_thumb'|'preview')`
- `->multiple()->reorderable()` for gallery/evidence collections
- `->maxFiles(8)` and PDF support for report evidence

### Public submission

`resources/views/components/pages/submit-event/create.blade.php` includes:
- `cover` upload for website/mobile app display, fixed to 16:9
- `poster` upload for external/social distribution, fixed to 4:5 portrait
- `gallery` upload with reorder support
- image editor + responsive images + conversion wiring

### Quick-create forms

`InstitutionFormSchema`, `SpeakerFormSchema`, and `VenueFormSchema` also support media uploads during relation quick-create flows, then call:
- `$schema?->model($model)->saveRelationships();`

## Filament Table/Infolist Rendering Pattern

Use conversion-specific media columns/entries for lightweight lists:
- `SpatieMediaLibraryImageColumn` in table resources
- `SpatieMediaLibraryImageEntry` in infolists
- Always point to the correct collection + conversion (`thumb`, `banner`, `gallery_thumb`, `preview`)

This avoids serving full originals in admin grids.

## Frontend Consumption Pattern

### Event detail page

Implemented in:
- `app/Livewire/Pages/Events/Show.php`
- `resources/views/livewire/pages/events/show.blade.php`

Features:
- Gallery payload built from `poster` + `gallery`.
- Uses `getAvailableUrl(['preview','thumb'])` with safe fallback to original URL.
- Gallery slider with thumbnail strip.
- Related events section uses `card_image_url`.
- Share preview modal uses `card_image_url` + social share links + native share/copy flow.

### Other pages

- Speaker and institution public pages render conversion URLs (`profile`, `banner`, `gallery_thumb`, etc.)
- Listing pages eager load `media` to avoid N+1.

## Card Image Fallback Chain

`Event::getCardImageUrlAttribute()`:
1. Event cover `card`/`preview`/`thumb`
2. Event poster `card`/`preview`/`thumb`
3. Institution logo `thumb`
4. Global placeholder image

## Event Aspect Ratio Contract

- Event `cover` is the primary website/mobile-app visual and must be 16:9 on public submit forms, contribution update forms, admin forms, frontend/admin APIs, and MCP-generated images.
- Event `poster` is the shareable external-distribution flyer and must be 4:5 portrait on public submit forms, contribution update forms, admin forms, frontend/admin APIs, and MCP-generated images.
- MCP exposes separate event image tools: cover tools write the `cover` collection at 16:9; poster tools write the `poster` collection at 4:5. Do not add a generic ratio selector for event media generation.

Use this accessor for cards, previews, and social image fallback behavior.

## Maintenance and Cleanup

### Scheduled jobs (`routes/console.php`)

- Daily clean:
  - `media-library:clean --delete-orphaned --force`
- Weekly regenerate missing derivatives:
  - `media-library:regenerate --only-missing --with-responsive-images --force`

### Migration helper command

- `app: media:migrate-structure`
- File: `app/Console/Commands/MigrateMediaToNewStructure.php`
- Supports:
  - `--dry-run`
  - `--force`
- Moves legacy media paths into the sharded structure and renames files to the slug-based convention.

## Query and Storage Optimization Rules

- Always eager-load media when rendering list/detail pages:
  - `->with('media')`, `load(['media', ...])`
- Prefer conversion URLs for UI surfaces:
  - admin grids, cards, galleries, previews
- Use responsive images on major visual collections (`poster`, `cover`, `main`, `gallery` where configured).
- Keep strict MIME rules per collection.
- Keep singular assets (`poster`, `avatar`, `logo`, `main`, `cover`, `qr`) as `singleFile()` collections.

## Database-Level Optimizations Implemented

- `media.order_column` indexed
- Extra indexes added on media table:
  - `media_model_collection_order_index` on (`model_type`, `model_id`, `collection_name`, `order_column`)
  - `media_collection_created_at_index` on (`collection_name`, `created_at`)

These improve collection fetch ordering and maintenance/reporting queries.

## Testing Guarantees (Reference)

`tests/Feature/MediaConversionsTest.php` and `tests/Feature/SubmitEventMediaTest.php` verify:
- Collection MIME acceptance/rejection
- Conversions are registered and used
- Fallback URLs exist
- Custom media config is active (`path_generator`, `file_namer`, lazy loading, versioned URLs)
- Submit-event cover/poster/gallery uploads persist correctly

## AI Implementation Checklist (For New Media Features)

1. Add/extend collection + conversions in the model (`registerMediaCollections`, `registerMediaConversions`).
2. Use `SpatieMediaLibraryFileUpload` with explicit `collection()` and conversion mapping.
3. Use conversion-specific image columns/entries in Filament tables/infolists.
4. Render conversion URLs on frontend, not originals.
5. Eager-load `media` in queries to avoid N+1.
6. Add/adjust tests for conversions, MIME constraints, and fallback behavior.
7. Do not bypass global naming/path conventions.

## Do Not Do

- Do not introduce ad-hoc filename generation outside global upload config.
- Do not store large image originals directly in list/card UIs.
- Do not skip collection MIME constraints.
- Do not remove AppServiceProvider static boot guards in Octane environments.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- filament/filament (FILAMENT) - v5
- laravel/ai (AI) - v0
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/mcp (MCP) - v0
- laravel/octane (OCTANE) - v2
- laravel/passport (PASSPORT) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/scout (SCOUT) - v11
- laravel/socialite (SOCIALITE) - v5
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2
- tailwindcss (TAILWINDCSS) - v4

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

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== ai/core rules ===

## Laravel AI SDK

- This application uses the Laravel AI SDK (`laravel/ai`) for all AI functionality.
- Activate the `developing-with-ai-sdk` skill when building, editing, updating, debugging, or testing AI agents, text generation, chat, streaming, structured output, tools, image generation, audio, transcription, embeddings, reranking, vector stores, files, conversation memory, or any AI provider integration (OpenAI, Anthropic, Gemini, Cohere, Groq, xAI, ElevenLabs, Jina, OpenRouter).

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

=== octane/core rules ===

# Octane

- Octane boots the application once and reuses it across requests, so singletons persist between requests.
- The Laravel container's `scoped` method may be used as a safe alternative to `singleton`.
- Never inject the container, request, or config repository into a singleton's constructor; use a resolver closure or `bind()` instead:

```php
// Bad
$this->app->singleton(Service::class, fn (Application $app) => new Service($app['request']));

// Good
$this->app->singleton(Service::class, fn () => new Service(fn () => request()));
```

- Never append to static properties, as they accumulate in memory across requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== filament/filament rules ===

## Filament

- Filament is a Laravel UI framework built on Livewire, Alpine.js, and Tailwind CSS. UIs are defined in PHP via fluent, chainable components. Follow existing conventions in this app.
- Use the `search-docs` tool for official documentation on Artisan commands, code examples, testing, relationships, and idiomatic practices. If `search-docs` is unavailable, refer to https://filamentphp.com/docs.

### Artisan

- Always use Filament-specific Artisan commands to create files. Find available commands with the `list-artisan-commands` tool, or run `php artisan --help`.
- Inspect required options before running, and always pass `--no-interaction`.

### Patterns

Always use static `make()` methods to initialize components. Most configuration methods accept a `Closure` for dynamic values.

Use `Get $get` to read other form field values for conditional logic:

<code-snippet name="Conditional form field visibility" lang="php">
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

Select::make('type')
    ->options(CompanyType::class)
    ->required()
    ->live(),

TextInput::make('company_name')
    ->required()
    ->visible(fn (Get $get): bool => $get('type') === 'business'),

</code-snippet>

Use `Set $set` inside `->afterStateUpdated()` on a `->live()` field to mutate another field reactively. Prefer `->live(onBlur: true)` on text inputs to avoid per-keystroke updates:

<code-snippet name="Reactive field update" lang="php">
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

TextInput::make('title')
    ->required()
    ->live(onBlur: true)
    ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
        'slug',
        Str::slug($state ?? ''),
    )),

TextInput::make('slug')
    ->required(),

</code-snippet>

Compose layout by nesting `Section` and `Grid`. Children need explicit `->columnSpan()` or `->columnSpanFull()`:

<code-snippet name="Section and Grid layout" lang="php">
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

Section::make('Details')
    ->schema([
        Grid::make(2)->schema([
            TextInput::make('first_name')
                ->columnSpan(1),
            TextInput::make('last_name')
                ->columnSpan(1),
            TextInput::make('bio')
                ->columnSpanFull(),
        ]),
    ]),

</code-snippet>

Use `Repeater` for inline `HasMany` management. `->relationship()` with no args binds to the relationship matching the field name:

<code-snippet name="Repeater for HasMany" lang="php">
use Filament\Forms\Components\Repeater;

Repeater::make('qualifications')
    ->relationship()
    ->schema([
        TextInput::make('institution')
            ->required(),
        TextInput::make('qualification')
            ->required(),
    ])
    ->columns(2),

</code-snippet>

Use `state()` with a `Closure` to compute derived column values:

<code-snippet name="Computed table column value" lang="php">
use Filament\Tables\Columns\TextColumn;

TextColumn::make('full_name')
    ->state(fn (User $record): string => "{$record->first_name} {$record->last_name}"),

</code-snippet>

Use `SelectFilter` for enum or relationship filters, and `Filter` with a `->query()` closure for custom logic:

<code-snippet name="Table filters" lang="php">
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

SelectFilter::make('status')
    ->options(UserStatus::class),

SelectFilter::make('author')
    ->relationship('author', 'name'),

Filter::make('verified')
    ->query(fn (Builder $query) => $query->whereNotNull('email_verified_at')),

</code-snippet>

Actions are buttons that encapsulate optional modal forms and behavior:

<code-snippet name="Action with modal form" lang="php">
use Filament\Actions\Action;

Action::make('updateEmail')
    ->schema([
        TextInput::make('email')
            ->email()
            ->required(),
    ])
    ->action(fn (array $data, User $record) => $record->update($data)),

</code-snippet>

### Testing

Testing setup (requires `pestphp/pest-plugin-livewire` in `composer.json`):

- Always call `$this->actingAs(User::factory()->create())` before testing panel functionality.
- For edit pages, pass `['record' => $user->id]`, use `->call('save')` (not `->call('create')`), and do not assert `->assertRedirect()` (edit pages do not redirect after save).

<code-snippet name="Table test" lang="php">
use function Pest\Livewire\livewire;

livewire(ListUsers::class)
    ->assertCanSeeTableRecords($users)
    ->searchTable($users->first()->name)
    ->assertCanSeeTableRecords($users->take(1))
    ->assertCanNotSeeTableRecords($users->skip(1));

</code-snippet>

<code-snippet name="Create resource test" lang="php">
use function Pest\Laravel\assertDatabaseHas;

livewire(CreateUser::class)
    ->fillForm([
        'name' => 'Test',
        'email' => 'test@example.com',
    ])
    ->call('create')
    ->assertNotified()
    ->assertHasNoFormErrors()
    ->assertRedirect();

assertDatabaseHas(User::class, [
    'name' => 'Test',
    'email' => 'test@example.com',
]);

</code-snippet>

<code-snippet name="Edit resource test" lang="php">
livewire(EditUser::class, ['record' => $user->id])
    ->fillForm(['name' => 'Updated'])
    ->call('save')
    ->assertNotified()
    ->assertHasNoFormErrors();

assertDatabaseHas(User::class, [
    'id' => $user->id,
    'name' => 'Updated',
]);

</code-snippet>

<code-snippet name="Testing validation" lang="php">
livewire(CreateUser::class)
    ->fillForm([
        'name' => null,
        'email' => 'invalid-email',
    ])
    ->call('create')
    ->assertHasFormErrors([
        'name' => 'required',
        'email' => 'email',
    ])
    ->assertNotNotified();

</code-snippet>

Use `->callAction(DeleteAction::class)` for page actions, or `->callAction(TestAction::make('name')->table($record))` for table actions:

<code-snippet name="Calling actions" lang="php">
use Filament\Actions\Testing\TestAction;

livewire(ListUsers::class)
    ->callAction(TestAction::make('promote')->table($user), [
        'role' => 'admin',
    ])
    ->assertNotified();

</code-snippet>

### Correct Namespaces

- Form fields (`TextInput`, `Select`, `Repeater`, etc.): `Filament\Forms\Components\`
- Infolist entries (`TextEntry`, `IconEntry`, etc.): `Filament\Infolists\Components\`
- Layout components (`Grid`, `Section`, `Fieldset`, `Tabs`, `Wizard`, etc.): `Filament\Schemas\Components\`
- Schema utilities (`Get`, `Set`, etc.): `Filament\Schemas\Components\Utilities\`
- Table columns (`TextColumn`, `IconColumn`, etc.): `Filament\Tables\Columns\`
- Table filters (`SelectFilter`, `Filter`, etc.): `Filament\Tables\Filters\`
- Actions (`DeleteAction`, `CreateAction`, etc.): `Filament\Actions\`. Never use `Filament\Tables\Actions\`, `Filament\Forms\Actions\`, or any other sub-namespace for actions.
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g., `Heroicon::PencilSquare`)

### Common Mistakes

- **Never assume public file visibility.** File visibility is `private` by default. Always use `->visibility('public')` when public access is needed.
- **Never assume full-width layout.** `Grid`, `Section`, `Fieldset`, and `Repeater` do not span all columns by default.
- **Use `Select::make('author_id')->relationship('author', 'name')` for BelongsTo fields.** `BelongsToSelect` does not exist in v4.
- **`Repeater` uses `->schema()`, not `->fields()`.**
- **Never add `->dehydrated(false)` to fields that need to be saved.** It strips the value from form state before `->action()` or the save handler runs. Only use it for helper/UI-only fields.
- **Use correct property types when overriding `Page`, `Resource`, and `Widget` properties.** These properties have union types or changed modifiers that must be preserved:
  - `$navigationIcon`: `protected static string | BackedEnum | null` (not `?string`)
  - `$navigationGroup`: `protected static string | UnitEnum | null` (not `?string`)
  - `$view`: `protected string` (not `protected static string`) on `Page` and `Widget` classes

=== filament/blueprint rules ===

## Filament Blueprint

You are writing Filament v5 implementation plans. Plans must be specific enough
that an implementing agent can write code without making decisions.

**Start here**: Read
`/vendor/filament/blueprint/resources/markdown/planning/overview.md` for plan format,
required sections, and what to clarify with the user before planning.

=== spatie/guidelines-skills rules ===

# Project Coding Guidelines

- This codebase follows Spatie's coding guidelines.
- Always activate the `spatie-laravel-php` skill when writing, editing, reviewing, or formatting Laravel or PHP code.
- Always activate the `spatie-javascript` skill when writing, editing, reviewing, or formatting JavaScript or TypeScript code.
- Always activate the `spatie-version-control` skill when creating commits, branches, or managing Git operations.
- Always activate the `spatie-security` skill when configuring security, reviewing authentication, or setting up servers and databases.

</laravel-boost-guidelines>
