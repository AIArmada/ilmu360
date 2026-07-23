# Architecture Refactor Plan

> **Verified against source** on 2026-07-23. Every claim below was confirmed by reading the actual code, not just line counts.

**Goal:** Remove verified duplication, finish a half-done strategy pattern, and delete dead code — without changing any externally observable behavior.

**Architecture:** Four independent phases, each independently testable and committable. Ordered by confidence (highest first). No phase depends on another.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, Spatie laravel-data

## Global Constraints

- Every phase ends with `vendor/bin/pest --parallel` passing and `vendor/bin/pint --dirty --format agent` clean
- No behavior changes — refactor only. If existing tests are missing, add characterization tests FIRST
- No new dependencies
- Follow existing trait patterns in `app/Actions/Slugs/Concerns/`
- Octane-safe (no statics, no singletons for request state)

---

## Phase 1: Delete Dead Code (5 minutes)

**Files:**
- Delete: `app/Actions/Membership/RevokeSubjectMemberInvitation.php`
- Delete: `app/Actions/Membership/ResolveMemberInvitationByTokenAction.php`
- Check: `tests/` for references to either class

**Evidence:** Both files have **0 production callers** — only referenced in tests and docs. Verified via `rg -l "ClassName" app/ --glob '!**/Actions/**'`.

- [ ] **Step 1: Confirm zero callers**

```bash
rg -n "RevokeSubjectMemberInvitation|ResolveMemberInvitationByTokenAction" app/ --glob '!**/Actions/Membership/**'
```

Expected: no output (or only docs/comments). If any controller, Livewire, or service references these, STOP.

- [ ] **Step 2: Check test references**

```bash
rg -n "RevokeSubjectMemberInvitation|ResolveMemberInvitationByTokenAction" tests/
```

- [ ] **Step 3: Delete both files**

```bash
rm app/Actions/Membership/RevokeSubjectMemberInvitation.php
rm app/Actions/Membership/ResolveMemberInvitationByTokenAction.php
```

- [ ] **Step 4: Remove orphaned test references** (if any tests reference them)

If tests exist that test these classes directly, delete those test cases too — they test dead code.

- [ ] **Step 5: Run tests + lint**

```bash
vendor/bin/pest --parallel --compact
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "refactor: delete dead Membership actions with zero production callers"
```

---

## Phase 2: Extract Slug Generation Traits (2-3 hours)

**Problem:** 5 slug generator Actions share verbatim code: the do/while candidate loop, `nextSequenceFor*` skeleton, and 6 geo-suffix helpers (`slugSegment`, `countryCodeSegment`, `firstFilled`, `uuidValue`, `areaName`, `resolveCountryCode`). The team already extracts shared slug concerns this way (`InteractsWithOrderedSlugModels`, `SyncCanonicalSlugAction`).

**Files:**
- Create: `app/Actions/Slugs/Concerns/BuildsUniqueSlug.php` (~80 lines)
- Create: `app/Actions/Slugs/Concerns/ResolvesLocationSuffix.php` (~120 lines)
- Modify: `app/Actions/Institutions/GenerateInstitutionSlugAction.php` (356 → ~180)
- Modify: `app/Actions/Speakers/GenerateSpeakerSlugAction.php` (240 → ~120)
- Modify: `app/Actions/Venues/GenerateVenueSlugAction.php` (263 → ~130)
- Modify: `app/Actions/References/GenerateReferenceSlugAction.php` (94 → ~40)
- Modify: `app/Actions/Events/GenerateEventSlugAction.php` (333 → ~250 — less benefit, divergent algorithm)

**Net:** ~600 lines removed. One algorithm for uniqueness, one for geo-suffix.

### Task 2.1: Write characterization tests

**Files:**
- Test: Existing tests in `tests/Feature/` that cover slug generation. Find them first.

- [ ] **Step 1: Find existing slug tests**

```bash
rg -l "GenerateEventSlug|GenerateInstitutionSlug|GenerateSpeakerSlug|GenerateVenueSlug|GenerateReferenceSlug" tests/
```

- [ ] **Step 2: If coverage gaps exist, write characterization tests**

For each generator, test: basic slug, fallback word, uniqueness collision (sequence 2, 3), location suffix (where applicable), ignore-self on update. These tests must pass BEFORE and AFTER the refactor.

- [ ] **Step 3: Run tests to confirm green baseline**

```bash
vendor/bin/pest --parallel --filter=Slug --compact
```

Expected: all pass.

### Task 2.2: Extract `ResolvesLocationSuffix` trait

**Files:**
- Create: `app/Actions/Slugs/Concerns/ResolvesLocationSuffix.php`

**Interface (what the trait provides):**
```php
protected function slugSegment(?string $value): string       // empty-string coercion
protected function countryCodeSegment(?string $code): string  // empty-string coercion
protected function firstFilled(string ...$values): ?string    // first non-empty
protected function uuidValue(mixed $value): ?string           // trim-or-null
protected function areaName(?string $areaId): ?string         // lookup address_areas.name
protected function resolveCountryCode(array $address, bool $preferLiteral = false): ?string
protected function buildLocationSuffix(array $segments): string  // dedupe + join with '-'
```

These are **byte-identical** across Institution/Speaker/Venue today. Pick the most complete implementation (Institution's — it has the full `areaName` lookup) and copy it verbatim into the trait.

- [ ] **Step 1: Create the trait file**

Copy the 6 helpers from `GenerateInstitutionSlugAction` into the trait. Add `use App\Models\...` imports as needed.

- [ ] **Step 2: Replace the 3 copies with `use ResolvesLocationSuffix;`**

In `GenerateInstitutionSlugAction`, `GenerateSpeakerSlugAction`, `GenerateVenueSlugAction`: remove the 6 private methods, add `use ResolvesLocationSuffix;` trait.

- [ ] **Step 3: Handle the `resolveCountryCode` divergence**

Venue prefers `country_code` literal; Institution/Speaker prefer `country_id`. The trait's `$preferLiteral` flag handles this. Each Action calls `$this->resolveCountryCode($address, preferLiteral: true/false)`.

- [ ] **Step 4: Run slug tests**

```bash
vendor/bin/pest --parallel --filter=Slug --compact
```

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "refactor: extract ResolvesLocationSuffix trait from 3 slug generators"
```

### Task 2.3: Extract `BuildsUniqueSlug` trait

**Files:**
- Create: `app/Actions/Slugs/Concerns/BuildsUniqueSlug.php`

**Interface:**
```php
/**
 * @param class-string<Model> $modelClass
 * @param string $baseSlug       The slugified name/title
 * @param array $middleSegments  Ordered extra segments (speaker slugs, date suffix)
 * @param string $trailingSuffix Location suffix or ''
 * @param ?string $ignoreId      Exclude self on update
 */
protected function buildUniqueSlug(
    string $modelClass,
    string $baseSlug,
    array $middleSegments = [],
    string $trailingSuffix = '',
    ?string $ignoreId = null,
): string
```

This owns the do/while candidate loop + the `SlugGenerator::exists()` check. Each Action's `handle()` calls it with its composed segments.

- [ ] **Step 1: Create the trait**

Extract the do/while loop (currently byte-identical in Institution `:69-82`, Speaker `:61-74`, Venue `:65-78`). The trait method builds candidate from parts and loops until unique.

- [ ] **Step 2: Simplify Institution, Speaker, Venue, Reference handlers**

Each `handle()` becomes: compute base slug → compute middle segments → compute trailing suffix → call `$this->buildUniqueSlug(...)`.

- [ ] **Step 3: Leave Event mostly alone**

Event's algorithm diverges (date suffix in viewer timezone, speaker segments, create-only sequencing). Only extract what's clean — the basic do/while shape — and let Event override if needed.

- [ ] **Step 4: Run ALL slug tests**

```bash
vendor/bin/pest --parallel --filter=Slug --compact
```

- [ ] **Step 5: Run full test suite** (slug logic touches many models)

```bash
vendor/bin/pest --parallel --compact
```

- [ ] **Step 6: Lint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "refactor: extract BuildsUniqueSlug trait, slim 4 slug generators"
```

---

## Phase 3: Extract Event-Write Helpers (30 minutes)

**Problem:** `SubmitFrontendEventAction` and `SaveAdminEventAction` duplicate 3 small things. They are NOT candidates for consolidation (genuinely distinct orchestrators) — but the duplications are real.

**Files:**
- Modify: `app/Actions/Events/GenerateEventSlugAction.php` (add 1 method)
- Create: `app/Support/Events/OrganizerResolver.php` (~15 lines)
- Modify: `app/Actions/Events/SubmitFrontendEventAction.php` (use helpers)
- Modify: `app/Actions/Events/SaveAdminEventAction.php` (use helpers)

### Task 3.1: Move speaker-slug fallback into GenerateEventSlugAction

**Problem:** Both actions duplicate ~10 lines: "if no speaker segments, fall back to primary organizer if it's a Speaker."

- [ ] **Step 1: Add method to GenerateEventSlugAction**

```php
/**
 * Resolve speaker slug segments, falling back to primary organizer if it's a speaker.
 *
 * @param array $speakerIds
 * @param Institution|Speaker|null $primaryOrganizer
 * @return array
 */
public function speakerSlugSegmentsForState(array $speakerIds, Institution|Speaker|null $primaryOrganizer): array
{
    $segments = $this->speakerSlugSegmentsForSpeakerIds($speakerIds);

    if ($segments === [] && $primaryOrganizer instanceof Speaker) {
        $segments = $this->speakerSlugSegmentsForSpeakerIds([
            (string) $primaryOrganizer->getKey(),
        ]);
    }

    return $segments;
}
```

- [ ] **Step 2: Update SubmitFrontendEventAction** (lines ~136-144)

Replace the inline fallback logic with:
```php
$speakerSlugSegments = $this->generateEventSlugAction->speakerSlugSegmentsForState(
    $this->normalizeEnumList($validated['speakers'] ?? []),
    $primaryOrganizer,
);
```

- [ ] **Step 3: Update SaveAdminEventAction** (lines ~403-415)

Replace the inline fallback with:
```php
$organizer = $this->resolveOrganizer($state['primary_organizer_id'] ?? null);
$speakerSlugSegments = $this->generateEventSlugAction->speakerSlugSegmentsForState(
    $this->normalizeStringArray($state['speakers'] ?? []),
    $organizer,
);
```

- [ ] **Step 4: Run event submission tests**

```bash
vendor/bin/pest --parallel --filter=Event --compact
```

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "refactor: move speaker-slug fallback into GenerateEventSlugAction"
```

### Task 3.2: Extract OrganizerResolver

**Problem:** Both actions duplicate the one-liner `Institution::find($id) ?? Speaker::find($id)`.

- [ ] **Step 1: Create `app/Support/Events/OrganizerResolver.php`**

```php
<?php

namespace App\Support\Events;

use App\Models\Institution;
use App\Models\Speaker;

final class OrganizerResolver
{
    public static function find(?string $id): Institution|Speaker|null
    {
        if ($id === null) {
            return null;
        }

        return Institution::query()->find($id)
            ?? Speaker::query()->find($id);
    }
}
```

- [ ] **Step 2: Replace inline lookups in both actions**

- [ ] **Step 3: Run tests + commit**

```bash
vendor/bin/pest --parallel --filter=Event --compact
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "refactor: extract OrganizerResolver for primary organizer lookup"
```

---

## Phase 4: Finish EventSearchService Strategy Pattern (4-6 hours)

**Problem:** `EventSearchService` (1,783 lines) holds ALL query-building logic for BOTH Postgres and Typesense. The "strategy" is half-done: `App\Contracts\EventDiscoveryAdapter` exists, `PostgresEventDiscovery` and `TypesenseEventDiscovery` exist, but they're **33-line hollow shells** that immediately call back into the service's `@internal` methods. The deletion test passes — delete both adapters and behavior is identical.

**Goal:** Move the implementation INTO the adapters where it belongs. Service shrinks to ~250 lines (engine selection + fallback + cache).

**Files:**
- Modify: `app/Services/EventSearchService.php` (1,783 → ~250)
- Modify: `app/Services/PostgresEventDiscovery.php` (33 → ~600)
- Modify: `app/Services/TypesenseEventDiscovery.php` (33 → ~500)
- Create: `app/Support/EventDiscovery/FuzzyEventMatcher.php` (~190 lines — extracted collaborator)
- Check: `app/Support/EventDiscovery/EventDiscoveryFilterSet.php` (already exists, may need finishing)

### Task 4.1: Write characterization tests for search

- [ ] **Step 1: Find existing search tests**

```bash
rg -l "EventSearchService|EventDiscovery|search.*event" tests/Feature/ --type php
```

- [ ] **Step 2: Identify coverage gaps**

Key paths that MUST have tests before refactor:
- Text search via Typesense (with fallback to Postgres)
- Nearby search (geo radius)
- Filter combinations (category, state, prayer-time, date range)
- Default-search cache hit/miss
- Fuzzy matching (typo tolerance)
- Empty query (browse mode)

- [ ] **Step 3: Fill gaps with characterization tests**

Each test should assert: given input X, the returned event IDs match expected. These tests must pass before AND after refactor.

- [ ] **Step 4: Confirm green baseline**

```bash
vendor/bin/pest --parallel --filter=Search --compact
```

### Task 4.2: Extract FuzzyEventMatcher

**Problem:** ~190 lines of fuzzy-matching logic (similarity scoring, candidate patterns, omission variants, subsequence matching) live inside EventSearchService as private methods. This is a self-contained algorithm with zero service dependencies.

**Files:**
- Create: `app/Support/EventDiscovery/FuzzyEventMatcher.php`

**What moves:**
- `normalizeForSimilarity`
- `applyFuzzyTitleCandidateFilter` / `applyFuzzyTitleCandidateOrdering`
- `fuzzyCandidatePatterns` / `fuzzyCandidatePatternSources` / `fuzzyCandidateOmissionVariants` / `fuzzyCandidateSubsequencePattern`
- `fuzzyCandidateLimit`
- `similarityScore` / `eventSimilarityScore`

- [ ] **Step 1: Create FuzzyEventMatcher class**

Copy the 190 lines verbatim. Make methods public (they were private on the service). Constructor takes no deps.

- [ ] **Step 2: Update EventSearchService to delegate**

Replace all internal calls to these methods with `$this->fuzzyMatcher->method(...)`.

- [ ] **Step 3: Run search tests**

```bash
vendor/bin/pest --parallel --filter=Search --compact
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "refactor: extract FuzzyEventMatcher from EventSearchService"
```

### Task 4.3: Move Postgres query building into PostgresEventDiscovery

**Problem:** `buildDatabaseQuery` (~290 lines), `searchWithDatabase`, `searchNearbyWithDatabase`, `applyDirectSearch`, `applyDatabaseOrdering`, `applyLocationAddressFilter`, time-scope helpers, and filter-normalization helpers all live in the service but belong in the Postgres adapter.

**What moves into `PostgresEventDiscovery`:**
- `buildDatabaseQuery` and ALL its helper methods
- `searchWithDatabase` / `fuzzySearchWithDatabase`
- `searchNearbyWithDatabase` / `searchNearbyWithDatabaseQuery`
- `applyDirectSearch` / `applyDatabaseOrdering`
- `applyLocationAddressFilter` / `applyLocationAddressCriterion`
- `applyAbsoluteTimeRangeFilter`
- All `startsAfter*` / `startsBefore*` / `startsOnLocalDateRange` / `normalizeTimeScope` / `parseDateFilter`
- `startsAtUserTimeSqlExpression` / `userUtcOffsetMinutes`
- All filter-normalization helpers (`normalizeArrayFilter`, `uuidFilterValues`, etc.)
- `databaseDriver` / `databaseLikeOperator` / `requiresDatabaseFiltering`
- `FuzzyEventMatcher` injection (from Task 4.2)

**What STAYS in EventSearchService:**
- `search()` / `searchNearby()` / `searchNearbyWithQuery()` — public entry points
- `performSearch()` — Typesense-then-Postgres fallback router
- `usesDefaultSearchCache()` / `cachedDefaultSearch()` — default cache
- `cardRelationships()` — shared eager-load spec (passed to adapters)
- `postgresDiscovery()` / `typesenseDiscovery()` — adapter factories (stop passing `$this`)

- [ ] **Step 1: Update PostgresEventDiscovery to be self-contained**

Inject `FuzzyEventMatcher` + `EventDiscoveryFilterSet` via constructor. Move all Postgres methods in. The adapter no longer holds a reference to `EventSearchService`.

- [ ] **Step 2: Remove the circular `@internal` bridge methods**

Delete: `searchWithDatabaseCriteria`, `searchNearbyWithDatabaseCriteria`, `searchNearbyWithDatabaseQueryCriteria` from the service. The adapter calls its OWN methods now.

- [ ] **Step 3: Run search tests**

```bash
vendor/bin/pest --parallel --filter=Search --compact
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "refactor: move Postgres query building into PostgresEventDiscovery"
```

### Task 4.4: Move Typesense DSL into TypesenseEventDiscovery

**What moves:**
- `searchWithTypesense` / `searchNearbyWithTypesense` / `searchNearbyWithTypesenseQuery`
- `buildTypesenseFilterParts` (~150 lines of `filter_by` string construction)

- [ ] **Step 1: Move Typesense methods into the adapter**

Same pattern as Task 4.3 — adapter becomes self-contained.

- [ ] **Step 2: Remove remaining `@internal` bridge methods**

Delete: `searchWithTypesenseCriteria`, `searchNearbyWithTypesenseCriteria`, `searchNearbyWithTypesenseQueryCriteria`.

- [ ] **Step 3: Run search tests**

```bash
vendor/bin/pest --parallel --filter=Search --compact
```

- [ ] **Step 4: Verify EventSearchService is now ~250 lines**

```bash
wc -l app/Services/EventSearchService.php
```

Expected: ~200-300 lines (down from 1,783).

- [ ] **Step 5: Run full test suite + lint**

```bash
vendor/bin/pest --parallel --compact
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "refactor: move Typesense DSL into TypesenseEventDiscovery, slim EventSearchService"
```

### Task 4.5: PHPStan check

- [ ] **Step 1: Run PHPStan**

```bash
vendor/bin/phpstan analyse --ansi
```

If new errors appear from the refactor (e.g., the adapter no longer references the service), fix them. Do NOT add baseline suppressions for issues introduced by this refactor.

---

## What This Plan Does NOT Cover (Deferred)

These were identified but are lower-leverage or need more investigation:

- **SearchController query extraction** — the controller bypasses existing search services. Worth doing after Phase 4 (the services will be cleaner to extend). Separate plan.
- **Support/ namespace reorganization** — navigational improvement, no behavior risk. Low urgency.
- **Observer scope cleanup** — `AuditedMediaObserver` (269 lines) mixes audit + cache. Worth a look but not urgent.
- **Enum normalization unification** — SubmitFrontendEventAction and SaveAdminEventAction have diverged `normalizeEnumValue` methods. SaveAdmin's is stricter. Could unify, but only ~30 lines and 2 callers. Marginal.
- **Inline RevokeCurrentApiTokenAction** — 1 caller, 20 lines. Trivial but no urgency.

---

## Risk Matrix

| Phase | Risk | Why | Mitigation |
|-------|------|-----|------------|
| 1. Dead code | Very low | 0 callers verified | Grep confirms |
| 2. Slug traits | Low | Follows existing trait pattern | Characterization tests first |
| 3. Event helpers | Low | 3 small extractions | Existing event tests |
| 4. Search strategy | Medium-High | Touches core search | Characterization tests FIRST, incremental commits per concern |

---

## Execution Order

Phases are independent. Recommended order: **1 → 3 → 2 → 4** (lowest risk first, build confidence).

If short on time: **Phase 1 + Phase 4** deliver the most value. Phase 1 takes 5 minutes; Phase 4 is the highest-leverage refactor (1,783 lines → ~250 in the service, logic properly distributed).
