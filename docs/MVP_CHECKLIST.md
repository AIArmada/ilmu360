# ilmu360° MVP Feature Checklist

**Last Updated:** 2026-03-15
**Document Purpose:** Track current MVP implementation status against the live codebase (routes, Livewire pages, Filament resources, services, jobs, and tests).

This checklist distinguishes between:

- true MVP work still missing
- deliberate product-policy decisions
- superseded architecture that should not remain as fake TODOs

---

## MVP Health Snapshot

| Pillar | Status | Notes |
|---|---|---|
| Public Pages & Core Discovery | DONE | Browse, detail, submission, calendar, share, SEO, and discovery flows are live. |
| Authenticated User Features | DONE | Auth, saved events/searches, registrations, dashboard flows, and settings are implemented. |
| Institution Dashboard | MOSTLY DONE | Operational dashboard, scoped Ahli links, member management, and invitations are live; attendee export UX remains. |
| Admin/Moderation (Filament) | PARTIAL | Core queue, resources, and moderation actions are implemented; reviewer ergonomics remain incomplete. |
| API Surface | DONE | Public and authenticated MVP API endpoints are implemented. |
| Search & Discovery | DONE | Full-text, geo, and advanced filter UI are implemented with Typesense + DB fallback. |
| Infrastructure & Operations | PARTIAL | Core scheduling, notifications, indexing, and logging are implemented; Horizon and numeric trust-score automation are not. |

---

## True Remaining MVP Work

### Institution Self-Service
- [ ] Export attendee list from institution self-service UI

Note: the CSV backend already exists at `GET /api/v1/events/{id}/registrations/export`, but the institution-facing Ahli/dashboard flow still lacks an export action.

### Moderator Reviewer Ergonomics
- [ ] Side-by-side diff view for submitted event vs current event
- [ ] SLA visibility in moderation queue UI (overdue / age indicators)
- [ ] Duplicate detection hints in moderation queue UI

Note: the moderation queue already supports core review actions. These remaining items are reviewer-speed features, not core moderation correctness gaps.

---

## Implemented MVP Scope

### Public Pages & Features

#### Event Discovery & Display
- [x] Event listing page (`/events`, `/majlis`)
- [x] Event detail page (`/events/{slug}`, `/majlis/{slug}`)
- [x] Event description, speakers, and topic display
- [x] Waze / Google Maps navigation links
- [x] Livestream / recording links
- [x] Donation account display
- [x] Registration form with validation
- [x] JSON-LD structured data (SEO)
- [x] OpenGraph / Twitter Cards
- [x] Share button (native Web Share API + share modal)
- [x] Add to Calendar (Google, Apple/iCal via ICS, Outlook, Office 365, Yahoo)
- [x] Prayer-relative timing display
- [x] "Near me" geo-location button
- [x] Event distance display in search results

#### Institutions, Speakers, Series
- [x] Institutions listing page (`/institutions`, `/institusi`)
- [x] Institution detail page (`/institutions/{slug}`, `/institusi/{slug}`)
- [x] Speakers listing page (`/speakers`, `/penceramah`)
- [x] Speaker detail page (`/speakers/{slug}`, `/penceramah/{slug}`)
- [x] Series detail page (`/series/{slug}`, `/siri/{slug}`)

#### Public Submission
- [x] Event submission form (`/submit-event`, `/hantar-majlis`)
- [x] Submission success page
- [x] CAPTCHA integration (Turnstile)
- [x] Poster upload on submission

### User Features (Requires Authentication)

#### Authentication
- [x] User registration page
- [x] User login page
- [x] Password reset flow
- [x] Email verification flow
- [x] Social login (Google)

#### Saved Events, Interests, Searches
- [x] Save/bookmark events
- [x] View saved events list (API + dashboard)
- [x] Saved searches CRUD API
- [x] Event interest marking API (going / interested)
- [x] Saved searches UI page
- [x] Daily digest email job (scheduled)
- [x] Weekly digest email job (scheduled)
- [x] Email preference settings

#### User Dashboard
- [x] User profile summary/dashboard page
- [x] My registrations list
- [x] My saved events list
- [x] My saved searches list

### Institution Dashboard

#### Institution Management
- [x] Institution dashboard home
- [x] Institution-scoped event list
- [x] Institution-scoped event creation flow
- [x] Institution-scoped event edit/review links into Ahli panel
- [x] Manage institution profile (self-service dashboard flow)
- [x] Manage donation accounts (self-service dashboard flow)
- [x] View event registrations

#### Member Management
- [x] Add/remove institution members from institution dashboard
- [x] Role assignment (admin, editor, viewer) from institution dashboard
- [x] Member invitation flow

Note: invitation delivery is still manual copy/share from the relation manager; there is no outbound invite-notification workflow yet.

### Moderation & Admin (Filament)

#### Filament Resources
- [x] EventResource (CRUD)
- [x] InstitutionResource (CRUD)
- [x] SpeakerResource (CRUD)
- [x] VenueResource (CRUD)
- [x] SeriesResource (CRUD)
- [x] DonationChannelResource (CRUD)
- [x] ReportResource (CRUD)
- [x] ReferenceResource (CRUD)
- [x] TagResource (taxonomy-based tags)
- [x] SpaceResource (supporting location model)

#### Moderation Workflow
- [x] Moderation Queue page
- [x] Approve / Reject / Needs Changes actions
- [x] High-risk report escalation path (report-driven re-moderation)

### API Endpoints

#### Public API
- [x] `GET /events` - Search/list events (legacy alias)
- [x] `GET /events/{slug}` - Event details (legacy alias)
- [x] `GET /events/{slug}/calendar.ics` - ICS download (legacy alias)
- [x] `GET /institutions` - List institutions (legacy alias)
- [x] `GET /speakers` - List speakers (legacy alias)
- [x] `GET /api/v1/events` - JSON API events index
- [x] `GET /api/v1/events/{id}` - JSON API event detail
- [x] `POST /api/v1/reports` - Report content

#### Authenticated API
- [x] `POST /events/{slug}/register` - Event registration
- [x] `PUT /events/{event}/saved` - Save event
- [x] `DELETE /events/{event}/saved` - Unsave event
- [x] Saved searches CRUD API (`/api/v1/saved-searches`)
- [x] `GET /api/v1/events/{id}/registrations/export` - Export registrations (CSV)
- [x] `GET /api/v1/user/registrations` - User registrations

### Search & Discovery

#### Search Functionality
- [x] Text search (title, description, institution, venue, speaker)
- [x] Filter by state/district/subdistrict
- [x] Filter by language / type / audience-related fields
- [x] Filter by institution / speaker
- [x] Date range filter (API + UI)
- [x] Topic filter UI
- [x] Geo-distance sorting ("Near me")
- [x] Typesense integration with DB fallback

#### Search UI
- [x] Basic search input
- [x] Advanced filters panel
- [x] Active filter chips
- [x] Sort options UI (time / relevance / distance)
- [x] "Near me" button with geolocation

### SEO & Sitemaps
- [x] Sitemap index (`/sitemap.xml` redirected to localized sitemap)
- [x] Events sitemap (`/sitemap-events.xml`)
- [x] Institutions sitemap (`/sitemap-institutions.xml`)
- [x] Speakers sitemap (`/sitemap-speakers.xml`)
- [x] Canonical URLs on event pages
- [x] noindex handling for draft / pending / private pages
- [x] robots.txt configuration
- [x] Meta title / description optimization pass

### Infrastructure & Operations

#### Rate Limiting
- [x] Search rate limiting
- [x] Registration rate limiting
- [x] Rate limit service provider
- [x] Reports rate limiting

#### Audit & Logging
- [x] Audit logging (`owen-it/laravel-auditing`)
- [x] Event model observers and cache-busting hooks

#### Queue & Jobs
- [x] Event escalation job (`EscalatePendingEvents`)
- [x] Digest email jobs (daily / weekly scheduled)
- [x] Search indexing pipeline (Scout + searchable models)
- [x] Media maintenance schedules (`media-library:clean`, `media-library:regenerate`)

#### Notifications
- [x] Event approved notification
- [x] Event rejected notification
- [x] Event needs changes notification
- [x] Event submitted notification (moderators)
- [x] Event escalation notification
- [x] Report resolved notification
- [x] Saved search digest notification
- [x] Push destination registration and push delivery pipeline

### UI/UX Enhancements

#### Event Page
- [x] Premium event card / hero treatment
- [x] Prayer-relative timing badge
- [x] Add to Calendar dropdown
- [x] Loading states in key interactive areas
- [x] Image gallery / slider
- [x] Related events section
- [x] Share preview modal
- [x] Self check-in flow on event page

#### Home Page
- [x] Hero section with search
- [x] "Near me" quick access
- [x] Featured events section
- [x] Browse by state / topic section
- [x] Upcoming events grid
- [x] 7-day date quick filter
- [x] Tonight / near-prayer contextual highlight section
- [x] Submit event CTA section

#### General
- [x] Responsive mobile design
- [x] Dark mode support
- [x] Comprehensive loading skeleton coverage
- [x] System-wide toast notifications
- [x] Custom error pages (404, 500)

---

## Deliberate Policy Decisions

- The `event-submission` limiter exists, but it is intentionally not attached to the public `submit-event.create` route in the current product policy.
- Institution event creation is intentionally split across the advanced parent-program builder and the stable submit-event flow, instead of a separate dashboard-only CRUD creator.

---

## Superseded Architecture

- `TopicResource` is no longer a real admin-resource goal; taxonomy-backed `TagResource` is the active architecture.
- `EventTypeResource` is not a standalone resource in the current architecture; event type is enum-backed application data.

---

## Deferred / Beyond Current MVP

- Horizon dashboard setup is not configured in-app yet.
- Numeric trust-score model, auto-approval, and weekly trust-score recalculation are not implemented.

Note: the current trust layer is credibility-based public-submission lock / unlock automation, not a numeric trust-score system.

---

## Phase 2 / Post-MVP

- [ ] Recurring series automation / recurring child-event generation
- [x] Follow speaker / institution
- [ ] Browser web push notifications
- [ ] QR-based on-site check-in
- [ ] Distinguish check-in source in attendance records (`button` vs `qr_scan`)
- [ ] Poster generator

Note:

- Parent-program templates already exist for weekly / weekend / Ramadan-style setup.
- Push-device registration and push delivery already exist for signed-in mobile-app/device flows; the unchecked item here is browser web push specifically.
- Standard self check-in already exists; the unchecked item here is QR-based check-in UX.

---

## Review Notes (2026-03-15)

1. The previous checklist had architecture drift (`TopicResource` and `EventTypeResource` were lingering as fake active TODOs). The current architecture is `TagResource` plus enum-backed `EventType`.
2. Submit-event throttling is intentionally disabled on the public submit route despite a defined `event-submission` limiter, so it should be tracked as policy, not as an implementation miss.
3. Numeric trust-score automation remains unimplemented; the current trust layer is credibility-based public-submission lock and unlock automation.
4. The true MVP tail is now intentionally small: institution self-service attendee export UX plus moderation reviewer ergonomics.
