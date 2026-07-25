# Replace nnjeim/world Languages with aiarmada/commerce-support

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the `nnjeim/world` package and replace its `languages` table and `Language` model with `aiarmada/commerce-support`'s UUID-based equivalent.

**Architecture:** Write migrations to swap the `languages` table from integer PK (nnjeim/world) to UUID PK (commerce-support), alter the `languageables` pivot column type, create a new `App\Models\Language` model, and update all 20+ consumer files.

**Tech Stack:** Laravel 13, commerce-support SeedLanguagesAction/Seeder, UUID PKs, polymorphic M2M pivot

## Global Constraints

- No backward compatibility for existing `languageables` data — clean break
- Follow project conventions: no FK constraints, no `down()` methods, `uuid('id')->primary()`, `foreignUuid()`
- All migrations safe/idempotent
- Commerce-support `HasLanguages` JSON trait — NOT used

---

### Task 1: Database migration — replace languages table

**Files:**
- Create: `database/migrations/2026_07_25_000001_swap_languages_to_commerce_uuid.php`

**Interfaces:**
- Produces: New `languages` table with UUID PK (`id`, `code`, `name`, `native`, `dir`, `created_at`, `updated_at`)
- Consumed by: All subsequent tasks (seeding, model creation)

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration swap_languages_to_commerce_uuid
```

Edit the generated file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('languages');

        Schema::create('languages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('native')->nullable();
            $table->string('dir', 3)->default('ltr');
            $table->timestampsTz();
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate --no-interaction
```
Expected: `languages` table recreated with UUID schema, no errors.

- [ ] **Step 3: Seed languages from commerce-support**

```bash
php artisan commerce:seed-languages
```
Expected: `Languages seeded: 184 created, 0 updated, 0 skipped.`

- [ ] **Step 4: Verify in tinker**

```bash
php artisan tinker --execute 'echo DB::table("languages")->count(); echo PHP_EOL; DB::table("languages")->where("code", "ms")->dump();'
```
Expected: 184 rows, row for `ms` with UUID `id`, `dir = ltr`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/
git commit -m "feat: replace languages table with commerce-support UUID schema"
```

---

### Task 2: Create App\Models\Language

**Files:**
- Create: `app/Models/Language.php`

**Interfaces:**
- Produces: `App\Models\Language` (UUID-keyed Eloquent model for `languages` table)
- Consumed by: All files currently importing `Nnjeim\World\Models\Language`

- [ ] **Step 1: Create the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'native',
        'dir',
    ];
}
```

- [ ] **Step 2: Verify in tinker**

```bash
php artisan tinker --execute 'echo App\Models\Language::where("code", "ms")->value("code");'
```
Expected: `ms`

- [ ] **Step 3: Commit**

```bash
git add app/Models/Language.php
git commit -m "feat: create App\Models\Language model for commerce-support languages table"
```

---

### Task 3: Database migration — recreate languageables pivot with UUID FK

**Files:**
- Create: `database/migrations/2026_07_25_000002_alter_languageables_to_uuid.php`

**Interfaces:**
- Consumes: New UUID-based `languages` table (Task 1)
- Produces: `languageables` table with `foreignUuid('language_id')` and `uuidMorphs('languageable')`
- Consumed by: `HasLanguages` concern (Task 4)

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration alter_languageables_to_uuid
```

Edit the generated file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('languageables');

        Schema::create('languageables', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('language_id');
            $table->uuidMorphs('languageable');
            $table->timestamps();

            $table->index(['language_id', 'languageable_type', 'languageable_id'], 'languageables_lookup');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate --no-interaction
```
Expected: `languageables` table recreated with UUID PK and `foreignUuid('language_id')`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/
git commit -m "feat: alter languageables pivot to use UUID language_id"
```

---

### Task 4: Update HasLanguages concern

**Files:**
- Modify: `app/Models/Concerns/HasLanguages.php:6`

**Interfaces:**
- Consumes: `App\Models\Language` (Task 2)
- Produces: Updated concern using new Language model

- [ ] **Step 1: Swap the import**

In `app/Models/Concerns/HasLanguages.php`, line 6:

```diff
- use Nnjeim\World\Models\Language;
+ use App\Models\Language;
```

No other changes — the `morphToMany(Language::class, 'languageable', 'languageables')` and `auditSync` calls work identically; the relationship is resolved by Eloquent.

- [ ] **Step 2: Commit**

```bash
git add app/Models/Concerns/HasLanguages.php
git commit -m "refactor: use App\Models\Language in HasLanguages concern"
```

---

### Task 5: Update Event model

**Files:**
- Modify: `app/Models/Event.php:67,396-452`

**Interfaces:**
- Consumes: `App\Models\Language` (Task 2)
- Produces: `syncLanguages(array|string)` with UUID support

- [ ] **Step 1: Swap the import**

In `app/Models/Event.php`, line 67:

```diff
- use Nnjeim\World\Models\Language;
+ use App\Models\Language;
```

- [ ] **Step 2: Update syncLanguages() signature and body**

Replace lines 396-452:

```php
    /**
     * @param  array<int, string>|string  $languages
     */
    public function syncLanguages(array|string $languages): void
    {
        $languageIds = collect(is_array($languages) ? $languages : [$languages])
            ->filter(fn (mixed $languageId): bool => filled($languageId))
            ->map(fn (mixed $languageId): string => (string) $languageId)
            ->values();

        $before = $this->resolvedLanguages()
            ->map(fn (Language $language): array => [
                'id' => (string) $language->id,
                'name' => (string) $language->name,
                'code' => (string) $language->code,
            ])
            ->all();

        $languageCodes = Language::query()
            ->whereIn('id', $languageIds->all())
            ->get(['id', 'code'])
            ->sortBy(fn (Language $language): int => $languageIds->search((string) $language->id) ?: 0)
            ->pluck('code')
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
            ->values();

        OwnerContext::withOwner(null, function () use ($languageCodes): void {
            $this->languageRecords()->delete();

            foreach ($languageCodes as $index => $languageCode) {
                $this->languageRecords()->create([
                    'event_id' => (string) $this->getKey(),
                    'language_code' => $languageCode,
                    'usage_type' => 'primary',
                    'is_primary' => $index === 0,
                    'sort_order' => $index,
                    'metadata' => [
                        'source' => 'commerce_languages',
                    ],
                ]);
            }
        });

        $this->unsetRelation('languages');
        $this->resolvedLanguageCache = null;

        $after = $this->resolvedLanguages()
            ->map(fn (Language $language): array => [
                'id' => (string) $language->id,
                'name' => (string) $language->name,
                'code' => (string) $language->code,
            ])
            ->all();

        $this->recordCustomAuditDifferences('updated', [
            'languages' => $before,
        ], [
            'languages' => $after,
        ]);
    }
```

Key changes from old:
- `array|int` → `array|string`
- `(int) $languageId` → `(string) $languageId`
- `(int) $language->id` → `(string) $language->id`
- `whereIn('id', $languageIds->all(), $languageIds->search((int) $language->id)` → `$languageIds->search((string) $language->id)`
- `'source' => 'world_languages'` → `'source' => 'commerce_languages'`

- [ ] **Step 3: Commit**

```bash
git add app/Models/Event.php
git commit -m "refactor: update Event::syncLanguages for UUID-based Language model"
```

---

### Task 6: Bulk swap all remaining app imports

**Files (11 files, import-only changes):**
- Modify: `app/Services/Ai/EventMediaExtractionService.php:22`
- Modify: `app/Forms/PersonContributionFormSchema.php:16`
- Modify: `app/Forms/EventContributionFormSchema.php:43`
- Modify: `app/Support/Mcp/EventCoverPromptBuilder.php:35`
- Modify: `app/Livewire/Pages/Events/AdvancedFiltersPanel.php:39`
- Modify: `app/Livewire/Pages/SubmitEvent/Create.php:90`
- Modify: `app/Livewire/Pages/Events/Index.php:53`
- Modify: `app/Support/Api/Frontend/FrontendCatalogService.php:28`
- Modify: `app/Filament/Resources/Series/Pages/EditSeries.php:13`
- Modify: `app/Filament/Resources/Series/Pages/CreateSeries.php:12`

**Interfaces:**
- Consumes: `App\Models\Language` (Task 2)
- Produces: All app imports resolved to new model

- [ ] **Step 1: Replace all imports in one pass**

Each file has exactly one line to change:

```diff
- use Nnjeim\World\Models\Language;
+ use App\Models\Language;
```

Files and line numbers:

1. `app/Services/Ai/EventMediaExtractionService.php:22`
2. `app/Forms/PersonContributionFormSchema.php:16`
3. `app/Forms/EventContributionFormSchema.php:43`
4. `app/Support/Mcp/EventCoverPromptBuilder.php:35`
5. `app/Livewire/Pages/Events/AdvancedFiltersPanel.php:39`
6. `app/Livewire/Pages/SubmitEvent/Create.php:90`
7. `app/Livewire/Pages/Events/Index.php:53`
8. `app/Support/Api/Frontend/FrontendCatalogService.php:28`
9. `app/Filament/Resources/Series/Pages/EditSeries.php:13`
10. `app/Filament/Resources/Series/Pages/CreateSeries.php:12`

- [ ] **Step 2: Commit**

```bash
git add app/
git commit -m "refactor: swap all Nnjeim\World\Models\Language imports to App\Models\Language"
```

---

### Task 7: Update FrontendCatalogService::languages()

**Files:**
- Modify: `app/Support/Api/Frontend/FrontendCatalogService.php:212-225`

**Interfaces:**
- Consumes: `App\Models\Language` (Task 2), import already swapped in Task 6
- Produces: API response with UUID `id` instead of integer `id`

- [ ] **Step 1: Change id type from int to string**

In `app/Support/Api/Frontend/FrontendCatalogService.php`, lines 214-224:

```diff
-     /**
-      * @return list<array{id: int, label: string}>
-      */
      public function languages(): array
      {
          /** @var Collection<int, Language> $languages */
          $languages = Language::query()->orderBy('name')->get(['id', 'name']);

          return $languages
              ->map(fn (Language $language): array => [
-                 'id' => (int) $language->id,
+                 'id' => (string) $language->id,
                  'label' => (string) $language->name,
              ])
              ->all();
      }
```

- [ ] **Step 2: Commit**

```bash
git add app/Support/Api/Frontend/FrontendCatalogService.php
git commit -m "refactor: return UUID string as language id in catalog endpoint"
```

---

### Task 8: Update behavior in SubmitEvent Create.php

**Files:**
- Modify: `app/Livewire/Pages/SubmitEvent/Create.php:151`

**Interfaces:**
- Consumes: `App\Models\Language` (Task 2), import already swapped in Task 6
- Produces: Default languages resolved from `code` lookup instead of hardcoded int `101`

- [ ] **Step 1: Change default language value from hardcoded int to code lookup**

In `app/Livewire/Pages/SubmitEvent/Create.php`, find the mount() or form state section with `'languages' => [101]` (around line 151):

```diff
-             'languages' => [101],
+             'languages' => [Language::where('code', 'ms')->value('id') ?? ''],
```

Add the import if not already present (should already be imported from Task 6):

```php
use App\Models\Language;
```

- [ ] **Step 2: Commit**

```bash
git add app/Livewire/Pages/SubmitEvent/Create.php
git commit -m "refactor: resolve default language by code in submit event form"
```

---

### Task 9: Update blade views

**Files:**
- Modify: `resources/views/livewire/pages/events/index.blade.php:945`
- Modify: `resources/views/components/pages/submit-event/partials/review-preview.blade.php:142`

- [ ] **Step 1: Replace FQNs in blade files**

`resources/views/livewire/pages/events/index.blade.php`, line 945:

```diff
- ->map(fn (\Nnjeim\World\Models\Language $language): string => (string) ($language->code === 'ms' ? 'BM' : strtoupper((string) $language->code)))
+ ->map(fn (\App\Models\Language $language): string => (string) ($language->code === 'ms' ? 'BM' : strtoupper((string) $language->code)))
```

`resources/views/components/pages/submit-event/partials/review-preview.blade.php`, line 142:

```diff
- $languageMap = \Nnjeim\World\Models\Language::query()
+ $languageMap = \App\Models\Language::query()
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/
git commit -m "refactor: update blade FQN references to App\Models\Language"
```

---

### Task 10: Update API validation rules

**Files:**
- Modify: `app/Services/ContributionEntityMutationService.php:239,292`
- Modify: `app/Support/Api/Admin/AdminResourceMutationService.php:2105,2236,2278`

- [ ] **Step 1: Change exists validation from integer to string/uuid**

In `app/Services/ContributionEntityMutationService.php`, lines 239 and 292:

```diff
-             'language_ids.*' => ['integer', 'exists:languages,id'],
+             'language_ids.*' => ['string', 'exists:languages,id'],
```

In `app/Support/Api/Admin/AdminResourceMutationService.php`, lines 2105, 2236, and 2278:

```diff
-             'languages.*' => ['integer', 'exists:languages,id'],
+             'languages.*' => ['string', 'exists:languages,id'],
```

```diff
-             'language_ids.*' => ['integer', 'exists:languages,id'],
+             'language_ids.*' => ['string', 'exists:languages,id'],
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/ContributionEntityMutationService.php app/Support/Api/Admin/AdminResourceMutationService.php
git commit -m "refactor: update language ID validation rules for UUID type"
```

---

### Task 11: Update database seeders and factories

**Files:**
- Modify: `database/seeders/EventSeeder.php:38`
- Modify: `database/seeders/SeriesSeeder.php:9,78`
- Modify: `database/factories/PersonFactory.php:23`

- [ ] **Step 1: Swap imports**

In all three files, replace:

```diff
- use Nnjeim\World\Models\Language;
+ use App\Models\Language;
```

- [ ] **Step 2: No further changes needed in SeriesSeeder**

The seeder already uses `Language::query()->pluck('id')->toArray()` (line 27) to get language IDs. After the import swap to `App\Models\Language`, `pluck('id')` returns UUID strings. `array_rand(array_flip($languageIds), ...)` works on string keys. The `DB::table('languageables')->insert()` stores UUIDs in `language_id` (now `foreignUuid`). The `languageable_type` (`'series'`) is unchanged — it refers to the Series morph type, not Language. No code logic changes needed beyond the import swap.

- [ ] **Step 3: Commit**

```bash
git add database/seeders/ database/factories/
git commit -m "refactor: update seeders and factories for UUID-based Language model"
```

---

### Task 12: Update tests

**Files (5 files, import-only changes):**
- Modify: `tests/Unit/EventTest.php:13`
- Modify: `tests/Feature/Laravel13CacheSerializationTest.php:13`
- Modify: `tests/Feature/AdminAuditFollowUpTest.php:19`
- Modify: `tests/Feature/EventSearchTest.php:35`
- Modify: `tests/Feature/Api/Admin/AdminApiTest.php:44`

- [ ] **Step 1: Swap imports in all test files**

Each file has exactly one line to change:

```diff
- use Nnjeim\World\Models\Language;
+ use App\Models\Language;
```

- [ ] **Step 2: Verify no remaining Nnjeim references in app/tests**

```bash
rg --no-filename -n "Nnjeim\\World" app/ tests/ database/ resources/
```
Expected: empty output (zero matches).

If any remain, fix them now.

- [ ] **Step 3: Commit**

```bash
git add tests/
git commit -m "refactor: update test imports to App\Models\Language"
```

---

### Task 13: Remove config/world.php and nnjeim/world dependency

**Files:**
- Delete: `config/world.php`
- Modify: `composer.json:63`

- [ ] **Step 1: Delete config file**

```bash
rm config/world.php
```

- [ ] **Step 2: Remove composer dependency**

```bash
composer remove nnjeim/world --no-interaction
```
Expected: Package removed, composer.lock updated, autoloader regenerated.

If `composer remove` fails because other packages (e.g., `persons`, `events`) depend on nnjeim/world, use `--no-update` then `composer update`:

```bash
composer remove nnjeim/world --no-interaction --no-update
composer update nnjeim/world --no-interaction
```

- [ ] **Step 3: Verify no nnjeim references in composer**

```bash
rg "nnjeim" composer.json composer.lock
```
Expected: empty output for composer.json. composer.lock may still cache old entries — run `composer update` once more if needed.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: remove nnjeim/world dependency and config"
```

---

### Task 14: Final verification

- [ ] **Step 1: Run Pest tests**

```bash
php artisan test --compact --parallel
```
Expected: All tests pass. If failures occur, debug and fix before proceeding.

Note: Any test that hardcodes integer language IDs (like `101` for Malay) will fail and needs to be updated to use `Language::where('code', 'ms')->value('id')` or similar lookup. Fix those tests.

- [ ] **Step 2: Run PHPStan**

```bash
vendor/bin/phpstan analyse --ansi
```
Expected: No new errors introduced. Fix any type errors.

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --format agent
```
Expected: All files formatted.

- [ ] **Step 4: Final check — zero Nnjeim references**

```bash
rg --no-filename -n "Nnjeim\\World" app/ tests/ database/ resources/ config/
```
Expected: empty output.

- [ ] **Step 5: Commit any final cleanup**

```bash
git add -A
git commit -m "chore: final cleanup after nnjeim/world removal"
```
