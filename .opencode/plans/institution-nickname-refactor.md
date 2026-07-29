# Refactor: institutions.nickname → institution_names table

## Goal
Replace the `institutions.nickname` string column with a join-table `institution_names` following the `person_names` pattern. Form supports multiple names per institution. No backwards compatibility.

## Architecture

```
institution_names
├── id (UUID PK)
├── institution_id (UUID FK → institutions.id, indexed)
├── name_type (string, 50)          # InstitutionNameType enum
├── full_name (string, 255)         # The alternative name text
├── language_code (string, 10)      # ms, en, ar, etc.
├── is_primary (boolean)
├── created_at, updated_at (timestampsTz)
└── index: [institution_id, name_type]
```

**InstitutionNameType enum:**
- `Official` → official registered name
- `Nickname` → "Masjid Biru", "Masjid Besi"
- `Abbreviation` → "MTAJ", "Pusat Islam PJ"
- `Local` → what locals call it
- `Historical` → former names

### Query pattern changes

| Old | New |
|-----|-----|
| `Institution::where('nickname', ...)` | `Institution::whereHas('names', fn($q) => $q->where('full_name', ...))` |
| `$institution->nickname` | `$institution->primaryNickname` (accessor via names relationship) |
| `->get(['id','name','nickname'])` | `->with(['names'])->get(['id','name'])` |
| `institutions.nickname ILIKE ...` | `EXISTS (SELECT 1 FROM institution_names ...)` |

---

## Phase 1: Database & Enum

### 1.1 Create `InstitutionNameType` enum
- `app/Enums/InstitutionNameType.php`

### 1.2 Create `institution_names` migration
- `database/migrations/XXXX_create_institution_names_table.php`
- Then: `php artisan make:migration create_institution_names_table`

### 1.3 Drop `nickname` column migration
- `database/migrations/XXXX_drop_nickname_from_institutions_table.php`

### 1.4 Create `InstitutionName` model
- `app/Models/InstitutionName.php`
- Fillable: `institution_id`, `name_type`, `full_name`, `language_code`, `is_primary`
- Casts: `name_type → InstitutionNameType`, `is_primary → boolean`
- BelongsTo: `institution()`

---

## Phase 2: Model

### 2.1 Update `Institution`
- Remove `nickname` from `$fillable` + `searchIndexShouldBeUpdated`
- Add `names(): HasMany` → `InstitutionName`
- Add `getPrimaryNicknameAttribute()` accessor
- Update `formatDisplayName()` → use `primaryNickname`
- Update `toSearchableArray()` → `nicknames` (array) or primary_nickname (flat)
- Update `searchableText()` → concatenate all names
- Rewrite `searchNameOrNickname()` scope → `whereHas('names', ...)`

---

## Phase 3: Seeders & Factory

### 3.1 `InstitutionFactory`
- Remove `'nickname' => null`, add `nickname` state → creates InstitutionName

### 3.2 `InstitutionSeeder`, `MasjidSeeder`, `GeneratedFileFinalFixedPoskodSeeder`
- Switch any nickname assignments to InstitutionName records

---

## Phase 4: Forms

### 4.1 Filament `InstitutionForm`
- Replace TextInput::make('nickname') with `Repeater::make('names')->relationship()`

### 4.2 `InstitutionFormSchema` (quick-create)
- Same repeater in `createOptionForm()` + `createOptionUsing()`

### 4.3 `InstitutionContributionFormSchema`
- Replace nickname TextInput with simple single-name field (stored as InstitutionName with type='nickname')

### 4.4 `InstitutionInfolist`, `InstitutionsTable`
- Add names column/entry

---

## Phase 5: Actions & Services

### 5.1 `SaveInstitutionAction`
- Handle names instead of single nickname string

### 5.2 `ContributionEntityMutationService`
- Replace `nickname` field with `names` in contract, validation, create, update, state

### 5.3 `AdminResourceMutationService`
- Replace `nickname` field with `names` in write schema + validation

### 5.4 `FrontendFormContractService`
- Replace `nickname` with `names`

### 5.5 `InstitutionSearchService`
- Replace ALL nickname column references with name table joins/exists
- Update Typesense `query_by` fields

### 5.6 `ApproveContributionRequestAction`
- Handle names array instead of single nickname

### 5.7 `ResolveAdvancedBuilderMembershipOptionsAction`
- Eager-load names, use primaryNickname

---

## Phase 6: API & Data

### 6.1 Data DTOs
- `InstitutionListData`, `InstitutionDetailData`: replace `?string $nickname` with names array

### 6.2 API Controllers
- `SearchController`, `InstitutionWorkspaceController`, `ContributionController`, catalog endpoints
- Update field selections, validation rules

### 6.3 API Documentation
- All schema classes, `PublicDirectorySchemasTransformer`
- Update Scramble property definitions

### 6.4 `FrontendCatalogService`
- Update all `->get(['id', 'name', 'nickname'])` → eager-load names

### 6.5 Scout config
- Update `query_by` and field definitions

---

## Phase 7: Livewire

Update all `->get(['id', 'name', 'nickname'])` → `->with(['names'])->get(['id', 'name'])` in:
- `Events/Index.php` (3 locations)
- `AdvancedFiltersPanel.php` (2)
- `SubmitEvent/Create.php` (2)
- `Contributions/Index.php` (2)
- `SavedSearches/Index.php` (1)
- `Dashboard/AccountSettings.php` (2)

---

## Phase 8: MCP
- Update MCP docs (agent guides) to reflect names array
- Write tools delegate to AdminResourceMutationService (already handled in Phase 5)

---

## Phase 9: Tests

Update ~11 test files:
- `SearchableModelTest`, `SearchServiceFallbackTest`
- `AdminApiTest`, `FrontendApiParityTest`
- `AdminServerTest`, `MemberServerTest`, `SecurityChecklistTest`
- `InstitutionIndexTest`, `ContributionPagesTest`
- `SubmitEventLocationTest`, `ApiDocumentationSchemaSerializationTest`

---

## Verification
```bash
php artisan migrate:fresh --seed
vendor/bin/pest --parallel
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --format agent
```

## Affected files: ~48
