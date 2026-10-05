# Final Codex review status (2026-10-02, Codex — CURRENT; supersedes "pending" notes below)

**Database readiness update — 2026-10-02:** The user subsequently authorized `php artisan migrate:fresh --seed`. It completed successfully (exit 0), and `/hantar-majlis` now renders the submission form in the browser. This supersedes the historical local-schema blocker and earlier statements that no rebuild had been performed. No live event was submitted.

**Final independent audit — 2026-10-02:** Codex reviewed and accepted the final category validation, catalog fixture setup, and deterministic public-page timings. This supersedes earlier pending-review notes. Verification: 552 application tests (2804 assertions), 84 package tests (267 assertions), and 35 affected public-page tests rerun after the fixture patch (244 assertions); application/package PHPStan and Pint passed. The live browser walkthrough remains blocked by the local database missing the canonical `references.published_at` column. No database rebuild, compatibility fallback, or backfill was performed.

> The contributor resolution note and the review below are retained verbatim as
> historical record. Statements below that Codex final review "remains pending"
> are superseded: Codex independently reviewed the completed refactor and reran
> verification after the old `/tmp` logs disappeared. Final audit DID find and
> repair a category-validation gap (see repair note below); the earlier "no new
> source defects" statement is superseded. Root final approval is pending only
> for these last changes; nothing here claims new final audit approval. The
> live browser walkthrough is BLOCKED on a stale local schema (see limitation
> below) — code/refactor completion is distinct from local browser readiness,
> and nothing here claims a browser pass.

- **Scope checked:** thin pipeline phase ordering with captcha before writes;
  DB transaction/completion/after-commit tracking; per-request authorized form
  context and reactive Get/Set closures; distinct session persistence
  (session-owned relations/media, no parent moderation/admission mutation); App
  root readers excluding occurrence/session rows; generic package write
  scope/owner guards; parent-lock session slug serialization; nullable UTC
  scheduling; canonical original migration edits; no obsolete
  `PersistValidated` callers and no App dependencies in generic new package
  actions.
- **Rerun evidence (durable, `storage/logs/`; no sources changed, no tests
  weakened during verification):** app sweep 547 passed / 2786 assertions /
  167.99s / parallel 4, same documented consolidated filter
  (`hantar-majlis-review-tests.log`); package sweep 84 passed / 267 assertions /
  50.05s / parallel 4, filter `SyncPrimaryEventOccurrenceActionTest|
  EventLifecycleWorkflowTest|EventSubmissionSessionLinkTest|
  EventSessionActionsTest|ScopedSyncActionsTest` — scoped sync coverage beyond
  the earlier 67 (`hantar-majlis-review-package-tests.log`); app PHPStan level 6
  no errors, 1055 files (`hantar-majlis-review-phpstan.log`); package PHPStan
  (`packages/events` incl. database) no errors, 519 files
  (`hantar-majlis-review-package-phpstan.log`); Pint `--test` passed on changed
  PHP in both repos (`hantar-majlis-review-app-pint.log`,
  `hantar-majlis-review-package-pint.log`); `git diff --check` clean in both repos.
- **Material limitation (BLOCKER, not a refactor defect):** browser `GET
  https://ilmu360.test/hantar-majlis` rendered 500 — PostgreSQL undefined
  `references.published_at` via `Reference::active()` in
  `resources/views/components/pages/submit-event/partials/review-preview.blade.php:212`.
  The canonical column already exists in the references package original
  migration (`2000_01_01_000001_create_references_table.php:37`). The local DB
  is stale relative to canonical source schema, beyond the two documented
  events migration edits. No fallback/compat fix and no migrate/reset was run
  (user forbids backwards compatibility/backfill; original-migration edits
  only). Source checks are green on fresh test schemas; the live browser
  walkthrough stays BLOCKED until an authorized fresh/rebuilt schema matches
  current source. No live event submitted.
- **Handoff:** `storage/app/agent-handoffs/hantar-majlis-final-review.md`.
- **Category-validation repair (2026-10-02):** structural UUID validation
  followed by silent catalog minimalization let unknown/wrong-taxonomy/
  inactive IDs become `[]` or silently drop invalid from mixtures.
  `SubmitFrontendEventAction` (public catalog API only, no production cache
  handling) now rejects such selections under exact prefix
  `event_category_ids` with the existing invalid-category string immediately
  after structural validation (before scoped normalization/captcha), then
  minimalizes via `validateTermIds`; valid parent+child remains acceptable
  and normalizes canonically. Fixtures seed the canonical category catalog
  in `beforeEach` before any `Event::factory` call
  (`SubmitEventInputValidationTest`, `SubmitEventSessionIsolationTest`);
  no global seeding, all assertions preserved. Changed only that action and
  those two test setups plus docs; no migration, dependency, database, or
  lang changes; package unchanged. Final proof:
  `storage/logs/hantar-majlis-category-final-tests.log` 552 passed / 2804
  assertions / 192.15s / parallel 4 (same consolidated filter; 547 baseline
  + 5 new action regressions; the narrow 73-test run is superseded
  intermediate evidence);
  `storage/logs/hantar-majlis-category-final-phpstan.log` no errors / 1055
  files; Pint passed on changed source/test; `git diff --check` clean.
  Earlier 547 app logs remain valid except this changed boundary; all
  package 84 checks still valid. Root final approval pending only for these
  last changes.
- **PublicPages deterministic-fixture repair (2026-10-02, Codex
  independent-audit cause):** `PublicPagesTest` had 15 `Event::factory`
  overrides of `starts_at` (`now()->addDay()` / `now()->addDays(2)`)
  with no `ends_at`; the factory's random `ends_at` derives from its
  ORIGINAL start and could precede the overridden start, so the strict
  canonical schedule writer correctly threw — the one intermediate-sweep
  flake. Fixed narrowly in `tests/Feature/PublicPagesTest.php` only:
  every `starts_at` override now pairs a consistent `ends_at` later than
  that SAME start (`addDay()->addHour()`, `addDays(2)->addHour()`); all
  assertions unchanged; existing `EventRoleSeeder` seeding preserved; no
  production, factory, default, migration, dependency, database, or lang
  changes; no cache handling; package unchanged. Proof:
  `storage/logs/hantar-majlis-public-fixture-tests.log` 35 passed / 244
  assertions / 17.28s / parallel 8 (`--filter=PublicPagesTest`
  `tests/Feature`);
  `storage/logs/hantar-majlis-public-fixture-phpstan.log` no errors (app
  level 6); Pint passed on `PublicPagesTest`; `git diff --check` clean.
  The full 552 sweep was NOT rerun (source unchanged); prior 552 green
  evidence is reused and all package 84 checks remain valid. Status per
  root instruction: category repair approved (public catalog API, no
  private cache) and early catalog seeding in the two fixtures approved
  per parent request; ROOT final review of this fixture patch remains
  PENDING until read.

---

# Resolution status (2026-10-02, Muse Spark contributor — SUPERSEDES the findings below)

> The review below is retained verbatim as historical record. Implementation is
> complete; Codex final review remains pending and nothing here claims Codex approval.

- **A1/P1 — fresh-schema-only (user requirement):** two ORIGINAL package
  `create`-migration edits only (nullable `event_session_id` on
  `event_submissions`; `unique(event_id,slug)` on `event_sessions`). No upgrade
  migrations, backfills, or compatibility shims. Existing databases need a
  rebuilt/fresh schema; no migrate/reset was run on the user database.
- **A2/A3 — fixed:** localized description preservation (event map as-is,
  session locale scalar + `metadata.description_localized`) and blank-enum
  defaults/UUID trim covered by `SubmitEventInputValidationTest`.
- **A4 — existing throttling policy retained consciously:** method-level check
  reuses the rate limiter; submitting without a token errors on the captcha
  field. No change.
- **A6 — decomposition/shared helper decision:** `SubmissionValues` shares only
  genuinely identical semantics (`prefixedKey`, replacing 3 copies; `enumValue`
  post-validated reads). The structural validator keeps its blank-to-default
  coercion and session persistence its mixed enum passthrough — intentionally
  different, documented in code.
- **A7 — intentional linkage:** new events link via `event→primaryOccurrence`;
  the submission row keeps `event_occurrence_id`/`event_session_id` null for
  new events and canonical IDs for sessions.
- **A8/A9 — fixed:** prayer enum rejects garbage structurally; duplicate-owner
  checks resolved.
- **P2 — nullable end consumers safe:** explicit null = open-ended sessions;
  `resolveEndsAt` honors omit-vs-midnight; schedule consumers covered by
  `SubmitEventScheduleAtomicityTest`.
- **P4 — roles active + fixtures seeded:** all 7 roles active in local DB;
  `EventRoleSeeder` seeded in the submission suites (incl. `PublicPagesTest`
  guest-submit).
- **P5 — race closed:** parent-lock serialization + DB-collation uniqueness
  check for session slugs (pre-existing package behavior, unchanged).
- **New-event reference gap was an ORIGINAL API bug, not a regression:**
  references now persist once via `SubmissionRelationSync` in both persist
  paths; covered by `EventSubmissionReferencesApiTest`.
- **Cover/poster remain optional** on this form (§B.17 of the flow doc); the
  app-wide media-matrix requirement was not imposed.

Final evidence: app sweep 547 passed/2786 assertions
(`/tmp/ilmu360-submission-app-tests-final.log`); app PHPStan level 6 clean,
1055 files (`/tmp/ilmu360-submission-app-phpstan-final.log`); Pint passed;
`git diff --check` clean; package evidence reused (67 passed, PHPStan clean —
no package changes this continuation). Decomposition: `Create` 3170→759,
`SubmitFrontendEventAction` 858→267, `PersistValidated…` deleted. Handoff:
`/tmp/ilmu360-submission-agentic-handoff.md`.

---

# Handoff: /hantar-majlis submission refactor — review & audit (both repos)

**To:** gpt-6.1-sol
**From:** Muse Code review session (2026-10-02)
**Subject:** Independent review of the `/hantar-majlis` refactor across `ilmu360` (app) and `commerce` (package monorepo)
**Review mode:** read-only. No files edited, no tests executed, no Pint/PHPStan runs. All findings are from static reading of the working-tree diffs.

## 1. Context you need

- Another agent (per `tasks/todo.md`: "Muse CLI `muse-spark-1.3-contributor`, effort `max`") refactored the public event-submission flow. This doc is the independent review of that work.
- Two repos, both dirty (uncommitted working-tree changes):
  - App: `/Users/Saiffil/Herd/ilmu360` — 17 modified files + 2 new src files + 4 new test files.
  - Packages: `/Users/Saiffil/Herd/commerce` — 16 modified files + 4 new src files + 3 new test files.
- Linkage: `ilmu360/vendor/aiarmada/*` are **symlinks** to `commerce/packages/*` (composer `path` repo, `ilmu360/composer.json:185-190`). Both checkouts run as one working system locally.
- The refactor's tracked plan is `ilmu360/tasks/todo.md` under "hantar-majlis submission refactor (2026-10-02)". Its verify + doc-update items are still unchecked — test sweeps and static analysis have reportedly **not** been run yet on this work.
- Prior art, now **stale**: `ilmu360/docs/hantar-majlis-data-flow.md` documents the pre-refactor flow. Do not trust it until the todo's doc-update item is done.
- My earlier proposal (8 refactor items) is only partially what was built: the implementer prioritized security hardening, atomicity, and session isolation over decomposition. That was the right call — several genuine bugs died in the process.

## 2. Overall verdict

Strong work, approve with fixes. The session-isolation rewrite, atomicity rework, and authorization hardening are correct in design and well tested on paper. Exactly one finding blocks deployment (A1: missing column on existing databases). One finding is a probable data-loss bug (A2). Everything else is UX, hygiene, or deployment preconditions.

## 3. App-side (ilmu360) — confirmed bug fixes (no action)

These were verified against `git show HEAD:` versions. Real bugs, now fixed:

1. **Session submits mutated the parent event.** Old `CompleteFrontendEventSubmissionAction` wrote organizer/people/languages/classifications/media to the container and ran the approve/pending block on it (a scoped session submit would `approve()` the parent). New code scopes all relations to the session and returns early from completion.
2. **Slug sync rewrote other events' slugs.** Old `EventKeyPersonSyncService` called `syncEventSlugsForTitle($event->title)` (all events sharing a title). New code calls the pre-existing `syncEventSlug($event)` (this event only). Verified `GenerateEventSlugAction::syncEventSlug` pre-exists (`app/Actions/Events/GenerateEventSlugAction.php:100`).
3. **Bad container IDs silently became new events.** Now fail closed: malformed/deleted → 404, unauthorized → 403 (`Create::selectedEventContainer`), plus action-level re-authorization with `can('update')` (`SubmitFrontendEventAction::authorizedEventContainer`). `EventPolicy` exists and is registered (`AppServiceProvider:408`); `update()` has full rules (`app/Policies/EventPolicy.php:72-95`).
4. **Member auto-approvals verified entities.** Old `ApproveEvent` ran `verifyPendingRelatedRecords` unconditionally (even with null moderator). Now gated on `moderator` having `moderator`/`super_admin` role. Note `Complete` now passes `$submitter` as moderator (was `null`) — signature accepts `?User` (`ModerationService.php:36-40`).
5. **Lax date parsing.** `Carbon::parse` accepted `tomorrow`, `12/31/2026`, and rolled `2026-02-31` forward. Now strict `Y-m-d` + `checkdate` and strict `H:i` (`SubmissionTimingPolicy`).
6. **Tarawih stored as prayer anchor.** Now label-only (`prayerReference = null` for `SelepasTarawih`), matching the project's standing timezone rule.
7. **External effects ran inside transactions.** Completion, `ApproveEvent`, and `SubmitForModeration` now run Scout/notifications/tracking in contained `afterCommit` blocks. Nesting is correct: Laravel fires these only when the outermost submit transaction commits.

## 4. App-side — findings (ordered by severity)

### A1. [P0 — but root-caused in commerce] Every submission writes `event_session_id`; the column exists only on fresh databases
- App writes it unconditionally: `app/Actions/Events/PersistValidatedEventSubmissionAction.php:172-181` (`'event_session_id' => $session?->getKey()`, null for new events).
- Package adds the column by **editing** `create_event_submissions_table` (no upgrade migration; `git status` in commerce confirms zero new migration files). Any migrated database (prod/staging/dev) throws `Unknown column` on submit.
- Tests mask it (fresh migrate). See P1 for the fix discussion. This is the single deployment blocker.

### A2. [Bug] Array descriptions are silently nulled on events but kept on sessions
- Validator explicitly allows string|array: `app/Actions/Events/ValidateEventSubmissionInputAction.php:231-247` ("accepts a string or the canonical localized array").
- API allows any description: `app/Http/Controllers/Api/Frontend/EventSubmissionController.php:56` (`['nullable']`).
- Model casts to array: `app/Models/Event.php:326` (`'description' => 'array'`). Old code stored `$state['description']` raw.
- New event path: `is_string(...) ? ... : null` (`PersistValidatedEventSubmissionAction.php:63-65`) → arrays become `null`. New session path stores `$state['description']` raw (`:135-136`) → arrays survive.
- Fix one way or the other: either normalize arrays through `EventContentNormalizer::normalize()` or reject non-strings at the boundary. Current state is self-contradictory and loses API-submitted data.

### A3. [Leftover] `tests/Feature/SubmitEventInputValidationTest.php` is scaffold, not coverage
- 7 lines: `test('example')` asserting `GET /` → 200. Its name promises validator coverage for a new 360-line action. Write the tests or delete the file.

### A4. [UX] Throttle counts validation failures and blames the captcha field
- `Create::submit()` hits `RateLimiter` before any validation (`Create.php:1790` → `assertSubmissionNotRateLimited`). Five form-error iterations in the wizard = 1-hour IP lockout, with the error attached to `data.captcha_token` (user sees a captcha error for throttling).
- Consider hitting only after captcha passes (or on success) and using a form-level key. The limiter itself is sane (5/hr/IP, shared constant with the API route; separate buckets per channel).

### A5. [Doc] `docs/hantar-majlis-data-flow.md` is stale
- Documents pre-refactor behavior (after-commit completion, silent primary default, null moderator, inline occurrence create). The todo already tracks the rewrite; until then, treat the doc as wrong.

### A6. [Minor] Normalization helpers now exist in 3+ copies
- `enumValue`/`normalizeEnumValue`, `validationKey`/`key`, prefix handling across the validator, `SubmitFrontendEventAction`, `SubmissionTimingPolicy`, `PersistValidatedEventSubmissionAction`. A tiny shared value object would stop the fourth copy. Not blocking.

### A7. [Minor] Asymmetric submission linkage
- Session submits record `event_occurrence_id` + `event_session_id`; new-event submits record neither although exactly one occurrence was just created. Either set the created occurrence id or document that new-event linkage resolves via `event → primaryOccurrence`.

### A8. [Minor] `prayer_time` passes the structural boundary unvalidated
- The validator normalizes it but has no rule for it, so garbage (`true`, `123`) passes the "boundary" and fails later in the timing policy. Harmless in practice (timing runs pre-captcha), but the validator's docblock over-claims.

### A9. [Nit] Duplicate `$user` assignment in `Create::selectedEventContainer`
- `submitterUser()` is called once for the memo key and again for the auth check. Reuse the variable.

## 5. Package-side (commerce) — findings

### P1. [P0] Same as A1: schema changes need an upgrade path
- `event_session_id` column + `unique(event_id, slug)` on sessions were added by editing `create_*` migrations. The todo forbids upgrade migrations, but without one, existing databases break on submit (A1) and never get the uniqueness guarantee the code now assumes.
- Additional wrinkle: production may already hold duplicate `(event_id, slug)` session rows, which would block a future backfill. Check before enforcing.
- Suggested resolution: one upgrade migration adding the nullable column (+ optional unique with a pre-check / backfill), and an explicit decision recorded for why the todo's constraint bends here.

### P2. [Behavior — confirm intended] Null session ends are now open-ended (was +1h)
- `CreateEventSessionAction::resolveEndsAt`: omitted/`''` → +1h default preserved; explicit `null` → `null` (previously also +1h via `blank()`).
- Occurrences via the old app path already stored explicit nulls, so only sessions change. Dashboard is unaffected (always passes strings via `toUtcString`).
- This matches the todo's "optional end times" item, so likely deliberate — but confirm null-`ends_at` consumers (schedule display, "happening now", exports, prayer labels) handle open-ended sessions. I did not audit those consumers.

### P3. [Behavior — likely fine] Occurrence slug default changed
- Old app path copied the full event slug (title + date + person segments). New package default is `Str::slug(title)` (app adapter passes no slug).
- This *aligns* with the dashboard convention (`Schedule::saveOccurrence` never passed a slug either), old rows keep long slugs, lookup is per-event exact match (`PublicScheduleDiscoveryService`), and there is no unique constraint to collide on. Net: shorter URLs for new public submissions, nothing breaks. Noting so the URL change is a conscious choice.

### P4. [Deploy precondition] Re-run `EventRoleSeeder` everywhere
- `SyncEventInvolvementsAction::resolveRoleIds` throws on unknown/inactive roles; old code stored null `event_role_id`. The seeder covers organizer + all six enum cases as active (`ilmu360/database/seeders/AIArmada/EventRoleSeeder.php`), but only if it has run since the enum grew (imam/khatib/bilal). A missing code fails every keyed-people submission.

### P5. [Race] Session-slug uniquification is check-then-insert
- Two concurrent same-title creates can both pass `uniqueSlugInEvent` and then collide on the DB constraint (where it exists) as a raw `QueryException`. Acceptable risk; note it. A catch-and-retry or translation to a validation error would close it fully.

### P6. [Watch] Submission owner-scope guard now always resolves the event
- `EventSubmissionOwnerScope::guardSaving` runs the event-integrity block even when owner scoping is disabled (previously early-returned). Intended hardening, but seeders/suites that save submissions without owner context may newly throw. Watch the package suite.

### P7. [Nits]
- `'live'` hardcoded in `SyncPrimaryEventOccurrenceAction:83` and `DefaultEventLifecycleWorkflow` — no `LIVE` constant exists anywhere, so this matches the codebase; consider adding the constant.
- `foreignUuid` vs `uuid` mixed in the submissions migration (cosmetic; neither creates a real FK).

## 6. Package-side — verified equivalences (the checklist this review closed)

| App-side assumption | Package reality | Status |
|---|---|---|
| Occurrence create copies title/status/visibility/delivery/tz | `CreateEventOccurrenceAction` defaults all from event; `SCHEDULED` default; tz chain attr → occurrence → event → config | ✅ (+ title now trimmed via normalizer — negligible) |
| Explicit-null ends stay open-ended | `resolveEndsAt` in both create actions; sync threads explicit null through | ✅ |
| Lifecycle reschedule path preserved | Extended: delayed/rescheduled included, open-ended supported, tz option, repeat-reschedule without self-transition, live guard | ✅ (superset of old published/postponed behavior) |
| `SyncEventClassificationsAction(scope:)` | First param renamed `event` → `scope`, union type, scoped delete+create; sole caller is the app adapter | ✅ |
| `SyncEventLanguagesAction` signature | `(scope, codes, usageType='primary', metadata=null)`; app call with named `metadata:` matches | ✅ |
| `SyncEventInvolvementsAction` won't wipe organizers | `EventKeyPersonRole` has no organizer case; filter excludes it; package throws if organizer rows arrive without opt-in | ✅ |
| No `App\*` deps in new package code | Grep over all 4 new files: zero matches | ✅ |
| Submission session linkage end-to-end | Model fillable + `session()` relation + migration column + delete-cascade entry + owner-scope graph-integrity check. App model has no own `$fillable`, inherits package's | ✅ |
| Contract changes (`reschedule` nullable ends) | Backward compatible (new optional middle param); `HasEventLifecycle` has no implementers anywhere, so the interface edit is inert (possibly vestigial — consider a follow-up) | ✅ |

## 7. Non-issues (verified so you don't re-chase them)

- `event_category_ids: required` in the new validator matches prior effective behavior (API already required it; old orchestrator validated it). Not a regression.
- Keeping `validationKeyPrefix` was correct — the API controller is a second caller with `''`. (An earlier suggestion to drop it would have been wrong.)
- `SyncEventSlug($event)` and `memberInstitutionQueryForSubmitter` / `personQueryForSubmitter` / `recordOutcome` all pre-exist with compatible signatures. No fatal-error risk from the rewiring.
- `validateTermIds` cannot emit unprefixed validation keys: `minimalTermIds` filters (never throws `ValidationException`; only a `RuntimeException` on taxonomy cycles). The `try/catch` around it in `hasCommunityCategorySelection` is dead-but-harmless defense (pre-existing pattern). Side observation (pre-existing, out of scope): well-formed-but-nonexistent category UUIDs via forged Livewire state are silently dropped to `[]` rather than rejected.
- No existing test asserts the old +2h/+1h default durations (all matches are explicit fixture values), so P2 shouldn't break suites on that axis.
- `UpdateEventSessionAction` has no slug handling — slugs are create-only, so no update path can violate the new unique constraint.
- `Create.php` is still ~3,150 lines and the prayer time-expression stays event-level: conscious defers, not oversights. The narrowed expression delete (event-level only, no longer nuking scoped rows) is still a strict improvement.
- Firing `EventOccurrenceCreated`/`EventOccurrenceUpdated` from the package actions is new behavior on these paths but has zero registered listeners in repo — a dormant seam, not a behavior change today.

## 8. Test coverage read (not executed)

- App: 30 new cases across `SubmitEventSessionIsolationTest` (11), `SubmitEventSubmissionSecurityTest` (10), `SubmitEventCompletionAtomicityTest` (9). Well-targeted: parent-untouched assertions, rollback-with-no-rows-and-no-notifications, throttle, impossible dates, moderator-vs-member verification. No execution, so "green" is unverified.
- Package: `SyncPrimaryEventOccurrenceActionTest` (~17 cases incl. identical-resync no-ops, terminal/live refusals, forged-event guards), `ScopedSyncActionsTest` (~17 cases incl. forged in-memory scopes, organizer preservation, dedupe), `EventSubmissionSessionLinkTest` (~17 cases incl. cross-owner, orphans, cascade), plus additions to lifecycle/session suites. Same caveat: read, not run.
- Gap: the new 360-line `ValidateEventSubmissionInputAction` has no direct tests (and A3's placeholder file pretends otherwise).

## 9. Suggested next actions (for whoever picks this up)

1. Decide P1/A1: write the upgrade migration (recommended) or formally accept fresh-install-only and gate the write. Nothing ships before this.
2. Fix or justify A2 (array descriptions) and A3 (placeholder test).
3. Run the two todo verification items: one affected parallel Pest sweep per repo, scoped Pint, app PHPStan L6 + package analysis. My review is static-only and does not substitute.
4. Confirm P2's null-ends consumers and P4's seeder state in every environment.
5. Refresh `docs/hantar-majlis-data-flow.md` (todo item exists), then delete or supersede this review doc to avoid two competing references.

## 10. Suggested skills (for the agent continuing this work)

- `testing-best-practices` + `pest-testing` — before running or extending the new suites.
- `laravel-best-practices` — the iemand already applied its consistency-first lens; keep it for any follow-up edits.
- `spatie-laravel-php` — the codebase follows Spatie style; use for any fix PRs.
- `plan` (bundled) — if P1/P2 force a design decision, plan before coding.

## 11. File index (reviewed)

App (`/Users/Saiffil/Herd/ilmu360`): `app/Actions/Events/{SubmitFrontendEventAction, PersistValidatedEventSubmissionAction, CompleteFrontendEventSubmissionAction, SyncEventScheduleAction, SyncEventClassificationsAction, ValidateEventSubmissionInputAction}.php`, `app/Support/Submission/{SubmissionTimingPolicy, EntitySubmissionAccess}.php`, `app/Support/Events/OrganizerResolver.php`, `app/Livewire/Pages/SubmitEvent/Create.php` (diff hunks), `app/Http/Controllers/Api/Frontend/EventSubmissionController.php`, `app/Models/{Event, Language, EventSubmission}.php`, `app/Services/EventKeyPersonSyncService.php`, `app/States/EventStatus/Transitions/{ApproveEvent, SubmitForModeration}.php`, `app/Policies/EventPolicy.php`, `app/Data/Events/ValidatedEventSubmission.php`, `tests/Feature/SubmitEvent{SessionIsolation, SubmissionSecurity, CompletionAtomicity, InputValidation}Test.php`.

Package (`/Users/Saiffil/Herd/commerce`): `packages/events/src/Actions/{SyncPrimaryEventOccurrenceAction, SyncEventInvolvementsAction, SyncEventLanguagesAction, CreateEventOccurrenceAction, UpdateEventOccurrenceAction, CreateEventSessionAction, SyncEventClassificationsAction}.php`, `packages/events/src/Support/{EventScopeResolver, EventSubmissionOwnerScope, EventDeleteCascade}.php`, `packages/events/src/{Contracts, Models, Services}/` lifecycle + submission diffs, both migration diffs, `packages/events/docs/04-usage.md` diff, `tests/src/Events/{SyncPrimaryEventOccurrenceActionTest, ScopedSyncActionsTest, EventSubmissionSessionLinkTest}.php` + modified lifecycle/session suites.
