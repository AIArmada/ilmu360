# ilmu360° — Executive Product Assessment (Codebase-Only)

Date: 2026-04-26  
Method: Repository reverse-engineering (code, routes, models, services, tests, configs, docs) without founder-first interviews.

Update note: refreshed on 2026-04-28 to reflect stronger reference/source modeling (root books + child parts such as jilid/bahagian/volume) across web, API, and MCP surfaces.

---

## 1) What this product is

ilmu360° is a vertical platform for Islamic event discovery in Malaysia, with expanding organizer and moderation operations across web, API, and MCP interfaces.

### Evidence
- Public discovery and detail routes: `routes/web.php` (`/majlis`, `/institusi`, `/penceramah`, `/rujukan`, `/hantar-majlis`)  
- Public/auth API: `routes/api.php` (`/api/v1/search`, `/api/v1/events`, `/api/v1/submit-event`, follows/saved-searches/notifications)  
- Domain model depth: `app/Models/Event.php` and related models + migrations  
- Product docs and roadmap: `MVP_CHECKLIST.md`, `docs/ilmu360_mvp_status.md`, `V2_ROADMAP.md`

### Confidence
High.

---

## 2) What problem it appears to solve

Two linked problems:
1. **Discovery pain**: people struggle to find reliable, relevant, nearby majlis/kuliah.  
2. **Supply quality pain**: organizers/contributors need structured workflows to publish and keep event data accurate.

### Evidence
- Rich search with location/filtering: `app/Http/Controllers/Api/Frontend/SearchController.php`, `app/Services/EventSearchService.php`  
- Structured reference/source discovery now supports book families and part-aware search/filter behavior: `app/Models/Reference.php`, `SearchController.php`, `EventSearchService.php`, `ReferenceSearchService.php`
- Contribution and moderation pipelines: `ContributionController.php`, admin moderation/review controllers, report triage controllers  
- Engagement loops: `SavedSearchController.php`, `FollowController.php`, notification controllers

### Confidence
High for problem shape; medium for real-world pain magnitude.

---

## 3) Who it serves

### Primary user
Attendees searching for relevant Islamic events.

### Strategic buyer/user
Institutions and organizer teams managing event operations.

### Operational user
Moderators/reviewers ensuring quality and trust.

### Evidence
- Public UX and copy: `resources/views/components/pages/⚡home.blade.php`  
- Institution/team workflows: `InstitutionWorkspaceController.php`, dashboard pages in `app/Livewire/Pages/Dashboard/*`  
- Moderation actions: admin API controllers + Filament resources

### Confidence
Medium-High.

---

## 4) What is strong today

1. **Discovery architecture is robust** (search, geo, filters, directories).  
2. **Trust/governance layer exists** (moderation, claims, reviews, triage).  
3. **Cross-surface platform maturity** (web + API + admin/member MCP + parity tests).  
4. **Retention primitives are implemented** (saved searches, follows, notifications, digests).
5. **Knowledge-source modeling is maturing** (references are no longer just flat book records; the platform now understands whole books versus specific parts/volumes, which improves search precision and event-source context).

### Evidence
- `SearchController.php`, `EventSearchService.php`  
- `Reference.php`, `ReferenceSearchService.php`, reference web/API pages and schemas
- `AdminEventModerationController.php`, `AdminReportTriageController.php`, review controllers  
- `routes/ai.php`, `app/Mcp/Servers/*`, `tests/Feature/Mcp/*`  
- `SavedSearchController.php`, `Notification*Controller.php`

### Confidence
High.

---

## 5) What is weak or risky

1. **Potential over-engineering vs current user pull** (heavy parity/transport abstraction).  
2. **Organizer workflow friction still visible** (roadmap/checklist explicitly flags gaps).  
3. **Monetization intent not implemented in code** (no clear billing/subscription flow).  
4. **High complexity can dilute focus** (many surfaces and pathways).

### Evidence
- Parity governance depth: `docs/ilmu360_api_mcp_filament_crud_comparison.md` + companion tests  
- Remaining gaps explicitly noted: `MVP_CHECKLIST.md`, `docs/ilmu360_mvp_status.md`, `V2_ROADMAP.md`  
- No clear payment/billing pipeline in current implementation surface

### Confidence
Medium-High.

---

## 6) Could this become indispensable?

Yes—**if** it becomes the default organizer operating layer (not just a discovery directory).

### Dependency levers already present
- Stored operational value: events, registrations, follows, saved searches, claims, moderation history  
- Team and role workflows: institution workspace/member roles  
- Automation foundations: schedules, notifications, MCP tooling

### What would make it must-have
- Recurring program automation  
- Attendance operations (QR/source/analytics)  
- Faster moderation productivity (diff + SLA visibility)  
- Measurable organizer time savings tied to weekly workflows

### Confidence
Medium-High.

---

## 7) Strategic recommendation (blunt)

### The one thing to fix first
**Simplify and harden the organizer weekly workflow (publish → moderate → attendance → follow-up) into one canonical path with measurable time savings.**

Why this first:
- It increases supply quality and freshness.
- It drives retention and switching cost for institutions.
- It creates the clearest route to monetization.

### Immediate priorities (next 7 days)
1. Reduce organizer flow branching and make one default path.
2. Deliver moderator productivity upgrades already identified (diff/SLA).
3. Instrument submission-to-approval funnel and publish one north-star KPI dashboard.

---

## 8) Final scorecard (codebase-informed)

- Product Clarity: **7/10**  
- User Value: **7.5/10**  
- Differentiation: **6.5/10**  
- Ease of Use: **6.5/10**  
- Retention Potential: **7/10**  
- Must-Have Potential: **6.5/10**  
- Commercial Potential: **7/10**

### Overall confidence
**Medium-High** (strong code evidence for capability and direction; medium confidence on market outcomes without live usage metrics).

---

## Brutal truth

The product’s engineering maturity is ahead of its market proof. If organizer workflow simplicity and monetization are not validated soon, ilmu360° risks becoming a highly capable platform that users appreciate—but don’t depend on.