# Space / Venue / Institution Data Model — Review & Remediation Report

| | |
|---|---|
| **Scope** | Shared space catalog, venue-owned spaces, institution pivots, event location linkage |
| **Audience** | Senior auditor, senior developer |
| **Status** | Draft for review — findings verified against code (Aug 2026) |
| **Stack** | Laravel 13, aiarmada/events + aiarmada/filament-events packages, Postgres |

---

## 1. Executive Summary

The application models spaces in a single table (`venue_spaces`) that serves **two distinct concepts**:

1. **Catalog (shared) spaces** — rows with `venue_id IS NULL`; a global directory of reusable room names (Dewan Utama, Bilik Mesyuarat, Surau, ...) seeded by `SpaceSeeder`. Institutions link to these via the `institution_space` pivot. Events at institutions reference them directly.
2. **Venue-owned (physical) spaces** — rows with `venue_id NOT NULL`; physically-addressable rooms owned by a venue (carry `level`, `unit_no`, `block`, `wing`, coordinates, maps URLs, directions).

Both are valid `EventLocation` targets, and the catalog is genuinely shared by both institution and venue events today.

The **core design is sound** and should not be restructured. However, five defects undermine it. In severity order:

| # | Finding | Severity |
|---|---------|----------|
| F1 | Global unique slug on `venue_spaces` blocks legitimate same-named rooms across venues/catalog | **High** |
| F2 | Venue-owned spaces have no admin CRUD surface at all | **High** |
| F3 | `venue_space_types` taxonomy is consumed by the package (`event_locations.venue_space_type_id`) but entirely unwired in the app | **Medium** |
| F4 | Catalog `capacity` is a single global guess; the pivot carries no per-institution override, yet capacity is exposed publicly via API | **Medium** |
| F5 | `EventLocation` points at mutable catalog rows; renaming/deleting a space rewrites or breaks event history | **Medium** |
| F6 | The two-scope discriminator (`venue_id IS NULL` vs `NOT NULL`) is implicit, undocumented, and unenforced | Low (architectural debt) |

No schema rework is required. Remediation is: **two partial unique indexes, two nullable columns, one app-side resource extension, and wiring already-existing columns**. Detail in §6.

---

## 2. As-Built Architecture

### 2.1 Tables

**`venue_spaces`** (package table; app model `App\Models\Space` maps to it)

```
id              uuid PK
venue_id        uuid NULL        ← the discriminator (NULL = catalog row, else venue-owned)
name            varchar
slug            varchar UNIQUE   ← GLOBAL uniqueness (F1)
code            varchar
space_type      varchar          ← string taxonomy, never populated in app (F3)
level, unit_no, block, wing      ← physical addressing (catalog rows: meaningless)
capacity        int
latitude, longitude, google_maps_url, waze_url, map_url, directions
status, visibility               ← string enums ('active'/'inactive', 'public'/'unlisted'/'private')
metadata        jsonb
created_at / updated_at
```

Indexes: `venue_spaces_slug_unique` (global, the F1 problem), `venue_id`, `code`, `space_type`, `status`, `visibility`.

**`institution_space`** (pivot)

```
institution_id  uuid   PK (composite)
space_id        uuid   PK (composite)
created_at / updated_at
```

No extra columns — no per-institution capacity/override (F4).

**`event_locations`** (package table)

```
event_id, event_occurrence_id, event_session_id
location_role                  ('primary' | 'additional')
locationable_type/id           (polymorphic)
venue_id, venue_space_id       ← the space linkage
venue_space_type_id            ← FK to taxonomy, NEVER written by app (F3)
label, line1..country, level, unit_no
latitude/longitude, google_place_id, maps URLs, directions
address_snapshot  jsonb        ← snapshot precedent exists for address data (relevant to F5)
visibility, status, sort_order, metadata, timestamps
```

**`venue_space_types`**

```
id, code (unique), name, description, category,
applies_to_venue_type, sort_order, is_active, metadata, timestamps
```

Consumed by `EventLocation::venueSpaceType()` (package) — **not dead schema**, but nothing in the app populates it.

### 2.2 Model layer

- `App\Models\Space extends AIArmada\Events\Models\VenueSpace` + `AuditsModelChanges` (audit trail is a strength). Adds `institutions()` belongsToMany → `institution_space` (app/Models/Space.php:19).
- `Institution::spaces()` belongsToMany → `institution_space` (app/Models/Institution.php:280).
- `Venue::spaces()` **hasMany** `VenueSpace` (package Venue.php:131) — venues own rows rather than pivoting.
- `EventLocation` — package model; `venue_space_id` is the linkage; `venueSpaceType()` BelongsTo.

### 2.3 Eligibility rules (the "shared" semantics)

Space selection is gated at validation, not by a single FK:

- **Institution events** — options come from `EventContributionFormSchema::spaceOptionsForInstitution()` (app/Forms/EventContributionFormSchema.php:842): active spaces that are either pivoted to the institution or unclaimed (no pivot rows). Submission path re-validates against the pivot (app/Actions/Events/PersistValidatedEventSubmissionAction.php:118–125).
- **Venue events** — admin path `SaveAdminEventAction` (app/Actions/Events/SaveAdminEventAction.php:372–393): a selected space must have `venue_id IS NULL` (catalog — allowed for *any* venue) or be owned by the event's venue.
- Selected space IDs become `EventLocation` rows via `Event::syncLocation()` (app/Models/Event.php:854): first space = `location_role 'primary'`, rest = `'additional'`; `venue_id` set only on the primary for venue events.

### 2.4 Admin surfaces

- **Catalog CRUD exists**: `SpaceResource` (app/Filament/Resources/Spaces/SpaceResource.php:22, nav "Directory"), pages List/Create/View/Edit, `SpaceForm`, `SpaceInfolist`, `SpacesTable`, relation managers for Institutions/Events/Audits, `SpacePolicy` (CRUD restricted to admin+ roles, delete to super_admin), persistence via `SaveSpaceAction`.
- **Venue-owned spaces have NO admin surface**: the package `VenueResource` declares no `getRelations()`; the app has no `VenueResource` override (no `app/Filament/Resources/Venues/`). Venue rooms can only be created through API/contribution flows (F2).

### 2.5 Public rendering

`Event Show` eager-loads `locations.venueSpace` and `primaryLocation.venueSpace` (app/Livewire/Pages/Events/Show.php:130–131) — i.e. the live, mutable catalog row (F5).

---

## 3. Findings

### F1 — Global unique slug blocks legitimate same-named rooms — **HIGH**

**Evidence**
- Migration: `venue_spaces_slug_unique` index on `slug` (global).
- `SaveSpaceAction::ensureUniqueSlug` (app/Actions/Spaces/SaveSpaceAction.php:101) — plain global existence query, throws `slug taken`.
- `SpaceForm::slug → unique(ignoreRecord: true)` (app/Filament/Resources/Spaces/Schemas/SpaceForm.php:24) — global.
- Admin API meta: `uniqueness_scope => 'spaces.slug'` (app/Support/Api/Admin/AdminResourceMutationService.php:1434) — global.

**Impact**
- Two different venues cannot each have a "Bilik Mesyuarat A" — the second is rejected with a hard validation error.
- A venue's physical "Dewan Utama" can never share its name with the catalog "Dewan Utama" — even though the venue row is a *different physical room*.
- Uniqueness is a *per-venue* property for venue rows and a *catalog-global* property for `venue_id IS NULL` rows. The current constraint enforces the wrong scope for both.

**Root cause**: uniqueness scoped globally instead of by the row's discriminator.

---

### F2 — Venue-owned spaces have no admin CRUD — **HIGH**

**Evidence**
- `vendor/aiarmada/filament-events/src/Resources/VenueResource.php` — no `getRelations()` (verified).
- No app-side VenueResource override; `app/Filament/Resources/` contains no Venues directory.
- App `SpaceResource` + `SaveSpaceAction` manage catalog rows only — `SaveSpaceAction` never sets `venue_id`.

**Impact**
- Venue room inventory (physical addressing: level, block, unit, coordinates, maps URLs, capacity, status) is invisible to admins and only writable through API/contribution paths.
- No moderation, no auditing visibility, no way to deactivate a broken/closed room — data hygiene risk on a table events point at.
- Operational asymmetry: institutions get full CRUD via the pivot; the venue side gets nothing.

**Root cause**: venue admin lives in a package resource with no extension seam used by the app — while the app already demonstrates the correct pattern for spaces (`SpaceResource extends VenueSpaceResource`).

---

### F3 — Space-type taxonomy unwired — **MEDIUM**

**Evidence**
- `event_locations.venue_space_type_id` column exists; `EventLocation::venueSpaceType()` BelongsTo exists (package migration 2000_01_01_000007).
- Zero references to `space_type` / `VenueSpaceType` / `venue_space_types` in `app/` (verified by grep).
- `SpaceSeeder` hardcodes 20 named spaces without a type; `SpaceForm` has no type field; `syncLocation` never writes `venue_space_type_id`.

**Impact**
- Every `event_locations.venue_space_type_id` is NULL — the taxonomy the package clearly intends is dead in this app.
- No room-type classification on events; no "find a venue/event with a surau" capability.
- A complete table (`venue_space_types`) + column (`venue_spaces.space_type`) + FK column sit unused — schema that must be either wired or removed.

**Root cause**: app forms/actions never consumed a package-provided classification seam.

---

### F4 — Capacity semantics are unreliable — **MEDIUM**

**Evidence**
- Catalog `capacity` is a single global number per shared space (e.g. "Dewan Utama 500"), pivoted to any number of institutions (SpaceSeeder).
- `institution_space` carries no per-institution override (schema, §2.1).
- Capacity is **exposed to the public** via API payloads (`app/Data/Api/Event/EventPayloadData.php:113,134`).

**Impact**
- A submitter at a 200-capacity institution sees "Dewan Utama — 500" and cannot correct it.
- Public API consumers receive numbers that are guesses, not facts — they may filter/plan against them.
- Venue-owned rows are physically accurate; catalog rows are not — inconsistent trust across the same API surface.

**Root cause**: catalog capacity conflates "typical" with "actual"; no override mechanism at the point of use (pivot).

---

### F5 — Event history references mutable catalog rows — **MEDIUM**

**Evidence**
- `EventLocation` stores only `venue_space_id` (FK); `Event Show` renders the live relation (`locations.venueSpace`, Show.php:130).
- No space-name snapshot anywhere (the `address_snapshot` jsonb column on the same table is only used for addresses — the precedent exists, the space name is not included).
- `SpacePolicy::delete` allows super_admin deletion with no guard against in-use spaces.

**Impact**
- Renaming "Dewan Utama" in the catalog silently rewrites the displayed location of every past and future event that used it.
- Deleting an in-use space leaves dangling `venue_space_id` references (no FK constraints by design guideline) → broken location rendering on historical events.

**Root cause**: linkage is a live pointer; the domain is historical (events happened *at* a place at a time) and needs a snapshot, which the table already models for addresses but not for spaces.

---

### F6 — Implicit two-scope discriminator — **LOW (architectural debt)**

**Evidence**
- One table holds catalog rows (`venue_id IS NULL`) and physical rooms (`venue_id NOT NULL`); nothing enforces or documents the split.
- Validation logic must special-case both scopes in three places (contribution form, submission action, admin action) because the discriminator is a convention, not a constraint.

**Impact**
- Future contributors can silently create rows with the wrong semantics; slug scoping (F1) cannot be implemented as a constraint until the scopes are explicit.
- No partial indexes, no check constraints, no code comment or ADR documenting intent.

**Note**: Splitting into two tables is **not** recommended — both scopes share `event_locations.venue_space_id` linkage and hundreds of event rows; the cost/benefit does not justify it. The fix is to make the existing design explicit, not to replace it.

---

## 4. Strengths (preserve)

- Unified `venue_spaces` + discriminator is a workable model; catalog is genuinely shared by both institution and venue events.
- Eligibility is enforced server-side in every write path (institution pivot check, venue ownership check) — not just filtered in the UI.
- `SaveSpaceAction` centralizes normalization/validation/auditing for catalog spaces — single save path worth extending rather than forking.
- Full audit trail (`AuditsModelChanges`) and policy layer exist.
- `address_snapshot` jsonb on `event_locations` proves the snapshot pattern is native to this codebase (reuse for F5).

---

## 5. Target Model (recommended)

Make the two-scope design explicit and enforced:

| Scope | Rows | Slug uniqueness | Admin CRUD | Capacity |
|-------|------|-----------------|------------|----------|
| **Catalog** | `venue_id IS NULL` | global among catalog rows | `SpaceResource` (exists) | indicative; per-institution override on pivot (F4) |
| **Venue-owned** | `venue_id NOT NULL` | per-venue (`venue_id, slug`) | new `VenueResource` + `SpacesRelationManager` (F2) | physical, owned by venue row |

Both scopes remain valid `EventLocation` targets under the existing eligibility rules (§2.3). `venue_space_types` becomes populated and propagated into `event_locations` (F3). `EventLocation` gains a space-name snapshot (F5).

---

## 6. Remediation Plan

### Phase 1 — F1: scope slug uniqueness

1. **Pre-flight data check** (prod): find existing collisions under the new scopes; resolve before index creation.
2. **Migration** (app-level; do not touch package migrations):
   - Drop `venue_spaces_slug_unique`.
   - `CREATE UNIQUE INDEX venue_spaces_catalog_slug_unique ON venue_spaces (slug) WHERE venue_id IS NULL;`
   - `CREATE UNIQUE INDEX venue_spaces_venue_slug_unique ON venue_spaces (venue_id, slug);`
     (Postgres treats NULLs as distinct in unique indexes — catalog rows are automatically exempt from the per-venue pair index; the partial index covers catalog-global uniqueness.)
3. **`SaveSpaceAction`**: accept `venue_id`; `ensureUniqueSlug` scoped to `venue_id IS NULL` for catalog rows, `(venue_id, slug)` for venue rows. **Single source of truth for uniqueness.**
4. **`SpaceForm`**: drop the Filament `->unique()` rule (the action now enforces); keep `->dehydrated()` unchanged. (Or use a scoped `Rule::unique` — but one enforcement point is simpler.)
5. **Admin API meta**: change `uniqueness_scope` from `'spaces.slug'` to a per-scope rule matching the action.

### Phase 2 — F2: venue space admin CRUD

1. Create `app/Filament/Resources/Venues/VenueResource extends` package `VenueResource` — the app already uses this exact override pattern for `SpaceResource extends VenueSpaceResource`. Delegate form/infolist/table to the package schemas; add `getRelations()`.
2. Add `SpacesRelationManager` (mirror `SpaceResource`'s `InstitutionsRelationManager` structure) with table columns (name, capacity, status, visibility) and create/edit routed through `SaveSpaceAction` with `venue_id` preset — one save path for both scopes.
3. Scope `SpacePolicy`/authorization: venue RM inherits the policy (extend `update` to accept venue context if needed).

### Phase 3 — F3: wire the type taxonomy

1. **Seeder**: populate `venue_space_types` (dewan, bilik mesyuarat, bilik kuliah, surau, dewan jamuan, ruang pameran, kafeteria, makmal, dapur, ...) via `updateOrCreate` by `code`; map the 20 existing catalog spaces to types by name heuristics.
2. **`SpaceForm` + `SaveSpaceAction`**: add a `space_type` field/select; persist the string `space_type` on `venue_spaces` (column already exists — no schema change).
3. **`syncLocation`** (app/Models/Event.php:854): when creating `EventLocation`, resolve the space's `space_type` → `venue_space_types.code` and write `venue_space_type_id`. One propagation point; classifies every event location from then on.
4. Optional (nice-to-have): type filter on `SpacesTable` and on event location display.

### Phase 4 — F4: per-institution capacity override

1. **Migration**: add nullable `capacity` int to `institution_space`.
2. **`SpaceResource` Institutions relation manager**: per-pivot capacity input (blank = inherit catalog).
3. **`spaceOptionsForInstitution`** (EventContributionFormSchema.php:842): display effective capacity (pivot override ?? catalog).
4. **API**: extend the institution-space sync payload to accept optional pivot capacity (admin API only).
5. If product confirms capacity is purely informational, alternative is to drop it from catalog display entirely — **decision required (§8, Q1)**.

### Phase 5 — F5: snapshot space name on EventLocation

1. **Migration**: add `space_name_snapshot` varchar nullable to `event_locations` (explicit column; alternatively fold into existing `address_snapshot` jsonb — zero-migration option, but an explicit column is clearer and indexable).
2. **`syncLocation`**: write `space_name_snapshot` from the selected space at creation time.
3. **Rendering** (Show.php:130): display `space_name_snapshot ?? venueSpace.name`.
4. **Protection**: extend `SpacePolicy::delete` to block (or require confirmation with impact listing) deletion of spaces referenced by `event_locations`.

### Phase 6 — Tests

| Fix | Test |
|-----|------|
| F1 | Two venues each create "Bilik Mesyuarat A" → both succeed; catalog "Dewan Utama" and venue "Dewan Utama" coexist; duplicate slug within same venue rejected; duplicate catalog slug rejected |
| F2 | Venue RM creates/edits/deactivates a venue-owned space with `venue_id` set; row appears in venue event form options |
| F3 | Event created with a typed space → `event_locations.venue_space_type_id` populated; untyped space → NULL |
| F4 | Institution pivot capacity override appears in `spaceOptionsForInstitution`; blank falls back to catalog |
| F5 | Rename catalog space → historical event still renders old name; delete of in-use space blocked |
| Regression | Existing eligibility rules: institution event cannot use another institution's pivoted space; venue event cannot use another venue's owned space |

**Sequencing**: Phase 1 → 2 → 3 → 4 → 5. Phases are independent; each is shippable alone.

---

## 7. Risks

- **Prod data collisions** under new slug scopes (F1) — must run the pre-flight query before the migration; rename outliers first.
- **Package upgrades** — indexes/columns added in app migrations, so `composer update aiarmada/*` cannot clobber them; do not patch package migrations.
- **F5 backfill** — existing `event_locations` rows predate the snapshot; backfill from current space names (approximation) or accept NULL and fall back to the live relation.
- **Scope creep** — Phases 3–5 touch a package hot path (`syncLocation`); keep changes additive (new columns/fields), never reorder existing writes.

---

## 8. Open Questions for Reviewers

1. **Capacity (F4)**: does any product surface actually filter/plan by space capacity, or is it informational? This decides override-column vs. de-emphasis. (It is already public in API payloads either way.)
2. **Type taxonomy (F3)**: confirm seed-and-propagate is preferred over removing the FK from `event_locations` (the package expects it — removal is the more invasive option).
3. **History (F5)**: snapshot-only, or also freeze renames of spaces already referenced by events (warn + require new row)? Snapshot is the minimum; a "clone-and-switch" flow is the fuller option.
4. **Quick-create**: `app/Forms/Components/Select.php` ships a `quickAdd` capability — should event forms allow inline creation of venue-owned spaces, and if so, through `SaveSpaceAction` (recommended) or directly?
5. **Owner semantics**: should a venue event be allowed to attach an *unclaimed* catalog space (currently yes, by design)? Confirm this is intended for reporting purposes, or restrict to the venue's own rows + institutions' pivots.

---

## 9. Evidence Index

| Reference | Location |
|-----------|----------|
| Global unique slug index | migration `create_event_venue_spaces_table` → `venue_spaces_slug_unique` |
| `ensureUniqueSlug` (global) | app/Actions/Spaces/SaveSpaceAction.php:101 |
| `SpaceForm` `->unique()` | app/Filament/Resources/Spaces/Schemas/SpaceForm.php:24 |
| API `uniqueness_scope 'spaces.slug'` | app/Support/Api/Admin/AdminResourceMutationService.php:1434 |
| `Space extends VenueSpace` | app/Models/Space.php:19 |
| `Institution::spaces()` pivot | app/Models/Institution.php:280 |
| `Venue::spaces()` hasMany | vendor/aiarmada/events/src/Models/Venue.php:131 |
| Package VenueResource (no RMs) | vendor/aiarmada/filament-events/src/Resources/VenueResource.php:22 |
| `spaceOptionsForInstitution` | app/Forms/EventContributionFormSchema.php:842 |
| Institution eligibility validation | app/Actions/Events/PersistValidatedEventSubmissionAction.php:118–125 |
| Venue/institution space validation | app/Actions/Events/SaveAdminEventAction.php:372–393 |
| `syncLocation` / EventLocation writes | app/Models/Event.php:854 |
| `event_locations` schema (incl. `venue_space_type_id`, `address_snapshot`) | vendor/aiarmada/events/database/migrations/2000_01_01_000007_create_event_locations_table.php |
| Public event page renders live space | app/Livewire/Pages/Events/Show.php:130–131 |
| Capacity in public API | app/Data/Api/Event/EventPayloadData.php:113,134 |
| `SpaceSeeder` (20 catalog spaces) | database/seeders/SpaceSeeder.php |
| `SpacePolicy` (delete unguarded) | app/Policies/SpacePolicy.php:35–38 |
