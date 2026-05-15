# Repository Reverse-Engineering — Phases 1-3

## Scope and method

This analysis is based on repository evidence first (code, routes, models, tests, configs, docs). Inference is explicitly separated from direct evidence.

## Phase 1 — Repository Excavation

### Evidence from codebase

| Area | Files/Folders | What It Does | Product Meaning | Confidence |
|---|---|---|---|---|
| Runtime + stack | `composer.json`, `package.json`, `bootstrap/app.php`, `config/*.php` | Laravel 13+, Livewire 4, Filament 5, Scout/Typesense, Passport + Sanctum, MCP server support, notification center, AI SDK | Mature Laravel app with web + API + AI/MCP interfaces | High |
| Public web entry points | `routes/web.php`, `resources/views/components/pages/⚡home.blade.php` | Public homepage, search/discovery, event detail/calendar, institution/speaker/reference/venue/series pages, submit-event flow | Core user-facing discovery + submission platform | High |
| API surface | `routes/api.php`, `app/Http/Controllers/Api/Frontend/*`, `app/Http/Controllers/Api/*` | Public + authenticated API for search, catalogs, submissions, follows, registrations, saved searches, account settings, notification center, admin generic resource API | Product is designed for web + mobile/API clients, not only server-rendered pages | High |
| MCP/agent surface | `routes/ai.php`, `app/Mcp/Servers/AdminServer.php`, `app/Mcp/Servers/MemberServer.php`, `app/Mcp/Tools/**` | Admin/member MCP endpoints + local MCP handles for inspector/debug | Product includes machine-facing operational interface (AI agents/automations) | High |
| Domain model breadth | `app/Models/*`, `database/migrations/*`, database schema summary | Rich event ecosystem: events, institutions, speakers, venues, references, series, registrations, saved searches, follows, reports, moderation, membership claims; references now support root/child families for whole books and specific parts | Focus is event discovery + organizer operations + governance | High |
| Search/discovery engine | `app/Services/EventSearchService.php`, `app/Http/Controllers/Api/Frontend/SearchController.php`, `config/scout.php` | Full-text + geo + faceted filters, directory search, DB fallback, and family-aware reference search/filtering | Discovery quality is a core product pillar | High |
| Moderation + trust workflows | `app/Http/Controllers/Api/Admin/*Moderation*`, `*ReviewController.php`, `app/Filament/Resources/*`, `docs/ilmu360_api_mcp_filament_crud_comparison.md` | Admin moderation and review actions (event moderation, report triage, contribution/membership review) | Platform is not a passive listing site; it actively curates quality | High |
| Contribution/supply pipelines | `resources/views/components/pages/submit-event/create.blade.php`, `app/Http/Controllers/Api/Frontend/ContributionController.php`, `MembershipClaimController.php` | Public submission + authenticated contribution/update/claim flows | Supply-side acquisition is central strategy | High |
| Engagement + retention | `SavedSearchController.php`, `EventSaveController.php`, `EventGoingController.php`, `Notification*Controller.php`, scheduled jobs in `routes/console.php` | Save/follow/going, saved searches, digest + notifications | Product attempts recurring engagement loops | High |
| Product instrumentation | `config/signals.php`, `config/product-signals.php`, home page signal attributes in Blade | Event tracking and product telemetry configuration | Team is optimizing behavior, not just shipping pages | Medium-High |
| Documentation posture | `MVP_CHECKLIST.md`, `docs/ilmu360_mvp_status.md`, `V2_ROADMAP.md`, `docs/ilmu360_mcp_guide.md` | Explicit maturity tracking + roadmap + API/MCP parity governance | Product direction is being actively managed | High |
| Tests and confidence | `tests/Feature/*`, `tests/Feature/Mcp/*`, `tests/Unit/CrudComparisonDocsTest.php` | Broad test coverage including web/API/MCP contract parity | Engineering discipline is above prototype level | High |

### What type of app is this?

**Evidence:** `routes/web.php` + `routes/api.php` + domain models (`Event`, `Institution`, `Speaker`, `Reference`) + UI copy in `⚡home.blade.php` (“Cari kuliah & majlis ilmu di Malaysia”).  
**Inference:** A religious/knowledge-event discovery and operations platform (primarily Malaysian Muslim event ecosystem).  
**Confidence:** High.

### What stack does it use?

**Evidence:** `composer.json` includes Laravel, Livewire, Filament, Scout/Typesense, Passport, Sanctum, laravel/ai, laravel/mcp; `package.json` includes Tailwind 4, Alpine, Vite; `config/scout.php`, `config/horizon.php`, `routes/ai.php`.  
**Inference:** Modern Laravel full-stack app with web, API, and machine-consumable MCP interfaces.  
**Confidence:** High.

### Main entry points

- **Public web:** `/`, `/majlis`, `/institusi`, `/penceramah`, `/rujukan`, `/hantar-majlis` (`routes/web.php`)
- **Public API:** `/api/v1/search`, `/api/v1/events`, `/api/v1/submit-event`, `/api/v1/institutions`, `/api/v1/speakers` (`routes/api.php`)
- **Authenticated API:** follows, saved-searches, registrations, account settings, notifications, contributions (`routes/api.php`)
- **Admin API + MCP:** `/api/v1/admin/*`, `/mcp/admin`, `/mcp/member`, plus local handles in `routes/ai.php`

Notable discovery detail: references are no longer treated purely as flat book cards. The repository now models a root source plus child parts (for example jilid/bahagian/volume), and public/API discovery can distinguish whole-book context from exact-part context.

Confidence: High.

### Main user-facing flows

1. Discover events (search/filter/geo/date/topic)  
2. View event detail and take action (calendar/share/register/save/going)  
3. Submit new events and suggest updates  
4. Follow entities (speakers/institutions/references/series)  
5. Manage personal dashboard (saved, registrations, notifications, settings)  
6. Institution/member workflows (workspace + membership claims)

Evidence: `routes/web.php`, `routes/api.php`, `app/Livewire/Pages/*`, `SearchController.php`, `EventController.php`, `EventSubmissionController.php`.  
Confidence: High.

### Main backend capabilities

- Search engine abstraction with fallback (`EventSearchService`, Scout config)
- Reference-family modeling and search/filter expansion (`Reference`, `ReferenceSearchService`, reference directory/detail surfaces)
- Rich moderation workflows (`AdminEventModerationController`, review/triage controllers)
- Generic admin resource API and schemas (`AdminResourceController`, `AdminResourceRegistry`, `AdminResourceMutationService`)
- MCP tooling for admin/member operations (`app/Mcp/Tools/*`)
- Notification center and destinations (`Notification*Controller`, `config/notification-center.php`)
- Media pipeline with conversions and path/naming strategies (`config/media-library.php`, model media configs)

Confidence: High.

### What appears complete / unfinished / experimental / overbuilt / underbuilt

- **Appears complete:** discovery + directory + event detail + submit + core moderation + API/MCP contracts.
  - Evidence: route breadth, feature controllers, docs parity matrix, active tests.
- **Appears unfinished:** moderator ergonomics (diff/SLA hints), trust-scoring automation, some organizer ops (attendee export UX).
  - Evidence: `MVP_CHECKLIST.md`, `docs/ilmu360_mvp_status.md`, `V2_ROADMAP.md`.
- **Appears experimental:** extensive MCP parity layer and machine-facing schema abstractions for an event platform at this stage.
  - Evidence: `docs/ilmu360_api_mcp_filament_crud_comparison.md`, `routes/ai.php`, many MCP tools/tests.
- **Appears overbuilt:** transport parity machinery (admin HTTP + MCP + member MCP + panel parity governance) relative to basic user growth stage.
  - Evidence: capability matrix + dedicated parity tests + dual server architecture.
- **Appears underbuilt:** onboarding clarity and “first success” simplification for non-technical organizers (still many branching workflows).
  - Evidence: long submission/contribution paths and roadmap emphasis on organizer UX improvements.

Confidence: Medium-High.

---

## Phase 2 — Product Identity From Code

## One-Sentence Description

ilmu360° is a Malaysian Islamic event discovery and contribution platform that combines public search/listings with moderated organizer workflows across web, API, and MCP interfaces.

## Plain-English Description

People can find nearby religious classes/lectures, follow speakers or institutions, register for events, and save searches. Organizers and contributors can submit new events or updates. Admins (and scoped members) can review, moderate, and manage records through both human UI (Filament) and machine interfaces (API/MCP).

The product is also becoming more opinionated about knowledge-source structure: it can represent not just a book title, but a specific part or volume of that book, and then carry that distinction through discovery and event linkage.

## Ambitious Version

A full operating system for Islamic event ecosystems: discovery, publication, moderation, attendance operations, contributor governance, and agent-ready automation.

### Identity claims table

| Claim | Evidence | Confidence |
|---|---|---|
| Product identity is mostly obvious | Home page copy (“Cari Kuliah & Majlis Ilmu di Malaysia”), route names (`majlis`, `hantar-majlis`), event-centric schema | High |
| Repo communicates clear product direction | `MVP_CHECKLIST.md`, `docs/ilmu360_mvp_status.md`, `V2_ROADMAP.md`, API/MCP matrix docs | High |
| Category | Workflow product + vertical platform (events/discovery/operations) with API layer | High |
| More than a simple directory | Contributions, membership claims, moderation, notification center, admin/member MCP tools | High |
| Wants to become organizer OS | `V2_ROADMAP.md` thesis explicitly says move from discovery app to organizer operating system | High |

---

## Phase 3 — Application Goals

## Explicit Goals (from docs/routes/UI)

- Help users find relevant majlis/kuliah quickly (location, date, tags, speaker/institution)
- Let community submit new events and corrections
- Keep quality high through moderation/review
- Provide authenticated value loops (saved searches, notifications, follows, dashboard)
- Expand toward organizer operations (recurring programs, attendance operations)

Evidence: `⚡home.blade.php`, `routes/web.php`, `routes/api.php`, `MVP_CHECKLIST.md`, `V2_ROADMAP.md`.

## Implied Goals (from architecture)

- Be integration-ready for mobile/third-party clients via stable API contracts (`/api/v1/manifest`, form-contract endpoints)
- Enable AI/agent operations through MCP and schema-guided writes
- Maintain cross-surface consistency via parity governance/testing
- Balance openness (public submission) with trust/control (moderation + claims + reports)

Evidence: `ManifestController`, `FrontendFormContractService`, `routes/ai.php`, `AdminResourceRegistry`, MCP docs/tests.

## Missing or unclear goals

- Clear monetization model is not visible in implementation (no billing/subscription primitives)
- Explicit north-star KPI instrumentation visible in config but business metrics targets are not codified in product docs
- Strongly differentiated end-user “wow” workflow beyond robust execution is not sharply expressed in UI copy

Evidence: no Stripe/Cashier subscription flows in code; docs focus on capability and ops not pricing/ICP specificity.

### What outcome is the app designed around?

- **Primary user outcome:** reliably discover and attend suitable Islamic events.
- **Secondary outcome:** make it easier for organizers/moderators to publish and maintain quality event supply.

Confidence: High.

### Main success path (inferred)

1. User searches and finds relevant events quickly
2. User registers/saves/follows
3. Contributor submits new events and updates
4. Moderation approves quickly
5. Content quality and freshness improve, driving repeat discovery

Evidence: search + engagement + submission + moderation architecture.  
Confidence: Medium-High.

---

## Confidence summary for Phases 1-3

- **Evidence from codebase:** Strong and broad.  
- **Inference from evidence:** Mostly high-confidence for product shape; medium confidence for market positioning depth.  
- **Overall confidence:** **High** for “what exists,” **Medium-High** for “why it wins.”

## Clarifying questions needed at this stage

Deferred to Phase 16 (only unresolved uncertainties after full analysis).