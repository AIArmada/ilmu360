# Repository Reverse-Engineering — Phases 4-7

## Phase 4 — Problem Analysis

### What pain this app appears to solve

**Evidence from codebase**
- Fragmented event discovery: unified search endpoints + rich filters (`app/Http/Controllers/Api/Frontend/SearchController.php`, `app/Services/EventSearchService.php`).
- Hard-to-find local/nearby programs: geo support (`lat/lng`, `radius_km`, nearby endpoints in `routes/api.php`; near-me controls in `resources/views/components/pages/⚡home.blade.php`).
- Event info inconsistency and stale data: contribution and moderation workflows (`ContributionController.php`, admin moderation/review controllers).
- Organizer/content supply friction: public submit-event flow + advanced event API (`resources/views/components/pages/submit-event/create.blade.php`, `EventSubmissionController.php`, `AdvancedEventController.php`).
- Follow-up/retention gaps: saved searches, follows, notifications (`SavedSearchController.php`, `FollowController.php`, `Notification*Controller.php`).

**Inference**
This is solving an operational discovery problem (finding trustworthy, relevant events) plus a supply quality problem (keeping listings current and moderated).

**Confidence**: High.

### Is the pain frequent / urgent / expensive / emotional / operational?

| Dimension | Score 1-10 | Evidence | Confidence |
|---|---:|---|---|
| Frequency | 8 | Search-heavy UX, saved searches, recurring digests, follow system | Medium-High |
| Urgency | 6 | Events are time-bound; missed event = lost opportunity; but not always mission-critical like payroll | Medium |
| Pain intensity | 7 | Rich filtering, geolocation, moderation, correction flows imply significant current friction | Medium |
| Willingness to pay | 5 | No visible billing implementation; value likely org-facing but not yet productized | Medium-Low |
| Existing frustration | 7 | Contribution/suggest-update/report/membership-claim workflows indicate messy existing data ownership | Medium-High |
| Need for better solution | 8 | Breadth of implemented workflows suggests manual alternatives are inadequate | Medium-High |

### Must-have or nice-to-have?

- **Current state:** should-have for attendees, potentially must-have for active organizers/moderators if organizer ops mature.
- **Evidence:** strong discovery + governance base, but roadmap still flags key organizer efficiency gaps (`V2_ROADMAP.md`, `MVP_CHECKLIST.md`).

### Blunt verdict

**This problem appears to be: interesting and potentially strong, but not yet fully urgent in its current packaged form.**

Reason: The repository demonstrates deep solutioning for discovery and governance, but “indispensable daily ops” for institutions is still being completed (explicitly documented as v2 focus).

---

## Phase 5 — User Analysis

### Likely user segments

| Likely User Segment | Evidence | Pain Level | Fit Score | Confidence |
|---|---|---:|---:|---|
| Event attendees/public seekers | Public routes (`/majlis`, `/carian`), mobile-friendly search API, registration/save/going | 7 | 9 | High |
| Institution organizers/admins | Institution dashboard, institution workspace API, member role management, advanced event creation | 8 | 8 | High |
| Community contributors | Submit-event + contribution/suggest-update + membership claims | 7 | 8 | High |
| Moderators/operations teams | Admin moderation endpoints, review schemas, report triage, Filament resources | 8 | 8 | High |
| AI/automation operators | MCP admin/member servers, write schemas, parity docs/tests | 6 | 7 | Medium-High |

### User conclusions

- **Most likely primary user:** public attendee searching for relevant nearby majlis.
- **Secondary user:** institution/operator who publishes and curates events.
- **Probably not the user:** general consumer social-media audience with no intent to attend structured religious events.
- **Built for:** individuals + community institutions/teams (not clearly enterprise SaaS yet).
- **ICP clarity:** moderate; strong domain specificity, but buyer persona and paid ICP are not explicit in code/docs.

Confidence: Medium-High.

---

## Phase 6 — Feature-by-Feature Review

| Feature | Evidence in Code | User Problem | Completeness | Strategic Importance | Recommendation |
|---|---|---|---:|---:|---|
| Event search + advanced filters | `SearchController.php`, `EventSearchService.php`, search routes/API | Hard to find relevant events | 9/10 | 10/10 | Keep, sharpen speed/relevance explainability |
| Event detail + share + calendar | `routes/web.php` calendar route, event detail pages, share endpoints/controllers | Turning discovery into attendance | 8/10 | 9/10 | Keep; improve conversion UX instrumentation |
| Public submit-event flow | `pages.submit-event.create`, `EventSubmissionController.php` | Supply shortage and stale listings | 8/10 | 10/10 | Keep; simplify and shorten first successful submission path |
| Contribution update + membership claims | `ContributionController.php`, `MembershipClaimController.php` | Ownership/accuracy of institutional data | 8/10 | 8/10 | Keep; combine with clearer trust badges and SLA feedback |
| Moderation workflows | Admin moderation/review/triage controllers + resources | Quality control and abuse prevention | 8/10 | 9/10 | Keep; prioritize diff/SLA reviewer ergonomics |
| Follow/save/going/registrations | `FollowController.php`, `EventSaveController.php`, `EventGoingController.php`, registration endpoints | Return visits and intent tracking | 8/10 | 9/10 | Keep; unify “my intent” dashboard cues |
| Saved searches + notifications | `SavedSearchController.php`, notification controllers/jobs | Re-discovery burden | 8/10 | 9/10 | Keep; optimize digest personalization |
| Institution workspace/member management | `InstitutionWorkspaceController.php`, dashboard routes/pages | Team collaboration in orgs | 7/10 | 8/10 | Improve; complete attendee export + invitation delivery UX |
| MCP admin/member tooling | `routes/ai.php`, `app/Mcp/Tools/**`, parity matrix docs | Machine-assisted operations | 8/10 | 7/10 now / 10 later | Keep but hide from core narrative until user pull exists |
| Generic admin CRUD API | `AdminResourceController.php`, `AdminResourceRegistry.php` | Operational flexibility and integration | 8/10 | 7/10 | Keep; avoid over-expanding without user demand |
| Product signals/telemetry layer | `config/signals.php`, signal attrs in home blade | Understand behavior/product loops | 7/10 | 7/10 | Keep; ensure outcomes map to product KPIs |

### Feature highlights

- **Strongest feature:** Search/discovery architecture (web + API + geo + filters + fallback).
- **Weakest feature:** Organizer workflow polish (acknowledged by roadmap/checklist).
- **Most confusing feature:** Heavy MCP/admin parity complexity for non-technical product positioning.
- **Most monetizable feature:** Organizer operations suite (recurring program management, attendance ops, team workflow).
- **Most defensible feature:** Curated data + moderation + local ecosystem depth over time.
- **Most retention-driving feature:** Saved searches + follows + notifications + recurring event supply.
- **Most likely unnecessary (now):** Some cross-surface parity overhead before demand proves need.

Confidence: Medium-High.

---

## Phase 7 — Value Proposition From Code

## Current Value Proposition

“Find credible Islamic events near you quickly, then register/save/follow while contributors and moderators keep listings fresh.”

## Stronger Value Proposition

“Run your institution’s recurring knowledge-event lifecycle in one place—from publishing and moderation to attendance operations and member collaboration.”

## Brutally Simple Pitch

**“ilmu360° helps people discover trustworthy Islamic events and helps institutions run them at scale.”**

### Value claims table

| Claim | Evidence | Confidence |
|---|---|---|
| Fastest path to value is search → event detail | Home hero search + `/api/v1/search` + event detail routes | High |
| Likely aha moment for attendees | Nearby/event relevance + immediate action (register/save/calendar/share) | Medium-High |
| Steps to aha | ~2-4 steps (search, open event, take action) | Medium |
| Slows users down | Submission complexity + branching contributor workflows | Medium-High |
| Makes app useful | Real event data model + engagement loops + moderation trust layer | High |
| Makes app feel generic | Lack of explicit, differentiated “organizer OS” story in primary UI | Medium |
| Could make users pay | Team operations + recurring program automation + attendance workflows | Medium |

### Evidence / inference / confidence summary

- **Evidence from codebase:** Strong for utility mechanics.
- **Inference from evidence:** Strong for user value; moderate for willingness-to-pay until organizer workflows mature.
- **Confidence level:** Medium-High.

### Clarifying questions needed here

Deferred to Phase 16 (only unresolved uncertainties).