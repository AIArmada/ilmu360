# /hantar-majlis — Data Flow Reference (Event vs Occurrence)

**Database readiness update — 2026-10-02:** The user subsequently authorized `php artisan migrate:fresh --seed`. It completed successfully (exit 0), and `/hantar-majlis` now renders the submission form in the browser. This supersedes the historical local-schema blocker and earlier statements that no rebuild had been performed. No live event was submitted.

**Final independent audit — 2026-10-02:** Codex reviewed and accepted the final category validation, catalog fixture setup, and deterministic public-page timings. This supersedes earlier pending-review notes. Verification: 552 application tests (2804 assertions), 84 package tests (267 assertions), and 35 affected public-page tests rerun after the fixture patch (244 assertions); application/package PHPStan and Pint passed. The live browser walkthrough remains blocked by the local database missing the canonical `references.published_at` column. No database rebuild, compatibility fallback, or backfill was performed.

What exactly happens — and what exactly gets stored — when someone submits the free
public event form at `/hantar-majlis`.

- **Part A** is plain-language (for non-developers).
- **Part B** is the technical record (exact fields and files; no line references —
  line numbers go stale, class and method names do not).
- Both describe the same flow; read either one alone or both together.

---

## Part A — Layman version

### A.1 What this form is

`/hantar-majlis` is the **free public event submission form**. Anyone (logged in or
guest) can propose a religious/community event for listing on ilmu360. Submitting the
form does **not** publish the event — it creates a draft that waits for a moderator to
approve, unless it was submitted from inside an institution's dashboard, in which case
it is published automatically.

The same submission engine also serves the mobile/API clients (`POST /api/v1/submit-event`)
and the institution dashboard (`/dashboard/institusi/tambah-majlis`, same form, scoped
to the institution).

### A.2 The three levels: event, occurrence, session

Think of it like this:

- **Event = the "what".** The poster on the wall: title, description, organizer,
  who can come (men/women/kids), online or physical. It has no date on it.
- **Occurrence = the "when and where it actually happens".** One dated instance:
  "Friday 12 Sept, after Maghrib, at Masjid X". This is the thing that can be
  upcoming, running now, or past. A weekly class is *one event* with *many
  occurrences*; a one-off seminar is *one event* with *one occurrence*.
- **Session = agenda slots inside one instance.** "Slot 1: lecture, Slot 2: Q&A".
  The public form never creates sessions (except in one special case — see A.6).

Every new submission from this form creates **one event + exactly one occurrence**.

### A.3 Why an event can't go live without an occurrence

"Running" is a statement about time. An event with no occurrence has no start or end,
so questions like "is it happening now?", "is it this weekend?", or "sort by date"
have no answer. That is why:

- Discovery listings only show events that have at least one occurrence.
- The approval step refuses to publish an event with zero occurrences
  ("An event must have at least one occurrence before it can be published").

A draft may briefly have no occurrence; a published event always has at least one.

### A.4 What happens when you press submit (plain-language steps)

1. **Abuse checks.** At most 5 submissions per hour per client; the security
   captcha runs last so a form error never burns the single-use token.
2. **Checks.** The system validates everything: a real organizer is picked, the end
   time is after the start time, the date is in the future, Friday-only options are
   only used on Fridays, "after Tarawih" is only used in Ramadhan, community
   events must be physical (not online), and session submissions name an eligible
   date. If anything fails, nothing is saved and the form shows errors.
3. **The event is created** — title, description, organizer, audience, format,
   visibility. No date is stored on it.
4. **The occurrence is created** — the date + start/end time (converted to UTC),
   the timezone it was entered in, and copies of the event's title, visibility, and
   format so schedule listings never need extra lookups.
5. **The extras are attached** — location, speakers/key people, languages,
   categories/tags, catalog references, uploaded cover/poster/gallery images
   (all optional on this form), and an admission record saying "no registration,
   walk-ins welcome".
6. **A submission ticket is filed** — a tracking row recording who submitted, when,
   and their contact details (for guests, kept private), so moderators can review it.
7. **Status is set** — ordinary submissions become **Pending** (waiting for
   moderation, invisible to the public). Submissions made from an institution
   dashboard are **auto-approved and published immediately**.
8. **You see the success page** confirming the submission (and, for sessions, which
   existing event the new slot was added to).

All of steps 3–7 happen atomically: if any of them fails, the whole submission is
rolled back and no partial event is left behind.

### A.5 What "free" and "walk-in" mean here

Neither word is stored anywhere. There is no "free" checkbox and no "free" flag in
the database. An event from this form is free and walk-in **by construction**:

- The form has no ticket or price inputs, so no tickets are ever created.
- The system hardcodes "no registration required, walk-ins allowed".

On the published event page this shows up as a **"Boleh hadir terus"** badge and the
notice *"Tiada tiket diperlukan. Anda boleh hadir terus."* ("No ticket needed. You
may just walk in.") So "free walk-in event" = an event with no tickets and no
registration requirement — a shape of data, not a label.

### A.6 The one exception: adding a slot to an existing event

If the form is opened for an already-existing event (e.g. adding another speaker slot
to a seminar), submitting creates **only a session** under one of that event's
existing dates. No new event and no new occurrence are created, and the parent
event's organizer, people, status, images, and admission settings are left exactly
as they were.

This path always needs a logged-in user who is allowed to edit that event — guests
cannot add sessions. When the event has several dates, the submitter must pick which
date the slot belongs to; dates that are cancelled, completed, or archived never
accept new slots. The success page honestly reports the new slot's own visibility
and never claims it was "auto-approved".

### A.7 Timezones in one paragraph

Malaysia has one timezone, but the system supports many countries. Each occurrence
stores the timezone its time was entered in (e.g. `Asia/Kuala_Lumpur`) next to the
UTC timestamp, so the time always displays correctly. The event also stores a
timezone, which acts as the **default**; the occurrence's own value is the **effective**
one. For this form they are always identical — the separate value only matters for
things like multi-city tours where each stop is in a different timezone.

---

## Part B — Technical version

### B.1 Entry points

| Step | Location |
|---|---|
| Route `GET /hantar-majlis` → Livewire `Create` (also `/dashboard/institusi/tambah-majlis` scoped, `/hantar-majlis/berjaya` success) | `routes/web.php`, `app/Livewire/Pages/SubmitEvent/Create.php` (thin lifecycle: `mount`/`form`/`submit`, per-request guards; fields in `EventSubmissionFormSchema`, scope in `SubmitEventFormContext`, options in `SubmitEventOptionsProvider`, prefill in `SubmitEventPrefill`) |
| API `POST /api/v1/submit-event` (create-only; form contract at `GET /api/v1/forms/submit-event`) | `routes/api.php`, `app/Http/Controllers/Api/Frontend/EventSubmissionController.php` (`store()`) |
| Orchestrating action (validate → derive → transact → return; pipeline over resolver + persist paths) | `app/Actions/Events/SubmitFrontendEventAction.php` (`handle()`) |
| Fail-closed context resolution (scoped institution/container authorization, scoped normalization, organizer/country/target/occurrence resolution) | `app/Support/Submission/SubmissionContextResolver.php` |
| Structural boundary validation + normalization (shapes/enums/UUIDs/booleans/lengths; omitted-key defaults) | `app/Actions/Events/ValidateEventSubmissionInputAction.php` (`handle()`) |
| Transactional writes, new-event path (event/occurrence/submission + satellites) | `app/Actions/Events/PersistNewEventSubmissionAction.php` |
| Transactional writes, session path (session/submission + session-scoped satellites; parent untouched) | `app/Actions/Events/PersistSessionSubmissionAction.php` |
| Shared persist helpers (person/key-people normalization, event/session reference writers) | `app/Support/Submission/SubmissionRelationSync.php` |
| Shared value helpers (prefixed keys, post-validated enum reads) | `app/Support/Submission/SubmissionValues.php` |
| In-transaction completion + post-commit effects | `app/Actions/Events/CompleteFrontendEventSubmissionAction.php` (`handle()` / `handleAfterCommit()`) |
| Schedule/occurrence writer (single writer — also used by admin/dashboard paths) | `app/Actions/Events/SyncEventScheduleAction.php` (`execute()`) |
| Validated DTO between stages | `app/Data/Events/ValidatedEventSubmission.php` |
| Shared timing derivation (strict dates, prayer map, Ramadhan windows) | `app/Support/Submission/SubmissionTimingPolicy.php` |

Both the Livewire form and the API controller funnel through `SubmitFrontendEventAction`;
they differ only in how input arrives (form state vs JSON request) and how media is
persisted (injected `$persistRelationships` callback — see §B.7).

### B.2 Validation order (all before any write)

`SubmitFrontendEventAction::handle()` validates in this order; any failure throws
`ValidationException` and nothing is persisted:

1. **Scoped-institution authorization** — the passed institution is re-resolved from
   storage and current membership is re-checked; a deleted institution, revoked
   membership, non-UUID key, or a `scoped_institution_id` in state that does not
   match the authorized model all fail closed (never silently downgrade to public).
2. **Event-container authorization** — the container is re-resolved, must be a UUID
   that still exists, the submitter must pass the `update` policy, and under an
   institution scope the container's *primary organizer* (not its location) must be
   that institution.
3. **Structural boundary** (`ValidateEventSubmissionInputAction::handle()`) — raw
   shapes are checked before any lossy normalization: required title/category,
   explicit enum values, UUID formats, boolean-likes, string length caps, and the
   description union (a string or a locale→string/null map; numbers, nested
   arrays, and blank/non-string locale keys are rejected). Validation runs on a
   sanitized copy — backed enums become their values, Collections become arrays,
   UUID inputs are trimmed, and blanks on blank-tolerant optional fields become
   null — while the original input is never mutated, so malformed values still
   reach the rules and fail instead of being silently dropped. The action returns
   the normalized state: omitted-key defaults (`physical` format, `public`
   visibility, `all` gender, `[all_ages]` age group, `''` prayer time), explicit
   values preserved, flat-list item errors collapsed to the whole-field key
   (`other_key_people` keeps its row indexes), with optional `data.` prefixing
   for Livewire state.
4. **Normalization** — scoped state coercion (organizer forced to the institution,
   location forced same-as-institution unless explicitly overridden), category-id
   validation, space-id merging.
5. **Conditional requirements** — organizer present; online events skip location rules.
6. **Entity accessibility** — referenced institutions/persons must be usable by this
   submitter (one batched person check, not per-row queries).
7. **Guest contacts** — guests must supply email or phone, format-checked.
8. **Country match** — organizer/location entities must sit in the explicit
   `submission_country_id` (scoped organizer/location are exempt by construction).
9. **Community category ⇒ must be physical format.**
10. **Prayer/date rules** — strict `Y-m-d` parsing (impossible dates and relative
    text rejected, never rolled forward); Friday prayer options ⇒ date must be a
    Friday; `Selepas Tarawih` ⇒ date must fall in Ramadhan.
11. **Timing** — `ends_at` after `starts_at` (when an end is given); `starts_at`
    must be in the future.
12. **Occurrence selection (session path)** — an explicit selection must belong to
    the container and be non-terminal; an omitted selection resolves the single
    eligible default; multi-eligible containers require an explicit pick; a
    terminal-only container errors instead of defaulting to a dead date.
13. **Captcha (Turnstile) verification — always last**, so no field error burns the
    single-use token or its HTTP call. Disabled environments use a null verifier
    that passes.

Abuse control sits outside the action: the shared `event-submission` limiter
(5 attempts/hour per IP) guards the API route via middleware and the Livewire
submit/AI-extraction paths via a method-level check that reuses the same limiter,
so web and API stay aligned. Throttle failures surface on the captcha field for
submits ("Terlalu banyak percubaan…").

### B.3 Timestamp derivation

| Value | Derivation |
|---|---|
| `timezone` | `AddressCountryResolver::timezoneFor(submissionCountryId)` (submitter's *country*, not browser tz), fallback `config('app.timezone')` |
| `starts_at` | `event_date` at start-of-day in submission tz; time set from `custom_time` if custom, else from the static `defaultPrayerTimes()` map; converted to **UTC** for storage |
| `ends_at` | `event_date` + `end_time` in submission tz → UTC; `null` when `end_time` empty (open-ended) |
| Prayer fields | `prayer_time` → `PrayerReference` + default offset minutes + display label (e.g. `Selepas Maghrib`); `TimingMode::PrayerRelative` unless custom time, else `Absolute`. `Selepas Tarawih` keeps its label but stores a **null** anchor (label-only, no prayer reference) |

Note: for prayer-relative options the stored `starts_at` is an approximation from a
static map (e.g. Maghrib → 20:00), not the true prayer time. True resolution is
deferred to `PrayerTimeExpressionResolver` via the time-expression row (§B.6).

### B.4 `events` row (one `INSERT`)

From `PersistNewEventSubmissionAction::handle()` (new-event path; the session path in `PersistSessionSubmissionAction` mirrors the satellite writes with session scope):

| Column | Source |
|---|---|
| `title` | `state['title']` verbatim |
| `slug` | `GenerateEventSlugAction` (title + `event_date` + speaker segments) |
| `description` | Validated localized map stored as-is (empty map → `null`); scalar strings content-normalized; anything else → `null` |
| `timezone` | Resolved submission tz (§B.3) — the **default** tz |
| `institution_id` | Resolved target institution id, nullable |
| `default_venue_id` | Resolved target venue id, nullable |
| `gender` | `state['gender']`, default `all` |
| `age_group` | `state['age_group']`, default `[all_ages]` |
| `children_allowed` | `state['children_allowed']`, **forced `true`** when age group includes children/all-ages, default `true` |
| `is_muslim_only` | `state['is_muslim_only']`, default `false` |
| `delivery_mode` | `state['event_format']`, default `physical` |
| `event_url`, `live_url` | `state`, nullable |
| `visibility` | `state['visibility']`, default `public` |
| `status` | `'pending'` when auto-approvable (scoped institution present), else model default `'draft'` |

Post-create mutations on the same row, same transaction:

| Mutation | Where |
|---|---|
| `schedule_kind = single` | `SyncEventScheduleAction` (via the package writer) |
| `registration_mode = none` + access policy (`registration_required: false`, `walk_in_allowed: true`) — **non-session submissions only** | `CompleteFrontendEventSubmissionAction::handle()` |

Deliberately absent from the event: all date/time/prayer data.

### B.5 `event_occurrences` row (one `INSERT`)

From `SyncEventScheduleAction::execute()` → package `SyncPrimaryEventOccurrenceAction`
(fresh-submit path — no existing occurrence to update):

| Column | Source |
|---|---|
| `event_id` | New event id |
| `title`, `slug` | **Copied** from event (denormalized for schedule queries) |
| `starts_at` | §B.3, UTC |
| `ends_at` | §B.3, UTC or `null` (an explicit null stays open-ended; never falls back to a default duration) |
| `timezone` | Submission tz string — the **effective** tz for this instance |
| `status` | Always `scheduled` |
| `visibility`, `delivery_mode` | **Copied** from event |
| `capacity`, lifecycle timestamps, `pricing_mode`, `registration_mode`, `metadata` | Untouched (`null`) |
| `issue_passes_for_free` | Package default `true` |

Denormalized copies let schedule queries render without joining `events`. Event values
are defaults; occurrence values are effective.

### B.6 `event_time_expressions` row (conditional `updateOrCreate`)

Only when `TimingMode::PrayerRelative` (non-custom prayer option):

| Column | Value |
|---|---|
| `event_id` | New event id |
| `event_occurrence_id`, `event_session_id` | `null` (stored at **event level**) |
| `time_mode` | `prayer_relative` |
| `anchor_type` / `anchor_code` | `prayer` / e.g. `maghrib` (`null` for Tarawih — label-only) |
| `relation` / `offset_minutes` | `before`/`after` + absolute minutes (default offset 5) |
| `display_label` | e.g. `Selepas Maghrib` |
| `resolver_class` | `PrayerTimeExpressionResolver::class` |

Otherwise (custom/absolute time): event-level prayer-anchored expressions for the
event are deleted. Both branches are scoped to the event level only —
occurrence- and session-scoped expressions are never touched. The expression write
shares one transaction with the occurrence write, so a mid-sync expression failure
rolls the occurrence back too; the caller's event model is then refreshed in place
(`schedule_kind` + `primaryOccurrence` relation, no `fresh()` needed).

### B.7 Satellite writes (same transaction)

| Record | Writer |
|---|---|
| `EventLocation` (venue + spaces) | `$event->syncLocation($venueId, $spaceIds)` |
| Primary organizer involvement | `$event->setPrimaryOrganizer(...)` |
| Key people / speakers (deduped; event scope never touches session scope) | `EventKeyPersonSyncService::sync()` |
| Languages | `$event->syncLanguages(...)` (when provided) |
| Classifications (domain/discipline/source/issue + categories) | `SyncEventClassificationsAction` |
| Catalog references (pivot defaults, input-order `sort_order`) | owned once in the persist action for events and sessions alike |
| Media (cover 16:9, poster 3:4, gallery — all **optional** on this form, §B.17) | injected `$persistRelationships` callback: Livewire saves the form's upload components; the API syncs via `FrontendMediaSyncService` |
| `EventSubmission` (`status: pending`, `submitted_at`, `submission_data` incl. submitter name/notes, submitter morph; `target_type`/`target_id` stay null) | `EventSubmission::query()->create(...)` |

References deserve emphasis because they regressed before: the persist action is the
single writer for both entry points, so API-validated references persist exactly
like Livewire ones. Each callback runs at most once per submission (media-only —
never references).

### B.8 Completion: atomic database work vs best-effort after-commit

`CompleteFrontendEventSubmissionAction::handle()` runs **inside** the submission
transaction:

1. Guest submitter contacts (email/phone) stored on the submission as non-public
   contact methods (`is_public: false`, ordered). Authenticated submitters keep no
   guest contacts.
2. Non-session submissions: `registration_mode = none` + access policy
   (`registration_required: false`, `walk_in_allowed: true`).
3. Status: auto-approved (scoped institution) ⇒ `ModerationService::approve()` →
   `Approved` + `published_at`; else if event is `draft` ⇒ transition to `Pending`.
   Session submissions return before steps 2–3: the parent keeps its admission
   defaults and lifecycle state untouched.

`handleAfterCommit()` runs via `DB::afterCommit`, after the transaction commits:

- Share-tracking outcome recorded (`DawahShareOutcomeType::EventSubmission`,
  outcome key `event_submission:submission:{submissionId}`, metadata carries the
  submission id). Failures are caught (`Throwable`), warning-logged with the
  submission id, and **never** poison the already-valid submission.

Failure semantics: anything thrown inside the transaction (including a moderation
failure during auto-approval) rolls the whole graph back — event, occurrence,
session, submission — and the after-commit callback never fires.

### B.9 Status lifecycle for this flow

```text
public/guest submit          institution-dashboard submit
       │                                │
       ▼                                ▼
Event(status: draft)           Event(status: pending)
       │                                │
       ▼                                ▼
  Pending (moderation queue,      Approved + published_at
  invisible in discovery)        (live immediately)
       │
       ▼ (moderator)
  Approved  ──requires ≥1 occurrence──  ApproveEvent throws
  (live)                                 ValidationException otherwise
```

The ≥1-occurrence publish gate lives in the `ApproveEvent` transition. Discovery
scopes independently filter to events with occurrences and order by occurrence
`starts_at`.

Entity verification is a moderator authority: approving an event verifies its
pending related people/places **only** when the approver holds the moderator (or
super-admin) role. An ordinary member's dashboard auto-approval publishes the event
but leaves pending related records pending.

Session submissions schedule immediately (package session status `scheduled`)
without event moderation; the returned `auto_approved` is honestly `false` for
sessions even under an institution scope.

### B.10 Session-submission path

When the form carries an existing event id (`?event={uuid}`, and the same
`event_id` field on the API):

- No new event, no new occurrence, no schedule sync, no location sync.
- Requires an authenticated submitter passing the container's `update` policy —
  guests fail closed (403 on the form, validation error on direct action calls).
- Occurrence targeting: the selected occurrence wins; otherwise the single
  eligible default. Explicit selections must belong to the container
  ("bukan milik majlis ini"); terminal occurrences (cancelled/completed/archived)
  never accept sessions, whether selected explicitly or reached via the default;
  multi-eligible containers require an explicit pick ("Sila pilih jadual majlis
  untuk sesi ini"). A container with no usable occurrence errors instead of
  silently becoming a new event.
- Creates one `EventSession` under that occurrence via the package
  `CreateEventSessionAction` (title/slug/summary/description/starts/ends/timezone/
  visibility/delivery_mode from state + container; slug uniquified within the event).
  Session `summary`/`description` are plain string columns: a localized map resolves
  to the current-locale value, then the fallback-locale value, then the first
  non-blank entry (strings pass through; non-string non-map input yields null),
  while the full original map is preserved in `metadata.description_localized`
  (null when the input is not a non-empty map).
- Session owns its own key people, languages, classifications, references, and
  media; the parent graph (organizer, people, languages, classifications,
  references, media, admission, status) is byte-identical afterwards.
- Canonical linkage on the submission row: `event_session_id` + `event_occurrence_id`
  set, `target_type`/`target_id` null.
- The result reports the **session's** visibility and `auto_approved: false`;
  the success page renders the container title ("This session has been added to
  …") plus the session visibility.

### B.11 "Free" and "walk-in" — implicit shape, not stored flags

- No `is_free` column, no `free`/`percuma` string anywhere in the submit flow
  (component, actions, DTO, or views).
- Freeness = absence of `ticketTypes` (catalog lives on event/occurrence/session
  morphs; only the dashboard advanced flow creates them) + `registration_mode: none`
  + `registration_required: false`.
- Walk-in = `walk_in_allowed: true` (access policy) — set by §B.8; dashboard flows
  derive it as `!registration_required` / `registrationMode === None`.
- Read path: `EventDetailPresenter::allowsWalkIn()` (any scope policy allows walk-in,
  or any scope has explicit `RegistrationMode::None`).
- Display (detail page only): `Boleh hadir terus` badge + `Tiada tiket diperlukan.
  Anda boleh hadir terus.` when walk-in with zero ticket entries.
- Canonical "free walk-in" marker for queries: `!EventTicketingPolicy::requiresTicketSelection()`
  (checks event + occurrence + session levels for active/public types)
  AND `registration_mode === none` AND `accessPolicy?->registration_required !== true`.
  Caveat: this means "zero-friction walk-in"; free-but-RSVP-required events and
  all-zero-price ticket catalogs (rendered `Percuma`) need a price-based check instead.
- Domain catalog: `EventCommerceModes::catalog()` (`free_open` = Free pricing +
  None registration) — see ADR-013.

### B.12 Timezone design note (default vs effective)

`events.timezone` and `event_occurrences.timezone` intentionally coexist:

- Occurrence tz is the **effective** value interpreted alongside its UTC `starts_at`;
  keeping them adjacent prevents event-level edits from silently re-interpreting
  historical times, and supports multi-tz events (one tour, stops in several zones).
- Event tz is the **default**; reads fall back to it when an occurrence/session has
  none set.
- The public form always writes identical values (single known tz) — defaulting, not
  redundancy. `SyncEventScheduleAction` remains the single writer that re-syncs the
  occurrence tz from the event context on updates.

### B.13 Who can submit what (path matrix)

| Path | Guest | Logged-in member | Institution dashboard member |
|---|---|---|---|
| New event via `/hantar-majlis` | Yes (name + email/phone required, contacts private) | Yes | Yes (organizer + location forced to the institution) |
| New event via API | Yes (same contact rules) | Yes (Sanctum) | Yes (`scoped_institution_id`, membership enforced, 403 otherwise) |
| Session via `?event=` / API `event_id` | **No** — container needs the `update` policy (guests fail closed) | Yes, if they can update the event | Yes, if they can update the event **and** the event is organized by that institution |
| Outcome | `draft` → `Pending` | `draft` → `Pending` | `pending` → `Approved` (new events); sessions always `scheduled` + honest `auto_approved: false` |

Contact tests must not claim guest sessions are allowed: the container update check
requires an authenticated user, so session coverage always uses an owner/member
submitter.

### B.14 Authorization revalidation (no trust in the client)

- **Locked context props.** `eventId`, `scopedInstitutionId`, and the other identity
  props on the Livewire component are `#[Locked]` — client-side tampering throws
  instead of applying.
- **Per-request membership.** The scoped institution is re-resolved from storage on
  every request (and again at action entry); a membership revoked after the form
  rendered fails the submit. Memoization is keyed by actor + institution so actor
  swaps never reuse a stale authorization.
- **Container policy.** Both the Livewire component (404 malformed/deleted, 403
  unauthorized) and the action (validation errors) re-resolve the container; the
  location institution never counts as ownership — only the primary-organizer
  involvement establishes institutional scope.
- **Occurrence eligibility** is re-checked at submit time (§B.10), before captcha.

### B.15 Package vs app responsibility

Generic, reusable behavior lives in the events package with no app dependencies:

- Primary-occurrence persistence and lifecycle transitions
  (`SyncPrimaryEventOccurrenceAction`, create/update occurrence and session actions).
- Owner guards, involvement synchronization, language synchronization.

App-owned (editorial, regional, or product-specific) and deliberately kept out of
the package:

- Prayer vocabulary and timing derivation (`EventPrayerTime`, `PrayerReference`,
  `TimingMode`, `SubmissionTimingPolicy`, `PrayerTimeExpressionResolver`).
- Submission-country resolution and timezone defaulting.
- Slug policy (`GenerateEventSlugAction`), captcha verification, throttle policy.
- Public-form orchestration: `SubmitFrontendEventAction` pipeline over
  `SubmissionContextResolver`, `PersistNewEventSubmissionAction` /
  `PersistSessionSubmissionAction`, `CompleteFrontendEventSubmissionAction`,
  schedule adapter (`SyncEventScheduleAction`), classification adapter,
  reference writers (`SubmissionRelationSync`), media callbacks, and shared
  value helpers (`SubmissionValues`).
- Public-form UI: `Create` lifecycle plus `EventSubmissionFormSchema`,
  `SubmitEventFormContext`, `SubmitEventOptionsProvider`, `SubmitEventPrefill`.
- Moderation transitions and the ≥1-occurrence publish gate.

`SyncEventScheduleAction` is the seam: a thin app adapter that maps prayer timing
onto the generic package writer inside one shared transaction.

### B.16 Schema note

Two package **original** `create` migrations changed (edits to the original files,
per the standing original-migration-only policy — never upgrade migrations for
unreleased schema):

- `2000_01_01_000040_create_event_submissions_table.php` (events package) adds a
  nullable indexed `foreignUuid('event_session_id')` for canonical session linkage
  on the submission row (§B.10).
- `2000_01_01_000003_create_event_sessions_table.php` (events package) adds a
  `unique(['event_id', 'slug'])` index backing slug uniquification within the event.

There are no upgrade/alter migrations, no backfills, and no compatibility shims.
No `migrate`/`migrate:fresh`/reset command was run for this change, and none should
be run casually: existing databases predate the new column/index, so a fresh or
rebuilt schema is required before running the new code.

### B.17 Media contract note (optional here, ratios enforced)

The app-wide media matrix lists Event `cover` (16:9) and `poster` (3:4) as required
single-file collections. The public submission form intentionally diverges: cover,
poster, and gallery are all **optional** — a guest proposing a small prayer
gathering must not be blocked for lack of artwork. This is a preserved product
contract, not an oversight.

What is enforced, on both entry points:

- `cover` must be 16:9 (`dimensions:ratio=16/9` + image-editor crop on the form,
  `dimensions:ratio=16/9` rule on the API).
- `poster` must be 3:4 portrait (same enforcement pair).
- `gallery` accepts up to 10 images.
- MIME is restricted to jpg/jpeg/png/webp; uploads inherit the global media-library
  policy (size cap, path generator, immutable cache headers).

### B.18 Redirect and success page

`Create::submit()` flashes `event_title`, `event_slug`, `event_auto_approved`,
`submission_institution_id`, `event_visibility` (+ container id/title for session
submissions) and redirects to `submit-event.success` (`/hantar-majlis/berjaya`).
The success view consumes them to show the title, the "added to …" message for
sessions, a visibility-gated public link (public/unlisted only), and a scoped
"submit another" route. The API instead returns a 201 JSON payload with the event
(id/slug/title/status/visibility), the session (id/title or null), and the
submission (id/`auto_approved`).

---

## File index

| File | Role |
|---|---|
| `routes/web.php` | Routes → Livewire `Create`, success page |
| `routes/api.php` | API submit route + form-contract/catalog endpoints |
| `app/Livewire/Pages/SubmitEvent/Create.php` | Thin lifecycle: mount/submit/guards/extract, `submit()` entry, throttle, locked context, media callback |
| `app/Livewire/Pages/SubmitEvent/EventSubmissionFormSchema.php` | Field definitions, step builders, shared form predicates |
| `app/Data/Events/SubmitEventFormContext.php` | Per-request form scope (scoped institution/container/submitter/occurrence) |
| `app/Support/Submission/SubmitEventOptionsProvider.php` | Stateless option queries (institutions/persons/venues/languages/tags/occurrences) |
| `app/Support/Submission/SubmitEventPrefill.php` | Mount-time container/duplicate/scoped prefill defaults |
| `app/Http/Controllers/Api/Frontend/EventSubmissionController.php` | API validation + media callback via `FrontendMediaSyncService` |
| `app/Actions/Events/SubmitFrontendEventAction.php` | Pipeline: validate + derive + transact + shape result |
| `app/Support/Submission/SubmissionContextResolver.php` | Scoped authorization, scoped normalization, organizer/country/target/occurrence resolution |
| `app/Actions/Events/ValidateEventSubmissionInputAction.php` | Structural validation + normalization (rules/messages/defaults) |
| `app/Actions/Events/PersistNewEventSubmissionAction.php` | New-event transactional writes (event/occurrence/submission + satellites) |
| `app/Actions/Events/PersistSessionSubmissionAction.php` | Session transactional writes (session/submission + session-scoped satellites) |
| `app/Support/Submission/SubmissionRelationSync.php` | Shared person/key-people/reference normalization + writers |
| `app/Support/Submission/SubmissionValues.php` | Shared prefixed keys + post-validated enum reads |
| `app/Actions/Events/SyncEventScheduleAction.php` | Single writer for schedule_kind + occurrence + time expression |
| `app/Actions/Events/CompleteFrontendEventSubmissionAction.php` | In-transaction completion + post-commit share tracking |
| `app/Support/Submission/SubmissionTimingPolicy.php` | Strict date/time derivation, prayer map, Friday/Ramadhan rules |
| `app/Support/Submission/EntitySubmissionAccess.php` | Organizer/person/location accessibility + country matching (+ submission asserts) |
| `app/Support/Submission/SubmitterContactRules.php` | Submitter email/phone rules (+ submission assert) |
| `app/Data/Events/ValidatedEventSubmission.php` | Validated DTO between stages |
| `app/States/EventStatus/Transitions/ApproveEvent.php` | ≥1-occurrence publish gate + moderator entity verification |
| `app/Support/Events/EventDetailPresenter.php` | Read/display logic (`allowsWalkIn`, `scheduleMode`) |
| `app/Support/Events/EventTicketingPolicy.php` | Has-ticketing check (all 3 levels) |
| `app/Support/Commerce/EventCommerceModes.php` | Commerce-mode catalog (ADR-013) |
| `resources/views/components/pages/submit-event/` | Form, review preview, landing, and success views |

## Regression coverage and verification status

Focused regression tests for this refactor (Pest — verified green; evidence below.
Codex final review is complete — see the rerun evidence and material limitation
below; the earlier "pending" note is superseded):

- `tests/Feature/SubmitEventInputValidationTest.php` — structural boundary:
  malformed title/enum/UUID/boolean/tag/key-people/description shapes rejected
  pre-captcha with no writes, title + categories required, enum objects accepted
  with omitted-key defaults, canonical localized descriptions accepted (event map
  stored as-is; session locale scalar + `metadata.description_localized`), blank
  enum defaults, UUID trim parity, Collection normalization, prefixed/unprefixed
  error keys; **added**: semantic category boundary — unknown, wrong-taxonomy,
  inactive, and mixed valid+unknown IDs rejected pre-captcha with no writes
  under exact `event_category_ids` prefix, valid parent+child accepted and
  normalized canonically.
- `tests/Feature/SubmitEventSubmissionSecurityTest.php` — forged/revoked scopes,
  locked props, container policy, location-is-not-ownership, invalid containers,
  submit + AI-extract throttles, malformed dates/times, space eligibility before
  captcha. (No additions needed; already covers its area.)
- `tests/Feature/SubmitEventSessionIsolationTest.php` — parent-untouched linkage,
  selected occurrence, foreign/terminal rejection, member contact honesty, slug
  uniquification, session references, cover ratio; **added**: explicit selection
  required for multi-date events (pre-captcha), terminal-default refusal, single
  eligible default resolution, private session visibility reflection,
  scoped-session honesty (no parent auto-approval).
- `tests/Feature/SubmitEventCompletionAtomicityTest.php` — private guest contacts +
  NONE admission + single UTC occurrence, share-tracking failure containment,
  completion-failure rollback, member-vs-moderator entity verification, person
  dedup, key-person scope isolation, bounded auth queries; **added**: share outcome
  recorded once per committed submission.
- `tests/Feature/SubmitEventScheduleAtomicityTest.php` — **new**: expression-failure
  rollback, occurrence/session expression scopes untouched, passed-model refresh.
- `tests/Feature/Api/Frontend/EventSubmissionReferencesApiTest.php` — **new**:
  API-validated references persist on new events.

Verified evidence (final code, this session):

- App consolidated sweep `/tmp/ilmu360-submission-app-tests-final.log`: **547
  passed, 2786 assertions, EXIT 0** (parallel, 4 processes, ~310s). Filter is
  the root affected set plus adjacent coverage: `SubmitEvent`,
  `EventActionsTest`, `SyncEventClassificationsActionTest`,
  `EventOrganizerInvolvementSyncTest`, `ModerationServiceTest`,
  `ManagedWorkspacesTest`, `PersonSlugGenerationTest`, `UniqueSlugIgnoreKeyTest`,
  `DawahShareImpactTest`, `MediaConversionsTest`, `FrontendApiParityTest`,
  `FormContractApiTest`, `InstitutionWorkspaceMemberApiTest`,
  `ShareAnalyticsApiTest`, `EventSubmissionReferencesApiTest`,
  `FilamentEventResourceTest`, `EventLocationCascadeTest`,
  `ContributionLocationCascadeTest`, `EventTermDomainMappingTest`,
  `PublicPagesTest`. One intermediate run showed 5 white-box failures (moved
  option-provider methods called directly by tests + one missing role seeder);
  call sites were repointed with identical assertions and the sweep re-ran
  green. A later single-run flake in `EventLocationCascadeTest` (factory random
  `ends_at` vs overridden `starts_at`) was fixed test-only by pinning both.
- App PHPStan level 6 `/tmp/ilmu360-submission-app-phpstan-final.log`: **no
  errors, 1055 files, EXIT 0**.
- Pint on changed PHP files: passed; `git diff --check`: clean.
- Events package unchanged by this continuation; reused green evidence:
  `/tmp/ilmu360-events-package-tests-final.log` (67 passed, 222 assertions),
  `/tmp/ilmu360-events-package-phpstan-final.log` (no errors, 396 files).
- Focused baselines along the way: validator+isolation 64 passed, completion 9
  passed, actions-refactor 86 passed, form-wiring 38 passed, mount/submit 20
  passed.

```bash
vendor/bin/pest --parallel --processes=4 --compact tests/Feature --filter='SubmitEvent|EventActionsTest|SyncEventClassificationsActionTest|EventOrganizerInvolvementSyncTest|ModerationServiceTest|ManagedWorkspacesTest|PersonSlugGenerationTest|UniqueSlugIgnoreKeyTest|DawahShareImpactTest|MediaConversionsTest|FrontendApiParityTest|FormContractApiTest|InstitutionWorkspaceMemberApiTest|ShareAnalyticsApiTest|EventSubmissionReferencesApiTest|FilamentEventResourceTest|EventLocationCascadeTest|ContributionLocationCascadeTest|EventTermDomainMappingTest|PublicPagesTest'
vendor/bin/phpstan analyse --ansi --memory-limit=2G
```

Codex final review rerun (current, durable — the `/tmp` logs above have expired;
old counts preserved as historical record). Codex independently checked thin
pipeline phase ordering with captcha before writes, DB
transaction/completion/after-commit tracking, per-request authorized form
context and reactive Get/Set closures, distinct session persistence
(session-owned relations/media, no parent moderation/admission mutation), App
root readers excluding occurrence/session rows, generic package write
scope/owner guards, parent-lock session slug serialization, nullable UTC
scheduling, canonical original migration edits, and no obsolete
`PersistValidated` callers / App dependencies in generic new package actions.
Final audit DID find and repair a category-validation gap (structural UUIDs
then silent minimalization to `[]`); the earlier "no new source defects"
statement is superseded. Historical scope retained; root final approval is
pending only for these last changes:

- `storage/logs/hantar-majlis-review-tests.log`: **547 passed, 2786
  assertions, 167.99s, parallel 4** — same consolidated filter as above.
- `storage/logs/hantar-majlis-review-package-tests.log`: **84 passed, 267
  assertions, 50.05s, parallel 4** — filter
  `SyncPrimaryEventOccurrenceActionTest|EventLifecycleWorkflowTest|
  EventSubmissionSessionLinkTest|EventSessionActionsTest|ScopedSyncActionsTest`
  (scoped sync coverage beyond the earlier 67).
- `storage/logs/hantar-majlis-review-phpstan.log`: **no errors, 1055 files**
  (app level 6).
- `storage/logs/hantar-majlis-review-package-phpstan.log`: **no errors, 519
  files** (`packages/events` path, includes database).
- `storage/logs/hantar-majlis-review-app-pint.log` and
  `storage/logs/hantar-majlis-review-package-pint.log`: Pint `--test` passed
  on changed PHP.
- `git diff --check` clean in both repositories.
- Category repair proof: `storage/logs/hantar-majlis-category-final-tests.log`
  **552 passed, 2804 assertions, 192.15s, parallel 4** — same consolidated
  filter as above (547 baseline + 5 new action regressions in
  `SubmitEventInputValidationTest`; the narrow 73-test run is superseded
  intermediate evidence). Production validation uses the public catalog API
  only (no cache handling); fixtures seed the canonical category catalog in
  `beforeEach` before any `Event::factory` call
  (`SubmitEventInputValidationTest`, `SubmitEventSessionIsolationTest`).
  Changed only that action and those two test setups plus docs; no
  migration, dependency, database, or lang changes; package unchanged.
- `storage/logs/hantar-majlis-category-final-phpstan.log`: **no errors, 1055
  files** (app level 6). Pint passed on changed source/test;
  `git diff --check` clean. Earlier 547 app logs remain valid except this
  changed boundary; all package 84 checks still valid. Final classes:
  `Create` 759 lines unchanged, `SubmitFrontendEventAction` 280 lines
  revised.
- PublicPages deterministic-fixture repair (Muse Spark, 2026-10-02 —
  Codex independent-audit cause): `PublicPagesTest` had 15
  `Event::factory` overrides of `starts_at` (`now()->addDay()` /
  `now()->addDays(2)`) with no `ends_at`; the factory's random `ends_at`
  derives from its ORIGINAL start and could precede the overridden start,
  so the strict canonical schedule writer correctly threw — the one
  intermediate-sweep flake that passed in isolation and in the final 552
  run. Fixed narrowly in `tests/Feature/PublicPagesTest.php` only: every
  `starts_at` override now pairs a consistent `ends_at` later than that
  SAME start (`addDay()->addHour()`, `addDays(2)->addHour()`); all
  assertions unchanged; existing `EventRoleSeeder` seeding preserved; no
  production, factory, default, migration, dependency, database, or lang
  changes; no cache handling; package unchanged. Proof:
  `storage/logs/hantar-majlis-public-fixture-tests.log` **35 passed, 244
  assertions, 17.28s, parallel 8** (`--filter=PublicPagesTest`
  `tests/Feature`); `storage/logs/hantar-majlis-public-fixture-phpstan.log`
  **no errors** (app level 6); Pint passed on `PublicPagesTest`;
  `git diff --check` clean. The full 552 sweep was NOT rerun (source
  unchanged); the prior 552 green evidence is reused and all package 84
  checks remain valid. Status per root instruction: category repair
  approved (public catalog API, no private cache) and early catalog
  seeding in the two fixtures approved per parent request; ROOT final
  review of this fixture patch remains PENDING until read.

## Material limitations

- Prayer-relative `starts_at` values are static-map approximations, not true prayer
  times; exact resolution is deferred to the expression resolver.
- Post-commit share tracking is best-effort by design: a tracking outage is logged
  and the submission stands.
- Throttling is IP-based (5/hour); authenticated users behind shared NAT count
  together.
- The public form's optional cover/poster diverges from the app-wide media matrix
  (§B.17) — preserved deliberately.
- Local browser readiness is BLOCKED on a stale local schema (not a refactor
  defect): browser `GET https://ilmu360.test/hantar-majlis` rendered 500 —
  PostgreSQL undefined `references.published_at` via `Reference::active()` in
  `resources/views/components/pages/submit-event/partials/review-preview.blade.php:212`.
  The canonical column already exists in the references package original
  migration (`2000_01_01_000001_create_references_table.php:37`), so the local
  DB predates canonical source schema — beyond the two documented events
  migration edits (§B.16). No fallback/compat fix and no migrate/reset was run
  (user forbids backwards compatibility/backfill; original-migration edits
  only). Source checks are green on fresh test schemas; the live browser
  walkthrough stays BLOCKED until an authorized fresh/rebuilt schema matches
  current source. Code/refactor completion is distinct from local browser
  readiness — no browser pass is claimed. No live event submitted.
