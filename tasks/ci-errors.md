# CI Errors - Run #30111522267

## Summary
- 10 shards, 8 failing shards
- Quality gate: rector ✅, pint ✅, phpstan ✅, tests ❌ (~139 unique failing tests)
- PR: "Clean up unused code and update Tag model" (Person identity system refactor)

## Root Causes & Fixes Applied

### 1. ✅ Blade: `$this->speaker_ids` → `$this->person_ids`
**File**: `resources/views/livewire/pages/events/index.blade.php:79`
The Livewire component property was renamed from `$speaker_ids` to `$person_ids`
but the blade template still referenced `$this->speaker_ids`.
→ Fixed.

### 2. ✅ Blade: `$this->followingSpeakers` → `$this->followingPersons`
**File**: `resources/views/livewire/pages/dashboard/user-dashboard.blade.php:10`
The Livewire `#[Computed]` method was renamed from `followingSpeakers()` to `followingPersons()`
but the blade template still accessed `$this->followingSpeakers`.
→ Fixed.

### 3. ✅ API route parameter: `speakerKey` → `personKey`
**File**: `tests/Feature/Api/Frontend/FrontendApiParityTest.php:3174,3422`
Tests passed `speakerKey` but the API route expects `personKey`.
→ Fixed (2 occurrences).

### 4. ✅ Follow API route constraint: `person` → `speaker`
**File**: `routes/api.php:222,225,228`
The FollowController resolves `type => 'speaker'` (morph type) but the route constraint
`->whereIn('type', ['institution', 'person', ...])` rejected it. Controller is the truth.
→ Fixed the route constraint back to `'speaker'`.

### 5. ✅ Migration: `affiliation_type` made nullable
**File**: `database/migrations/2026_07_23_212622_create_affiliations_table.php:19`
The `affiliation_type` column is NOT NULL but many fixtures don't populate it.
→ Fixed: made nullable.

## Remaining Issues (require deeper investigation)

### AdminApiTest — "Array to string conversion" on `nationality_country_id`
**File**: `tests/Feature/Api/Admin/AdminApiTest.php:281-295`
The test creates a Person via factory with attributes that trigger array-to-string
conversion on PostgreSQL. The `bio` field is cast to `'array'` but the migration may
have it as `text`. Affects: ~10 test cases.

### EventSearchTest — `PropertyNotFoundException` on `pages.events` (without `.index`)
Multiple EventSearchFilter tests fail with `Property [$speaker_ids] not found on
component: [pages.events]`. The blade fix for `pages.events.index` may address
some, but `pages.events` component name mismatch needs investigation.

### FrontendApiParityTest — UrlGenerationException
Tests trying to generate URLs for shared/report routes that may no longer exist.
Route name `contributions.suggest-update` or similar may have changed parameters.

### ContributionPagesTest — Multiple failures
- 7 tests failing: render speaker pages, form rendering, slug resolution
- Likely route name/parameter changes

### MCP Server Tests
- AdminServerTest: 8+ failures (event CRUD, metadata, search)
- MemberServerTest: 3 failures (search, schema, metadata)
- Likely schema changes in MCP tool definitions

### MediaConversionsTest — 3 failures
- fallback URL, public_avatar_url, main media collection registration
- May be related to model/media path changes (speaker→person)

### Other
- PersonFollowTest: UrlGenerationException (2 failures)
- PersonShowPageTimingTest: UrlGenerationException
- PersonShowSocialPlacementTest: UrlGenerationException
- ScrambleDocsTest: 5 failures (schema documentation)
- SignalsIntegrationTest: 1 failure
- SlugRedirectFeatureTest: ModelNotFoundException
- SocialMediaNormalizationTest: 1 failure
- SubmitEvent* tests: multiple failures
- InstitutionShowPageTest: 2 failures
