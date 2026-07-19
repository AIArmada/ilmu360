# ilmu360° Platform — Product + Technical Documentation (Laravel 13, Postgres, Filament v5, Typesense, UUID)

**Document purpose:** Handover document for developers.  
It explains **what the application is**, **why it exists**, **what success looks like**, and then provides the **technical spec** to build it.

> **Status note (Jul 19, 2026):** This document is the original product spec. Some sections (e.g. the Filament resources list in B7) describe the MVP plan rather than the current runtime surface. For the authoritative runtime inventory, see `docs/ilmu360_api_mcp_filament_crud_comparison.md` (currently 61 admin + 19 Ahli runtime resources from app + `aiarmada/*` plugins).

---

# Part A — Product / Vision (Descriptive, non-technical but concrete)

## A1) What is this application?
This application is a **national discovery and publishing platform** for *majlis ilmu* (Islamic religious talks: kuliah, ceramah, tazkirah, forum) — starting in Malaysia.

Right now, majlis ilmu information is **fragmented** across:
- WhatsApp posters and forwards
- Facebook pages
- mosque noticeboards
- word-of-mouth

People miss talks or show up at the wrong time because details are unclear or outdated. Donation links can be inconsistent or even abused.

This platform becomes the **source of truth**: fast discovery, accurate details, trusted donation references, and optional registration.

## A2) Who is it for?
### Primary audiences
1) **Attendees (public users)**
- Find talks by location, date/time, topic, speaker.
- “Near me tonight” discovery.
- Save preferences and get alerts/digests.
- One page for everything: map, Waze, live link, recording, donation, registration.

2) **Institutions (Masjid/Surau admins)**
- Publish and update talks quickly.
- Share a clean event link and/or poster to WhatsApp groups.
- Attach verified donation accounts (QR/bank) safely.

3) **Speakers and their admins**
- Maintain a consistent profile.
- List upcoming talks and attach livestream/recording links.
- Update details with proper authorization.

4) **Moderators (platform ops)**
- Approve submissions, prevent spam, handle reports, keep info accurate.

### Secondary audiences (later)
- NGOs/community groups
- Religious authorities (optional partnerships)
- External sites using embeds/widgets (Phase 3)

## A3) The value proposition
### For attendees
- Find the right talk in seconds.
- Trust the details (approved listings + change controls).
- Save preferences; get notified.
- Navigate easily via Google Maps/Waze.
- Access livestream/recording links from one canonical page.

### For institutions
- Reduce confusion and repeated Q&A (“Ustaz siapa?”, “pukul berapa?”).
- Increase attendance and engagement.
- Donations are standardized under the institution identity.
- Manage multiple admins and event updates.

### For speakers
- Verified credibility.
- Centralized upcoming schedule + archive links.

### For moderators/platform
- Real tools to scale trust: moderation queue, reports, audit trails.

## A4) What does success look like?
### MVP success (first 90–120 days)
- Strong search usage (people return because it works).
- Dozens to hundreds of active institutions posting.
- Low error rate (few “wrong info” reports).
- Majority of traffic comes from **sharing** (WhatsApp) + **search engines** (SEO).

### Long term success
- “If you want majlis ilmu, check ilmu360°” becomes default behavior.
- Institutions prefer posting here because it reduces operational friction and improves attendance.

## A5) Product principles (non-negotiable)
1) **Trust-first:** donation references and event details must resist abuse.
2) **Discovery-first:** search must be fast and intuitive.
3) **WhatsApp-native:** sharing is core; links/posters must be simple.
4) **Moderation-enabled:** allow public submissions but enforce accuracy.
5) **Low friction:** web/PWA works on any phone; no app install requirement for MVP.

## A6) Example user journeys
### Journey 1 — Attendee: “Kuliah Maghrib near me”
1. Opens site on phone
2. Tap “Near me tonight”
3. Sees upcoming talks with distance + time
4. Opens talk page → taps Waze → attends

### Journey 2 — Institution admin posts a weekly program
1. Logs in → Institution dashboard
2. Creates event (MVP) / template (Phase 2)
3. Submit → moderation approves
4. Shares link/poster to WhatsApp groups

### Journey 3 — Public user submits an event
1. Uses “Submit event” form
2. Enters talk details (Phase 3: poster upload)
3. Event goes to moderation queue
4. Approved → published + searchable

### Journey 4 — Private event requiring registration
1. Event marked registration_required + private/unlisted
2. Organizer shares invite link
3. Attendees register
4. Organizer exports attendee list

## A7) MVP feature set (human-readable)
- Search + filters (state, district, speaker, topic, date, language, genre)
- Event page: maps, Waze, live link, recording link, donation, registration
- Save searches + daily/weekly digest email
- Institution dashboard: manage events, admins, donation accounts
- Moderation tools: approve/reject/needs-changes
- Reporting + audit trail

---

# Part B — Technical Specification (Build guide)

## B1) Tech stack
- **Backend:** Laravel 13 (PHP 8.4+)
- **Database:** Postgres
- **Admin:** Filament v5
- **Search:** Typesense via Laravel Scout
- **Queue:** Redis + Horizon
- **Storage:** S3/R2 (QR, posters)
- **IDs:** UUID primary keys end-to-end (domain entities + geography via `aiarmada/addressing`); no integer geography tables
- **AI / MCP:** `laravel/ai`, `laravel/mcp` (Admin + Member MCP servers)
- **Auth:** Fortify + Sanctum + Socialite (Google) + Passport (MCP OAuth)
- **Runtime:** Laravel Octane (long-lived workers)
- **Monitoring:** Sentry

## B2) Architecture
Use a modular monolith:
- Domain modules + service layer
- Policies for authorization
- Jobs for async indexing + notifications
- Optional outbox for reliable search sync

## B3) Database schema overview
Entities:
- Users, Institutions, Venues, Speakers, Events, Series, Topics, Donation Accounts
Workflow:
- Event submissions, moderation reviews, reports, audit logs
User features:
- Saved searches, bookmarks, registrations
Supporting:
- states/districts, media_assets

**Reference migrations**
- Core schema lives under `database/migrations/` (UUID primary keys, no DB-level FK constraints/cascades — integrity enforced in app logic).
- Typesense outbox proposal (never adopted): `ilmu360_schema_uuidv7_typesense.md`.

## B3a) Domain invariants and enums (baseline)
- Event visibility:
  - `public`: listed in search, indexable.
  - `unlisted`: accessible by direct link only, not listed in search.
  - `private`: only visible to authorized users (organizers, moderators).
- Event status:
  - `draft`: not visible to public, no indexing.
  - `pending`: awaiting moderation.
  - `approved`: public and indexable (if visibility allows).
  - `rejected`: not public, show to submitter with reason.
  - `cancelled`/`postponed`: public but clearly marked, not recommended in search results.
- Verification status (institution, speaker, donation account): `unverified`, `pending`, `verified`, `rejected`.
- Donation safety:
  - Donation accounts belong to institutions.
  - Events can only reference donation accounts owned by their institution.
- Geo rules:
  - Event location inherits from venue when present; fall back to institution address.
  - Search requires lat/lng for distance sorting; if missing, fall back to time + relevance only.
- **Event timing modes** (prayer-relative scheduling):
  - `absolute`: Event scheduled at a specific clock time (e.g., 10:00 AM).
  - `prayer_relative`: Event scheduled relative to prayer times (e.g., "selepas Maghrib").
  - Prayer references: `fajr` (Subuh), `dhuhr` (Zohor), `asr` (Asar), `maghrib`, `isha` (Isyak), `friday_prayer` (Jumaat).
  - Prayer offsets: `before_30`, `before_15`, `immediately` (5 min buffer), `after_15`, `after_30`, `after_45`, `after_60`.
  - Display text auto-generated (e.g., "Selepas Maghrib", "30 minit selepas Isyak").
  - `starts_at` is always calculated and stored for search/filtering.
  - Prayer times fetched via Aladhan API using venue coordinates.


## B4) Workflows (technical)
### Event lifecycle
- Create draft (institution members) → submit → `pending`
- Public submission → `pending` (no draft state)
- Moderator → approve/reject/needs-changes
- Approved → searchable (Typesense) + public (if visibility allows)
- Post-approval sensitive change → `pending` + review created
- Cancelled/postponed → stays public but clearly marked and deboosted in search

### Sensitive change gating
Time, venue, donation, visibility, speaker list → status becomes pending + review created.

### Donation safety
Donation accounts belong to institutions. Events reference `donation_account_id` only.

### Search indexing
- Scout/Typesense indexing on approved event updates.
- Optional outbox for reliability and backfills.

### Notifications
- Saved searches send daily/weekly digests via queued job.

## B4a) Moderation workflow and notification rules
### Moderation workflow
- Each moderation action creates a `moderation_reviews` record with `decision`, `reason_code`, and optional `note`.
- Approved events:
  - Set `status=approved`, set `published_at`, enqueue search indexing.
- Needs changes:
  - Keep `status=pending` and mark latest review as `needs_changes`.
  - UI should surface the latest review note and required fields to the submitter.
- Rejected:
  - Set `status=rejected`, de-index from search.
  - Submitter can edit and resubmit; resubmission creates a new review.
- Sensitive change gating (time/venue/donation/visibility/speakers):
  - Set `status=pending` and create a new review.
  - Remove from public search until re-approved (direct link can show "pending update").
- Reports flow:
  - Report starts at `open`, then `triaged`, then `resolved` or `dismissed`.
  - If a report indicates wrong info or abuse, event is set to `pending` or `rejected` based on severity.

### Notification rules
- Channels: in-app + email (MVP). All notifications are queued.
- On submission (public or institution):
  - Notify moderators; include event title, institution, and time.
- On approval:
  - Notify submitter and institution admins; include public link.
- On needs changes:
  - Notify submitter and institution admins; include review note and required edits.
- On rejection:
  - Notify submitter and institution admins; include reason and resubmission guidance.
- On sensitive change gating:
  - Notify moderators and submitter that an approved event is back in review.
- On report resolution:
  - Notify reporter of outcome when resolved or dismissed.
- SLA escalation:
  - Pending > 48 hours: notify moderators.
  - Pending > 72 hours: notify super admin.
- Idempotency:
  - Deduplicate notifications per event + decision to avoid duplicates on retries.

## B4b) Registration workflow (MVP)
- Registration is allowed only when `status=approved` and the event is not `cancelled`/`rejected`.
- If `registration_required=false`, the registration endpoint returns 403.
- Open/close window:
  - If `registration_opens_at` is null, registration opens immediately.
  - If `registration_closes_at` is set and in the past, registration is closed.
- Capacity:
  - If `capacity` is null, registrations are unlimited.
  - If `registrations_count >= capacity`, return 409 conflict.
- Guest registration:
  - Require `name` and one of `email` or `phone`.
  - Dedupe by `email` per event when provided; otherwise by `phone`.
- Cancellation:
  - Update `status=cancelled` and decrement `registrations_count`.
  - Keep record for audit; do not delete rows.

## B4c) Saved search digest workflow (MVP)
- Daily digest: runs 08:00 Asia/Kuala_Lumpur, includes approved public events:
  - `starts_at` within the next 14 days
  - `updated_at` within the last 24 hours
- Weekly digest: runs Monday 08:00 Asia/Kuala_Lumpur, includes approved public events:
  - `starts_at` within the next 30 days
  - `updated_at` within the last 7 days
- One email per user per run, grouped by saved search.
- Dedupe by event ID within the email.
- Cap results to 30 events per email to avoid overload.

## B5) Routes / APIs (REST)

Current mobile-facing API contract lives in `docs/ilmu360_mobile_api_reference.md`.

Public:
- `GET /events` search/list (+ geo)
- `GET /events/{slug}`
- `GET /institutions/{slug}`
- `GET /speakers/{slug}`
- `GET /series/{slug}`

Auth user:
- Saved searches CRUD
- Event saves (bookmarks)
- Register for event
- Create report

Dashboards:
- Institution CRUD + members
- Event create/edit
- Donation accounts create/edit
Moderation:
- Queue + approve/reject
- Reports resolution

## B5a) Search endpoint contract
Request query (public `GET /events`):
- `q`: text query.
- `state_id`, `admin_area_1_id` (district), `admin_area_2_id` (subdistrict): filter by location.
- `topic_ids[]`, `speaker_ids[]`, `institution_id`, `venue_id`.
- `starts_at_from`, `starts_at_to`: time window.
- `lat`, `lng`, `radius_km`: geo search.
- `language`, `genre`, `audience`.
- `visibility=public` is enforced; ignore `private`.
- `sort`: `relevance`, `time`, `distance`.
- `page`, `per_page` (default 1, 20).

Minimal response shape (event list item):
- `id`, `slug`, `title`, `starts_at`, `ends_at`, `timezone`
- `venue_name`, `institution_name`, `state_id`, `admin_area_1_id`, `admin_area_2_id`
- `lat`, `lng`, `distance_km` (when geo query provided)
- `language`, `genre`, `audience`
- `speakers[]` (id, name, slug)
- `topics[]` (id, name, slug)
- `status`, `visibility`

## B5b) API response and validation rules
### Response envelope (JSON)
Success:
```json
{
  "data": {},
  "meta": {
    "request_id": "uuid",
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 120
    }
  }
}
```

Error:
```json
{
  "error": {
    "code": "validation_error",
    "message": "The given data was invalid.",
    "details": {
      "starts_at": ["The starts_at field is required."]
    }
  }
}
```

### Common error codes and HTTP status
- `validation_error` (422)
- `unauthenticated` (401)
- `forbidden` (403)
- `not_found` (404)
- `rate_limited` (429)
- `conflict` (409) - duplicate registration, duplicate save
- `server_error` (500)

### Validation rules (selected endpoints)
Public event submission (`POST /event-submissions`):
- `title`: required, string, max 160.
- `description`: optional, string, max 5000.
- `starts_at`: required, ISO8601.
- `ends_at`: optional, after `starts_at`.
- `timezone`: optional, default `Asia/Kuala_Lumpur`.
- `institution_id`: optional, uuid, exists:institutions,id.
- `venue_id`: optional, uuid, exists:venues,id.
- If `venue_id` is missing: require `venue_name` and at least one of `address_line1` or (`lat` + `lng`) to create a new venue.
- `state_id`, `city_id`, `admin_area_1_id`, `admin_area_2_id`: package addressing FKs.
- `lat`: optional, numeric between -90 and 90.
- `lng`: optional, numeric between -180 and 180.
- `language`, `genre`, `audience`: optional, in allowed list.
- `speaker_ids[]`: optional, array of uuid (existing speakers).
- `topic_ids[]`: optional, array of uuid (existing topics).
- `submitter_name`: required for guests.
- `submitter_contact`: required for guests (email or phone).

Saved searches (`POST /saved-searches`):
- `name`: required, string, max 80.
- `query`: optional, string, max 200.
- `filters`: optional, object (stored as JSON).
- `radius_km`: optional, integer 1..200.
- `lat`, `lng`: required when `radius_km` is provided.
- `notify`: required, in `off|instant|daily|weekly`.

Registrations (`POST /events/{event}/registrations`):
- If unauthenticated: require `name` and one of `email` or `phone`.
- `email`: optional, valid email, unique per event.
- `phone`: optional, E.164 or local format.
- Reject if `event.status` is `cancelled` or `rejected`, or `registration_required=false`.

Reports (`POST /reports`):
- `entity_type`: required, in `event|institution|speaker|donation_account`.
- `entity_id`: required, uuid.
- `category`: required, in `wrong_info|cancelled_not_updated|fake_speaker|inappropriate_content|donation_scam|other`.
- `description`: required when `category=other`, max 2000.

## B6) Authorization
- Global roles: spatie/laravel-permission (`super_admin`, `moderator`)
- Scoped roles: `institution_user`, `speaker_user`
- Laravel Policies enforce all access.

## B6a) Authorization matrix (high level)
- Public:
  - Read public events, institutions, speakers, series.
  - Submit event (with captcha and rate limit).
- Authenticated user:
  - Manage own saved searches, bookmarks, registrations.
  - Report content.
- Institution member:
  - Manage institution profile, venues, donation accounts.
  - Create and edit institution events.
- Speaker member:
  - Manage speaker profile and associated events (where authorized).
- Moderator:
  - Approve/reject/needs-changes, resolve reports, manage trust flags.
- Super admin:
  - Full access, role management, system settings.

## B6b) Trust scoring, auto-approval, and escalation (MVP)
### Trust score model
- Trust score range: 0..100 (stored on institutions and speakers).
- Baselines:
  - `unverified`: 10
  - `pending`: 20
  - `verified`: 70
  - `rejected`: 0
- Manual overrides:
  - Moderators can adjust trust score in increments of 5 with a reason code.
- Automatic adjustments (weekly job):
  - +10 if the last 20 approved events had zero valid reports.
  - -20 if any `donation_scam` report is confirmed.
  - -10 if two or more valid reports occur within 30 days.

### Effective event trust score
- Use the max of:
  - institution trust score
  - highest speaker trust score (if speakers exist)
- If event is submitted by public (no institution), treat trust score as 0.

### Auto-approval rules
- Public submissions: never auto-approve.
- Institution submissions:
  - Auto-approve if institution is `verified` and effective trust score >= 80.
  - Event must start at least 24 hours in the future.
  - Content must be "non-sensitive" change only (title/description/topics/media links).
- Sensitive changes (time/venue/donation/visibility/speakers):
  - Always require moderation review, regardless of trust score.
- Anti-duplication:
  - If event title + starts_at + venue matches an existing approved event within 7 days, auto-approval is blocked.

### Escalation thresholds
- High-risk report categories (`donation_scam`, `fake_speaker`):
  - Immediately set event to `pending` and remove from search.
  - Notify moderators and super admin.
- Two unique reports within 24 hours:
  - Set to `pending` and flag in moderation queue.
- Event starts within 6 hours and is still `pending`:
  - Mark as priority and notify moderators.

## B7) Filament admin panel plan (original MVP plan)
The original MVP plan listed these resources:
- InstitutionResource
- VenueResource
- SpeakerResource
- EventResource
- DonationAccountResource (now `donation-channels`)
- TopicResource (now unified under `tags` with `TagType` enum)
- ReportResource

Custom Pages:
- ModerationQueuePage (pending events + actions)

**Current runtime inventory (Jul 19, 2026):** 61 admin-panel resources + 19 Ahli-panel resources are registered at runtime, combining app-owned resources with `aiarmada/filament-*` plugin resources (events, engagement, communications, inventory, seating, signals, ticketing, authz, addressing, contacting, references). See `docs/ilmu360_api_mcp_filament_crud_comparison.md` for the canonical inventory and the parity test at `tests/Unit/CrudComparisonDocsTest.php`.

## B7a) Moderation UI requirements (MVP)
- Queue views:
  - Tabs: `pending`, `needs_changes`, `reports`, `high_risk`, `recently_rejected`.
  - Filters: state, district, institution, speaker, date range, source, trust score, report category.
- Event review panel:
  - Side-by-side diff: current approved version vs. submitted changes.
  - Trust indicators: institution verification, trust score, report history.
  - Context: previous reviews, prior approvals/rejections, and submitter history.
- Actions:
  - Approve, reject, needs changes, and request clarification.
  - Reason code selector with templates for common issues.
  - Optional moderator note (required on reject and needs changes).
- Safety tooling:
  - Quick "remove from search" toggle.
  - Flag donation account for verification review.
  - Duplicate detection hints (title + time + venue matches).
- SLA visibility:
  - Show time in queue and escalation countdown.
- Auditability:
  - All actions recorded to audit_logs with actor and before/after payloads.

## B8) Typesense design
Collection: `events`

Suggested fields:
- identifiers: id, slug
- text: title, description, speaker_names, institution_name, venue_name
- filters: state_id, admin_area_1_id, admin_area_2_id, language, genre, audience, topic_ids, speaker_ids, status, visibility
- time: starts_at, ends_at
- geo: lat, lng
- signals: saves_count, registrations_count, trust_score (optional ranking)

## B8a) Typesense indexing and ranking
- Index only `approved` + `public` events.
- De-index events that become `private`, `unlisted`, `rejected`, or deleted.
- Ranking:
  - Primary: relevance to query text.
  - Boost: upcoming events within 30 days.
  - Secondary: trust_score, saves_count, registrations_count.
- Facets: state_id, admin_area_1_id, admin_area_2_id, topic_ids, speaker_ids, language, genre, audience.
- Near me: geo sort when lat/lng provided, fallback to starts_at.

## B9) Ops requirements
- Horizon for queues
- Rate limiting + captcha on public submit
- Backups
- Sentry

## B9a) Non-functional requirements (MVP targets)
- Search response p95 <= 300ms (Typesense), event page render <= 1s server time.
- p95 indexing latency <= 2 minutes after approval.
- Sitemaps refreshed daily; event pages include canonical URLs.
- Basic caching for public list pages (60s) and event pages (5m) with cache bust on update.
- Audit logs for moderation actions and sensitive changes.

## B9b) SEO, metadata, and sitemaps (MVP)
- Canonical URLs:
  - Events: `/events/{slug}` with permanent 301 from old slugs if changed.
  - Institutions, speakers, venues, series use their slug routes.
- Indexing rules:
  - `public` + `approved` events are indexable.
  - `unlisted`, `private`, `draft`, `pending`, and `rejected` use `noindex, nofollow`.
- Metadata:
  - Title: `{event_title} | {institution_name} | ilmu360°`.
  - Description: first 160 chars of event description.
  - OpenGraph + Twitter cards include title, description, start time, and image when available.
- Structured data:
  - Use JSON-LD `Event` with `name`, `startDate`, `endDate`, `location`, `organizer`, `eventAttendanceMode`, and `eventStatus`.
- Sitemaps:
  - `sitemap-index.xml` with separate sitemaps for events, institutions, speakers, and series.
  - Events sitemap updated daily; others weekly.
  - Maximum 50,000 URLs per sitemap.
- Robots:
  - `robots.txt` references `sitemap-index.xml`.

## B9c) Observability and analytics (MVP)
- Tooling:
  - Sentry for backend exceptions and queue failures.
  - PostHog for product analytics events.
  - Metabase for operational dashboards (read-only DB role).
  - Horizon for queue monitoring.
- Error tracking:
  - Tag with `request_id`, route name, user_id (if authenticated), and event_id where relevant.
- Performance:
  - Track search response time (p50/p95/p99), event page render time, and queue processing time.
  - Monitor Typesense latency separately from DB latency.
- Product event schema (PostHog):
  - `search_performed` (q, filters_count, results_count, sort, has_geo)
  - `search_result_clicked` (event_id, position)
  - `event_viewed` (event_id, source)
  - `event_shared` (event_id, channel)
  - `event_saved` (event_id)
  - `event_registered` (event_id, is_guest)
  - `submission_started` / `submission_submitted` (source)
  - `moderation_decision` (event_id, decision, reason_code)
  - `report_created` (entity_type, category)
- Dashboards to build first:
  - Moderation SLA: pending count, median time to decision, >48h backlog.
  - Search quality: queries/day, zero-result rate, CTR on results, top filters.
  - Supply pipeline: submissions by source, approval rate, time to publish.
  - Trust and abuse: reports per 100 events, high-risk categories, repeat offenders.
  - Ops health: queue depth, indexing latency, error rate by job.

## B9d) Security, privacy, and data retention (MVP)
- Transport and session:
  - HTTPS only, secure cookies, and HSTS in production.
  - CSRF protection on all form posts.
- Input safety:
  - Event descriptions are stored as plain text; strip HTML.
  - URLs are validated and must be `https` for livestream/recording/donation links.
- Access control:
  - Policies enforce data access for institutions and speakers.
  - Exports of registrations require institution owner/admin role and are audit logged.
- Data retention:
  - Registrations PII retained for 6 months after event end, then anonymized (keep counts).
  - Reports retained for 24 months; reporter contact is removed after 6 months.
  - Audit logs retained for 24 months.
  - Application logs retained for 60 days.
  - Data deletion requests fulfilled within 30 days; keep a hashed suppression list for opt-outs.

## B9e) Abuse controls and rate limits (MVP)
- Rate limits (guest/IP, authenticated/user):
  - `GET /events`: 60/min, 180/min.
  - `GET /events/{slug}`: 120/min, 240/min.
  - `POST /event-submissions`: 3/day + 1/hour, 10/day.
  - `POST /reports`: 3/day, 10/day.
  - `POST /events/{event}/registrations`: 5/hour, 20/day.
  - `POST /saved-searches`: 30/day (auth only).
- Captcha:
  - Required for guest submissions and guest reports.
  - Trigger for guest registrations after 3 attempts/hour.
- Duplicate prevention:
  - Block submissions that match title + starts_at + venue within 7 days.
  - Block repeated reports from the same user/IP for the same entity within 24 hours.

## B9f) Backups and disaster recovery (MVP)
- Database:
  - Daily full backup with 30-day retention.
  - Weekly backup retained for 12 weeks.
  - Monthly backup retained for 12 months.
- Storage:
  - Enable bucket versioning for S3/R2.
  - Keep deleted media for 30 days to allow recovery.
- Restore testing:
  - Perform a restore test at least once per quarter.

## B10) Testing
- Policy tests (auth)
- Workflow tests (pending→approved)
- Sensitive change gating tests
- Search indexing job tests (mock Typesense)
- Digest tests
- Registration capacity and duplicate checks
- Rate limiting and captcha enforcement
- SEO metadata and structured data rendering

## B11) Implementation order
1) Auth + roles + policies
2) Migrations + models + relationships
3) Filament CRUD resources
4) Moderation queue and status workflow
5) Public pages + search endpoints
6) Typesense integration (Scout)
7) Saved searches + digest emails
8) Registrations + exports
9) Reports + resolution
10) Harden: rate limits, audit logs, monitoring
11) SEO + sitemap + metadata
12) Analytics + operational dashboards

---

# Part C — Roadmap awareness (for future-proofing)

## Phase 2
- Series templates (recurring scheduling)
- Follow speaker/institution
- Push notifications
- QR check-in
- Poster generator

## Phase 3 (1000×)
- Poster-to-event extraction pipeline
- Widgets/embeds for masjid sites
- Partner API (read-only)
- Reputation-based auto-approval

---

# Part D — Decisions (MVP defaults)
- Public event submissions allow guest submissions with phone/email + captcha; login is optional and unlocks faster moderation and edit history.
- Moderation SLA target: 24 hours. Escalation: pending > 48 hours triggers moderator review alert; pending > 72 hours escalates to super admin.
- Geo provider: Google Maps is canonical for place IDs and geocoding. Waze URL is generated from lat/lng using the universal link format.
- Unlisted events are not in public search; they remain visible in the institution dashboard and by direct link.
- Pagination: `page` + `per_page` for MVP; cursor pagination can be added later for high-volume feeds.

---

**End of document.**
