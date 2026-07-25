# Replace nnjeim/world Languages with aiarmada/commerce-support

**Date:** 2026-07-25
**Status:** approved

## 1. Objective

Remove the `nnjeim/world` package dependency and replace its `languages` table and `Language` model with `aiarmada/commerce-support`'s equivalent infrastructure.

## 2. Scope

### In scope

- Replace `languages` reference table (integer PK → UUID PK)
- Replace `Nnjeim\World\Models\Language` → new `App\Models\Language`
- Update `languageables` pivot column type (`bigint` → `uuid`)
- Update all ~15 app files importing `Nnjeim\World\Models\Language`
- Update all form fields, API endpoints, seeders, factories, tests
- Update `Event::syncLanguages()` to work with UUIDs
- Remove `config/world.php`
- Remove `composer.json` entry for `nnjeim/world`
- Run `composer remove nnjeim/world`

### Out of scope

- Commerce-support's `HasLanguages` JSON trait — not adopted (pivot table approach is better for queryability)
- Commerce-support's `HasCommerceTranslations` trait — no change to translation system
- Locale switching (`SetLocale` middleware, `LocaleController`, `filament-language-switch`) — unchanged
- `event_languages` table schema — already stores `language_code` (string), no structural change needed
- Other nnjeim/world tables (`world_countries`, `world_states`, `world_cities`, `timezones`, `currencies`) — out of scope; removed when/if geography is migrated
- No backward compatibility for existing `languageables` pivot data

## 3. Architecture

### Before

```
nnjeim/world package
    └── languages table (int PK, auto-increment)
            ├── EventLanguage (language_code, string FK — resolved via app code)
            │       └── Event::syncLanguages([int IDs])
            │
            └── languageables pivot (language_id bigint, morphToMany)
                    ├── Person
                    ├── Series
                    └── Institution
```

### After

```
aiarmada/commerce-support
    └── languages table (uuid PK)
            ├── EventLanguage (language_code, string — already decoupled)
            │       └── Event::syncLanguages([uuid IDs])
            │
            └── languageables pivot (language_id uuid, morphToMany)
                    ├── Person
                    ├── Series
                    └── Institution

No nnjeim/world dependency.
```

### Custom code added

| Artifact | Purpose |
|----------|---------|
| `App\Models\Language` | Eloquent model for commerce-support's `languages` table (the package provides no model) |
| Migration: drop old `languages`, create new UUID-schema `languages` | Replace the reference table |
| Migration: alter `languageables.language_id` `bigint` → `uuid` | Match new PK type |

## 4. Database Changes

### Step 1: Replace `languages` table

**New migration** that in order:
1. Drops the existing `languages` table (nnjeim/world, integer PK)
2. Creates a new `languages` table using commerce-support's schema:

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid | Primary key |
| `code` | string(10) | Unique ISO 639-1 |
| `name` | string | English name |
| `native` | string, nullable | Endonym |
| `dir` | string(3) | `ltr` or `rtl`, default `ltr` |
| `created_at` | timestamptz | |
| `updated_at` | timestamptz | |

3. Seed via `php artisan commerce:seed-languages` (184 languages)

### Step 2: Alter `languageables` pivot

**New migration:**
1. Drop existing `languageables` table (old data invalid — int FK to dropped table)
2. Recreate with `foreignUuid('language_id')` instead of `foreignId('language_id')`
3. Keep `uuidMorphs('languageable')` and composite index unchanged

### Step 3: No change to `event_languages`

The `event_languages` table stores `language_code` directly (string, no FK). Schema unchanged.

## 5. New `App\Models\Language`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['code', 'name', 'native', 'dir'];
}
```

- `$keyType = 'string'` and `$incrementing = false` for UUID PK
- `code` is the canonical lookup key; commerce-support's seed contains 184 ISO 639-1 codes
- No traits needed (no sortable, no soft deletes, no KeepsDeletedModels)

## 6. Code Changes: File-by-File

### core changes

| File | Change |
|------|--------|
| `app/Models/Concerns/HasLanguages.php` | `use Nnjeim\World\Models\Language` → `use App\Models\Language`; return type unchanged |
| `app/Models/Event.php` | `use Nnjeim\World\Models\Language` → `use App\Models\Language`; `syncLanguages()` resolves UUIDs to codes |
| `app/Models/Person.php` | No import change (uses `HasLanguages` concern) |
| `app/Models/Series.php` | No import change (uses `HasLanguages` concern) |
| `app/Models/Institution.php` | No import change (uses `HasLanguages` concern) |

### forms & Livewire

| File | Change |
|------|--------|
| `app/Forms/EventContributionFormSchema.php` | `use Nnjeim\World\Models\Language` → `use App\Models\Language` |
| `app/Forms/PersonContributionFormSchema.php` | `use Nnjeim\World\Models\Language` → `use App\Models\Language` |
| `app/Livewire/Pages/Events/AdvancedFiltersPanel.php` | Same import swap |
| `app/Livewire/Pages/Events/Index.php` | Same import swap |
| `app/Livewire/Pages/SubmitEvent/Create.php` | Same import swap |

### filament admin

| File | Change |
|------|--------|
| `app/Filament/Resources/Series/Schemas/SeriesForm.php` | `->relationship('languages', 'name')` — continues to work via `HasLanguages` concern |
| `app/Filament/Resources/Persons/Schemas/PersonForm.php` | Same — no change needed |
| `app/Filament/Resources/Series/Pages/EditSeries.php` | `use Nnjeim\World\Models\Language` → `use App\Models\Language` |
| `app/Filament/Resources/Series/Pages/CreateSeries.php` | Same import swap |

### services & support

| File | Change |
|------|--------|
| `app/Services/Ai/EventMediaExtractionService.php` | Import swap; `mapLanguageCodesToIds()` returns UUIDs |
| `app/Support/Mcp/EventCoverPromptBuilder.php` | Import swap |
| `app/Support/Api/Frontend/FrontendCatalogService.php` | Import swap; `languages()` returns `s id` as string/UUID |
| `app/Http/Controllers/Api/Frontend/CatalogController.php` | No change (delegates to FrontendCatalogService) |

### seeders & factories

| File | Change |
|------|--------|
| `database/seeders/EventSeeder.php` | Import swap |
| `database/seeders/SeriesSeeder.php` | Import swap; `DB::table('languageables')->insert()` uses UUIDs |
| `database/factories/PersonFactory.php` | Import swap |

### tests

| File | Change |
|------|--------|
| `tests/Feature/Laravel13CacheSerializationTest.php` | Import swap |
| `tests/Feature/EventSearchTest.php` | Import swap |
| `tests/Feature/AdminAuditFollowUpTest.php` | Import swap |
| `tests/Feature/Api/Admin/AdminApiTest.php` | Import swap |
| `tests/Unit/EventTest.php` | Import swap |

### views

| File | Change |
|------|--------|
| `resources/views/livewire/pages/events/index.blade.php` | `\Nnjeim\World\Models\Language` → `\App\Models\Language` |
| `resources/views/components/pages/submit-event/partials/review-preview.blade.php` | Same FQN swap |

### config & dependencies

| File | Change |
|------|--------|
| `config/world.php` | Delete entire file |
| `composer.json` | Remove `"nnjeim/world": "^1.1"`, run `composer remove nnjeim/world` |

## 7. Event::syncLanguages() Behavior Change

**Current:**
```php
public function syncLanguages(array|int $languages): void
{
    // $languages = [101, 1] — integer IDs
    // Resolves: Language::whereIn('id', [101, 1])->pluck('code')
    // Stores language_code in event_languages
}
```

**After:**
```php
public function syncLanguages(array|string $languages): void
{
    // $languages = ['uuid-ms', 'uuid-en'] — UUID strings
    // Resolves: Language::whereIn('id', ['uuid-ms', 'uuid-en'])->pluck('code')
    // Stores language_code in event_languages (unchanged)
}
```

No change to `event_languages` table or the stored `language_code` values.

## 8. API / Frontend Contract

### Catalog endpoint: `GET /api/frontend/catalogs/languages`

**Before:**
```json
{ "data": [{ "id": 101, "label": "Malay" }, { "id": 1, "label": "English" }] }
```
(`id` is integer)

**After:**
```json
{ "data": [{ "id": "019a-...", "label": "Malay" }, { "id": "019b-...", "label": "English" }] }
```
(`id` is UUID string)

### Admin API: ContributionEntityMutationService

Validation `'language_ids.*' => ['integer', 'exists:languages,id']` changes to `['string', 'uuid', 'exists:languages,id']`.

### Submit Event form

`languages` field switches from integer values `[101]` to UUID values `["019a-..."]`.

## 9. What stays the same

- `config/app.php` locale settings — unchanged
- `SetLocale` middleware, `LocaleController`, locale switching — unchanged
- `filament-language-switch` — unchanged
- Translation files in `resources/lang/` — unchanged
- `event_languages` table, `EventLanguage` model — schema unchanged
- `HasLanguages` concern pattern (polymorphic M2M via `languageables`) — unchanged
- Audit-syncing via `syncLanguages()` — unchanged
- UI layouts for language selection — unchanged

## 10. No landing for commerce-support traits

- `HasLanguages` (JSON array) — skipped; pivot table is better for queryability
- `HasCommerceTranslations` — skipped; already using spatie/laravel-translatable directly where needed

## 11. Risks & Notes

- **Clean break:** old `languageables` data is lost. Re-populated when next save happens on each entity via Filament admin or public APIs.
- **Event language:** the `event_languages` table stores `language_code` (string), not PK, so existing event language data survives. Only the `syncLanguages()` resolution step changes.
- **nnjeim/world package removal** also removes access to `Country`, `State`, `City`, `Currency`, `Timezone` models. If any of those are in active use elsewhere, the package removal will break them. The geography migration is a separate project.
- **Factories/seeders** will need fresh UUID values for language references. Since commerce-support's seed is deterministic by `code`, factories can look up languages by `code` instead of hardcoding IDs.
