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
