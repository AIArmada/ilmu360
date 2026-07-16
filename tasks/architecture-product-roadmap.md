# ilmu360° Architecture Execution Backlog

**Status:** implementation-ready handoff
**Created:** 2026-07-16
**Audience:** an executor with limited context and no authority to broaden scope

**Architecture decision record:** [ADR-014 — Product Roadmap Execution Boundaries](../docs/architecture-product-roadmap-adr.md)

## Purpose

This is an ordered set of atomic tickets. Complete one ticket, verify it, commit it,
and stop. Do not combine tickets or start a dependent ticket when its prerequisite
is not merged and green.

The objective is to reduce risk in event discovery, moderation escalation, event
submission, product telemetry, AI cost control, and test feedback without changing
public contracts.

## Non-negotiable rules

- Use codebase-memory graph discovery before code search. Re-index only when the
  project index is stale or missing.
- Preserve all unrelated working-tree changes. Never stage unrelated files.
- Do not add compatibility aliases, dual writes, fallback reads, schema guards, or
  legacy request keys.
- Public API, MCP, Livewire, Filament, and mobile request/response contracts remain
  unchanged unless a ticket explicitly says otherwise.
- Keep prayer, Ramadan, Malaysian geography, moderation vocabulary, and Malay copy
  in `app/`. Do not extract them into a package.
- Use canonical address keys only: `country_id`, `state_id`, `city_id`,
  `admin_area_1_id`, and `admin_area_2_id`.
- Side effects that expose data externally run after the transaction commits.
- Do not run the complete local Pest suite. Run the ticket’s listed focused checks.
- If the current code contradicts a ticket, stop and write the conflict and evidence
  to `tasks/architecture-product-roadmap-blockers.md`. Do not choose a substitute.

## Required delivery protocol

For every ticket:

1. Read this document, `AGENTS.md`, `tasks/todo.md`, and relevant lessons.
2. Trace the named symbol’s callers, tests, and side effects in the graph.
3. Add or adjust the narrowest behavior test before changing behavior.
4. Implement only the ticket’s listed change.
5. Run the listed Pest command, `vendor/bin/phpstan analyse --ansi`, Pint on changed
   files, and `git diff --check`.
6. Record the verification results in the ticket commit message or PR description.
7. Create one commit and stop.

## Fixed architecture decisions

| Concern | Canonical decision |
| --- | --- |
| Discovery input | One immutable app-owned `EventDiscoveryCriteria` object contains normalized search intent. |
| Discovery execution | `PostgresEventDiscovery` and `TypesenseEventDiscovery` consume the same criteria. The facade owns cache, pagination, eager loads, health checks, and backend selection. |
| Unsupported search semantics | The facade selects PostgreSQL. Typesense never silently approximates an unsupported filter. |
| Escalation history | A dedicated append-only `EventEscalation` model and table is the only escalation record. `ModerationReview` is not reused. |
| Escalation deduplication | A unique application-level decision key is persisted before notifications dispatch. One event may have one record per escalation type. |
| Submission input | A readonly `ValidatedEventSubmission` is built after existing validation. Request keys and validation errors do not change. |
| Submission side effects | A named after-commit workflow dispatches notifications, Signals, indexing, and cache invalidation. |
| Product signals | Allowlisted properties only. Raw search text, notes, IP-derived data, referrers, prompts, and upload metadata are omitted by default. |
| AI spend | One budget policy decides allow, deny, defer, or privileged approval before a costed operation starts. |

## Dependency order

`A1 → A2 → B1 → B2 → B3 → C1 → C2 → C3 → D1 → D2 → D3 → E1 → E2 → F1 → F2 → F3`

Run this sequence serially. Do not run two tickets at the same time.

---

## A — Baseline and safety

### A1 — Record the decisions and execution boundaries

**Commit:** `docs(architecture): record canonical execution decisions`

**Change:** Add an ADR linked from this document. Copy the fixed decisions above,
the non-negotiable rules, and these non-goals: no microservices, no package
extraction, no public-contract changes, no compatibility layer, and no broad test
deletion.

**Done when:** The ADR has an owner, date, decisions, consequences, and a link to
this backlog. No source code changes are included.

**Verify:** `git diff --check`.

### A2 — Report Pest file durations in CI

**Commit:** `chore(ci): report Pest file durations by shard`

**Files:** `.github/workflows/quality.yml` and
`scripts/publish_pest_timing_report.php`.

**Change:** Preserve the existing ten-shard selection and parallel/sequential split.
Capture every executed test file’s elapsed time, shard number, PHP version, driver,
and worker count. Publish the slowest files in the job summary and as an artifact.

**Failure rule:** The report must still publish when Pest fails or the job is
cancelled. It must not hide Pest output or change the test-selection command.

**Done when:** A dry-run or workflow syntax check proves the report runs after Pest
with `always()`, and a documented sample shows the required columns.

**Verify:** Workflow syntax/configuration check only; do not change tests.

---

## B — Canonical escalation lifecycle

### B1 — Add append-only escalation persistence

**Commit:** `refactor(moderation): add canonical event escalation records`

**Files:** New `EventEscalation` model, one migration, `Event` relationship, and
`tests/Feature/EventEscalationTest.php`.

**Schema:** UUID primary key; `event_id`; `type`; `decision_key`; nullable `reason`;
nullable `dispatched_at`; nullable `resolved_at`; timestamps. Add a unique index on
`decision_key` and an index on `event_id, type`. Do not add foreign keys, cascades,
or soft deletes.

**Values:** `type` is a string-backed enum with exactly `moderator_sla`,
`super_admin_sla`, `imminent`, and `priority`. `decision_key` is
`{event UUID}:{type}`.

**Behavior:** Creating a record is the sole proof that an escalation decision was
made. `dispatched_at` records notification dispatch. `resolved_at` is set when the
event exits pending status.

**Done when:** The model casts timestamps immutably. Tests prove uniqueness,
per-event type isolation, and no legacy fields are read by the new model.

**Verify:** `vendor/bin/pest --parallel tests/Feature/EventEscalationTest.php`.

### B2 — Make `EscalatePendingEvents` deterministic and idempotent

**Commit:** `fix(moderation): persist escalation decisions before notifications`

**Files:** `app/Jobs/EscalatePendingEvents.php`, its direct notification path, and
`tests/Feature/EventEscalationTest.php`.

**Behavior:** Capture one `now()` at job start. Query pending events in chunks.
Create the applicable `EventEscalation` record before queuing notifications. Treat a
duplicate `decision_key` as already processed.

**Thresholds:**

- `moderator_sla`: pending for at least 48 hours.
- `super_admin_sla`: pending for at least 72 hours and `moderator_sla` was created
  at least 24 hours earlier.
- `imminent`: starts in more than 6 and at most 24 hours.
- `priority`: starts in more than 0 and at most 6 hours.

The `priority` notification goes to moderators and super admins. The other three
types preserve the current recipient groups. Events that are not pending, have
started, or have a resolved matching escalation are excluded.

**Delete:** All reads and writes of `event_attributes.escalated_at`,
`event_attributes.is_priority`, `events.escalated_at`, and `events.is_priority` from
this job.

**Done when:** Re-running the job sends no duplicate notification for any type. The
four threshold boundaries, recipient groups, and exclusions have focused tests.

**Verify:** `vendor/bin/pest --parallel tests/Feature/EventEscalationTest.php`.

### B3 — Resolve and remove lifecycle shadow state

**Commit:** `refactor(moderation): remove legacy escalation state`

**Files:** A one-shot idempotent backfill command, transition hooks, migration, and
escalation tests.

**Backfill:** For each event with legacy escalation metadata, create the matching
record only when its `decision_key` does not exist. Map legacy priority to `priority`;
map all other legacy escalation timestamps to `moderator_sla`. Set `dispatched_at`
from the legacy timestamp when it is parseable, otherwise from the event update time.

**Cutover:** Run the backfill in deployment before the migration that removes legacy
columns. The migration removes `events.is_priority` and `events.escalated_at` only
when they exist. Existing `event_attributes` history stays immutable but is never
read as lifecycle state after cutover.

**Resolution:** Pending-exit transitions set `resolved_at` for unresolved records.

**Done when:** The forbidden-state search below produces no app/test match outside
the backfill migration and historical-data test fixture.

**Verify:**

```bash
vendor/bin/pest --parallel tests/Feature/EventEscalationTest.php tests/Feature/LifecycleHardCutTest.php
rg -n "is_priority|escalated_at" app/ tests/ database/
```

---

## C — Event discovery parity

### C1 — Add normalized discovery criteria without query changes

**Commit:** `refactor(search): add normalized event discovery criteria`

**Files:** New `app/Data/EventDiscoveryCriteria.php`, a factory beside it,
`app/Services/EventSearchService.php`, and the two existing discovery test files.

**Shape:** The readonly object contains text, fuzzy eligibility, UTC start/end
boundaries, sort, canonical geography UUIDs, event filters, relation filters, nearby
coordinates/radius, page, per-page, and `requiresDatabaseFiltering`.

**Normalization:** Preserve current public validation behavior. Empty values become
null. Invalid UUID-like values are discarded before a query is built. Date-only input
uses `UserDateTimeFormatter::parseUserDateToUtc`. No legacy geography or tag key is
accepted.

**Done when:** Equal input produces equal criteria and the existing service produces
identical results with the criteria in place. No backend extraction occurs here.

**Verify:**

```bash
vendor/bin/pest --parallel tests/Feature/EventSearchTest.php tests/Feature/EventSearchTypesenseFilterTest.php
```

### C2 — Extract the two discovery executors

**Commit:** `refactor(search): split database and Typesense discovery execution`

**Files:** `EventSearchService` plus new `PostgresEventDiscovery` and
`TypesenseEventDiscovery` collaborators.

**Ownership:** The service retains cache policy, health check, pagination envelope,
result hydration, card eager loads, and selection. PostgreSQL owns SQL, fuzzy search,
nearby search, and ordering. Typesense owns its filter/query serialization only.

**Selection:** Set `requiresDatabaseFiltering` for a requested filter that Typesense
cannot represent exactly. When set, use PostgreSQL without calling Typesense. When
Typesense is unhealthy, use PostgreSQL. Do not approximate results.

**Invariant:** Every query explicitly constrains public visibility and approved
status, qualifies reused scope columns, and has a deterministic ordering.

**Done when:** Search entry points still call the facade only. Neither executor
contains cache invalidation, notifications, or controller/Livewire logic.

**Verify:** The C1 command plus the graph trace of `EventSearchService::search`.

### C3 — Lock SQL/Typesense contract parity and cache correctness

**Commit:** `test(search): enforce backend parity and facade cache behavior`

**Fixtures:** Add a local discovery scenario builder to the discovery tests. It must
cover visibility, format, canonical locality, relationships, absolute/prayer times,
non-UTC date boundaries, open-ended events, and ended events.

**Parity:** For every filter Typesense supports exactly, compare ordered IDs, total,
and pagination between both executors. For database-only criteria, assert that the
facade selects PostgreSQL. Assert Typesense outage falls back to PostgreSQL.

**Cache:** Cache only the facade result. Cached and uncached ordered IDs and totals
must match. Invalidate on approval, cancellation/unpublish, public event edits, and
index refresh. A cache failure returns uncached correct results.

**Index rule:** Do not add an index in this ticket. Attach `EXPLAIN (ANALYZE,
BUFFERS)` output for representative PostgreSQL scenarios to the PR. A later ticket
may add only an index directly justified by that output.

**Verify:** The C1 command plus focused approval/publication search tests found by
graph tracing before editing them.

---

## D — Submission decomposition

### D1 — Characterize the submission contract and create its command

**Commit:** `refactor(events): introduce validated event submission command`

**Files:** `SubmitFrontendEventAction`, a new readonly `ValidatedEventSubmission`,
and directly affected `SubmitEvent*.php` tests.

**Contract:** Build the command only after current validation and normalization. It
uses canonical geography fields, normalized enum values, and UTC resolved timestamps.
It does not replace request validation or modify error messages.

**Matrix:** Add named behavior coverage for access, location, online/hybrid rules,
prayer/Ramadan/Friday restrictions, timezone/end-time rules, relations, sessions,
auto-approval, moderation, notifications, and registration.

**Done when:** Existing entry points construct the same command without public key or
error-shape changes. Tests name every current user-visible rule once.

**Verify:** Run every directly affected `tests/Feature/SubmitEvent*.php` file in
parallel.

### D2 — Extract pure policy and transactional persistence

**Commit:** `refactor(events): split submission policy and persistence`

**Files:** `SubmitFrontendEventAction`, app-owned access/timing/location policy
collaborators, and one persistence action.

**Policy:** Access, organiser/location resolution, timezone handling, prayer timing,
Ramadan/Friday restrictions, and end-time validation are pure collaborators. They
receive dependencies by constructor; do not call `app()`.

**Persistence:** One transaction creates the event or canonical occurrence/session
and invokes existing relation-sync actions. It returns a typed result with event,
optional session, and submission-record IDs. It never dispatches external side
effects.

**Invariant:** A session cannot exist without its canonical occurrence. Online events
clear institution, venue, and space. Physical location remains the existing strict
institution-or-venue XOR rule.

**Verify:** The D1 command plus event/session and registration-focused tests traced
from the persistence action.

### D3 — Extract after-commit submission workflow and remove duplicates

**Commit:** `refactor(events): move submission side effects after commit`

**Files:** `SubmitFrontendEventAction`, a named after-commit workflow, and focused
submission notification/indexing tests.

**Behavior:** The workflow records sharing, submitter contacts, registration setup,
notifications, Signals, search indexing, cache invalidation, and lifecycle handoff
only after the persistence transaction commits. Failure of one side effect cannot
roll back a valid submission.

**Cutover:** Make `SubmitFrontendEventAction` a coordinator. Delete duplicated inline
helpers after all current entry points use the collaborators.

**Verify:** The D1 command, `tests/Feature/SubmitEventNotificationTest.php`, and
focused lifecycle/index/cache tests found from the after-commit workflow’s callers.

---

## E — Govern product signals

### E1 — Enforce an allowlisted signal schema

**Commit:** `feat(signals): enforce product-signal allowlists`

**Files:** `ProductSignalsService`, its event schema registry, and Signals tests.

**Schema:** Each event declares name, actor type, surface, purpose, allowed property
keys, retention days, and operator access level. Drop unknown properties and omit
sensitive values by default.

**Initial events:** Authentication, search/filter execution, moderation transitions,
notifications, submissions, attendance/check-in, and AI operations.

**Verify:** Focused Signals tests must prove unknown keys and sensitive-looking search
input are absent, while tracking errors remain non-blocking.

### E2 — Build the restricted discovery/moderation scorecard

**Commit:** `feat(insights): add discovery and moderation operator scorecard`

**Behavior:** Aggregate by day and week. Show zero-result filters, supply gaps,
search-to-outcome conversion, median/p90 moderation and publication time, and
imminent pending events. Do not show raw search text by default.

**Access:** Extend the existing `App\\Filament\\Pages\\ProductSignals` admin-panel
page. Do not add a public route, a new panel, or a separate authorization mechanism.
Add tests that unauthorised users cannot access its data.

**Verify:** Focused insight, authorization, and redaction tests.

---

## F — AI budget and test feedback operations

### F1 — Centralize AI budget decisions

**Commit:** `feat(ai): enforce operation budget policy`

**Files:** A small app-owned policy, existing AI usage/cost entry points, and their
focused tests.

**Input:** operation, provider/model, actor/context, estimated cost, actual cost,
and current-period usage. **Output:** exactly `allow`, `privileged_approval`,
`defer`, or `deny`, with a non-sensitive reason code.

**Coverage:** media extraction, cover generation, transcription, embeddings,
reranking, and generation. Check before dispatch. Record actual usage and business
outcome after success. Retries use one invocation key and never double count.

**Verify:** Focused ledger/cost/job fake tests. Denials return a useful user response
and no credentials, prompts, or sensitive context are exposed.

### F2 — Optimize the measured slowest test setup

**Commit:** `test(performance): reduce measured shard setup cost`

**Prerequisite:** Two green CI runs containing A2 duration artifacts.

**Procedure:** Select the single slowest file from the artifacts. Profile that file.
Replace repeated setup only when the identical public scenario appears in two or more
tests. Use a named local scenario builder, not a global catch-all helper.

**Do not:** Delete a test for being slow. Do not change shard selection. Do not set a
duration limit in this ticket.

**Done when:** The PR records before/after duration for that file and preserves every
behavior assertion.

### F3 — Set evidence-based CI duration budgets

**Commit:** `chore(ci): enforce Pest shard duration budgets`

**Prerequisite:** Two further green runs after F2. Use their slower shard duration as
the baseline. Set warning at 125% and failure at 150% of that baseline, rounded up to
the next whole minute.

**Behavior:** A breach publishes slowest-file data and preserves failure logs. It
must not suppress test failures or replace the existing shard matrix.

**Verify:** CI workflow configuration check and a script fixture that covers warning,
failure, and missing-artifact handling.

---

## Required absence checks

Run the applicable checks before closing B, C, or D:

```bash
rg -n "state_area_id|\\bdistrict_id\\b|\\bsubdistrict_id\\b|stateArea\\(|districtArea\\(|subdistrictArea\\(" app/ tests/ database/ resources/
rg -n -- "constrained\\(|cascadeOnDelete\\(" packages/*/database database/
rg -n "softDeletes\\(\\)|SoftDeletes" database/ app/Models/
git diff --check
```

## Final acceptance criteria

- Discovery uses one criteria contract and has exact backend parity where supported.
- Escalations are append-only, idempotent, and free of lifecycle shadow state.
- Submission has one post-validation path with transactional persistence and
  after-commit side effects.
- Signals are allowlisted and useful to operators without retaining raw sensitive data.
- AI spend is decided centrally and observable by outcome.
- CI reports per-file timing and enforces a measured, reproducible budget.
