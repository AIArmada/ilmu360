# ilmu360° MVP Status (Fresh)

Updated: July 19, 2026  
Source references: `MVP_CHECKLIST.md`, current routes/resources/tests in codebase, `docs/ilmu360_api_mcp_filament_crud_comparison.md`.

## 1. Scope and Interpretation
This document is the fresh MVP status reference for current implementation state.

It intentionally corrects drift from older checklist entries when product decisions changed in code, for example:
- Public submit-event route is intentionally **unthrottled** for guests.
- Topic management is implemented through the unified tag system (`TagResource`), not a standalone `TopicResource`.

## 2. Status Legend
- `DONE`: implemented and used in current app flow.
- `PARTIAL`: implemented in backend and/or partially exposed in UX.
- `PENDING`: not implemented yet for MVP.
- `DEFERRED`: planned for post-MVP or intentionally postponed.

## 3. MVP Pillar Status

| Pillar | Status | Notes |
|---|---|---|
| Public Event Discovery Pages | DONE | `/majlis`, `/majlis/{slug}`, map/navigation links, related events, share, add-to-calendar are available. |
| Public Directory Pages | DONE | `/institusi`, `/institusi/{slug}`, `/penceramah`, `/penceramah/{slug}`, `/siri/{slug}`, `/rujukan`, and `/tempat`/`/lokasi/{slug}` are available. |
| Public Event Submission | DONE | `/hantar-majlis` wizard is live with media, references, organizer/location logic, captcha integration. |
| Submission Abuse Controls | PARTIAL | Captcha + reports throttling + search throttling exist; submit-event route intentionally unthrottled by policy. |
| User Auth + Basic Account | DONE | Registration/login/reset/social login (Google) and authenticated user routes are present. |
| Saved Searches + Digests | DONE | Saved search CRUD API + UI and daily/weekly digest jobs are implemented. |
| Event Save/Interest | DONE | Save/unsave and interest endpoints exist with API + dashboard integration. |
| Registration Flow | DONE | Register endpoint, validations, user registrations API, export endpoint are implemented. |
| Admin Core Resources (Filament) | DONE | 61 admin-panel resources at runtime (16 local app + 45 plugin) — Event/Institution/Speaker/Venue/Series/Reference/DonationChannel/Report/Tag + engagement/communications/events/inventory/seating/signals/ticketing/authz resources. |
| Moderation Core Actions | DONE | Moderation queue and approve/reject/needs-changes actions available. |
| Moderation Advanced UX | PARTIAL | Core moderation works; side-by-side diff and richer SLA UX still pending. |
| Search and Filtering | DONE | DB fallback + Typesense with geo and multi-filter support is in place. |
| SEO and Sitemaps | PARTIAL | Sitemap endpoints and canonical/noindex behavior exist; meta optimization pass still open. |
| Queue + Scheduler Ops | DONE | Digest/escalation/pruning/media maintenance schedules are configured. |
| OpenAPI Documentation | DONE | Scramble installed (`config/scramble.php`, `app/Providers/ApiDocumentationServiceProvider.php`); `/docs.json` exposed for AI/mobile clients. |
| Trust Scoring Automation | PENDING | Trust-score model and auto-approval pipeline are not implemented. |
| MCP Admin + Member Servers | DONE | Admin MCP (`/mcp/admin`) + Member MCP (`/mcp/member`) plus local handles; 59 MCP tool classes across both servers. |

## 4. Fresh Corrections vs Older Checklist

1. Submit-event rate limiting
- Previous checklist marked it as done.
- Current product decision is to keep guest submission unthrottled.
- Code reflects this (`routes/web.php` does not apply `throttle:event-submission`).

2. Topic management naming
- Previous checklist mentions `TopicResource`.
- Current implementation uses tags with type taxonomy, surfaced via `TagResource`.

3. Trust scoring jobs
- Previous notes referenced placeholder trust job.
- Current status: no active trust-score pipeline in production path.

## 5. Key Implemented MVP Capabilities (Current)

### 5.1 Event Discovery and Event Page
- Event listing with search, filters, date range, and near-me sorting.
- Event detail with:
  - schedule and location
  - donation channels
  - speaker/institution context
  - share and calendar actions
  - media rendering and related events

### 5.2 Public Submission Workflow
- Wizard-style submit form supports:
  - event details and timing modes
  - organizer and location branching
  - references and media uploads
  - captcha verification
- Speaker quick-create in submit flow supports:
  - biography (rich content JSON)
  - avatar + main image uploads
  - affiliated institution + institution-specific position

### 5.3 Admin and Moderation
- Filament admin panel for major entities.
- Moderation queue with decision actions and report visibility.
- Escalation jobs for pending moderation backlog.

### 5.4 API Surface
- Public event and report endpoints.
- Authenticated saved-search, save/interest, registration and export endpoints.

## 6. Remaining MVP Gaps

### 6.1 Institution Dashboard Capabilities
- Create/edit institution events from institution dashboard context.
- Institution profile management UX completion.
- Donation account self-management UX completion.
- Member invitation and role assignment UX completion.

### 6.2 Moderation Productivity
- Side-by-side content diff for changes.
- Rich SLA dashboarding (queue age, breach countdown, prioritization visibility).
- Strong duplicate detection hints in moderation interface.

### 6.3 Trust and Risk Automation
- Trust score domain model.
- Auto-approval thresholds and guardrails.
- Scheduled trust score recalibration.

### 6.4 Platform Quality/Operations
- ~~OpenAPI documentation.~~ DONE (Scramble; see pillar table).
- Broader performance and cache optimization pass.
- Error page polish and comprehensive loading/skeleton patterns.

## 7. Recommended Next MVP Sprint (Execution Order)

1. Institution dashboard completion
- Enable full institution-side event and profile operations.

2. Moderation UX upgrade
- Add diff view + SLA metrics and priority triage tools.

3. Trust scoring phase 1
- Introduce conservative trust scoring and explicit feature flags.

4. API documentation and contract freeze
- Produce OpenAPI baseline and lock MVP response schemas.

## 8. MVP Exit Criteria (Practical)
Declare MVP complete when all below are true:
- Institution dashboard critical operations are complete.
- Moderation queue includes diff/SLA tooling.
- Core trust/risk automation is operational (even if conservative).
- API contract documentation is publishable for integrators.
- No known P1/P2 regressions in search, submission, moderation, registration flows.

## 9. Verification Commands
Use these quick commands before status updates:

```bash
# Fast confidence checks
vendor/bin/pest --parallel --compact

# Focused checks for current policy/flows
vendor/bin/pest --parallel --compact --filter='SubmitEventRateLimitTest|SpeakerCreateOptionSchemaTest|PublicPagesTest'
```
