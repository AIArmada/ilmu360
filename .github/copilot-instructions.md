<laravel-boost-guidelines>
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
- `poster`: image/jpeg,image/png,image/webp, responsive, single file, fallback placeholder
- `gallery`: image/jpeg,image/png,image/webp, responsive, multi file
- Conversions:
  - `thumb`: 368x232 webp sharpen(10) on `poster`,`gallery`
  - `preview`: width 800 webp on `poster`

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
- `poster` upload
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
1. Event poster `thumb`
2. Institution logo `thumb`
3. First speaker avatar URL
4. Global placeholder image

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
- Submit-event poster/gallery uploads persist correctly

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

### 5. Demand Elegant (Balanced)
- For non-trivial changes: pause and ask "Is there a more elegant way?"
- If a fix feels hacky: "Knowing everything I know now, implement the elegant solution"
- Skip this for simple, obvious fixes – don't over-engineer
- Challenge your own work before presenting it

### 6. Autonomous Bug Fixing
- When given a bug report: just fix it. Don't ask for hand-holding
- Point at logs, errors, failing tests – then resolve them
- Zero context switching required from the user
- Go fix failing CI tests without being told how

### 7. Laravel Actions Where Appropriate
- Prefer Laravel Actions for reusable workflow orchestration that spans validation-adjacent normalization, transactions, side effects, or multiple entrypoints
- Do not force every mutation into an action; trivial single-call controller or Livewire handlers can stay inline
- Before adding a new action, check whether the behavior is already covered by an existing action and extend that path instead of duplicating orchestration
- Keep controllers and Livewire components focused on HTTP/UI concerns when a workflow is substantial enough to extract

### 8. Spatie Laravel Data Adoption

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

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to enhance the user's satisfaction building Laravel applications.

## Foundational Context
This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.19
- filament/filament (FILAMENT) - v5
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/octane (OCTANE) - v2
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/scout (SCOUT) - v10
- laravel/socialite (SOCIALITE) - v5
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2
- tailwindcss (TAILWINDCSS) - v4

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove it works. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

## Laravel Boost
- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan
- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs
- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging
- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool
- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)
- Boost comes with a powerful `search-docs` tool you should use before any other approaches when dealing with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- The `search-docs` tool is perfect for all Laravel-related packages, including Laravel, Inertia, Livewire, Filament, Tailwind, Pest, Nova, Nightwatch, etc.
- You must use this tool to search for Laravel ecosystem documentation before falling back to other approaches.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries to start. For example: `['rate limiting', 'routing rate limiting', 'routing']`.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax
- You can and should pass multiple queries at once. The most relevant results will be returned first.

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

## PHP

- Always use curly braces for control structures, even if it has one line.

### Constructors
- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

### Type Declarations
- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Comments
- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless there is something very complex going on.

## PHPDoc Blocks
- Add useful array shape type definitions for arrays when appropriate.

## Enums
- That being said, keys in an Enum should follow existing application Enum conventions.

=== herd rules ===

## Laravel Herd

- The application is served by Laravel Herd and will be available at: `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate URLs for the user to ensure valid URLs.
- You must not run any commands to make the site available via HTTP(S). It is always available through Laravel Herd.

=== tests rules ===

## Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

## Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Database
- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation
- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources
- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

### Controllers & Validation
- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

### Queues
- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

### Authentication & Authorization
- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

### URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

### Configuration
- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

### Testing
- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

### Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

## Laravel 12

- Use the `search-docs` tool to get version-specific documentation.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

### Laravel 12 Structure
- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

### Database
- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models
- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== fluxui-free/core rules ===

## Flux UI Free

- This project is using the free edition of Flux UI. It has full access to the free components and variants, but does not have access to the Pro components.
- Flux UI is a component library for Livewire. Flux is a robust, hand-crafted UI component library for your Livewire applications. It's built using Tailwind CSS and provides a set of components that are easy to use and customize.
- You should use Flux UI components when available.
- Fallback to standard Blade components if Flux is unavailable.
- If available, use the `search-docs` tool to get the exact documentation and code snippets available for this project.
- Flux UI components look like this:

<code-snippet name="Flux UI Component Example" lang="blade">
    <flux:button variant="primary"/>
</code-snippet>

### Available Components
This is correct as of Boost installation, but there may be additional components within the codebase.

<available-flux-components>
avatar, badge, brand, breadcrumbs, button, callout, checkbox, dropdown, field, heading, icon, input, modal, navbar, otp-input, profile, radio, select, separator, skeleton, switch, text, textarea, tooltip
</available-flux-components>

=== livewire/core rules ===

## Livewire

- Use the `search-docs` tool to find exact version-specific documentation for how to write Livewire and Livewire tests.
- Use the `php artisan make:livewire [Posts\CreatePost]` Artisan command to create new components.
- State should live on the server, with the UI reflecting it.
- All Livewire requests hit the Laravel backend; they're like regular HTTP requests. Always validate form data and run authorization checks in Livewire actions.

## Livewire Best Practices
- Livewire components require a single root element.
- Use `wire:loading` and `wire:dirty` for delightful loading states.
- Add `wire:key` in loops:

    ```blade
    @foreach ($items as $item)
        <div wire:key="item-{{ $item->id }}">
            {{ $item->name }}
        </div>
    @endforeach
    ```

- Prefer lifecycle hooks like `mount()`, `updatedFoo()` for initialization and reactive side effects:

<code-snippet name="Lifecycle Hook Examples" lang="php">
    public function mount(User $user) { $this->user = $user; }
    public function updatedSearch() { $this->resetPage(); }
</code-snippet>

## Testing Livewire

<code-snippet name="Example Livewire Component Test" lang="php">
    Livewire::test(Counter::class)
        ->assertSet('count', 0)
        ->call('increment')
        ->assertSet('count', 1)
        ->assertSee(1)
        ->assertStatus(200);
</code-snippet>

<code-snippet name="Testing Livewire Component Exists on Page" lang="php">
    $this->get('/posts/create')
    ->assertSeeLivewire(CreatePost::class);
</code-snippet>

=== pint/core rules ===

## Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest
### Testing
- If you need to verify a feature is working, write or update a Unit / Feature test.

### Pest Tests
- All tests must be written using Pest. Use `php artisan make:test --pest {name}`.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files - these are core to the application.
- Tests should test all of the happy paths, failure paths, and weird paths.
- Tests live in the `tests/Feature` and `tests/Unit` directories.
- Pest tests look and behave like this:
<code-snippet name="Basic Pest Test Example" lang="php">
it('is true', function () {
    expect(true)->toBeTrue();
});
</code-snippet>

### Running Tests
- Run the minimal number of tests using an appropriate filter before finalizing code edits.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).
- When the tests relating to your changes are passing, ask the user if they would like to run the entire test suite to ensure everything is still passing.

### Pest Assertions
- When asserting status codes on a response, use the specific method like `assertForbidden` and `assertNotFound` instead of using `assertStatus(403)` or similar, e.g.:
<code-snippet name="Pest Example Asserting postJson Response" lang="php">
it('returns all', function () {
    $response = $this->postJson('/api/docs', []);

    $response->assertSuccessful();
});
</code-snippet>

### Mocking
- Mocking can be very helpful when appropriate.
- When mocking, you can use the `Pest\Laravel\mock` Pest function, but always import it via `use function Pest\Laravel\mock;` before using it. Alternatively, you can use `$this->mock()` if existing tests do.
- You can also create partial mocks using the same import or self method.

### Datasets
- Use datasets in Pest to simplify tests that have a lot of duplicated data. This is often the case when testing validation rules, so consider this solution when writing tests for validation rules.

<code-snippet name="Pest Dataset Example" lang="php">
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
</code-snippet>

=== pest/v4 rules ===

## Pest 4

- Pest 4 is a huge upgrade to Pest and offers: browser testing, smoke testing, visual regression testing, test sharding, and faster type coverage.
- Browser testing is incredibly powerful and useful for this project.
- Browser tests should live in `tests/Browser/`.
- Use the `search-docs` tool for detailed guidance on utilizing these features.

### Browser Testing
- You can use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories within Pest 4 browser tests, as well as `RefreshDatabase` (when needed) to ensure a clean state for each test.
- Interact with the page (click, type, scroll, select, submit, drag-and-drop, touch gestures, etc.) when appropriate to complete the test.
- If requested, test on multiple browsers (Chrome, Firefox, Safari).
- If requested, test on different devices and viewports (like iPhone 14 Pro, tablets, or custom breakpoints).
- Switch color schemes (light/dark mode) when appropriate.
- Take screenshots or pause tests for debugging when appropriate.

### Example Tests

<code-snippet name="Pest Browser Test Example" lang="php">
it('may reset the password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create());

    $page = visit('/sign-in'); // Visit on a real browser...

    $page->assertSee('Sign In')
        ->assertNoJavascriptErrors() // or ->assertNoConsoleLogs()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!')

    Notification::assertSent(ResetPassword::class);
});
</code-snippet>

<code-snippet name="Pest Smoke Testing Example" lang="php">
$pages = visit(['/', '/about', '/contact']);

$pages->assertNoJavascriptErrors()->assertNoConsoleLogs();
</code-snippet>

=== tailwindcss/core rules ===

## Tailwind CSS

- Use Tailwind CSS classes to style HTML; check and use existing Tailwind conventions within the project before writing your own.
- Offer to extract repeated patterns into components that match the project's conventions (i.e. Blade, JSX, Vue, etc.).
- Think through class placement, order, priority, and defaults. Remove redundant classes, add classes to parent or child carefully to limit repetition, and group elements logically.
- You can use the `search-docs` tool to get exact examples from the official documentation when needed.

### Spacing
- When listing items, use gap utilities for spacing; don't use margins.

<code-snippet name="Valid Flex Gap Spacing Example" lang="html">
    <div class="flex gap-8">
        <div>Superior</div>
        <div>Michigan</div>
        <div>Erie</div>
    </div>
</code-snippet>

### Dark Mode
- If existing pages and components support dark mode, new pages and components must support dark mode in a similar way, typically using `dark:`.

=== tailwindcss/v4 rules ===

## Tailwind CSS 4

- Always use Tailwind CSS v4; do not use the deprecated utilities.
- `corePlugins` is not supported in Tailwind v4.
- In Tailwind v4, configuration is CSS-first using the `@theme` directive — no separate `tailwind.config.js` file is needed.

<code-snippet name="Extending Theme in CSS" lang="css">
@theme {
  --color-brand: oklch(0.72 0.11 178);
}
</code-snippet>

- In Tailwind v4, you import Tailwind using a regular CSS `@import` statement, not using the `@tailwind` directives used in v3:

<code-snippet name="Tailwind v4 Import Tailwind Diff" lang="diff">
   - @tailwind base;
   - @tailwind components;
   - @tailwind utilities;
   + @import "tailwindcss";
</code-snippet>

### Replaced Utilities
- Tailwind v4 removed deprecated utilities. Do not use the deprecated option; use the replacement.
- Opacity values are still numeric.

| Deprecated |	Replacement |
|------------+--------------|
| bg-opacity-* | bg-black/* |
| text-opacity-* | text-black/* |
| border-opacity-* | border-black/* |
| divide-opacity-* | divide-black/* |
| ring-opacity-* | ring-black/* |
| placeholder-opacity-* | placeholder-black/* |
| flex-shrink-* | shrink-* |
| flex-grow-* | grow-* |
| overflow-ellipsis | text-ellipsis |
| decoration-slice | box-decoration-slice |
| decoration-clone | box-decoration-clone |

=== filament/filament rules ===

## Filament

- Filament is used by this application. Follow the existing conventions for how and where it is implemented.
- Filament is a Server-Driven UI (SDUI) framework for Laravel that lets you define user interfaces in PHP using structured configuration objects. Built on Livewire, Alpine.js, and Tailwind CSS.
- Use the `search-docs` tool for official documentation on Artisan commands, code examples, testing, relationships, and idiomatic practices. If `search-docs` is unavailable, refer to https://filamentphp.com/docs.

### Artisan

- Always use Filament-specific Artisan commands to create files. Find available commands with the `list-artisan-commands` tool, or run `php artisan --help`.
- Always inspect required options before running a command, and always pass `--no-interaction`.

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

Use `state()` with a `Closure` to compute derived column values:

<code-snippet name="Computed table column value" lang="php">
use Filament\Tables\Columns\TextColumn;

TextColumn::make('full_name')
    ->state(fn (User $record): string => "{$record->first_name} {$record->last_name}"),

</code-snippet>

Actions encapsulate a button with an optional modal form and logic:

<code-snippet name="Action with modal form" lang="php">
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

Action::make('updateEmail')
    ->schema([
        TextInput::make('email')
            ->email()
            ->required(),
    ])
    ->action(fn (array $data, User $record) => $record->update($data))

</code-snippet>

### Testing

Always authenticate before testing panel functionality. Filament uses Livewire, so use `Livewire::test()` or `livewire()` (available when `pestphp/pest-plugin-livewire` is in `composer.json`):

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
use function Pest\Livewire\livewire;

livewire(CreateUser::class)
    ->fillForm([
        'name' => 'Test',
        'email' => 'test@example.com',
    ])
    ->call('create')
    ->assertNotified()
    ->assertRedirect();

assertDatabaseHas(User::class, [
    'name' => 'Test',
    'email' => 'test@example.com',
]);

</code-snippet>

<code-snippet name="Testing validation" lang="php">
use function Pest\Livewire\livewire;

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

<code-snippet name="Calling actions in pages" lang="php">
use Filament\Actions\DeleteAction;
use function Pest\Livewire\livewire;

livewire(EditUser::class, ['record' => $user->id])
    ->callAction(DeleteAction::class)
    ->assertNotified()
    ->assertRedirect();

</code-snippet>

<code-snippet name="Calling actions in tables" lang="php">
use Filament\Actions\Testing\TestAction;
use function Pest\Livewire\livewire;

livewire(ListUsers::class)
    ->callAction(TestAction::make('promote')->table($user), [
        'role' => 'admin',
    ])
    ->assertNotified();

</code-snippet>

### Correct Namespaces

- Form fields (`TextInput`, `Select`, etc.): `Filament\Forms\Components\`
- Infolist entries (`TextEntry`, `IconEntry`, etc.): `Filament\Infolists\Components\`
- Layout components (`Grid`, `Section`, `Fieldset`, `Tabs`, `Wizard`, etc.): `Filament\Schemas\Components\`
- Schema utilities (`Get`, `Set`, etc.): `Filament\Schemas\Components\Utilities\`
- Actions (`DeleteAction`, `CreateAction`, etc.): `Filament\Actions\`. Never use `Filament\Tables\Actions\`, `Filament\Forms\Actions\`, or any other sub-namespace for actions.
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g., `Heroicon::PencilSquare`)

### Common Mistakes

- **Never assume public file visibility.** File visibility is `private` by default. Always use `->visibility('public')` when public access is needed.
- **Never assume full-width layout.** `Grid`, `Section`, and `Fieldset` do not span all columns by default. Explicitly set column spans when needed.

=== laravel/fortify rules ===

# Laravel Fortify

- Fortify is a headless authentication backend that provides authentication routes and controllers for Laravel applications.
- IMPORTANT: Always use the `search-docs` tool for detailed Laravel Fortify patterns and documentation.
- IMPORTANT: Activate `developing-with-fortify` skill when working with Fortify authentication features.

=== filament/blueprint rules ===

## Filament Blueprint

You are writing Filament v5 implementation plans. Plans must be specific enough
that an implementing agent can write code without making decisions.

**Start here**: Read
`/vendor/filament/blueprint/resources/markdown/planning/overview.md` for plan format,
required sections, and what to clarify with the user before planning.
</laravel-boost-guidelines>
