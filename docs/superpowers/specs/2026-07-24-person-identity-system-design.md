# Person Identity System Design

**Date:** 2026-07-24  
**Status:** Draft  
**Author:** Design session

## Problem

The current `speakers` table stores titles, honorifics, and credentials as flat JSON arrays (`honorific`, `pre_nominal`, `post_nominal`, `qualifications`). This design cannot scale internationally — titles from different cultures have different meanings, issuing authorities, legal status, and formatting rules.

Additionally, the entity is misnamed: people who moderate, MC, or lead prayer are forced into the `Speaker` model even when they never speak.

## Decision

Rename `speakers` to `persons` as the canonical identity table. Replace JSON columns with a normalized relational system covering titles, credentials, affiliations, roles, documents, and names. Use polymorphic assignment tables so any model (Person, Institution, Venue) can participate.

**No backward compatibility, no legacy code, no shims.** Old JSON columns are dropped, old enum files are deleted, data is transformed once during migration.

## Architecture

```
PERSON (renamed from speakers)
 |
 +-- NAMES (person_names)
 |
 +-- TITLES (title_assignments → titles → title_categories, title_issuers)
 |
 +-- CREDENTIALS (credential_assignments → credential_definitions)
 |
 +-- AFFILIATIONS (affiliations → affiliation_roles)
 |
 +-- DOCUMENTS (Spatie media collections on Person)
 |
 +-- LANGUAGES (existing HasLanguages trait + languageables table)
```

Event-specific roles (Speaker, Moderator, MC, Imam, Khatib at a specific event) stay on `event_involvements.role_code` — unchanged. They are event-scoped, not standing positions.

Public-facing labels stay as "Penceramah" / "Speakers" — only internal code, route names, and DB identifiers change.

---

## Table Definitions

All tables follow project conventions:
- UUID primary keys (`uuid('id')->primary()`)
- No DB-level foreign key constraints or cascades (application-enforced)
- No SoftDeletes (uses `spatie/laravel-deleted-models` / `KeepsDeletedModels`)
- `timestampTz` for lifecycle timestamps
- PostgreSQL (JSONB available)
- Indexes on all morph columns (`*_type`, `*_id` pairs), FK columns, and `status` columns

---

### 1. `persons` (renamed from `speakers`)

Core identity table.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `name` | VARCHAR(255) | Full display name (authoritative) |
| `family_name` | VARCHAR(100) NULL | **NEW.** Last name/surname for sorting/grouping. Nullable for cultures without surnames. |
| `gender` | VARCHAR(50) | male, female |
| `date_of_birth` | DATE NULL | **NEW.** |
| `nationality_country_id` | UUID NULL | **NEW.** FK → `countries` (existing addressing package) |
| `slug` | VARCHAR(255) | Routing |
| `searchable_name` | VARCHAR(512) DEFAULT '' | Denormalized search field, rebuilt by `PersonSearchService`. Indexed. |
| `bio` | JSONB NULL | Multi-language biography |
| `status` | VARCHAR(50) | pending, verified, rejected, inactive |
| `verified_at` | TIMESTAMPTZ NULL | |
| `verified_by` | UUID NULL | FK → `users` |
| `rejected_at` | TIMESTAMPTZ NULL | |
| `inactive_at` | TIMESTAMPTZ NULL | |
| `last_state_change_at` | TIMESTAMPTZ NULL | |
| `allow_public_event_submission` | BOOLEAN DEFAULT FALSE | |
| `public_submission_locked_at` | TIMESTAMPTZ NULL | |
| `public_submission_locked_by` | UUID NULL | FK → `users` |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

**Dropped columns (migrated to relational tables):**
- `honorific` (JSONB) → `title_assignments`
- `pre_nominal` (JSONB) → `title_assignments`
- `post_nominal` (JSONB) → `title_assignments`
- `qualifications` (JSONB) → `credential_assignments`
- `job_title` (VARCHAR) → `affiliation_roles`
- `is_freelance` (BOOLEAN) → **dropped entirely** (freelance = no affiliations)

---

### 2. `person_names`

Multi-language / multi-context name variants for a person.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `person_id` | UUID | FK → `persons`. Indexed. |
| `name_type` | VARCHAR(50) | legal, display, birth, religious, professional, previous |
| `full_name` | VARCHAR(255) | |
| `language_code` | VARCHAR(10) | ms, en, ar |
| `is_primary` | BOOLEAN DEFAULT FALSE | |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

Same person can have: `Ahmad Rahman` (display/en), `أحمد بن عبد الرحمن` (religious/ar), `Ahmad bin Abdul Rahman` (legal/ms).

---

### 3. `title_categories`

Reference data for title types. Managed via Filament.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `code` | VARCHAR(50) UNIQUE | state_honour, royal, religious, academic, professional, military, social |
| `name` | VARCHAR(100) | |
| `sort_order` | INT DEFAULT 0 | |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

Seeded categories:

| code | name |
|---|---|
| `state_honour` | State Honour |
| `royal` | Royal Title |
| `religious` | Religious Title |
| `academic` | Academic Title |
| `professional` | Professional Title |
| `military` | Military Rank |
| `social` | Social Honorific |

---

### 4. `titles`

Title definitions. Seeded from existing `Honorific`, `PreNominal`, `PostNominal` enum data, then those enum files are deleted.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `category_id` | UUID | FK → `title_categories`. Indexed. |
| `name` | VARCHAR(100) | Full name: "Datuk", "Doctor of Philosophy" |
| `short_form` | VARCHAR(50) NULL | Abbreviation: "Dr", "PhD", "Ir" |
| `country_id` | UUID NULL | FK → `countries`. Country-specific titles (Datuk = MY, Sir = GB) |
| `language_code` | VARCHAR(10) NULL | Language of the title name itself ("Ustaz" = Malay, "Syeikh" = Arabic). Nullable for language-agnostic titles like "PhD". |
| `usage_position` | VARCHAR(20) | before_name, after_name |
| `sort_order` | INT DEFAULT 0 | **Global within usage_position scope** — all before_name titles share one ordering space, all after_name titles share another. See Sort Order Behavior below. |
| `description` | TEXT NULL | |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

**Seeding map from existing enum data:**

| Source enum | Category | usage_position | sort_order source |
|---|---|---|---|
| `Honorific` | state_honour | before_name | `Speaker::honorificSortOrder()` values |
| `PreNominal::Prof, ProfMadya` | academic | before_name | `Speaker::preNominalSortOrder()` values |
| `PreNominal::Ir, Ar` | professional | before_name | same |
| `PreNominal::Ustaz, Syeikh, Hj, etc.` | religious | before_name | same |
| `PostNominal::PhD, MSc, etc.` | academic | after_name | `Speaker::postNominalSortOrder()` values |

---

### 5. `title_issuers`

Entities that grant or recognize titles.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `country_id` | UUID NULL | FK → `countries` |
| `institution_id` | UUID NULL | FK → `institutions` (optional link when issuer is an org in the system) |
| `issuer_name` | VARCHAR(255) | "Yang di-Pertuan Agong", "Board of Engineers Malaysia" |
| `issuer_type` | VARCHAR(50) | government, royal, religious_body, university, professional_board, organization |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

---

### 6. `title_assignments` (polymorphic)

The assignment junction — links any entity to a title.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `titleable_type` | VARCHAR(255) | Morph type: person, institution |
| `titleable_id` | UUID | Morph ID |
| `title_id` | UUID | FK → `titles`. Indexed. |
| `issuer_id` | UUID NULL | FK → `title_issuers` |
| `date_awarded` | DATE NULL | |
| `date_expired` | DATE NULL | |
| `status` | VARCHAR(20) | active, revoked, expired |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

Index: `(titleable_type, titleable_id)`, `title_id`, `status`.

---

### 7. `credential_definitions`

Definitions of professional/academic credentials.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `name` | VARCHAR(200) | "Doctor of Philosophy", "Professional Engineer" |
| `short_form` | VARCHAR(50) | "PhD", "PE" |
| `field` | VARCHAR(100) NULL | "Engineering", "Islamic Studies" |
| `credential_type` | VARCHAR(50) | academic_degree, professional_license, certification |
| `language_code` | VARCHAR(10) NULL | |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

---

### 8. `credential_assignments` (polymorphic)

Links any entity to a credential with issuing details.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `credentialable_type` | VARCHAR(255) | Morph type |
| `credentialable_id` | UUID | Morph ID |
| `credential_id` | UUID | FK → `credential_definitions`. Indexed. |
| `issuing_institution_id` | UUID NULL | FK → `institutions` |
| `registration_number` | VARCHAR(100) NULL | |
| `date_obtained` | DATE NULL | |
| `date_expired` | DATE NULL | |
| `status` | VARCHAR(20) | active, expired, suspended |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

Index: `(credentialable_type, credentialable_id)`, `credential_id`, `status`.

---

### 9. `affiliations` (simplified polymorphic)

Links a person (or any entity) to an institution. One record per person-institution relationship.

Uses a **simplified design**: morph on the person side (`affiliatable`), direct FK on the institution side (`institution_id`). Institutions are the only affiliation target — no need for a double morph.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `affiliatable_type` | VARCHAR(255) | Morph: who is affiliated (person) |
| `affiliatable_id` | UUID | Morph ID |
| `institution_id` | UUID | FK → `institutions`. Direct, not morph. |
| `affiliation_type` | VARCHAR(50) | member, employee, advisor, partner, resident_scholar |
| `joined_at` | DATE NULL | |
| `left_at` | DATE NULL | |
| `is_primary` | BOOLEAN DEFAULT FALSE | |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

Index: `(affiliatable_type, affiliatable_id)`, `institution_id`.

Replaces the existing `institution_speaker` pivot table.

---

### 10. `affiliation_roles`

Roles held within an affiliation. A person can hold multiple roles at the same institution.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `affiliation_id` | UUID | FK → `affiliations`. Indexed. |
| `role_name` | VARCHAR(150) | CEO, Dean, Imam, Lecturer, Board Member |
| `department` | VARCHAR(150) NULL | |
| `start_date` | DATE NULL | |
| `end_date` | DATE NULL | |
| `is_current` | BOOLEAN DEFAULT TRUE | |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

Example structure:

```
Ahmad Rahman
├── Affiliation: University of Malaya (joined 2018, primary)
│   ├── Role: Senior Lecturer (2018-2023)
│   └── Role: Dean of Faculty (2023-present)
└── Affiliation: ABC Berhad (joined 2020)
    ├── Role: CEO (2020-present)
    └── Role: Board Member (2021-present)
```

---

### 11. Documents (Spatie Media Library — no new table)

Documents are handled as media collections on the `Person` model, reusing the existing global upload policy, naming strategy (`MediaFileNamer`), and path generation (`MediaPathGenerator`).

**New media collections on Person:**

```php
$this->addMediaCollection('documents')
    ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);

$this->addMediaCollection('certificates')
    ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
```

Metadata (document_type, issue_date, expiry_date, reference_number) stored in Spatie's `custom_properties`, filtered by string value — no `DocumentType` enum needed.

```php
$person->addMedia($file)
    ->toMediaCollection('documents')
    ->setCustomProperties([
        'document_type' => 'credential_proof',
        'issue_date' => '2019-06-15',
        'reference_number' => 'PE12345',
    ]);
```

---

## Relationships (Person model)

```php
// Person.php (was Speaker.php)

// Core identity
public function nationality(): BelongsTo           // → countries
public function names(): HasMany                   // → person_names

// Titles
public function titleAssignments(): MorphMany      // → title_assignments

// Credentials
public function credentialAssignments(): MorphMany // → credential_assignments

// Affiliations
public function affiliations(): MorphMany          // → affiliations

// Languages (existing trait)
// HasLanguages provides: languages(): MorphToMany

// Documents (via Spatie InteractsWithMedia)
// Collections: avatar, main, cover, gallery, documents, certificates

// KEPT from Speaker:
public function events(): BelongsToMany            // → events via event_involvements
public function follows(): MorphMany               // → follows
public function reports(): MorphMany               // → reports
// + Scout search, lifecycle, etc.
```

---

## Display & Formatting

No `title_display_rules` table — formatting stays as code on the `Person` model. The existing `formatted_name` logic is refactored to read from `title_assignments` instead of JSON columns.

### Logic

```php
public function getFormattedNameAttribute(): string
{
    $assignments = $this->titleAssignments()
        ->where('status', 'active')
        ->with('title')
        ->get();

    $beforeTitles = $assignments
        ->filter(fn ($a) => $a->title->usage_position === 'before_name')
        ->sortBy('title.sort_order')
        ->map(fn ($a) => $a->title->short_form ?? $a->title->name);

    $afterTitles = $assignments
        ->filter(fn ($a) => $a->title->usage_position === 'after_name')
        ->sortBy('title.sort_order')
        ->map(fn ($a) => $a->title->short_form ?? $a->title->name);

    $name = trim(implode(' ', $beforeTitles->all()) . ' ' . $this->name);

    if ($afterTitles->isNotEmpty()) {
        $name .= ', ' . implode(', ', $afterTitles->all());
    }

    return trim($name);
}
```

Output: `Datuk Dr. Ahmad Rahman, PhD`

### Sort order behavior

`sort_order` is **global within usage_position scope**, not within category. All `before_name` titles share one ordering space; all `after_name` titles share another.

During seeding, sort_order values must be reconciled into a global space because the current `Speaker::formatDisplayedName()` interleaves categories:

1. **Leading pre-nominals** (Prof, ProfMadya) — sort 10-11
2. **Honorifics** (Tun, Tan Sri, Datuk) — sort 20-99
3. **Trailing pre-nominals** (Syeikh, Ustaz, Dr, Ir, etc.) — sort 100+

This ensures `Datuk Dr. Ahmad Rahman` (honorific before academic prefix) displays correctly, not `Dr. Datuk Ahmad Rahman`.

Post-nominals (after_name) keep their own independent sort space:
- PhD (10) > MSc (20) > BSc (30)

### Morph type aliases

The existing `event_involvements.involveable_type` uses short aliases (`'speaker'`, not full class names). The rename requires updating all morph aliases from `'speaker'` → `'person'` across:
- `event_involvements.involveable_type` values
- Morph map in `AppServiceProvider`
- Existing data rows (UPDATE query during migration)

---

## Existing Tables Being Renamed

These tables are renamed as part of the refactor. No data migration needed beyond the rename:

| Old table | New table | Notes |
|---|---|---|
| `speakers` | `persons` | Core rename |
| `speaker_members` | `person_members` | Membership pivot |
| `speaker_search_terms` | `person_search_terms` | Search terms table |
| `institution_speaker` | **dropped** | Replaced by `affiliations` |

---

## Migration Strategy

### Phase 1: Create new tables

1. Create: `title_categories`, `titles`, `title_issuers`, `title_assignments`, `credential_definitions`, `credential_assignments`, `affiliations`, `affiliation_roles`, `person_names`
2. Add `family_name`, `date_of_birth`, `nationality_country_id` columns to `speakers`

### Phase 2: Seed reference data

3. Seed `title_categories` (7 categories)
4. Seed `titles` from existing `Honorific`, `PreNominal`, `PostNominal` enum data, mapping sort orders from `Speaker::honorificSortOrder()`, `Speaker::preNominalSortOrder()`, `Speaker::postNominalSortOrder()` into global sort spaces
5. Rebuild `searchable_name` for all persons via `PersonSearchService`

### Phase 3: Rename tables

6. Rename `speakers` → `persons`, `speaker_members` → `person_members`, `speaker_search_terms` → `person_search_terms`
7. Drop `institution_speaker` table

### Phase 4: Migrate data

8. For each person, migrate:
   - `honorific` JSON array → `title_assignments` (category: state_honour)
   - `pre_nominal` JSON array → `title_assignments` (categories: academic, religious, professional — by enum mapping)
   - `post_nominal` JSON array → `title_assignments` (category: academic, after_name)
   - `qualifications` JSON array → `credential_assignments` (with degree → credential_definition, institution, year)
   - `job_title` → `affiliation_roles` (create affiliation from institution_speaker pivot if exists)
   - `institution_speaker` pivot rows → `affiliations`

### Phase 5: Drop old columns

9. Drop from `persons`: `honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `job_title`, `is_freelance`
10. Delete `Honorific.php`, `PreNominal.php`, `PostNominal.php` enum files

### Phase 6: Morph data migration

11. UPDATE all morph/type columns:
    - `event_involvements.involveable_type`: `'speaker'` → `'person'`
    - `donatables.donatable_type`: `'speaker'` → `'person'`
    - `member_invitations.subject_type`: `'speaker'` → `'person'`
    - `membership_applications.subject_type`: `'speaker'` → `'person'`
    - `reports.entity_type`: `'speaker'` → `'person'`
    - share-tracking `subject_type`: `'speaker'` → `'person'`
    - `member_permissions` permission strings: `speaker.*` → `person.*`
12. `event_involvements.role_code` and `event_taxonomy_policy.policy_code` (`requires_speaker`) **stay unchanged** — they refer to the event role, not the entity.

### Phase 7: Update all code references

13. Rename `Speaker` → `Person` across the entire application (see Refactor Surface)
14. Rename route names `speakers.*` → `persons.*` (keep public URL segment `/penceramah`)

---

## Enums

### New enums to create

| Enum | Values | Location |
|---|---|---|
| `TitleUsagePosition` | `BeforeName`, `AfterName` | `app/Enums/TitleUsagePosition.php` |
| `AssignmentStatus` | `Active`, `Revoked`, `Expired` | `app/Enums/AssignmentStatus.php` |
| `IssuerType` | `Government`, `Royal`, `ReligiousBody`, `University`, `ProfessionalBoard`, `Organization` | `app/Enums/IssuerType.php` |
| `CredentialType` | `AcademicDegree`, `ProfessionalLicense`, `Certification` | `app/Enums/CredentialType.php` |
| `AffiliationType` | `Member`, `Employee`, `Advisor`, `Partner`, `ResidentScholar` | `app/Enums/AffiliationType.php` |
| `PersonNameType` | `Legal`, `Display`, `Birth`, `Religious`, `Professional`, `Previous` | `app/Enums/PersonNameType.php` |

### Enums to update

| Enum | Change |
|---|---|
| `MemberSubjectType` | `Speaker = 'speaker'` → `Person = 'person'` |
| `ContributionSubjectType` | `Speaker = 'speaker'` → `Person = 'person'` |
| `DawahShareSubjectType` | `Speaker = 'speaker'` → `Person = 'person'` |
| `EventKeyPersonRole` | **No change.** `Speaker = 'speaker'` stays — this is an event role, not the entity. |

### Enums to delete

- `Honorific.php` — data seeded into `titles` table
- `PreNominal.php` — data seeded into `titles` table
- `PostNominal.php` — data seeded into `titles` table

No `DocumentType` enum — documents use Spatie `custom_properties` with string values.

---

## Filament Resources

| Resource | Purpose |
|---|---|
| `PersonResource` (replaces `SpeakerResource`) | Manage persons with all relation managers |
| `TitleResource` | Manage title definitions + categories |
| `TitleIssuerResource` | Manage issuers |
| `CredentialDefinitionResource` | Manage credential types |

**Relation Managers on PersonResource:**
- `TitleAssignmentRelationManager` — manage title assignments inline
- `CredentialAssignmentRelationManager` — manage credentials inline
- `AffiliationRelationManager` — manage affiliations + roles inline
- `PersonNameRelationManager` — manage name variants inline
- Documents handled via `SpatieMediaLibraryFileUpload` in the main form (no relation manager needed)

Public-facing labels stay as "Penceramah" / "Speakers" — only internal class/file/route names change.

---

## Scope Boundaries

**In scope:**
- Rename `speakers` → `persons`
- Create 9 new tables
- Rename 3 existing tables, drop 1
- Migrate 6 columns (5 JSON/string → relational, 1 boolean dropped)
- Delete 3 enum files, create 6 new enums
- Full-surface refactor across every layer (see Refactor Surface)
- Filament resources for management

**Out of scope (future work):**
- `User` model integration with `persons` (User could get `person_id` FK in the future)
- Member model integration
- Internationalization of title formatting rules beyond current Malaysian focus
- Religious titles as a separate specialized table (handled via `title_categories` instead)

---

## Refactor Surface (Full Application)

This rename touches ~100+ files across every layer. Below is the complete surface, organized by layer.

### Critical distinction: three layered "speaker" concepts

Do NOT conflate these during refactor:

| Concept | What it is | Action |
|---|---|---|
| **Entity/model** (`Speaker` → `Person`) | The identity table/model | Full rename |
| **Event role** (`EventKeyPersonRole::Speaker` = `'speaker'`) | Role at a specific event ("spoke here") | **Keep as-is** — this is an event role, not an identity. `role_code='speaker'` stays in `event_involvements`. |
| **Morph alias** (`'speaker'` in `involveable_type`) | Polymorphic type discriminator | Change `'speaker'` → `'person'` in all morph columns + morph map |

### 1. Models (`app/Models/`)

| File | Action |
|---|---|
| `Speaker.php` | **[RENAME]** → `Person.php`. Drop JSON columns + `is_freelance`, add `family_name`/`date_of_birth`/`nationality_country_id`. Rewrite `formatted_name` to read from `title_assignments`. Update morph literals, table refs, media placeholder paths, search service refs. |
| `EventKeyPerson.php` | **[UPDATE]** `speaker()` → `person()`, type checks. |
| `Event.php` | **[UPDATE]** `speakers` relation → `persons`, `speakerKeyPeople()` → `personKeyPeople()`, eager loads, computed attributes (`speaker_names`, `speaker_ids`). |
| `Institution.php` | **[UPDATE]** `speakers()` → `persons()` via new `affiliations` table. |
| `User.php` | **[UPDATE]** `speakers()` pivot → `person_members`, `followingSpeakers()` → `followingPersons()`, `verifiedSpeakers()`. |
| `InstitutionSpeakerPivot.php` | **[DELETE]** — replaced by `affiliations`. |
| `Concerns/HasUserRestoration.php` | **[UPDATE]** `speaker_members` refs, `verified_speaker_ids`. |

### 2. Enums (`app/Enums/`)

| File | Action |
|---|---|
| `Honorific.php` | **[DELETE]** — data seeded into `titles` table during migration. |
| `PreNominal.php` | **[DELETE]** — same. |
| `PostNominal.php` | **[DELETE]** — same. |
| `EventKeyPersonRole.php` | **[KEEP]** — `Speaker = 'speaker'` stays. This is an event role, not the entity. |
| `MemberSubjectType.php` | **[UPDATE]** `Speaker = 'speaker'` → `Person = 'person'`. DB data migration for stored values. |
| `ContributionSubjectType.php` | **[UPDATE]** `Speaker = 'speaker'` → `Person = 'person'`. |
| `DawahShareSubjectType.php` | **[UPDATE]** `Speaker = 'speaker'` → `Person = 'person'`. |

### 3. Filament (`app/Filament/`)

| Area | Action |
|---|---|
| `Resources/Speakers/` (Resource, Form, Table, Pages, RelationManagers) | **[RENAME]** → `Resources/Persons/`. Rewrite form to use title/credential/affiliation relation managers instead of JSON fields. |
| `Ahli/Resources/Speakers/` | **[RENAME]** → `Ahli/Resources/Persons/`. |
| `Widgets/StatsOverview.php` | **[UPDATE]** `Speaker::query()`. |
| `Pages/ModerationQueue.php` | **[UPDATE]** `$record->speakers` → `$record->persons`. |
| `Ahli/Widgets/PendingApprovalEventsWidget.php` | **[UPDATE]** morph type check. |
| `Resources/Authz/UserResource/Pages/` | **[UPDATE]** membership summary. |
| `Resources/DonationChannels/` | **[UPDATE]** donatable option `Speaker::class`. |
| `Resources/Institutions/Schemas/InstitutionInfolist.php` | **[UPDATE]** speaker count. |

### 4. Livewire (`app/Livewire/`)

| File | Action |
|---|---|
| `Pages/Speakers/Show.php` | **[RENAME]** → `Pages/Persons/Show.php`. |
| `Pages/Contributions/SubmitSpeaker.php` | **[RENAME]** → `SubmitPerson.php`. |
| `Pages/Reports/Create.php` | **[UPDATE]** entity union. |
| `Pages/Membership/ShowInvitation.php` | **[UPDATE]** subject union. |
| `Pages/Events/{Index,Show,AdvancedFiltersPanel}.php` | **[UPDATE]** `speaker_ids` filter, options methods. |
| `Pages/SavedSearches/Index.php` | **[UPDATE]** speaker lookups. |
| `Pages/Dashboard/DawahImpactIndex.php` | **[UPDATE]** share subject type. |
| `Pages/Dashboard/Events/CreateAdvanced.php` | **[UPDATE]** organizer kind. |
| `Pages/Dashboard/InstitutionDashboard.php` | **[UPDATE]** eager loads. |
| `Pages/SubmitEvent/Create.php` | **[UPDATE]** speaker subset sync. |

### 5. API (`app/Http/`, `routes/`, `app/Data/`)

| Area | Action |
|---|---|
| `Http/Controllers/Api/Frontend/SearchController.php` | **[UPDATE]** `speakers()` → `persons()`, `showSpeaker()` → `showPerson()`. |
| `Http/Controllers/Api/Frontend/ContributionController.php` | **[UPDATE]** `storeSpeaker()`. |
| `Http/Controllers/Api/Frontend/CatalogController.php` | **[UPDATE]** `submitSpeakers()`. |
| `Http/Controllers/Api/EventController.php` | **[UPDATE]** `filter[speaker]`, `whereHas('speakers')`, `speakers.searchable_name`. |
| `Http/Controllers/Api/Admin/ResourceController.php` | **[UPDATE]** resource key `speakers`. |
| `Http/Controllers/SitemapController.php` | **[UPDATE]** sitemap generation. |
| `Http/Middleware/ResolvePublicSlugRedirect.php` | **[UPDATE]** slug param list. |
| `routes/api.php` | **[UPDATE]** route names `speakers.*` → `persons.*`, type-wherein `'speaker'` → `'person'`. |
| `routes/web.php` | **[UPDATE]** route names `speakers.*` → `persons.*`, livewire component. |
| `Data/Api/Event/EventSpeakerData.php` | **[RENAME]** → `EventPersonData.php`. |
| `Data/Api/Frontend/Search/SpeakerListData.php` | **[RENAME]** → `PersonListData.php`. |
| `Data/Api/Frontend/Search/SpeakerDetailData.php` | **[RENAME]** → `PersonDetailData.php`. (Update `qualifications` mapping to read from relational tables.) |
| `Data/Api/Frontend/Search/Speaker{DetailMedia,GalleryItem,Institution}Data.php` | **[RENAME]** all. |
| `Data/Api/Frontend/Search/EventListSpeakerData.php` | **[RENAME]** → `EventListPersonData.php`. |
| `Data/Events/ValidatedEventSubmission.php` | **[UPDATE]** organizer union, slug segments. |
| `Data/EventDiscoveryCriteriaFactory.php` | **[UPDATE]** `speaker_ids`, `search_include_speakers`. |
| `Support/Api/Admin/AdminResource{Registry,Service,MutationService}.php` | **[REWRITE]** resource key, morph maps, field defs, rules. |
| `Support/Api/Member/MemberResource{Registry,MutationService}*.php` | **[UPDATE]** Ahli resource refs. |
| `Support/Api/Frontend/{FrontendCatalogService,FrontendFormContractService}.php` | **[UPDATE]** contract endpoints. |
| `Support/Api/ResourceSearchDispatcher.php` | **[UPDATE]** instanceof dispatch. |
| `Support/ApiDocumentation/` | **[UPDATE]** OpenAPI schema generation (Speaker → Person schemas). |

### 6. MCP (`app/Mcp/`)

| Area | Action |
|---|---|
| `Servers/{AdminServer,MemberServer,Ilmu360Server}.php` | **[UPDATE]** instruction copy mentioning "speakers". |
| `Tools/Admin/Admin{Create,Update,BatchCreate,BatchUpdate}EventTool.php` | **[REWRITE]** `speaker_keys` → `person_keys`, payload alias, resolver. |
| `Tools/Admin/Admin{Create,Update,BatchCreate,BatchUpdate}RecordTool.php` | **[UPDATE]** resource registration. |
| `Tools/Admin/Admin{ListRelatedRecords,GetResourceMeta,ListResources,GetWriteSchema,SearchEvents,DocumentationSearch}Tool.php` | **[UPDATE]** description strings. |
| `Tools/Member/Member*.php` | **[UPDATE]** description strings. |
| `Prompts/AdminEvent{Cover,Poster}ImagePrompt.php` | **[UPDATE]** speaker refs. |
| `Resources/Docs/McpGuideResource.php` | **[UPDATE]** guide docs enumerating speakers. |
| `Support/Mcp/EventCoverPromptBuilder.php` | **[REWRITE]** payload map, reference keys, `speakerNames()`. |
| `Support/Mcp/McpWriteSchemaFormatter.php` | **[UPDATE]** schema key descriptions. |

### 7. Actions / Services

| File | Action |
|---|---|
| `Actions/Speakers/GenerateSpeakerSlugAction.php` | **[RENAME]** → `Actions/Persons/GeneratePersonSlugAction.php`. |
| `Actions/Speakers/SaveSpeakerAction.php` | **[RENAME]** → `SavePersonAction.php`. Rewrite to save via relational tables instead of JSON columns. |
| `Actions/Events/GenerateEventSlugAction.php` | **[UPDATE]** Speaker query, slug segments. |
| `Actions/Events/SaveAdminEventAction.php` | **[UPDATE]** organizer union. |
| `Actions/Reports/*.php` | **[UPDATE]** report subject refs. |
| `Actions/Contributions/*.php` | **[UPDATE]** dedup on honorific/pre_nominal/post_nominal → title_assignments. |
| `Services/ContributionEntityMutationService.php` | **[REWRITE]** `createSpeaker` → `createPerson`, field defs for relational title system. |
| `Services/EventKeyPersonSyncService.php` | **[UPDATE]** involveable_type. |
| `Services/{EventSearchService,PostgresEventDiscovery,TypesenseEventDiscovery}.php` | **[UPDATE]** search field names, `speakers.searchable_name` → `persons.searchable_name`. |
| `Services/CalendarService.php` | **[UPDATE]** Penceramah label source. |
| `Services/EventCategoryPolicy.php` | **[KEEP]** `requires_speaker` policy code stays — refers to event role, not entity. |
| `Services/Notifications/*.php` | **[UPDATE]** organizer checks, trigger types. |
| `Services/ShareTracking/*.php` | **[UPDATE]** share subject types. |

### 8. Database

| File | Action |
|---|---|
| `migrations/2026_01_10_000010_create_speakers_table.php` | **[REFERENCE]** — original schema for migration context. |
| New migration: rename tables | Rename `speakers`→`persons`, `speaker_members`→`person_members`, `speaker_search_terms`→`person_search_terms`. Drop `institution_speaker`. |
| New migration: morph data | UPDATE all stored `'speaker'` morph values to `'person'` (see Cross-Cutting Gotchas §1). |
| New migration: permission strings | UPDATE `member_permissions` permission strings `speaker.*` → `person.*`. |
| `factories/SpeakerFactory.php` | **[RENAME]** → `PersonFactory.php`. Update title/credential creation. |
| `factories/EventKeyPersonFactory.php` | **[UPDATE]** involveable_type. |
| `seeders/SpeakerSeeder.php` | **[RENAME]** → `PersonSeeder.php`. |
| `seeders/DatabaseSeeder.php` | **[UPDATE]** call `PersonSeeder` instead. |

### 9. Tests (`tests/`)

~20 test files reference Speaker. All need updating:
- **[RENAME]** `SpeakerIndexTest`, `SpeakerFollowTest`, `SpeakerShowPageTimingTest`, `SpeakerShowSocialPlacementTest`, `SpeakerAdminEditSocialMediaLabelTest`, `SpeakerAdminFollowersTableTest`, `SpeakerCreateOptionSchemaTest`, `SpeakerSlugGenerationTest`, `SpeakerSeederIdempotencyTest`, `Console/ReindexSpeakerSearchCommandTest`
- **[UPDATE]** all remaining tests referencing Speaker class/table/enum

### 10. Views (`resources/views/`)

| File | Action |
|---|---|
| `livewire/pages/speakers/show.blade.php` | **[RENAME + REWRITE]** → `persons/show.blade.php`. |
| `livewire/pages/contributions/submit-speaker.blade.php` | **[RENAME]** → `submit-person.blade.php`. |
| `components/pages/speakers/index.blade.php` | **[RENAME]** → `persons/index.blade.php`. |
| `layouts/app.blade.php` | **[UPDATE]** nav route names (keep visible label "Penceramah"). |
| `livewire/pages/{events,institutions,dashboard,search}/*.blade.php` | **[UPDATE]** all speaker variable/refs. |
| `public/images/placeholders/speaker*.png` | **[RENAME]** → `person*.png` or update Person model fallback paths. |

### 11. Support (`app/Support/`)

| File | Action |
|---|---|
| `Search/SpeakerSearchService.php` | **[RENAME]** → `PersonSearchService.php`. Update table refs, cache keys, `searchable_name` sync. |
| `Slugs/PublicSlugPathResolver.php` | **[UPDATE]** morph map + route names. |
| `Submission/*.php` | **[UPDATE]** speaker eligibility/lock methods, cache keys. |
| `Authz/*.php` | **[UPDATE]** permission gate, scopes, permission string prefixes. |
| `Events/OrganizerResolver.php` | **[UPDATE]** union type. |
| `Media/MediaPathGenerator.php` | **[UPDATE]** path prefix `speakers/` → `persons/`. Run `php artisan app:media:migrate-structure --force` after migration. |

### 12. Config

| File | Action |
|---|---|
| `app/Providers/AppServiceProvider.php` | **[REWRITE]** morph map `'speaker'` → `'person'`, observer registration, public-slug binding. |
| `config/scout.php` | **[UPDATE]** Typesense schema, field names (see below). |
| `config/scramble.php` | **[UPDATE]** API doc groups/examples. |

### 13. Policies / Observers

| File | Action |
|---|---|
| `Policies/SpeakerPolicy.php` | **[RENAME]** → `PersonPolicy.php`. |
| `Observers/SpeakerObserver.php` | **[RENAME]** → `PersonObserver.php`. Update watched fields (no longer honorific/pre_nominal). |

### 14. Forms (`app/Forms/`)

| File | Action |
|---|---|
| `SpeakerFormSchema.php` | **[RENAME]** → `PersonFormSchema.php`. Rewrite to use relational title/credential fields. |
| `SpeakerContributionFormSchema.php` | **[RENAME]** → `PersonContributionFormSchema.php`. |
| `SharedFormSchema.php` | **[UPDATE]** union types. |
| `EventContributionFormSchema.php` | **[UPDATE]** speaker refs. |

### 15. Console / Jobs

| File | Action |
|---|---|
| `Jobs/BackfillSpeakerSlugs.php` | **[RENAME]** → `BackfillPersonSlugs.php`. |
| `Console/Commands/IndexSpeakersToTypesense.php` | **[RENAME]** → `IndexPersonsToTypesense.php`. |
| `Console/Commands/ReindexSpeakerSearch.php` | **[RENAME]** → `ReindexPersonSearch.php`. |
| `Console/Commands/QueueBackfillSpeakerSlugs.php` | **[RENAME]** → `QueueBackfillPersonSlugs.php`. |

### 16. Docs (non-code)

| File | Action |
|---|---|
| `docs/ilmu360_mobile_api_reference.md` | **[UPDATE]** API endpoint docs. |
| `docs/ilmu360_review_and_enhancement_plan.md` | **[UPDATE]** references. |
| MCP guide resources | **[UPDATE]** speaker enumeration → person. |

---

## Cross-Cutting Gotchas

### 1. Stored DB strings requiring data migration

These aren't code renames — they're UPDATE queries on existing data:

| Table | Column | Old value | New value |
|---|---|---|---|
| `event_involvements` | `involveable_type` | `'speaker'` | `'person'` |
| `donatables` | `donatable_type` | `'speaker'` | `'person'` |
| `member_invitations` | `subject_type` | `'speaker'` | `'person'` |
| `membership_applications` | `subject_type` | `'speaker'` | `'person'` |
| `reports` | `entity_type` | `'speaker'` | `'person'` |
| share-tracking | `subject_type` | `'speaker'` | `'person'` |
| `member_permissions` | permission strings | `speaker.*` | `person.*` |
| `event_involvements` | `role_code` | `'speaker'` | `'speaker'` *(unchanged — event role)* |
| `event_taxonomy_policy` | `policy_code` | `requires_speaker` | `requires_speaker` *(unchanged — refers to event role)* |

### 2. Public URL contract

Route names `speakers.show`, `speakers.index` → renamed to `persons.show`, `persons.index`. Public URL segment `/penceramah` stays unchanged (user-facing Malay label). Nav visible label stays "Penceramah".

### 3. On-disk media paths

`MediaPathGenerator` writes to `speakers/{shard}/{uuid}/{collection}/`. Renaming the model class changes the path prefix to `persons/`. Run `php artisan app:media:migrate-structure --force` after migration to move existing files.

### 4. Typesense search index

`config/scout.php` Typesense schema field renames:

| Old field | New field |
|---|---|
| `speaker_names` | `person_names` |
| `speaker_ids` | `person_ids` |
| `key_person_speaker_ids` | `key_person_person_ids` |

Typesense index needs re-creation + re-import after migration.

### 5. Cache key renames

| Old key pattern | New key pattern |
|---|---|
| `speaker_search:*` | `person_search:*` |
| `submit_speakers:*` | `submit_persons:*` |
| `public_speakers_directory_seed` | `public_persons_directory_seed` |
| `public_institutions_directory_seed` | *(unchanged — institutions not renamed)* |
| Any other `speaker_*` cache keys in `SpeakerSearchService`, `PublicSubmissionLockService`, `PublicDirectoryCacheVersion` | `person_*` equivalents |

### 6. Event role vs entity name

`EventKeyPersonRole::Speaker = 'speaker'` is an **event role** (someone spoke at an event). This does NOT change. The enum case refers to the role, not the model. `event_involvements.role_code = 'speaker'` stays.

Only `event_involvements.involveable_type` changes from `'speaker'` to `'person'` (the morph alias).

`event_taxonomy_policy.policy_code = 'requires_speaker'` also stays — it means "this event category requires a person with the speaker role assigned."

---

## Entity Relationship Diagram

```
persons ─────────────────────────────────────────────────────
  │                                                          
  ├──< person_names                                          
  │                                                          
  ├──< title_assignments >── titles >── title_categories     
  │                          │              │                
  │                          ├── country_id │                
  │                          └── title_issuers               
  │                                ├── country_id            
  │                                └── institution_id         
  │                                                          
  ├──< credential_assignments >── credential_definitions     
  │      └── issuing_institution_id → institutions           
  │                                                          
  ├──< affiliations                                              
  │      ├── affiliatable (morph → person/institution)       
  │      ├── institution_id → institutions                   
  │      └──< affiliation_roles                              
  │                                                          
  ├── media collections: avatar, main, cover, gallery,       
  │   documents, certificates (Spatie Media Library)         
  │                                                          
  └──< languageables (existing, via HasLanguages trait)      
                                                             
persons ──< event_involvements >── events                    
  (event-scoped roles, unchanged)                            
```
