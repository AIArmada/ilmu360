# References: Editions + Submit-Flow Parts + Dead-Schema Gaps — Investigation Report

**Date:** 2026-10-01
**Trigger:** `/hantar-majlis` step 1 ("Rujukan Kitab") quick-create always builds a brand-new
root reference; believed the schema supported editions.
**Verdict:** Correct instinct, wrong noun. The schema supports a **parent/child "parts"
hierarchy** (Jilid/Bahagian/…), **not editions**. There is no edition concept anywhere
(zero matches for `edition`/`edisi`/`cetakan` in `app/`, `database/`, `resources/views/`,
`config/`, or the `aiarmada/references` package). The submit flow cannot create parts,
and `isbn` + `language` columns are fully dead (schema-only, zero app references).

This report is the implementation handoff. It defines the problems, the challenges, the
surfaces to change, and the owner's hard constraints.

---

## 1. Owner's implementation constraints (non-negotiable)

1. **Scope = all three:** (a) expose part-of-book creation in the submit flow, (b) design
   and implement a real **edition** concept, (c) wire up the dead schema (`isbn`,
   `language`, and the `url`-column inconsistency — see P3).
2. **No backward compatibility, no legacy shims/aliases, no backfill scripts.**
3. **Schema changes by editing migrations directly** (not new alter-table migrations).

Consequence the implementer must accept: direct migration edits require `migrate:fresh`
(or equivalent rebuild) in every environment. There is no upgrade path for existing
databases under these constraints. See C1 for the cross-repo wrinkle.

---

## 2. Current-state architecture

### 2.1 Schema (package-owned)

The `references` table is created by the **package**, not the app:

- Real source (sibling repo, path-symlinked):
  `../commerce/packages/references/database/migrations/2000_01_01_000001_create_references_table.php`
  (visible in this repo as `vendor/aiarmada/references/...` via the `path` repository
  in `composer.json` with `"symlink": true`).
- Columns: `id` uuid PK, `owner` nullable morphs, `type` string+index, `status`
  string(20) default `draft`, `title`, `slug`, `author` text?, `publisher` string?,
  `year` integer?, `isbn` string(20)?, `description` text?, `url` string?, `language`
  string(10)?, `parent_id` uuid?+index, `reference_parts` json?, `metadata` json?,
  `is_canonical` bool default false+index, `published_at` tz?, `timestampsTz`,
  index(`type`,`status`), plus `ReferenceIdentityIndexes::owner(slug)`.
- The only app-owned references migration,
  `database/migrations/2026_07_25_140600_add_verified_by_to_references_and_position_to_affiliations.php`,
  adds `verified_at`, `rejected_at`, `last_state_change_at`, `verified_by` (guarded by
  `Schema::hasColumn`, shared with venues/persons/affiliations in one file).

### 2.2 Model layer (two tiers)

- Package model `AIArmada\References\Models\Reference`
  (`vendor/aiarmada/references/src/Models/Reference.php`): casts (`type`/`status` to
  package enums), `validateFields()` (year range, isbn ≤20 chars, url must be
  http/https ≤255, language ≤10, JSON payloads ≤64KB — throws `InvalidArgumentException`,
  i.e. **500s, not validation errors**), `validateParentHierarchy()` (parent must exist,
  no self-parent, cycle rejection, owner-scope guard), `guardSingleCanonical()` (**one
  canonical per owner**, throws otherwise), `stampPublishedAt()`, children-first
  cascade delete with media cleanup, `HasReferenceParts` trait
  (`getPart/setPart/removePart/hasPart/getPartsGrouped`, JSON `{type, value}` entries).
- App model `App\Models\Reference` (`app/Models/Reference.php`, extends package model):
  overrides `casts()` **without** `type`/`status` enum casts (stored/read as plain
  strings; `*Value()` accessors tolerate `BackedEnum` anyway). Key logic:
  - `normalizeReferencePartFields()` (:602): if `type != book` or no `parent_id`,
    **force-clears** `parent_id` and `reference_parts`. Otherwise folds virtual inputs
    `part_type`/`part_number`/`part_label` into exactly one `reference_parts[0]` entry.
  - `ensureValidParentReference()` (:631): no self-parent; a row **with children can
    never become a child** (no grandchildren, ever); parent must exist, must be a root,
    must be `type=book`. Violations throw `ValidationException` (422-safe).
  - Family helpers: `familyRootId()`, `familyReferenceIds()` (root + direct children,
    active-only), `defaultEventReferenceIds()` (part → self only; root → whole family),
    `expandRootReferenceIdsForFiltering()` (static; root selections expand to children).
  - `displayTitle()` (:473): `"{title} — {partLabel}"` for parts; plain title otherwise.
    `partLabel` falls back to `"{PartTypeLabel} {number}"`.
  - Visibility: `status IN (verified, pending) AND published_at NOT NULL`
    (`PUBLIC_STATUSES`, `applyPublicVisibility()`, `active()` scope). Note `pending`
    rows are **publicly visible by design**.
  - Saving hook also auto-stamps `verified_at`/`published_at`/`rejected_at`/
    `last_state_change_at` on status change, and auto-generates slug when blank.
  - `isPart()` / `isRootReference()` / scopes `root()` / `part()` /
    `orWherePartTextLike()` (driver-aware JSON LIKE for part text search).

### 2.3 The "parts" vocabulary

- App enums (`app/Enums/`): `ReferenceType` = book/article/video/other (a **subset** of
  the package enum, which also has thesis/fatwa/audio/website); `ReferencePartType` =
  jilid/bahagian/part/volume/other (differs from package enum:
  jilid/juz/surah/chapter/section/page).
- Only `book` rows participate in hierarchy. Parts are "Jilid 2 of book X", addressed as
  their own rows with their own slugs, covers, and event links.

### 2.4 Where parts CAN be created today

| Surface | File | Mechanism |
|---|---|---|
| Admin Filament form | `app/Filament/Resources/References/Schemas/ReferenceForm.php:40-75` | `parent_id` select (root books only, book-type only) + `part_type`/`part_number`/`part_label` (visible+dehydrated only when parent set) |
| Contribution **update** flow | `app/Forms/ReferenceContributionFormSchema.php:39-71` (used by `app/Livewire/Pages/Contributions/SuggestUpdate.php`) | Same trio, but parent key is `parent_reference_id` |
| Admin API mutations | `app/Actions/References/SaveReferenceAction.php:36-41` (used by `app/Support/Api/Admin/AdminResourceMutationService.php`) | Accepts `parent_id` **or** `parent_reference_id` + `part_*` |

### 2.5 Where parts CANNOT be created (the reported gap)

- `/hantar-majlis` step 1, `Select::make('references')`
  (`app/Livewire/Pages/SubmitEvent/Create.php:1192-1286`): `createOptionForm` collects
  title/author/type/publication_year/publisher/reference_url/covers/description and
  `createOptionUsing` does a root-only `Reference::create(...)` with
  `status=pending`, `published_at=now()`, `is_canonical=false`. **No parent select, no
  part inputs.** A submitter covering "Jilid 2" must either attach the root (wrong
  granularity) or mint a duplicate root row.
- Ahli (member) panel (`app/Filament/Ahli/Resources/References/ReferenceResource.php`):
  `canCreate() = false`, edit-only, inherits the admin form — so members *can* set
  parts when editing, but cannot create.

### 2.6 Read/display surfaces that already understand parts

- Detail page: `app/Livewire/Pages/References/Show.php:199-210` eager-loads
  `parentReference` + `childReferences` (active-only);
  `resources/views/livewire/pages/references/show.blade.php:656-678` renders the family
  block with links.
- Public API `showReference`
  (`app/Http/Controllers/Api/Frontend/SearchController.php:~787+`): root (or
  `?include_all_parts=1`) aggregates events across `familyReferenceIds()`; a part shows
  only its own events by default.
- Event filtering anywhere using `expandRootReferenceIdsForFiltering()`: selecting a
  root implicitly includes its parts.
- Scout: `toSearchableArray()` / database-driver variant index part fields; search
  matches part JSON via `orWherePartTextLike()`.

---

## 3. Gap inventory (what is broken / missing)

### P1. No edition concept (core ask)
Same work, different publisher/year/printing ("cetakan ke-3", "terbitan Dar al-Kutub
2018 vs 2022") has **no representation**. Today it would be a duplicate root row with an
opaque deduped slug (`riyadhus-solihin-1`). Nothing links editions to each other or to
a canonical work record. Publisher/year/isbn are flat per-row attributes, so two
editions are indistinguishable from accidental duplicates.

### P2. Submit flow is root-only (reported symptom)
Per §2.5: no parent/part inputs in `createOptionForm`. The most-used creation surface
(users submitting events) is the least capable one.

### P3. Dead / inconsistent schema usage
- `isbn` (string 20) and `language` (string 10): **zero references** in `app/` and
  `resources/views/`. No form writes them, no view reads them, factory doesn't set them.
- `url` column: written by admin form? No — admin form's `url` input at
  `ReferenceForm.php:189` is inside the `socialProfiles` repeater (a related model),
  and `SaveReferenceAction` + contribution schema *do* map a top-level `url` onto the
  column — but the **submit flow stores its URL as a `socialProfiles` website row**
  (`Create.php:1278-1283`), leaving the column NULL. Same datum, two storages,
  depending on entry point. Decide one.
- Minor: submit flow casts year to **string** for an integer column (`Create.php:1268`).
  Works via coercion + package validation accepts integer-ish strings, but should be int.

### P4. Parts/editions are indistinguishable in selection surfaces
- Submit `Select` uses `->relationship('references', 'title', ...)` — raw `title`, **not**
  `displayTitle()`. Every part/edition of "Riyadhus Solihin" renders identically in the
  dropdown. Adding parts/editions without fixing the option label multiplies confusion.
- Admin table (`app/Filament/Resources/References/Tables/ReferencesTable.php`) shows
  cover/title/author/type/status/canonical/created — **no parent/part columns**. Parts
  are invisible rows there today; editions would be too.
- Public index `/rujukan` (`resources/views/components/pages/references/⚡index.blade.php`):
  part/edition grouping unverified — implementer must check and handle (see C7).

### P5. Model rules encode "parts", and parts ≠ editions
`normalizeReferencePartFields` + `ensureValidParentReference` enforce: book-only,
root-parent-only, exactly-one-part-entry, never-a-grandchild. An edition axis
("Jilid 2, Cetakan 3") is inherently **two-dimensional** (part × edition) and does not
fit. The edition design must either extend, generalize, or sit alongside this (see C2).

### P6. Family-expansion semantics are parts-shaped
`familyReferenceIds()`, `defaultEventReferenceIds()`,
`expandRootReferenceIdsForFiltering()`, and the API's `include_all_parts` flag all
assume root→parts. With editions, every one needs a re-decision: does selecting a work
include all editions? all parts of all editions? does an event attach to a work, an
edition, a part, or a part-of-an-edition? (See C3.)

### P7. Slug strategy is title-only and opaque under duplication
`GenerateReferenceSlugAction::handle()` slugifies the title; `UniqueSlug::build`
appends `-1`, `-2`, … Family fixtures hand-write meaningful slugs
(`riyadhus-solihin-jilid-2`) but **nothing generates them** — the submit path would
produce `riyadhus-solihin-1`. Editions need deterministic, readable slugs (and a
decision on whether part/edition slugs embed the family).

### P8. Validation asymmetry (500-risk)
Package `validateFields()` throws `InvalidArgumentException` on bad year/isbn/url/
language/JSON. Filament/admin paths mostly validate first, but any new submit-form
fields mapped onto `isbn`/`url`/etc. **must** add matching Filament rules, or user input
produces 500s instead of 422s. Same for model-level `ValidationException` from
`ensureValidParentReference` if parent selection is exposed (that one is 422-safe, but
its messages must surface in the wizard UX).

### P9. Instant-public moderation posture
Submit-created references are `status=pending` + `published_at=now()`, and `pending`
is in `PUBLIC_STATUSES` — i.e. **user-created rows are immediately public** (searchable,
selectable, Scout-indexed via `shouldBeSearchable`). Parts/editions created in the
wizard inherit this. Confirm intentional; editions especially multiply near-duplicate
public rows. Check whether a dedupe/merge workflow exists (none found during
investigation — implementer to verify).

### P10. Enum drift (app vs package)
App `ReferenceType`/`ReferencePartType` are narrower/different from package enums, and
the app model bypasses enum casts (plain strings). Any new edition vocabulary
(`Cetakan`? edition types?) must decide where it lives (app enum, package enum, or
plain validated strings) and whether non-book types can have editions.

---

## 4. Challenges & decisions for the implementer

### C1. Migration ownership (cross-repo)
The create-table migration lives in the **sibling `commerce` repo**
(`../commerce/packages/references/...`, symlinked into `vendor/`). "Edit the migration
directly" therefore means editing another repository's file — coordinate/commit there,
then `composer update`/relink here. Additionally the app migration
`2026_07_25_...` already deployed lifecycle columns (`verified_*`, etc.); folding vs
leaving it is a judgment call, but per constraints: direct-edit, no backfill, assume
fresh DBs everywhere. **Confirm the environment rebuild plan before starting** (local,
staging, production seed strategy).

### C2. THE design decision: what IS an edition? (pick one, no hybrids)
- **Option A — edition as row attributes on a part/work row.** Add columns like
  `edition_number` / `edition_label` (+ reuse `publisher`/`year`/`isbn` per row). A
  "Jilid 2, Cetakan 3" is one row: `parent_id` → work, part=jilid 2, edition=cetakan 3.
  Simplest; keeps 2-level hierarchy; but "all cetakan-3 Jilids" queries are ad-hoc, and
  per-edition covers/descriptions share one row.
- **Option B — edition as hierarchy level (work → edition → part).** Requires relaxing
  the no-grandchildren rule and generalizing `reference_parts` semantics (parts only
  meaningful at depth 2; editions carry publisher/year/isbn). Cleanest bibliographic
  model; largest blast radius (family helpers, expansion, display titles, detail page,
  API flags, tests).
- **Option C — edition as a second part-type axis in `reference_parts`.** Rejected
  unless redesigned: current code assumes exactly one entry and derives the display
  label from it. Only viable if `reference_parts` becomes genuinely multi-entry with
  ordered rendering — effectively a variant of A with JSON instead of columns.
- Whichever is chosen, also decide: can non-books have editions? (Recommend: books
  only, mirroring parts, unless a concrete use case exists.) Can an edition itself have
  a distinct cover gallery? (Schema says yes — media is per-row.)

### C3. Event-link granularity + expansion rules
Decide the matrix and implement consistently across `familyReferenceIds()`,
`defaultEventReferenceIds()`, `expandRootReferenceIdsForFiltering()`, `showReference`
(+ rename/generalize `include_all_parts` — no legacy aliases, so rename outright),
event detail payloads, and any frontend filters:
- Attaching a **work** to an event implies…? (today: family = work+parts)
- Attaching an **edition** implies…? (its parts? other editions? just itself?)
- Attaching a **part** implies…? (today: itself only)
- The submit wizard's multi-select must let users express the intended granularity, and
  option labels (P4) must make it unambiguous.

### C4. Canonical-work semantics
`is_canonical` is currently a per-owner singleton flag with a package-level guard
(single canonical per owner — note: this reads like "one canonical row per tenant",
which is suspicious as a data model for "canonical edition"; verify intent in the
package and either use it as "canonical edition of a work" — needs re-scoping per
family, i.e. package change — or define work-vs-edition canonicality in the app).
Also note `ReferenceFactory` sets `is_canonical` to a **random boolean**, which can
trip `guardSingleCanonical()` in tests — fix the factory while here.

### C5. UX: wizard-safe parent/edition selection
`createOptionForm` is a modal inside step 1. Adding "part of existing book" needs a
searchable parent-book picker (root books, publicly visible) with clear labeling, plus
conditional part fields (mirror `ReferenceForm.php:40-75` visibility/dehydration
rules), plus edition inputs per C2. Keep the modal usable on mobile; the wizard already
preloads the full reference list (`->preload()`), so consider query cost as rows grow
(editions multiply the option set — consider switching to lazy search or grouped
options).

### C6. Display titles, slugs, and URLs
- Extend `displayTitle()` for editions (e.g. `"Title — Cetakan 3 (Penerbit X, 2018)"` —
  exact format TBD) and use it **everywhere a reference is named**: wizard options,
  admin table, detail page, API payloads, Scout `search_text`.
- Slug generation must become family/edition-aware (P7); ensure `slugOrUuidResolver`
  and `SyncSlugRedirectAction` behavior stays sane when slugs change shape.
- Detail-page family block (`show.blade.php:656-678`) needs an edition-aware grouping
  (editions → their parts), not a flat child list.

### C7. Public index + search + Scout parity
- Audit `/rujukan` index (component `pages.references.index`,
  `resources/views/components/pages/references/⚡index.blade.php`) for how parts are
  listed/filtered today; extend to editions (collapse by work? filter by publisher/year?).
- Extend `toSearchableArray()` + database-driver variant + `orWherePartTextLike()`
  analogue for edition fields (publisher/year/edition label/isbn?).
- `Reference::applyPublicVisibility` stays the single gate; no new visibility concept.

### C5b. Contribution / moderation coverage (verify, then extend)
`ReferenceContributionFormSchema` (update flow via `SuggestUpdate`) already handles
parts; check the **create**-via-contribution path (`ContributionSubjectType::Reference`,
staged-create actions) and extend both to editions + isbn/language. No dual keys: note
today's `parent_id` vs `parent_reference_id` split across surfaces (P-surface table in
§2.4) — unify on one key as part of this work (constraints forbid legacy aliases).

### C8. Test + factory overhaul (no legacy expectations)
Existing coverage that WILL need updates (semantics are changing, not just additions):
`tests/Feature/ReferenceFamilyTest.php` (family fixtures/shape),
`tests/Feature/ReferenceFilamentFormTest.php`,
`tests/Feature/ReferenceSavingBootEventsTest.php`,
`tests/Feature/ReferenceShowPageTest.php`, `tests/Feature/ReferenceIndexTest.php`,
`tests/Unit/ReferenceCanonicalFieldsTest.php`,
`tests/Feature/ReferenceAuthorizationTest.php`, `tests/Feature/ReferenceSeederTest.php`,
plus submit-flow tests touching the wizard's reference select. Factory:
`database/factories/ReferenceFactory.php` needs `edition()` state (mirror `part()`),
deterministic `is_canonical` default (C4), and isbn/language fakes. Add parity tests for
wizard-created parts/editions (status/visibility/slug/display-title/expansion).

### C9. Package-vs-app placement
Hierarchy/validation/slug/identity-index behavior that is generic belongs in
`aiarmada/references` (sibling repo); ilmu360-specific policy (book-only, Malay labels,
visibility including `pending`, Scout shape) stays in `app/`. Every package-side change
is a cross-repo commit — batch them.

---

## 5. File inventory (implementer checklist)

**Schema & package (sibling repo `../commerce/packages/references/`):**
- `database/migrations/2000_01_01_000001_create_references_table.php` — direct-edit target
- `src/Models/Reference.php` — casts, `validateFields`, `validateParentHierarchy`,
  `guardSingleCanonical`, cascade delete
- `src/Traits/HasReferenceParts.php`, `src/Enums/ReferenceType.php`,
  `src/Enums/ReferencePartType.php`, `src/Support/ReferenceIdentityIndexes.php`
- `src/ReferencesServiceProvider.php` (`runsMigrations`, `discoversMigrations`)

**App model & actions:**
- `app/Models/Reference.php` — saving hook, part normalization/validation (:602-660),
  family/expansion helpers (:159-198, :424-471), `displayTitle()` (:473-492), scopes,
  Scout payload (:325-395)
- `app/Actions/References/SaveReferenceAction.php` — unify parent key here
- `app/Actions/References/GenerateReferenceSlugAction.php` — family/edition-aware slugs
- `app/Models/EventReferencePivot.php` — `reference_type` default `book` (free string),
  `title`/`notes` overrides available
- `database/factories/ReferenceFactory.php`, `app/Enums/ReferenceType.php`,
  `app/Enums/ReferencePartType.php`

**Creation surfaces (all must reach parity):**
- `app/Livewire/Pages/SubmitEvent/Create.php:1192-1286` — THE gap (root-only)
- `app/Filament/Resources/References/Schemas/ReferenceForm.php:28-90` — reference impl
  of parent/part UX
- `app/Forms/ReferenceContributionFormSchema.php` — contribution path
- `app/Filament/Ahli/Resources/References/ReferenceResource.php` — inherits admin form,
  edit-only (`canCreate=false`)

**Read surfaces:**
- `app/Livewire/Pages/References/Show.php` + `resources/views/livewire/pages/references/show.blade.php:656-678`
- `resources/views/components/pages/references/⚡index.blade.php` (audit!)
- `app/Http/Controllers/Api/Frontend/SearchController.php` (`showReference`, event
  filtering/expansion)
- `app/Filament/Resources/References/Tables/ReferencesTable.php` (add part/edition cols)
- Routes: `routes/web.php:210-215` (`/rujukan`, `/rujukan/{reference:slug}`),
  `routes/web.php:103-105` (`/hantar-majlis`)

**Migrations (app):**
- `database/migrations/2026_07_25_140600_add_verified_by_to_references_and_position_to_affiliations.php`
  (references lifecycle columns — decide fold vs leave)

---

## 6. Suggested acceptance criteria

1. From `/hantar-majlis` step 1 a submitter can (a) attach an existing work/edition/part
   with each option unambiguously labeled, (b) quick-create a new root work, (c)
   quick-create a part under an existing work, (d) quick-create an edition per the C2
   design — with 422-style field errors, never 500s, on bad input.
2. `isbn` and `language` are writable (all creation surfaces), readable (detail page,
   admin table/detail, API payload, search index where sensible), validated (lengths
   per package rules; ISBN format validation if desired — decide), and covered by tests.
3. Exactly one storage for the reference URL (column vs social profile) across all
   surfaces; the other path removed, not deprecated.
4. `displayTitle()` (or its successor) renders work/part/edition unambiguously and is
   used by every selection/display surface (wizard, admin table, detail, API, index).
5. Family/expansion semantics (§C3) implemented identically in model helpers, API,
   filters, and wizard behavior, with tests per branch.
6. Slugs for parts/editions are deterministic and human-meaningful; no bare `-N`
   suffixes for family members created through UI flows.
7. No `parent_reference_id` / `parent_id` duality remains; one key everywhere.
8. Full existing + new reference test files green (`vendor/bin/pest --parallel` on the
   touched areas); PHPStan level 6 clean; Pint applied.
9. Docs updated if a canonical glossary exists (check `docs/` for a references page).

---

## 7. Open questions for the owner (before/at implementation start)

1. C2 choice (A/B/other) — or delegate to implementer judgment?
2. Environment rebuild plan for direct migration edits (esp. production)?
3. Is instant-public (`pending` + `published_at`) for wizard-created rows intended?
   Should editions/parts from the wizard be instantly public too?
4. One URL storage: `references.url` column or `socialProfiles` website row?
5. Should non-book types (article/video) support parts/editions now or stay book-only?
6. ISBN: validate format/checksum, or length-only as today?
