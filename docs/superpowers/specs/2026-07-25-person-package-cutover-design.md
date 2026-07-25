# Person Package Cutover Design

**Date:** 2026-07-25
**Status:** Approved

## Goal

Replace the current custom Person implementation in ilmu360 with `aiarmada/persons` + `aiarmada/filament-persons`. Complete cutover — no backward compatibility, no legacy data backfill.

## Approach

Extend the package Person model in `app/Models/Person.php`, layering all app-specific traits on top. Register via `config/persons.php` model swap. Use package Filament resources, extended with app-specific relation managers.

---

## 1. Model Architecture

`app/Models/Person.php` extends `AIArmada\Persons\Models\Person`:

```php
class Person extends \AIArmada\Persons\Models\Person
{
    use HasAddresses;        // aiarmada/addressing
    use HasContactMethods;   // aiarmada/contacting
    use HasDonationChannels; // app concern
    use HasLanguages;        // app concern
    use HasMembers;          // aiarmada/membership
    use HasSocialProfiles;   // aiarmada/contacting
    use InteractsWithMedia;  // spatie/medialibrary
    use KeepsDeletedModels;  // spatie/deleted-models
    use Searchable;          // laravel/scout
    use AuditsModelChanges;  // owen-it/auditing
}
```

**Inherited from package:** `HasTitles`, `HasCredentials`, `HasAffiliations`, `HasUuids`, `HasFactory`.

**Config swap:** `config/persons.php` → `models.person = App\Models\Person::class`.

**Fillable** (package): `name`, `family_name`, `middle_name`, `gender`, `date_of_birth`, `nationality_country_id`, `slug`, `searchable_name`, `bio`, `status`.

**Fillable** (app adds): `verified_at`, `verified_by`, `rejected_at`, `inactive_at`, `last_state_change_at`, `allow_public_event_submission`, `public_submission_locked_at`, `public_submission_locked_by`.

**App-specific (stays):** events, follows, reports, members, memberInvitations, followers, personEvents, eventKeyPeople, nonSpeakerEventKeyPeople, verifier, affiliations institutions convenience, avatar/public URLs, default_avatar_url, formatted_name override if needed, publicDirectoryOrder scope, media collections (avatar/main/cover/gallery/documents/certificates), Scout config, lifecycle boot hook.

---

## 2. Package Elevation (2 items only)

### 2a. `AIArmada\Persons\Enums\Gender`

```php
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
}
```

### 2b. Gender enum cast in Person model + Filament form

- `Person::$casts`: `'gender' => Gender::class`
- Filament form: `->options(Gender::class)` instead of hardcoded array.

Everything else stays in the app. No lifecycle, no scopes, no relationships, no slug generation, no DTOs, no form sections — those are all app-specific or depend on optional packages.

---

## 3. Migration

### Package migration

Runs as-is. Creates 11 tables:
`persons`, `person_names`, `title_categories`, `titles`, `title_issuers`, `title_assignments`, `credential_definitions`, `credential_assignments`, `affiliations`, `affiliation_roles`, `languages`.

### App migration

A single migration adds app-specific columns to `persons`:

| Column | Type |
|--------|------|
| `verified_at` | timestampTz nullable |
| `verified_by` | foreignUuid nullable |
| `rejected_at` | timestampTz nullable |
| `inactive_at` | timestampTz nullable |
| `last_state_change_at` | timestampTz nullable |
| `allow_public_event_submission` | boolean default true |
| `public_submission_locked_at` | timestampTz nullable |
| `public_submission_locked_by` | foreignUuid nullable |

No data backfill. No legacy migration. Clean start.

---

## 4. Filament Resources

### Adopt from `filament-persons`

- **PersonResource** — extended with app-specific relation managers
- **TitleResource** — as-is
- **TitleIssuerResource** — as-is
- **CredentialDefinitionResource** — as-is

### Relation managers on PersonResource

**From package (4):** NamesRelationManager, TitleAssignmentsRelationManager, CredentialAssignmentsRelationManager, AffiliationsRelationManager.

**App adds (5):** MembersRelationManager, MemberInvitationsRelationManager, FollowersRelationManager, EventsRelationManager, InstitutionsRelationManager.

### Remove

Current app's `PersonResource`, `TitlesRelationManager`, `CredentialAssignmentsRelationManager`, `AffiliationsRelationManager`, `NamesRelationManager` — replaced by package versions.

Current `TitleResource`/`TitleIssuerResource`/`CredentialDefinitionResource` equivalents if any — replaced by package resources.

### Ahli panel

The member-scoped `PersonResource` in `app/Filament/Ahli/` adapts to extend the package resource with member-only scoping.

---

## 5. Files to Remove

### Models (replaced by package)
- `app/Models/PersonName.php`
- Any custom TitleAssignment, CredentialAssignment, Affiliation, AffiliationRole models

### Enums (replaced by package)
- `app/Enums/PersonNameType.php`
- Any custom TitleUsagePosition, AssignmentStatus, AffiliationType, IssuerType, CredentialType enums

### Filament (replaced by package)
- `app/Filament/Resources/Persons/PersonResource.php` (current)
- Custom relation managers for names, titles, credentials, affiliations

---

## 6. Files to Adapt

### Actions & Services
- `SavePersonAction` — adapt field mapping for package columns
- `GeneratePersonSlugAction` — adapt to package's nullable slug
- `ContributionEntityMutationService` — update person field references
- `PersonSearchService` — adapt searchable_name population
- `PersonObserver` — adapt hooks to package model

### Filament Schemas
- `PersonForm.php` — extend package's `PersonForm` with app sections
- `PersonsTable.php` — extend package's `PersonsTable` with app columns

### Forms
- `PersonFormSchema.php` — adapt field names
- `PersonContributionFormSchema.php` — adapt field names

### API DTOs
- `PersonListData.php` — adapt fields to package model
- `PersonDetailData.php` — adapt fields
- All other Person DTOs — adapt field references

### Livewire
- `Show.php` (persons detail) — adapt model field access
- `SubmitPerson.php` — adapt form field names

### Controllers
- `SearchController` — adapt field references
- `FollowController` — unchanged
- `ContributionController` — adapt field references
- `SitemapController` — adapt field references

### Factories & Seeders
- `PersonFactory` — extend package factory, add app columns
- `PersonSeeder` — adapt to new structure

### Config
- `config/persons.php` — or publish from package, set `models.person`

### Tests
- All 14 Person test files — adapt factories, field names, model references

---

## 7. What We Contribute to Packages

### `aiarmada/persons`
1. `src/Enums/Gender.php` — string-backed enum (Male, Female)
2. Update `Person::$casts` with `'gender' => Gender::class`

### `aiarmada/filament-persons`
1. Update `PersonForm` gender Select to use `->options(Gender::class)`

Nothing else. 2 items total.

---

## 8. Sequence

1. Add `Gender` enum to `aiarmada/persons`, update package Person + filament-persons form
2. Install packages in ilmu360 (`composer.json`)
3. Publish config, set `models.person`
4. Run package migrations
5. Run app migration (add app columns to persons)
6. Create `app/Models/Person.php` extending package Person with all traits
7. Extend `filament-persons` PersonResource with app relation managers
8. Register all 4 Filament resources in panel provider
9. Adapt all files listed in Section 6
10. Remove files listed in Section 5
11. Run tests, fix
12. Remove old `database/migrations/*_create_persons_table.php`
