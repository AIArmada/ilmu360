# Final Codex review status (2026-10-02, Codex — CURRENT; supersedes "pending" notes below)

**Database readiness update — 2026-10-02:** The user subsequently authorized `php artisan migrate:fresh --seed`. It completed successfully (exit 0), and `/hantar-majlis` now renders the submission form in the browser. This supersedes the historical local-schema blocker and earlier statements that no rebuild had been performed. No live event was submitted.

**Final independent audit — 2026-10-02:** Codex reviewed and accepted the final category validation, catalog fixture setup, and deterministic public-page timings. This supersedes earlier pending-review notes. Verification: 552 application tests (2804 assertions), 84 package tests (267 assertions), and 35 affected public-page tests rerun after the fixture patch (244 assertions); application/package PHPStan and Pint passed. The live browser walkthrough remains blocked by the local database missing the canonical `references.published_at` column. No database rebuild, compatibility fallback, or backfill was performed.

> The contributor resolution note and the proposal below are retained verbatim
> as historical record. The statement below that Codex final review "remains
> pending" is superseded: Codex independently reviewed the completed refactor
> (steps 1–4 implementation) and reran verification after the old `/tmp` logs
> disappeared. Final audit DID find and repair a category-validation gap (see
> review doc repair note: public-API semantic validation, catalog seeded in
> fixtures before factories, no production cache handling); the earlier
> "no new source defects" statement is superseded. Root final approval is
> pending only for these last changes; nothing here claims new final audit
> approval. Full scope and rerun evidence are recorded in
> `docs/hantar-majlis-refactor-review.md` and the flow doc; durable logs live
> in `storage/logs/hantar-majlis-review-*.log` plus
> `storage/logs/hantar-majlis-category-final-tests.log` (552 passed / 2804
> assertions, consolidated filter) and
> `storage/logs/hantar-majlis-category-final-phpstan.log` (no errors / 1055
> files). The live browser walkthrough is BLOCKED on a stale local schema
> (missing `references.published_at` in the local DB vs the canonical
> references migration) — code/refactor completion is distinct from local
> browser readiness, and nothing here claims a browser pass. No live event
> submitted. Handoff: `storage/app/agent-handoffs/hantar-majlis-final-review.md`.

- **PublicPages deterministic-fixture repair (2026-10-02, Codex
  independent-audit cause; Muse Spark):** `PublicPagesTest` had 15
  `Event::factory` overrides of `starts_at` with no `ends_at`; the
  factory's random `ends_at` could precede the overridden start and the
  strict canonical schedule writer correctly threw (one
  intermediate-sweep flake). Fixed narrowly in
  `tests/Feature/PublicPagesTest.php` only — every `starts_at` override
  now pairs a consistent `ends_at` later than that SAME start; all
  assertions unchanged; no production, factory, default, migration,
  dependency, database, or lang changes; package unchanged. Proof:
  `storage/logs/hantar-majlis-public-fixture-tests.log` 35 passed / 244
  assertions / 17.28s / parallel 8;
  `storage/logs/hantar-majlis-public-fixture-phpstan.log` no errors (app
  level 6); Pint passed; `git diff --check` clean. Full 552 sweep NOT
  rerun (source unchanged); prior 552 green evidence reused, package 84
  checks valid. Status per root instruction: category repair approved
  (public catalog API, no private cache), early catalog seeding in the
  two fixtures approved per parent request; ROOT final review of this
  fixture patch remains PENDING until read. Browser BLOCKER
  (`references.published_at` stale local schema) unchanged; details in
  the review doc and flow doc.

---

# Resolution status (2026-10-02, Muse Spark contributor — SUPERSEDES the proposal below)

> The proposal below is retained verbatim as historical record. Steps 1–4 are
> implemented and verified; Codex final review remains pending and nothing here
> claims Codex approval.

- **Step 1 (form/schema/options):** `Create` 3170→759 lines. New
  `EventSubmissionFormSchema` (builders + shared static predicates),
  `SubmitEventFormContext` (per-request DTO), `SubmitEventOptionsProvider`
  (stateless option queries), `SubmitEventPrefill` (mount defaults).
  Component retains lifecycle/guards/submit/extract/view API; dead
  `isRamadhan`/`getDefaultPrayerTimes`/`shouldAutoApproveSubmission` deleted,
  not moved. 759 vs the ~700 target is the faithful floor (guards + extract +
  submit must stay per plan).
- **Step 2 (validation pipeline):** `SubmitFrontendEventAction` 858→267 lines,
  same phase order, delegating to `SubmissionContextResolver` (scoped
  auth/normalization/resolution), `EntitySubmissionAccess` asserts (moved in),
  `SubmitterContactRules` assert (moved in); no new package API.
- **Step 3 (distinct persistence):** `PersistNewEventSubmissionAction` +
  `PersistSessionSubmissionAction` with one `SubmissionRelationSync` helper;
  `PersistValidatedEventSubmissionAction` deleted, no legacy wrapper.
- **Step 4 (shared values):** `SubmissionValues` holds only identical
  semantics (`prefixedKey`, `enumValue`); validator/session variants kept
  separate intentionally (see A6 note in the review doc).
- **Step 5 (deferred, untouched):** `CompleteFrontendEventSubmissionAction`
  listeners/prayer-storage redesign — still deferred as proposed.
- **Invariants preserved:** security/atomicity/null-end/UTC/privacy/Signals/
  media/reference-once semantics; `Create` instance never retained by
  collaborators; no dynamic forwarding, facades, or compat shims.

Final evidence: app sweep 547 passed/2786 assertions
(`/tmp/ilmu360-submission-app-tests-final.log`); app PHPStan level 6 clean,
1055 files (`/tmp/ilmu360-submission-app-phpstan-final.log`); Pint passed;
`git diff --check` clean. Handoff:
`/tmp/ilmu360-submission-agentic-handoff.md`.

---

# Proposal: Decomposition follow-up for the /hantar-majlis submission flow

**Status:** proposal (not started)
**Prerequisite:** the security/atomicity refactor currently in the working tree ships first (see `docs/hantar-majlis-refactor-review.md`, items A1–A3 must be resolved).
**Goal:** take the now-correct flow and make it small-unit maintainable, without changing behavior.

## Why this, why now (and why not earlier)

The shipped refactor prioritized correctness (isolation, atomicity, authorization) over
size. That was right: you don't split files while fixing bugs inside them. What remains:

| Unit | Size today | Jobs inside it |
|---|---|---|
| `app/Livewire/Pages/SubmitEvent/Create.php` | ~3,150 lines | Filament schema (~15 field groups), option providers + caching, duplicate/container prefilling, scoped-institution resolution, submit, AI extraction, rate limiting |
| `app/Actions/Events/SubmitFrontendEventAction.php` | ~860 lines | Orchestration + ~15 private assert/resolve/normalize helpers (scope auth, location, contacts, spaces, occurrence selection) |
| `app/Actions/Events/PersistValidatedEventSubmissionAction.php` | ~340 lines | Two flows in one method: new-event and session-into-container |

Each is correct but multi-job: every future change pays reading cost and risks unrelated
behavior. The proposal below splits along seams the refactor already created
(validator, timing policy, package actions), so each step is mechanical.

## Non-goals

- No behavior changes. Every step must be verifiable by the existing suite alone.
- No new package APIs. All splits stay inside the app.
- No splitting `SubmissionTimingPolicy` / `ValidateEventSubmissionInputAction` — they are the right size already.
- Items 5–6 are listed for completeness but explicitly deferred.

## Step 1 — Extract the form schema from `Create.php` (biggest win)

**Target:** `app/Livewire/Pages/SubmitEvent/Create.php` keeps component lifecycle
(`mount`, `submit`, `extractEventFromMedia`, property sync) and delegates schema +
options to collaborators.

**Move out:**

1. `app/Livewire/Pages/SubmitEvent/EventSubmissionFormSchema.php` (new)
   - All `get*Fields()` / step builders (`getScheduleFields`, `getPersonsMediaFields`,
     `buildReviewStep`, and siblings).
   - Pure function of a small context DTO (scoped institution?, event container?,
     country?): `schema(SubmitEventFormContext $ctx): array`. No `$this` access.
2. `app/Support/Submission/SubmitEventOptionsProvider.php` (new, or extend the
   existing access helpers)
   - Option providers + caches: `cachedSubmitTagOptions`, `cachedSubmitVenueOptions`,
     `availableInstitutionOptions`, and siblings. They are already near-pure
     (country-id in, options out).
3. Keep in the component: `mount`, `submit`, `extractEventFromMedia`,
   `selectedEventContainer`, `scopedInstitution`, rate-limit guard, Livewire property
   sync (`updatedData`). Target: under ~700 lines.

**Why this shape:** the schema methods only *read* component state to build arrays;
inverting that (pass a context DTO in) removes the `$this` coupling without changing
a single field definition. Filament component references (`SpatieMediaLibraryFileUpload`
in the media callback) stay in the component — the schema class returns definitions,
never touches the live form.

**Risk:** low. Mechanical move; field definitions byte-identical.
**Verify:** existing `SubmitEvent*` Livewire tests (form-state helpers post to the same
component API, so they cover the new classes without modification).

## Step 2 — Finish emptying `SubmitFrontendEventAction`

The validator + timing policy took ~40% of the old methods. Extract the rest into two
collaborators; `handle()` becomes a readable pipeline (~120 lines):

1. `app/Support/Submission/SubmissionContextResolver.php` (new)
   - `authorizedScopedInstitution`, `authorizedEventContainer`,
     `normalizeScopedInstitutionState`, `resolvePrimaryOrganizer`,
     `resolveTargetLocation`, `resolveSelectedOccurrenceId`, `resolveSubmissionCountryId`.
   - Returns a `SubmissionContext` DTO (scoped institution, container, organizer +
     kind, country, timezone, targets, occurrence id). All the fail-closed guards live
     here, covered by the existing security/session-isolation tests.
2. Fold the remaining asserts into the existing validator where they fit, else keep:
   - `assertSubmissionEntitiesAreAccessible` + `assertSubmissionEntitiesMatchCountry` →
     `EntitySubmissionAccess` (it already owns the underlying queries; the action only
     formats the exceptions).
   - `assertSubmitterContactsAreValid` → `SubmitterContactRules` (same reasoning).
   - `assertSpaceSelectionIsEligible` → keep in the action (thin remap over the
     injected resolver) or move next to the resolver's contract.
   - `normalizeEnumValue` / `validationKey` → delete in favor of one shared helper
     (see Step 4).
3. `handle()` afterwards: resolve context → validate input → timing → captcha →
   transact(persist + complete) → shape result. No private helpers left except
   `hasCommunityCategorySelection` (one domain rule; acceptable).

**Why not one giant validator:** the remaining checks need resolved models (organizer,
container) and request context (captcha, IP throttle), which don't fit the structural
validator's "pure shape check" contract. Context resolution is its own seam.

**Risk:** low-medium. Logic moves across 2–3 files; the `validationKeyPrefix`
threading must be preserved exactly (two callers: Livewire `data.`, API `''`).
**Verify:** `SubmitEventSubmissionSecurityTest`, `SubmitEventSessionIsolationTest`,
plus the API controller tests.

## Step 3 — Split `PersistValidatedEventSubmissionAction` by flow

Today one `handle()` serves two aggregates (new Event+Occurrence vs. Session-only)
behind `if ($submission->sessionSubmission)` branches.

**Target:**

- `PersistNewEventSubmissionAction` — event create, location, schedule, organizer,
  people, languages, classifications, references, media callback, submission row.
- `PersistSessionSubmissionAction` — container re-resolve, occurrence resolve, session
  create, session-scoped syncs, media callback, submission row with linkage.
- Shared private helpers (`normalizePersonIds`, `canonicalKeyPeople`, `enumValue`,
  reference syncs) move to a small `SubmissionRelationSync` support class used by both,
  or stay duplicated if the team prefers_zero shared mutable surface — either is
  defensible; pick one and note it.

**Why:** the two paths share ~15% of their lines and have opposite invariants (new
flow *must* touch the event; session flow *must not*). Readers currently filter half
the method every time, and the session-isolation guarantee from the shipped refactor
is easier to hold when the session path physically cannot reach parent writers.

**Risk:** low. Mostly cut/paste; keep method names and the return shape identical so
`SubmitFrontendEventAction` changes by one `if` at the call site.
**Verify:** `SubmitEventSessionIsolationTest` (parent-untouched assertions) and
`SubmitEventCompletionAtomicityTest`.

## Step 4 — One shared value helper (tiny, do with Step 2 or 3)

`enumValue`/`normalizeEnumValue`, `validationKey`/`key`/`prefixedErrors` now exist in
4 files. Introduce `app/Support/Submission/SubmissionValues.php` with static
`enumValue()`, `enumList()`, `prefixedKey()` and replace all copies.

**Risk:** trivial. **Verify:** full submit suite once.

## Step 5 — [Deferred] Domain event for post-submit reactions

`CompleteFrontendEventSubmissionAction` still bundles share tracking + guest contacts +
access policy + status. The event/listener split (`EventSubmitted` → dedicated
listeners) remains a good idea but touches runtime wiring and queue semantics; do it
only when the next post-submit requirement arrives. Not part of this proposal's scope.

## Step 6 — [Deferred] Occurrence-level prayer time-expression

Still event-level (`event_occurrence_id: null`). Harmless while the public flow only
creates `Single`-kind schedules. Revisit if multi-occurrence public submissions ever
exist. Needs a migration + backfill; explicitly out of scope here.

## Sequencing and verification

1. Steps 1 → 2 → 3 → 4, in that order (each shrinks the file the next step reads).
2. After each step: run the submit-focused suites
   (`tests/Feature/SubmitEvent*Test.php`) plus Pint on touched files. No step should
   require touching a test — if one does, the step changed behavior and must be redone.
3. After Step 4: full app suite + PHPStan L6 once, then close out.
4. Estimated shape: 4 small PRs, each reviewable in one sitting. No step depends on
   the commerce repo.

## Acceptance criteria

- `Create.php` ≤ ~700 lines; orchestrator `handle()` methods read as linear pipelines;
  no method with two-flow `if` branches over different aggregates.
- Zero behavior deltas: the suites from the shipped refactor pass unmodified.
- This doc is deleted or superseded on completion (it must not become a second
  competing reference next to the review doc).
