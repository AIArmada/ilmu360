# Phase 4 - Identity, Geography, Contacts, And Membership

State: **`Superseded`** (assessment-era plan; execution landed in Phase 8)

> **Do not implement from open checkboxes in this file.**  
> Live status: [`status.md`](status.md) · No-miss carry-forward: [`phase-reconciliation.md`](phase-reconciliation.md) §4 · Active work: Phase 9 only.  
> Residuals still open: **P9-D** (alias traits / `HasPrimaryAddressAccessors`), optional polish (`FormatAddressAction` vs `AddressHierarchyFormatter`).

## Objective

Replace app-owned support domains with package-owned UUID addressing, contact/social profiles, and membership workflows. Geography must become global by default: no app-wide country switcher, no preferred-country resolver as a product mode, and no Malaysia-only area tables in the fresh target schema.

**Historical note:** This file was planning-only until Phase 8. Phase 8 executed the bulk of the checklist; open boxes below are stale.

## Target Packages

- `addressing` (exists) + `filament-addressing` (exists) — **Ready**
- `contacting` (exists, installed) + `filament-contacting` (exists, not installed) — **Ready**
- `membership` (exists, not installed) + `filament-membership` (does not exist) — **Ready, needs filament adapter**
- **`aiarmada/filament-contacting` must be added to `composer.json` before Phase 8.**
- **`aiarmada/membership` must be added to `composer.json` before Phase 8.**

## Package Assessment

### `addressing` (WP-09) — Migration Effort: Medium

**Package provides:**
- `AddressCountry` (UUID PK, 249 ISO countries, rich metadata)
- `AddressArea` (UUID PK, hierarchical via parent_id, typed: state/city/district/etc.)
- `Address` (UUID PK, denormalized + area FKs + geo/provider)
- `Addressable` morph pivot (polymorphic attachment)
- `AddressSnapshot` (immutable address copies)
- `HasAddresses` trait, `FormatAddressAction`, `SeedAddressCountriesAction`, `ImportAddressAreasAction`
- `filament-addressing`: `AddressCountryResource`, `AddressAreaResource`, `AddressResource`, `AddressesRelationManager`, form/table/infolist schemas

**App has:**
- `Country`, `State`, `District`, `Subdistrict`, `City` models (integer IDs, `nnjeim/world` for country/state/city)
- `Address` (UUID PK, morphTo single, custom fields)
- `HasAddress` trait (morphOne per entity)
- Country switching: `PublicCountryPreference`, `PublicCountryRegistry`, `PreferredCountryResolver`, `/negara/{country}` route, layout dropdown
- Federal territory special handling (`FederalTerritoryLocation`)
- Google Places resolution (`ResolveGooglePlaceSelectionAction`)
- 4 API catalog endpoints, cascade form selects, Scout searchable arrays, speaker slug country suffix
- `AddressHierarchyFormatter`, `SharedFormSchema::addressFields()`

**Key mapping decisions needed:**

| App concept | Package equivalent | Notes |
|---|---|---|
| `Country` (int PK, nnjeim/world) | `AddressCountry` (UUID PK) | Seed 249 countries; map existing country_id references in addresses/events/entities |
| `State` (int PK) | `AddressArea` (type='state', parent=country) | Part of unified hierarchy |
| `District` (int PK) | `AddressArea` (type='district', parent=state) | Part of unified hierarchy |
| `Subdistrict` (int PK) | `AddressArea` (type='subdistrict', parent=district) | Part of unified hierarchy |
| `City` (int PK, nnjeim/world) | `AddressArea` (type='city', parent=state) | Part of unified hierarchy |
| Federal territory KL/Putrajaya/Labuan | `AddressArea` (type='state', no district children) | Remove `FederalTerritoryLocation`; package hierarchy handles this naturally via `parent_id` |
| `Address` (morphTo single, hasUuids) | `Address` (morphToMany via Addressable) | App uses one address per entity (morphOne); package supports multiple (morphToMany). Use `HasAddresses` trait + setPrimary pattern |
| `HasAddress` trait (morphOne) | `HasAddresses` trait (morphToMany) | Replace on Event, Institution, Speaker, Venue |
| `PublicCountryPreference` / `PublicCountryRegistry` / `PreferredCountryResolver` | Remove entirely | Country is an optional filter, not a session mode |
| `/negara/{country}` + layout switcher | Remove entirely | No global country selector needed |
| `GooglePlacesConfiguration` + `ResolveGooglePlaceSelectionAction` | Adapt to `AddressArea` types instead of specific tables | Replace DB lookups with AddressArea queries by type+name |
| `AddressHierarchyFormatter` | `FormatAddressAction` | Package provides `format(AddressData) -> string` |
| API catalog endpoints | Replace with addressing package or build lightweight wrapper | Countries, states, districts, subdistricts → AddressCountries, AddressAreas by type |
| Scout searchable geography | Package-native facets: `state_id`, `city_id`, `admin_area_1_id`, `admin_area_2_id` | Done (no legacy `district_id`/`subdistrict_id`) |
| Speaker slug country suffix | Convert from integer country_id to ISO2 lookup via AddressCountry | `GenerateSpeakerSlugAction` |
| Cascade form selects (country→state→district→subdistrict) | Replace with AddressArea cascade filtered by type + parent | `SharedFormSchema::addressFields()` rewritten |
| Filament admin resources (Country/State/District/Subdistrict) | Replace with `AddressCountryResource` + `AddressAreaResource` from filament-addressing | Remove 4 custom resources, use 2 package resources with filters |
| Deletion guard (`HasGeographyDeletionGuard`) | Unnecessary — package handles integrity at app level | Remove |
| GeographyObserver (cache busting) | Replace with package's event-driven cache or remove | Package has no observer; app may need to keep cache busting |

### `contacting` (WP-10) — Migration Effort: Low

**Package provides:**
- `ContactMethod` (UUID PK, 15 fields, auto-normalized: email→lowercase, phone→E164, website→scheme)
- `SocialProfile` (UUID PK, 40+ platforms, bidirectional handle/URL normalization via `SocialProfileConfig`)
- `ContactSnapshot` (immutable history)
- `HasContactMethods` / `HasSocialProfiles` traits with rich query API
- `BuildContactLinksAction` (mailto/tel/whatsapp/website)
- Filament: `ContactMethodResource`, `SocialProfileResource`, `ContactMethodsRelationManager`, `SocialProfilesRelationManager`
- Requires `ysfkaya/filament-phone-input` dependency

**App has:**
- `Contact` (UUID PK, simpler, 7 fields, no normalization, no primary/verified flags)
- `SocialMedia` (UUID PK, 11 platforms, custom sophisticated `SocialMediaLinkResolver` with 10 platform-specific extractors)
- `HasContacts` / `HasSocialMedia` traits (simpler, query-only)
- SVG icon system for social platforms

**Key decisions:**

| App concept | Package equivalent | Notes |
|---|---|---|
| `Contact` model | `ContactMethod` model | Add fields: purpose, label, normalized_value, country_code, is_primary, is_verified. Remove ContactType/ContactCategory enums, use package's. |
| `SocialMedia` model | `SocialProfile` model | Add fields: purpose, label, normalized_url, is_primary, is_public, is_verified, verified_at. Map 11 platforms to package's 40+ SocialPlatform. |
| `HasContacts` trait | `HasContactMethods` trait | Replace on Institution, Speaker, Venue, EventSubmission, Reference |
| `HasSocialMedia` trait | `HasSocialProfiles` trait | Same replacements |
| `SocialMediaLinkResolver` (435 lines, sophisticated) | `NormalizeSocialProfileAction` + `SocialProfileConfig` (simpler, config-driven) | ⚠️ **Regression risk**: package handles prefix/suffix patterns only. App's resolver extracts handles from varied URL formats (FB profile.php?id=, Twitter x.com, YouTube @handles/channel/ user). Either accept the simpler model (if handles are always typed by users) or extend the package with a custom SocialProfileNormalizer. |
| SVG icons (`getIconUrlAttribute()`) | Package has no icon system | Keep app's icon lookup or map to Filament heroicons |
| Inline form Repeaters in Institution/Speaker/Venue forms | `ContactMethodsRelationManager` + `SocialProfilesRelationManager` | Replace inline Repeaters with tabs/relation managers |
| `ContactCategory` / `ContactType` / `SocialMediaPlatform` enums | `ContactMethodType` / `ContactPurpose` / `SocialPlatform` | Remove 3 app enums, use 3 package enums |

### `membership` (WP-11) — Migration Effort: High

**Package provides:**
- `MembershipApplication` (UUID PK, lifecycle: pending→approved/rejected/cancelled, `ApplicationStatus` enum, `granted_role` as Spatie role name)
- `MembershipInvitation` (UUID PK, token SHA256 hashed, expiry, lifecycle)
- `HasMembers` trait (dynamic pivot: `{subject_snake}_members`, BelongsToMany)
- 10 actions (`ApplyForMembershipAction`, `ApproveMembershipApplicationAction`, `InviteMemberAction`, `AddMemberAction`, etc.)
- `MemberRole` enum (admin/editor/viewer → maps to configurable Spatie role names)
- `MembershipRoleSyncService` (assigns/revokes Spatie roles with team context)
- `MembershipApplicationNotifier` contract, `MembershipHook` contract
- 6 events, 2 commands, feature-flagged config

**App has:**
- `MembershipClaim` model + `MembershipClaimStatus` enum (similar to `MembershipApplication`)
- `MemberInvitation` model (similar to `MembershipInvitation`)
- `TeamRole` enum (owner/admin/member) — different role model, no package equivalent for "owner"
- `MemberSubjectType` enum (institution/speaker/event/reference) with `isClaimable()` — no package equivalent
- 14 custom actions in `app/Actions/Membership/` — integrate with 5+ app services:
  - `PublicSubmissionLockService` — automatic lock/unlock on membership change
  - `MemberRoleCatalog` — role resolution/validation/labels per subject type
  - `MemberRoleScopes` — authz scope resolution per subject type
  - `ScopedMemberRoleSeeder` — Spatie role existence per scope
  - `AuditSync` (custom audit sync on pivot changes)
  - `Authz::withScope()` — scope-based permission assignment
  - Media evidence uploads on claims
  - Email notifications inline

**Key decisions:**

| App concept | Package equivalent | Notes |
|---|---|---|
| `MembershipClaim` model | `MembershipApplication` model | Add: owner morphs, meta JSON. Rename: granted_role_slug→granted_role. Keep: media collection as app extension. |
| `MemberInvitation` model | `MembershipInvitation` model | Add: owner morphs. Change: token hashing. Near-identical otherwise. |
| `MembershipClaimStatus` enum | `ApplicationStatus` enum | Direct mapping (pending/approved/rejected/cancelled) |
| `TeamRole` (owner/admin/member) | `MemberRole` (admin/editor/viewer) | ⚠️ **No "owner" role** in package. App's owner role has hierarchy position not in MemberRole. Must either add a package MemberRole entry or map owner to admin. |
| `MemberSubjectType` + `isClaimable()` | True polymorphism (any model) | Keep `MemberSubjectType` as app-level helper for claimability filter; remove from core model |
| Custom `->members()` BelongsToMany per model (with auditSync) | `HasMembers` trait's dynamic pivot | Replace each model's manual BelongsToMany with trait. Handle auditSync via `MembershipHook::onMemberAdded/Removed/RoleChanged` |
| `PublicSubmissionLockService` | `MembershipHook` contract | Implement hook on app side + register in MembershipServiceProvider |
| `MemberRoleCatalog` + `MemberRoleScopes` + `ScopedMemberRoleSeeder` | Package's `MembershipRoleSyncService` + config | Configure Spatie role mapping in membership config; keep app-level role resolution as app service |
| `Authz::withScope()` calls | `MembershipRoleSyncService` (uses `setPermissionsTeamId()`) | Package already does scope-based role assignment; verify team context is correctly set |
| Claim evidence media uploads | None in package | Add `->registerMediaCollections()` on app-side extended model or keep media on a separate model |
| `MemberInvitationNotification` (Mail) | `MembershipApplicationNotifier` contract | Adapt app's notification to implement the contract |

## Work Plan

### WP-09 — Geography/Addressing (Medium)
- [ ] Add `config/addressing.php` published from package
- [ ] Add `HasAddresses` trait to Event, Institution, Speaker, Venue (replace `HasAddress`)
- [ ] Seed countries: `php artisan address:seed-countries`
- [ ] Import Malaysia address areas (states, districts, subdistricts) via CSV or array source
- [ ] Register `AddressesRelationManager` on entity Filament resources
- [ ] Replace EntityResource address form fields with `AddressFormSchema` or `AddressesRelationManager`
- [ ] Replace `SharedFormSchema::addressFields()` with `AddressFormSchema`-based fieldset
- [ ] Replace `AddressHierarchyFormatter` with `FormatAddressAction`
- [ ] Adapt `ResolveGooglePlaceSelectionAction` to query `AddressArea` by type+name
- [x] Remove `PublicCountryPreference`, `PublicCountryRegistry`, `PreferredCountryResolver`, `/negara/{country}`, layout country dropdown
- [ ] Remove `FederalTerritoryLocation` (hierarchy handles this)
- [ ] Remove `GeographyObserver`, `HasGeographyDeletionGuard`, `GetGeographyDeletionBlockReasonAction`
- [ ] Rewrite API catalog endpoints: `/api/v1/catalogs/countries` → `AddressCountry` query, `/api/v1/catalogs/areas` → `AddressArea` query filtered by type
- [ ] Convert Scout searchable arrays: replace int geography IDs with UUID address_area_ids
- [ ] Convert speaker slug country suffix from int ID lookup to ISO2 via `AddressCountry`
- [x] Search filters use package-native `state_id` / `city_id` / `admin_area_1_id` / `admin_area_2_id`
- [ ] Replace Filament Country/State/District/Subdistrict resources with `filament-addressing` resources
- [ ] Remove 4 custom geography models, keep only package models in fresh schema
- [x] Remove `config/public-countries.php`

### WP-10 — Contacting (Low)
- [ ] Add `aiarmada/filament-contacting` to `composer.json` (install dependency)
- [ ] Publish `config/contacting.php`
- [ ] Replace `Contact` model with `ContactMethod` model
- [ ] Replace `SocialMedia` model with `SocialProfile` model
- [ ] Replace `HasContacts` / `HasSocialMedia` traits with `HasContactMethods` / `HasSocialProfiles`
- [ ] Map `ContactCategory`, `ContactType`, `SocialMediaPlatform` to `ContactMethodType`, `ContactPurpose`, `SocialPlatform`
- [ ] Register `ContactMethodsRelationManager` and `SocialProfilesRelationManager` on Institution, Speaker, Venue, EventSubmission, Reference Filament resources
- [ ] Remove inline form Repeaters for contacts/social (replaced by relation managers)
- [ ] Remove `SocialMediaLinkResolver` — either accept package normalizer or build custom `SocialProfileNormalizer`
- [ ] Keep SVG icon system or migrate to Filament heroicons
- [ ] Remove `App\Models\Contact`, `App\Models\SocialMedia`, custom enums
- [ ] Remove `App\Traits\HasContacts`, `App\Traits\HasSocialMedia`

### WP-11 — Membership (High)
- [ ] Add `aiarmada/membership` to `composer.json`
- [ ] Publish `config/membership.php` — configure role mapping (admin/editor/viewer → Spatie roles)
- [ ] Replace `MembershipClaim` with `MembershipApplication` (add owner morphs, meta, rename granted_role_slug→granted_role)
- [ ] Replace `MemberInvitation` with `MembershipInvitation` (add owner morphs, token hashing)
- [ ] Replace `MembershipClaimStatus` with `ApplicationStatus`
- [ ] Map `TeamRole` to `MemberRole` (no "owner" equivalent — decide: add to package or map to admin)
- [ ] Implement `MembershipHook` on app side: wire `PublicSubmissionLockService` into `onMemberAdded/Removed/RoleChanged`
- [ ] Implement `MembershipApplicationNotifier`: wire `MemberInvitationNotification` into `notifySubmitted/Approved/Rejected`
- [ ] Replace custom `->members()` BelongsToMany per model with `HasMembers` trait
- [ ] Route audit tracking from `auditSync()` through `MembershipHook`
- [ ] Keep `MemberSubjectType`, `MemberRoleCatalog`, `MemberRoleScopes` as app-level services
- [ ] Run `php artisan membership:sync-roles` to ensure Spatie roles exist
- [ ] Generate pivot migration via `MakePivotCommand` for each subject type
- [ ] Keep media evidence collection on `MembershipApplication` app extension
- [ ] Remove 14 custom `App\Actions\Membership\*` actions
- [ ] Remove `App\Models\MembershipClaim`, `App\Models\MemberInvitation`, custom enums
- [ ] Remove `Team` model and `team_members` table if no longer needed

## Verification

```bash
vendor/bin/pest --parallel --compact --filter=Address
vendor/bin/pest --parallel --compact --filter=Country
vendor/bin/pest --parallel --compact --filter=Location
vendor/bin/pest --parallel --compact --filter=Contact
vendor/bin/pest --parallel --compact --filter=Membership
vendor/bin/phpstan analyse --ansi
```

## Exit Criteria

- Fresh database no longer needs old integer geography tables.
- Discovery and search are global by default and do not depend on country switching.
- Institution, venue, speaker, and event-location workflows use package UUID countries and address areas.
- Contact/social data writes through package actions/models.
- Membership workflows are package-backed and authorized through package authz contracts.
- (`contacting`) Social profile normalization matches current app behavior or documented trade-off accepted.
- (`membership`) Public submission lock, role catalog, and authz scope integration re-wired through package contracts.

## Stop And Re-plan Triggers

- `addressing` package seed data cannot support required public geography filters.
- A public search/listing/API/MCP path still depends on a selected global country.
- Membership roles require ilmu360-specific package code.
- Contact normalization loses current supported platform or phone behavior without a generic package fix.
- (`membership`) The absence of an "owner" role in `MemberRole` breaks existing access control semantics.
- (`contacting`) `SocialMediaLinkResolver` regression affects user-facing social link detection.
