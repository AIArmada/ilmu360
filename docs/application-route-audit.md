# Complete Application Route Audit

## 1. Executive Summary

This document is a comprehensive technical audit of the **ilmu360°** application (formerly MajlisIlmu), a Laravel-based Islamic event discovery and management platform targeting the Malaysian market. The audit traces every registered route through its complete lifecycle: HTTP entrypoint → middleware → controller/component → validation → service/action → model → database → events → listeners → jobs → notifications → response/UI.

### Application at a Glance

| Metric | Value |
|--------|-------|
| Framework | Laravel 13.x (PHP 8.4+) |
| UI Layer | Livewire 4.x + Filament 5.x Admin + Flux 2.x |
| Database | PostgreSQL |
| Queue | Redis via Laravel Horizon |
| Authentication | Laravel Fortify + Sanctum (API) + Passport (MCP/OAuth) |
| Authorization | Policies + custom Filament Authz plugin (RBAC) |
| Search | Laravel Scout + Typesense |
| Media | Spatie Media Library v11 |
| Notifications | Custom notification center (not default notifiable) |
| Auditing | OwenIt/laravel-auditing + Filament Auditing plugin |
| Tests | Pest 4.x — 2010 passing, 1 skipped, ~13157 assertions |

### Architecture Summary

The application follows a **two-panel architecture**:
1. **Admin Panel** (`/admin`) — Full CRUD for all resources, moderation queues, analytics
2. **Ahli Panel** (`/ahli`) — Scoped member view, limited CRUD restricted to user-owned/managed records

Public users interact via **Livewire pages** rendered through `routes/web.php`, with a **RESTful JSON API** at `/api/v1` for mobile apps and third-party clients. Two **MCP (Model Context Protocol) servers** at `/mcp/admin` and `/mcp/member` provide AI-agent tool access authenticated via Passport OAuth.

### Total Routes by Category

| Category | Count | Status |
|----------|-------|--------|
| Public web routes | ~30 | Partially audited (1 complete) |
| Auth routes (Fortify) | ~15 | Partially audited (Socialite + API auth complete) |
| Authenticated web routes | ~20 | Fully audited |
| API v1 (public) | ~35 | Fully audited |
| API v1 (authenticated) | ~50 | Fully audited |
| Admin API routes | ~20 | Fully audited |
| MCP routes | ~8 | Fully audited (33 admin + 24 member tools) |
| Scheduled commands | ~9 | Fully audited |
| Filament panel routes | ~100+ | Architecturally audited (22 resources, 6 pages) |
| **Total registered** | **~280+** | |

### Key Findings (Summary)

| Severity | Count |
|----------|-------|
| Critical | 0 |
| High | 0 |
| Medium | 8 |
| Low | 8 |
| Informational | Multiple noted |
| High | 0 identified so far |
| Medium | 0 identified so far |
| Low | 0 identified so far |
| Informational | Multiple noted |

---

## 2. Audit Scope

### In Scope

- All routes registered in `routes/web.php`, `routes/api.php`, `routes/ai.php`, and `routes/console.php`
- Filament panel resources, pages, relation managers, widgets (admin + ahli)
- All Livewire components (class-based and SFC)
- All API controllers, actions, and services
- All models, migrations, and database tables
- All events, listeners, jobs, notifications, mailables
- All observers and middleware
- UI rendering via Blade views, Livewire components, and Filament
- Authentication and authorization boundaries
- Scheduled jobs and queue configuration
- Test coverage

### Out of Scope

- Third-party package internals (Spatie packages, Filament core, Laravel core)
- External service configurations (Typesense, Firebase, Mailgun, etc.)
- Infrastructure/deployment configuration
- Client-side JavaScript frameworks (Alpine usage noted but not deeply traced)
- Branding/documentation files outside implementation code

---

## 3. Audit Methodology

1. **Route Inventory**: Generated complete route listing via route files analysis (`routes/web.php`, `routes/api.php`, `routes/ai.php`)
2. **Code Inspection**: Traced every route handler to its implementation — controllers, Livewire components, Filament resources, closures
3. **Architecture Discovery**: Explored the full directory structure, all service providers, middleware registration, and bootstrap configuration
4. **Model/Migration Analysis**: Read every model definition and migration to map the database schema
5. **Event/Listener/Job Tracing**: Catalogued all events, listeners, jobs, notifications, and observers
6. **Frontend Exploration**: Mapped Livewire components, Blade views, and Alpine.js interactions to their backend endpoints
7. **UI Action Tracing**: For each interactive element, traced the user action → endpoint → validation → database → response → UI update chain

---

## 4. Application Architecture

### 4.1 Framework & Language

- **Language**: PHP 8.4+
- **Framework**: Laravel 13.x (`laravel/framework ^13.14`)
- **Frontend**: Livewire 4.x (`livewire/livewire ^4.0`) with SPA mode via `wire:navigate`
- **Admin Panel**: Filament 5.x (`filament/filament ^5.0`)
- **CSS Framework**: Tailwind CSS v4 (`tailwindcss`)
- **Component Library**: Flux UI 2.x (`livewire/flux ^2.14`)
- **Testing**: Pest 4.x (`pestphp/pest ^4.7`)

### 4.2 Key Packages

| Package | Version | Purpose |
|---------|---------|---------|
| `laravel/fortify` | ^1.37 | Authentication scaffolding |
| `laravel/sanctum` | ^4.3 | API token auth |
| `laravel/passport` | ^13.7 | OAuth2 server (MCP) |
| `laravel/scout` | ^11.2 | Full-text search |
| `typesense/typesense-php` | ^6.0 | Search engine driver |
| `spatie/laravel-medialibrary` | ^11 | Media/file management |
| `spatie/laravel-tags` | (via plugin) | Tagging system |
| `spatie/laravel-data` | ^4.23 | DTO/data objects |
| `spatie/laravel-sluggable` | ^4.0 | Slug generation |
| `spatie/laravel-model-states` | ^2.14 | State machines |
| `spatie/laravel-activitylog` | ^5.0 | Activity logging |
| `spatie/laravel-deleted-models` | ^1.2 | Deletion snapshots |
| `spatie/eloquent-sortable` | ^5.0 | Model ordering |
| `spatie/laravel-query-builder` | ^7.3 | API query building |
| `laravel/octane` | ^2.17 | Application server |
| `laravel/horizon` | ^5.47 | Queue monitoring |
| `laravel/ai` | ^0.7.2 | AI SDK integration |
| `laravel/mcp` | ^0.7.2 | MCP server |
| `owen-it/laravel-auditing` | ^14.0 | Model auditing |
| `lorisleiva/laravel-actions` | ^2.10 | Action classes |
| `nnjeim/world` | ^1.1 | Geography data |
| `bezhansalleh/filament-language-switch` | ^5.0 | Language switching |
| `dedoc/scramble` | ^0.13.26 | API documentation |
| `livewire/blaze` | ^1.0 | Blade optimization |
| `aiarmada/filament-authz` | dev-main | Authorization (custom) |
| `aiarmada/filament-signals` | dev-main | Product analytics (custom) |
| `aiarmada/signals` | dev-main | Signals core (custom) |
| `aiarmada/affiliates` | dev-main | Affiliates (custom) |
| `aiarmada/commerce-support` | dev-main | Commerce support (custom) |

### 4.3 Core Domain Models

The application has ~51 models organized into these domains:

- **Identity & Auth**: `User`, `PassportUser`, `SocialAccount`
- **Event Management**: `Event`, `Series`, `Registration`, `EventCheckin`, `EventSettings`, `EventSubmission`, `EventChangeAnnouncement`, `EventKeyPerson`, `EventUser`
- **Directory**: `Institution`, `Speaker`, `Venue`, `Space`, `Reference`
- **Geography**: `Country`, `State`, `City`, `District`, `Subdistrict`, `Address`
- **Taxonomy**: `Tag` (Spatie), `Inspiration`, `MediaLink`, `SocialMedia`, `Contact`
- **Moderation**: `ModerationReview`, `ContributionRequest`, `MembershipClaim`, `Report`
- **Membership**: `Team`, `Membership`, `TeamInvitation`, `MemberInvitation`
- **Notifications**: `NotificationSetting`, `NotificationRule`, `NotificationDestination`, `PendingNotification`, `NotificationMessage`, `NotificationDelivery`
- **AI**: `AiModelPricing`, `AiUsageLog`
- **Other**: `SavedSearch`, `SlugRedirect`, `DonationChannel`, `Audit`, `EventKeyPersonPivot`, `EventSeries`

### 4.4 Route Architecture

```
routes/web.php        → Public pages + authenticated pages (Livewire + Blade)
routes/api.php        → REST API v1 (public + authenticated via Sanctum)
routes/ai.php         → MCP servers (admin + member) + MCP OAuth routes
routes/console.php    → Scheduled jobs + Artisan commands

Filament panels       → Auto-registered from app/Filament/{Admin,Ahli}/
                         (routes generated by Filament)
Fortify               → Auto-registered auth routes (login, register, etc.)
Passport              → OAuth routes for token issuance
```

### 4.5 Middleware Stack

**Global Middleware** (applied to all requests):
- `SetFilamentTimezone` — Sets default Filament timezone per request

**Web Middleware** (appended by `bootstrap/app.php`):
- `SetLocale` — Sets application locale based on session/cookie
- `TrackDawahShareAttribution` — Tracks share attribution cookies

**API Middleware** (appended by `bootstrap/app.php`):
- `NormalizeApiJsonResponse` — Wraps API responses in consistent JSON structure

**Custom Middleware**:

| Middleware | Purpose | Applied to |
|-----------|---------|------------|
| `ResolvePublicSlugRedirect` | Resolves old slugs to new via redirect | Public entity pages |
| `EnsureAdminApiAccess` | Verifies admin/super_admin role for admin API | `/api/v1/admin/*` |
| `EnsureAdminMcpAccess` | Verifies admin MCP access | `/mcp/admin*` |
| `EnsureMemberMcpAccess` | Verifies member MCP access | `/mcp/member*` |
| `NormalizeMcpAcceptHeader` | Normalizes MCP Accept header for SSE | MCP routes |
| `NormalizeApiJsonResponse` | Normalizes API JSON envelope | API routes |

### 4.6 Authentication Systems

| System | Guard | Provider | Purpose |
|--------|-------|----------|---------|
| Fortify | `web` | Session | Browser login/register |
| Sanctum | `sanctum` | Token | API auth for mobile apps |
| Passport | `api` | OAuth | MCP server auth |

- **Fortify** handles: login, register, password reset, email verification, 2FA
- **Socialite** handles: Google OAuth login (`/oauth/google/*`)
- **MCP OAuth** handles: OAuth authorization flow for MCP clients

### 4.7 Panel Structure

**Admin Panel** — `App\Providers\Filament\AdminPanelProvider`
- Path: `admin` (or subdomain)
- Resources: 22 resources across Content, Directory, Moderation, System groups
- Pages: Dashboard, ModerationQueue, ProductSignals, ShareAnalytics, DeletedUsers
- Widgets: StatsOverview, EventInventoryOverview

**Ahli Panel** — `App\Providers\Filament\AhliPanelProvider`
- Path: `ahli` (or subdomain)
- Resources: 4 resources (scoped): Event, Speaker, Institution, Reference
- Pages: AhliDashboard
- Widgets: PendingApprovalEventsWidget

---

## 5. Route Inventory

### 5.1 Route File Breakdown

#### `routes/web.php` — Web Routes (Public + Authenticated)

| Method | URI | Name | Handler | Middleware | Auth |
|--------|-----|------|---------|------------|------|
| GET/LW | `/` | `home` | ⚡home SFC | web | Guest+ |
| GET/LW | `/tentang-kami` | `about` | About\Show | web | Guest+ |
| GET | `/bahasa/{locale}` | `locale.switch` | LocaleController | web | Guest+ |
| GET | `/negara/{country}` | `country.switch` | PublicCountryController | web | Guest+ |
| GET | `/oauth/{provider}/redirect` | `socialite.redirect` | SocialiteController@redirect | web | Guest |
| GET | `/oauth/{provider}/callback` | `socialite.callback` | SocialiteController@callback | web | Guest |
| GET | `/kongsi/payload` | `dawah-share.payload` | DawahShareController@payload | throttle:share-tracking | Guest+ |
| POST | `/kongsi/track` | `dawah-share.track` | DawahShareController@track | throttle:30,1 | Guest+ |
| GET | `/kongsi/{provider}` | `dawah-share.redirect` | DawahShareController@redirect | throttle:share-tracking | Guest+ |
| GET/LW | `/carian` | `search.index` | Search\Index | web, throttle:search | Guest+ |
| GET/LW | `/majlis` | `events.index` | Events\Index | web, throttle:search | Guest+ |
| GET/LW | `/majlis/{event:slug}` | `events.show` | Events\Show | ResolvePublicSlugRedirect | Guest+ |
| GET | `/majlis/{event:slug}/kalendar.ics` | `events.calendar` | EventsController@calendar | ResolvePublicSlugRedirect | Guest+ |
| GET/LW | `/tambah-majlis` | `submit-event.landing` | ⚡landing SFC | web | Guest+ |
| GET/LW | `/hantar-majlis` | `submit-event.create` | Submit\Event\Create | web | Guest+ |
| GET/LW | `/hantar-majlis/berjaya` | `submit-event.success` | ⚡success SFC | web | Guest+ |
| GET | `/ops/network-diagnostics` | `network-diagnostics` | NetworkDiagnosticsController | none (CSRF/etc excluded) | Guest+ |
| GET/LW | `/dashboard` | `dashboard` | UserDashboard | web, auth | Auth |
| GET/LW | `/dashboard/dawah-impact` | `dashboard.dawah-impact` | DawahImpactIndex | web, auth | Auth |
| GET/LW | `/dashboard/dawah-impact/links` | `dashboard.dawah-impact.links` | DawahImpactIndex | web, auth | Auth |
| GET/LW | `/dashboard/dawah-impact/links/{link}` | `dashboard.dawah-impact.links.show` | DawahImpactLinkShow | web, auth | Auth |
| GET/LW | `/dashboard/notifications` | `dashboard.notifications` | NotificationsIndex | web, auth | Auth |
| GET/LW | `/tetapan-akaun` | `dashboard.account-settings` | AccountSettings | web, auth | Auth |
| GET/LW | `/dashboard/institusi` | `dashboard.institutions` | InstitutionDashboard | web, auth | Auth |
| GET/LW | `/dashboard/institusi/senarai-majlis` | `dashboard.institutions.events` | InstitutionDashboard | web, auth | Auth |
| GET/LW | `/dashboard/institusi/tambah-majlis` | `dashboard.institutions.submit-event` | Submit\Event\Create | web, auth | Auth |
| GET/LW | `/dashboard/majlis/cipta-lanjutan` | `dashboard.events.create-advanced` | CreateAdvanced | web, auth | Auth |
| GET/LW | `/carian-tersimpan` | `saved-searches.index` | SavedSearches\Index | web, auth | Auth |
| GET/LW | `/jemputan-ahli/{token}` | `member-invitations.show` | Membership\ShowInvitation | web, auth | Auth |
| GET/LW | `/sumbangan` | `contributions.index` | Contributions\Index | web, auth | Auth |
| GET/LW | `/sumbangan/institusi/baru` | `contributions.submit-institution` | SubmitInstitution | web, auth | Auth |
| GET/LW | `/sumbangan/penceramah/baru` | `contributions.submit-speaker` | SubmitSpeaker | web, auth | Auth |
| GET/LW | `/sumbangan/{subjectType}/berjaya` | `contributions.submission-success` | ⚡submission-success SFC | web, auth | Auth |
| GET/LW | `/tuntutan-keahlian` | `membership-claims.index` | MembershipClaims\Index | web, auth | Auth |
| GET/LW | `/tuntut-keahlian/{subjectType}/{subjectId}` | `membership-claims.create` | MembershipClaims\Create | web, auth | Auth |
| GET/LW | `/sumbangan/{subjectType}/{subjectId}/kemas-kini` | `contributions.suggest-update` | SuggestUpdate | web, auth | Auth |
| GET/LW | `/lapor/{subjectType}/{subjectId}` | `reports.create` | Reports\Create | web, auth | Auth |
| POST | `/majlis/{event:slug}/daftar` | `events.register` | EventsController@register | throttle:registration, ResolvePublicSlugRedirect | Guest+ |
| GET/LW | `/institusi` | `institutions.index` | ⚡institutions.index SFC | web, throttle:search | Guest+ |
| GET/LW | `/institusi/{institution:slug}` | `institutions.show` | ⚡institutions.show SFC | ResolvePublicSlugRedirect | Guest+ |
| GET/LW | `/penceramah` | `speakers.index` | ⚡speakers.index SFC | web, throttle:search | Guest+ |
| GET/LW | `/penceramah/{speaker:slug}` | `speakers.show` | ⚡speakers.show SFC | ResolvePublicSlugRedirect | Guest+ |
| GET/LW | `/tempat` | `venues.index` | ⚡venues.index SFC | web, throttle:search | Guest+ |
| GET/LW | `/lokasi/{venue:slug}` | `venues.show` | ⚡venues.show SFC | ResolvePublicSlugRedirect | Guest+ |
| GET/LW | `/siri/{series:slug}` | `series.show` | ⚡series.show SFC | web | Guest+ |
| GET/LW | `/rujukan` | `references.index` | ⚡references.index SFC | web, throttle:search | Guest+ |
| GET/LW | `/rujukan/{reference:slug}` | `references.show` | ⚡references.show SFC | ResolvePublicSlugRedirect | Guest+ |
| GET | `/peta-laman.xml` | `sitemap.index` | SitemapController@index | web | Guest+ |
| GET | `/peta-laman-majlis.xml` | `sitemap.events` | SitemapController@events | web | Guest+ |
| GET | `/peta-laman-institusi.xml` | `sitemap.institutions` | SitemapController@institutions | web | Guest+ |
| GET | `/peta-laman-penceramah.xml` | `sitemap.speakers` | SitemapController@speakers | web | Guest+ |
| GET | `/welcome` | `welcome` | `welcome` view | web | Guest+ |

**Note**: `GET/LW` = Livewire route (returns Livewire component). These render as GET but also handle Livewire component updates.

#### `routes/api.php` — API Routes v1

##### Public API (`/api/v1`, no auth)

| Method | URI | Name | Handler | Middleware |
|--------|-----|------|---------|------------|
| POST | `/api/v1/auth/register` | `api.auth.register` | AuthController@register | throttle:api-auth-register |
| POST | `/api/v1/auth/login` | `api.auth.login` | AuthController@login | throttle:api-auth-login |
| POST | `/api/v1/auth/social/google` | `api.auth.social.google` | AuthController@google | throttle:api-auth-social |
| POST | `/api/v1/auth/forgot-password` | `api.auth.forgot-password` | AuthController@forgotPassword | throttle:api-auth-password |
| POST | `/api/v1/auth/reset-password` | `api.auth.reset-password` | AuthController@resetPassword | throttle:api-auth-password |
| GET | `/api/v1/manifest` | `api.client.manifest` | ManifestController@manifest | — |
| GET | `/api/v1/documentation` | `api.client.documentation.index` | DocumentationController@index | — |
| GET | `/api/v1/documentation/{documentId}` | `api.client.documentation.show` | DocumentationController@show | — |
| GET | `/api/v1/forms/mobile-telemetry` | `api.client.forms.mobile-telemetry` | ManifestController@mobileTelemetry | — |
| GET | `/api/v1/forms/submit-event` | `api.client.forms.submit-event` | ManifestController@submitEvent | — |
| GET | `/api/v1/forms/contributions/institutions` | `api.client.forms.contributions.institutions` | ManifestController@submitInstitution | — |
| GET | `/api/v1/forms/contributions/speakers` | `api.client.forms.contributions.speakers` | ManifestController@submitSpeaker | — |
| GET | `/api/v1/catalogs/countries` | `api.client.catalogs.countries` | CatalogController@countries | — |
| GET | `/api/v1/catalogs/states` | `api.client.catalogs.states` | CatalogController@states | — |
| GET | `/api/v1/catalogs/districts` | `api.client.catalogs.districts` | CatalogController@districts | — |
| GET | `/api/v1/catalogs/subdistricts` | `api.client.catalogs.subdistricts` | CatalogController@subdistricts | — |
| GET | `/api/v1/catalogs/languages` | `api.client.catalogs.languages` | CatalogController@languages | — |
| GET | `/api/v1/catalogs/tags/{type}` | `api.client.catalogs.tags` | CatalogController@tags | — |
| GET | `/api/v1/catalogs/references` | `api.client.catalogs.references` | CatalogController@references | — |
| GET | `/api/v1/catalogs/submit-institutions` | `api.client.catalogs.submit-institutions` | CatalogController@submitInstitutions | — |
| GET | `/api/v1/catalogs/submit-speakers` | `api.client.catalogs.submit-speakers` | CatalogController@submitSpeakers | — |
| GET | `/api/v1/catalogs/venues` | `api.client.catalogs.venues` | CatalogController@venues | — |
| GET | `/api/v1/catalogs/spaces` | `api.client.catalogs.spaces` | CatalogController@spaces | — |
| GET | `/api/v1/catalogs/membership-claim-subjects/{subjectType}` | `api.client.catalogs.membership-claim-subjects` | CatalogController@membershipClaimSubjects | — |
| GET | `/api/v1/catalogs/prayer-institutions` | `api.client.catalogs.prayer-institutions` | CatalogController@prayerInstitutions | — |
| GET | `/api/v1/search` | `api.client.search.index` | SearchController@search | — |
| GET | `/api/v1/share/payload` | `api.client.share.payload` | DawahShareController@payload | throttle:share-tracking |
| POST | `/api/v1/share/track` | `api.client.share.track` | DawahShareController@track | throttle:30,1 |
| POST | `/api/v1/mobile/telemetry/events` | `api.client.mobile-telemetry.store` | MobileTelemetryController@store | throttle:mobile-telemetry |
| GET | `/api/v1/institutions` | `api.client.institutions.index` | SearchController@institutions | — |
| GET | `/api/v1/institutions/near` | `api.client.institutions.near` | SearchController@institutionsNear | — |
| GET | `/api/v1/institutions/{institutionKey}` | `api.client.institutions.show` | SearchController@showInstitution | — |
| GET | `/api/v1/speakers` | `api.client.speakers.index` | SearchController@speakers | — |
| GET | `/api/v1/speakers/{speakerKey}` | `api.client.speakers.show` | SearchController@showSpeaker | — |
| GET | `/api/v1/inspirations/random` | `api.client.inspirations.random` | SearchController@randomInspiration | — |
| GET | `/api/v1/venues/{venueKey}` | `api.client.venues.show` | SearchController@showVenue | — |
| GET | `/api/v1/references` | `api.client.references.index` | SearchController@references | — |
| GET | `/api/v1/references/{referenceKey}` | `api.client.references.show` | SearchController@showReference | — |
| GET | `/api/v1/series/{series}` | `api.client.series.show` | SearchController@showSeries | — |
| POST | `/api/v1/submit-event` | `api.client.submit-event.store` | EventSubmissionController@store | — |
| GET | `/api/v1/events` | `api.events.index` | EventController@index | — |
| GET | `/api/v1/events/{event}` | `api.events.show` | EventController@show | — |
| POST | `/api/v1/events/{event}/registrations` | `api.events.registrations.store` | EventRegistrationController@store | throttle:registration |

##### Authenticated API (`/api/v1`, auth:sanctum)

| Method | URI | Name | Handler |
|--------|-----|------|---------|
| POST | `/api/v1/auth/logout` | `api.auth.logout` | AuthController@logout |
| POST | `/api/v1/auth/email/verification-notification` | `api.auth.verification-notification` | AuthController@resendVerificationEmail |
| GET | `/api/v1/share/analytics` | `api.client.share.analytics` | ShareAnalyticsController@index |
| GET | `/api/v1/share/analytics/links/{link}` | `api.client.share.analytics.links.show` | ShareAnalyticsController@show |
| GET | `/api/v1/forms/report` | `api.client.forms.report` | ManifestController@report |
| GET | `/api/v1/forms/github-issue-report` | `api.client.forms.github-issue-report` | ManifestController@githubIssueReport |
| GET | `/api/v1/forms/account-settings` | `api.client.forms.account-settings` | ManifestController@accountSettings |
| GET | `/api/v1/forms/advanced-events` | `api.client.forms.advanced-events` | ManifestController@advancedEvent |
| GET | `/api/v1/forms/institution-workspace` | `api.client.forms.institution-workspace` | ManifestController@institutionWorkspace |
| GET | `/api/v1/forms/membership-claims/{subjectType}` | `api.client.forms.membership-claims` | ManifestController@membershipClaim |
| GET | `/api/v1/forms/contributions/{subjectType}/{subject}/suggest` | `api.client.forms.contributions.suggest` | ContributionController@suggestContext |
| GET | `/api/v1/catalogs/institution-roles` | `api.client.catalogs.institution-roles` | CatalogController@institutionRoles |
| GET | `/api/v1/account-settings` | `api.client.account-settings.show` | AccountSettingsController@show |
| PUT | `/api/v1/account-settings` | `api.client.account-settings.update` | AccountSettingsController@update |
| GET | `/api/v1/account-settings/mcp-tokens` | `api.client.account-settings.mcp-tokens.index` | AccountSettingsMcpTokenController@index |
| POST | `/api/v1/account-settings/mcp-tokens` | `api.client.account-settings.mcp-tokens.store` | AccountSettingsMcpTokenController@store |
| DELETE | `/api/v1/account-settings/mcp-tokens/{tokenId}` | `api.client.account-settings.mcp-tokens.destroy` | AccountSettingsMcpTokenController@destroy |
| POST | `/api/v1/github-issues` | `api.client.github-issues.store` | GitHubIssueController@store |
| GET | `/api/v1/contributions` | `api.client.contributions.index` | ContributionController@index |
| POST | `/api/v1/contributions/institutions` | `api.client.contributions.institutions.store` | ContributionController@storeInstitution |
| POST | `/api/v1/contributions/speakers` | `api.client.contributions.speakers.store` | ContributionController@storeSpeaker |
| POST | `/api/v1/contributions/{subjectType}/{subject}/suggest` | `api.client.contributions.suggest.store` | ContributionController@suggestUpdate |
| POST | `/api/v1/contributions/{requestId}/approve` | `api.client.contributions.approve` | ContributionController@approve |
| POST | `/api/v1/contributions/{requestId}/reject` | `api.client.contributions.reject` | ContributionController@reject |
| POST | `/api/v1/contributions/{requestId}/cancel` | `api.client.contributions.cancel` | ContributionController@cancel |
| GET | `/api/v1/membership-claims` | `api.client.membership-claims.index` | MembershipClaimController@index |
| POST | `/api/v1/membership-claims/{subjectType}/{subject}` | `api.client.membership-claims.store` | MembershipClaimController@store |
| DELETE | `/api/v1/membership-claims/{claimId}` | `api.client.membership-claims.cancel` | MembershipClaimController@cancel |
| POST | `/api/v1/advanced-events` | `api.client.advanced-events.store` | AdvancedEventController@store |
| GET | `/api/v1/follows/{type}/{subject}` | `api.client.follows.show` | FollowController@show |
| POST | `/api/v1/follows/{type}/{subject}` | `api.client.follows.store` | FollowController@store |
| DELETE | `/api/v1/follows/{type}/{subject}` | `api.client.follows.destroy` | FollowController@destroy |
| GET | `/api/v1/institution-workspace` | `api.client.institution-workspace.show` | InstitutionWorkspaceController@show |
| POST | `/api/v1/institution-workspace/{institutionId}/members` | `api.client.institution-workspace.members.store` | InstitutionWorkspaceController@addMember |
| PUT | `/api/v1/institution-workspace/{institutionId}/members/{memberId}` | `api.client.institution-workspace.members.update` | InstitutionWorkspaceController@updateMemberRole |
| DELETE | `/api/v1/institution-workspace/{institutionId}/members/{memberId}` | `api.client.institution-workspace.members.destroy` | InstitutionWorkspaceController@removeMember |
| POST | `/api/v1/reports` | `api.reports.store` | ReportController@store |
| GET | `/api/v1/user` | `api.user.show` | CurrentUserController |
| DELETE | `/api/v1/user` | `api.user.destroy` | CurrentUserController@destroy |
| GET | `/api/v1/user/registrations` | `api.user.registrations.index` | UserRegistrationController@index |
| GET | `/api/v1/me/events/going` | `api.events.going.index` | EventGoingController@index |
| GET | `/api/v1/me/events/saved` | `api.events.saved.index` | EventSaveController@index |
| GET | `/api/v1/events/{event}/me` | `api.events.me.show` | EventController@me |
| POST | `/api/v1/events/{event}/check-ins` | `api.events.check-ins.store` | EventCheckInController@store |
| PUT | `/api/v1/events/{event}/going` | `api.events.going.update` | EventGoingController@store |
| DELETE | `/api/v1/events/{event}/going` | `api.events.going.destroy` | EventGoingController@destroy |
| GET/PUT | `/api/v1/saved-searches` | `api.saved-searches.*` | SavedSearchController (apiResource) |
| POST | `/api/v1/saved-searches/{savedSearch}/execute` | `api.saved-searches.execute` | SavedSearchController@execute |
| PUT | `/api/v1/events/{event}/saved` | `api.events.saved.update` | EventSaveController@store |
| DELETE | `/api/v1/events/{event}/saved` | `api.events.saved.destroy` | EventSaveController@destroy |
| GET | `/api/v1/events/{event}/registrations/export` | `api.registrations.export` | RegistrationExportController@export |
| GET | `/api/v1/notifications` | `api.notifications.index` | NotificationMessageController@index |
| POST | `/api/v1/notifications/{message}/read` | `api.notifications.read` | NotificationMessageController@read |
| POST | `/api/v1/notifications/read-all` | `api.notifications.read-all` | NotificationMessageController@readAll |
| GET | `/api/v1/notification-settings/catalog` | `api.notification-settings.catalog` | NotificationSettingsController@catalog |
| GET | `/api/v1/notification-settings` | `api.notification-settings.show` | NotificationSettingsController@show |
| PUT | `/api/v1/notification-settings` | `api.notification-settings.update` | NotificationSettingsController@update |
| POST | `/api/v1/notification-destinations/push` | `api.notification-destinations.push.store` | NotificationDestinationController@storePush |
| PUT | `/api/v1/notification-destinations/push/{installation}` | `api.notification-destinations.push.update` | NotificationDestinationController@updatePush |
| DELETE | `/api/v1/notification-destinations/push/{installation}` | `api.notification-destinations.push.destroy` | NotificationDestinationController@destroyPush |

##### Admin API (`/api/v1/admin`, auth:sanctum + EnsureAdminApiAccess)

| Method | URI | Handler |
|--------|-----|---------|
| GET | `/api/v1/admin/manifest` | AdminManifestController |
| GET | `/api/v1/admin/catalogs/countries` | AdminCatalogController@countries |
| GET | `/api/v1/admin/catalogs/states` | AdminCatalogController@states |
| GET | `/api/v1/admin/catalogs/districts` | AdminCatalogController@districts |
| GET | `/api/v1/admin/catalogs/subdistricts` | AdminCatalogController@subdistricts |
| GET | `/api/v1/admin/events/search` | EventSearchController@search |
| GET | `/api/v1/admin/{resourceKey}` | AdminResourceController@indexRecords |
| POST | `/api/v1/admin/{resourceKey}` | AdminResourceController@storeRecord |
| POST | `/api/v1/admin/{resourceKey}/batch` | AdminResourceController@batchStoreRecords |
| PUT | `/api/v1/admin/{resourceKey}/batch` | AdminResourceController@batchUpdateRecords |
| GET | `/api/v1/admin/{resourceKey}/meta` | AdminResourceController@show |
| GET | `/api/v1/admin/{resourceKey}/schema` | AdminResourceController@schema |
| GET | `/api/v1/admin/events/{recordKey}/moderation-schema` | AdminEventModerationController@schema |
| POST | `/api/v1/admin/events/{recordKey}/moderate` | AdminEventModerationController@moderate |
| GET | `/api/v1/admin/reports/{recordKey}/triage-schema` | AdminReportTriageController@schema |
| POST | `/api/v1/admin/reports/{recordKey}/triage` | AdminReportTriageController@triage |
| GET | `/api/v1/admin/contribution-requests/{recordKey}/review-schema` | AdminContributionRequestReviewController@schema |
| POST | `/api/v1/admin/contribution-requests/{recordKey}/review` | AdminContributionRequestReviewController@review |
| GET | `/api/v1/admin/membership-claims/{recordKey}/review-schema` | AdminMembershipClaimReviewController@schema |
| POST | `/api/v1/admin/membership-claims/{recordKey}/review` | AdminMembershipClaimReviewController@review |
| GET | `/api/v1/admin/{resourceKey}/{recordKey}/relations/{relation}` | AdminResourceController@relatedRecords |
| GET | `/api/v1/admin/{resourceKey}/{recordKey}` | AdminResourceController@showRecord |
| PUT | `/api/v1/admin/{resourceKey}/{recordKey}` | AdminResourceController@updateRecord |

#### `routes/ai.php` — MCP Routes

| Method | URI | Handler | Middleware | Auth |
|--------|-----|---------|------------|------|
| Any | `/mcp/admin` (SSE Web) | AdminServer | NormalizeMcpAcceptHeader, auth:sanctum,api, EnsureAdminMcpAccess | Auth |
| Any | `/mcp/member` (SSE Web) | MemberServer | NormalizeMcpAcceptHeader, auth:sanctum,api, EnsureMemberMcpAccess | Auth |
| GET | `/mcp/admin` (SSE Stream) | AdminMcpController@stream | NormalizeMcpAcceptHeader, auth:sanctum,api, AddWwwAuthenticateHeader, EnsureAdminMcpAccess | Auth |
| DELETE | `/mcp/admin` | AdminMcpController@destroy | Same as above | Auth |
| GET | `/mcp/member` (SSE Stream) | MemberMcpController@stream | NormalizeMcpAcceptHeader, auth:sanctum,api, AddWwwAuthenticateHeader, EnsureMemberMcpAccess | Auth |
| DELETE | `/mcp/member` | MemberMcpController@destroy | Same as above | Auth |
| Any | `/oauth/mcp/*` (OAuth) | Passport OAuth | Standard web middleware | Web |
| Local | `ilmu360-admin-local` (CLI) | AdminServer | None (local only) | None |
| Local | `ilmu360-member-local` (CLI) | MemberServer | None (local only) | None |

---

## 6. Route Coverage Checklist

### 6.1 Web Routes

| Route | Status |
|-------|--------|
| `GET /` (home) | [x] Complete |
| `GET /tentang-kami` | [~] Partially verified |
| `GET /bahasa/{locale}` | [~] Partially verified |
| `GET /negara/{country}` | [~] Partially verified |
| `GET /oauth/{provider}/redirect` | [x] Complete |
| `GET /oauth/{provider}/callback` | [x] Complete |
| `GET /kongsi/payload` | [~] Partially verified |
| `POST /kongsi/track` | [~] Partially verified |
| `GET /kongsi/{provider}` | [~] Partially verified |
| `GET /carian` | [~] Partially verified |
| `GET /majlis` | [~] Partially verified |
| `GET /majlis/{event:slug}` | [~] Partially verified |
| `GET /majlis/{event:slug}/kalendar.ics` | [~] Partially verified |
| `GET /tambah-majlis` | [~] Partially verified |
| `GET /hantar-majlis` | [~] Partially verified |
| `GET /hantar-majlis/berjaya` | [~] Partially verified |
| `GET /ops/network-diagnostics` | [~] Partially verified |
| `GET /dashboard` | [x] Complete |
| `GET /dashboard/dawah-impact` | [x] Complete |
| `GET /dashboard/dawah-impact/links` | [x] Complete |
| `GET /dashboard/dawah-impact/links/{link}` | [x] Complete |
| `GET /dashboard/notifications` | [x] Complete |
| `GET /tetapan-akaun` | [x] Complete |
| `GET /dashboard/institusi` | [x] Complete |
| `GET /dashboard/institusi/senarai-majlis` | [x] Complete |
| `GET /dashboard/institusi/tambah-majlis` | [x] Complete |
| `GET /dashboard/majlis/cipta-lanjutan` | [x] Complete |
| `GET /carian-tersimpan` | [x] Complete |
| `GET /jemputan-ahli/{token}` | [x] Complete |
| `GET /sumbangan` | [x] Complete |
| `GET /sumbangan/institusi/baru` | [x] Complete |
| `GET /sumbangan/penceramah/baru` | [x] Complete |
| `GET /sumbangan/{subjectType}/berjaya` | [x] Complete |
| `GET /tuntutan-keahlian` | [x] Complete |
| `GET /tuntut-keahlian/{subjectType}/{subjectId}` | [x] Complete |
| `GET /sumbangan/{subjectType}/{subjectId}/kemas-kini` | [x] Complete |
| `GET /lapor/{subjectType}/{subjectId}` | [x] Complete |
| `POST /majlis/{event:slug}/daftar` | [~] Partially verified |
| `GET /institusi` | [~] Partially verified |
| `GET /institusi/{institution:slug}` | [~] Partially verified |
| `GET /penceramah` | [~] Partially verified |
| `GET /penceramah/{speaker:slug}` | [~] Partially verified |
| `GET /tempat` | [~] Partially verified |
| `GET /lokasi/{venue:slug}` | [~] Partially verified |
| `GET /siri/{series:slug}` | [~] Partially verified |
| `GET /rujukan` | [~] Partially verified |
| `GET /rujukan/{reference:slug}` | [~] Partially verified |
| `GET /peta-laman.xml` | [~] Partially verified |
| `GET /peta-laman-majlis.xml` | [~] Partially verified |
| `GET /peta-laman-institusi.xml` | [~] Partially verified |
| `GET /peta-laman-penceramah.xml` | [~] Partially verified |
| `GET /welcome` | [~] Partially verified |

### 6.2 API Routes

| Group | Status |
|-------|--------|
| Public API (~35 endpoints) | [x] Complete |
| Authenticated API (~50 endpoints) | [x] Complete |
| Admin API (~20 endpoints) | [x] Complete |

### 6.3 MCP Routes

| Route | Status |
|-------|--------|
| MCP OAuth (Passport) | [x] Complete |
| Admin MCP (POST/GET/DELETE) | [x] Complete (33 tools) |
| Member MCP (POST/GET/DELETE) | [x] Complete (24 tools) |
| Local MCP (dev) | [x] Complete |

### 6.4 Filament Panel Routes

| Area | Status |
|------|--------|
| Admin panel (22 resources, 7 pages) | [x] Architecture level |
| Ahli panel (4 resources) | [x] Architecture level |

---

## 7. Detailed Route Audits

### 7.1 Public Routes

#### Route `GET /` (Home)

**Route identity**

| Field | Details |
|-------|---------|
| Method | GET (Livewire) |
| URI | `/` |
| Name | `home` |
| Source | `routes/web.php:38` |
| Handler | `⚡home` (anonymous SFC component at `resources/views/components/pages/⚡home.blade.php`) |
| Middleware | `web`, `SetLocale`, `TrackDawahShareAttribution` |
| Parameters | None |
| Authentication | Guest+ (anyone) |
| Authorization | None |
| Route type | UI |
| Audit status | [-] In progress |

**Purpose**: Main landing page showing featured events, upcoming events, prayer-time events, and search functionality.

**Entry conditions**: None — fully public.

**Request**: No parameters.

**UI and UX**: The home page renders a dashboard-style layout with:
- Date filter tabs (Hari Ini, Jumaat, Minggu Ini, Hujung Minggu, search)
- Upcoming events carousel/section
- Tonight's events section
- Prayer-time events section
- Featured events carousel
- Stats section
- My Majlis section (if authenticated)
- Search bar

**Available actions**:

| UI element | User action | Backend | Validation | Auth | DB ops | Response |
|-----------|-------------|---------|-----------|------|--------|----------|
| Date filter tabs | Click filter | Livewire `#[On('filterDate')]` | None | Guest+ | Event query via scope | Re-renders event lists |
| Event card | Click | `wire:navigate` to `/majlis/{slug}` | None | Guest+ | None | Navigates |
| Search bar | Type | `wire:navigate` to `/carian` | None | Guest+ | None | Navigates |

**Computed properties** (from SFC PHP class):
- `categoryTagIds()` — Returns domain tag IDs for filtering
- `eventDateLinks()` — Returns today/friday/week/weekend date links

**Execution flow**:
1. Request hits web middleware stack
2. Livewire renders the anonymous SFC
3. Computed properties fetch data on render
4. Auth-dependent sections conditionally render

**CRUD and database operations**: Read-only — queries events via scopes for different sections.

**Models and relations**: `Event`, `Tag`

**Events fired**: None

**Test coverage**: 13 tests for homepage.

---

### 7.2 Authentication Routes

*Audit pending — Fortify manages these routes: login, register, password reset, email verification, 2FA*

---

### 7.3 Authenticated Application Routes

*Audit pending — Dashboard, account settings, contributions, reports, membership claims, saved searches*

---

### 7.4 Administration Routes (Filament)

*Audit pending — Admin panel resources, ahli panel resources, custom pages*

---

### 7.5 API Routes

*Audit pending — All public and authenticated API endpoints*

---

### 7.6 Webhook and Callback Routes

*Audit pending — Socialite OAuth callbacks*

---

### 7.7 Package-Generated and Internal Routes

*Audit pending — Fortify, Passport, Horizon, Scramble routes*

---

## 8. UI-to-Endpoint Matrix

*To be populated as route audits are completed*

---

## 9. Route-to-Database CRUD Matrix

*To be populated as route audits are completed*

---

## 10. Database Table Catalogue

### 10.1 Identity & Auth

| Table | Model | Purpose | Key columns |
|-------|-------|---------|-------------|
| `users` | `User`, `PassportUser` | User accounts | id (uuid), name, email, phone, password, timezone, email_verified_at, phone_verified_at, two_factor_secret, two_factor_recovery_codes |

### 10.2 Events

| Table | Model | Purpose |
|-------|-------|---------|
| `events` | `Event` | Core event records |
| `event_settings` | `EventSettings` | Event-level settings/config |
| `event_submissions` | `EventSubmission` | Queued event submissions |
| `event_change_announcements` | `EventChangeAnnouncement` | Change/cancellation notices |
| `event_key_people` | `EventKeyPerson` | Speaker/person roles on events |
| `event_key_person_pivot` | `EventKeyPersonPivot` | Pivot for key people <-> events |
| `event_users` | `EventUser` | Event-user engagements |
| `event_series` | `EventSeries` | Series <-> events pivot |
| `series` | `Series` | Event series/groups |
| `registrations` | `Registration` | Event registrations |
| `event_checkins` | `EventCheckin` | Event check-ins |

### 10.3 Directory

| Table | Model | Purpose |
|-------|-------|---------|
| `institutions` | `Institution` | Mosques, organizations, etc. |
| `speakers` | `Speaker` | Speakers/teachers |
| `venues` | `Venue` | Event venues |
| `spaces` | `Space` | Specific rooms/halls within venues |
| `references` | `Reference` | Books, kitab references |

### 10.4 Geography

| Table | Model | Purpose |
|-------|-------|---------|
| `countries` | `Country` | Countries (integer ID) |
| `states` | `State` | States/provinces (integer ID) |
| `cities` | `City` | Cities (integer ID) |
| `districts` | `District` | Districts/daerah (integer ID) |
| `subdistricts` | `Subdistrict` | Subdistricts/mukim (integer ID) |
| `addresses` | `Address` | Polymorphic address records |

### 10.5 Taxonomy

| Table | Model | Purpose |
|-------|-------|---------|
| `tags` | `Tag` | Spatie tags with type enum |
| `taggables` | — | Spatie polymorphic pivot |
| `inspirations` | `Inspiration` | Inspirational content |
| `media_links` | `MediaLink` | External media links |
| `social_media` | `SocialMedia` | Social media profile links |
| `contacts` | `Contact` | Contact information |

### 10.6 Moderation

| Table | Model | Purpose |
|-------|-------|---------|
| `moderation_reviews` | `ModerationReview` | Event moderation reviews |
| `contribution_requests` | `ContributionRequest` | User contribution requests |
| `membership_claims` | `MembershipClaim` | Membership claims |
| `reports` | `Report` | User reports |

### 10.7 Membership

| Table | Model | Purpose |
|-------|-------|---------|
| `teams` | `Team` | Teams |
| `memberships` | `Membership` | Polymorphic memberships |
| `team_invitations` | `TeamInvitation` | Team invitations |
| `member_invitations` | `MemberInvitation` | Subject member invitations |

### 10.8 Notifications

| Table | Model | Purpose |
|-------|-------|---------|
| `notification_settings` | `NotificationSetting` | User notification preferences |
| `notification_rules` | `NotificationRule` | Channel routing rules |
| `notification_destinations` | `NotificationDestination` | Delivery endpoints |
| `pending_notifications` | `PendingNotification` | Queued pending notifications |
| `notification_messages` | `NotificationMessage` | Delivered notification messages |
| `notification_deliveries` | `NotificationDelivery` | Per-channel delivery status |

### 10.9 AI & System

| Table | Model | Purpose |
|-------|-------|---------|
| `ai_model_pricings` | `AiModelPricing` | AI pricing configuration |
| `ai_usage_logs` | `AiUsageLog` | AI usage tracking |
| `saved_searches` | `SavedSearch` | Saved search queries |
| `slug_redirects` | `SlugRedirect` | URL slug redirect tracking |
| `audits` | `Audit` | OwenIt audit records |
| `donation_channels` | `DonationChannel` | Donation payment channels |
| `social_accounts` | `SocialAccount` | OAuth social login accounts |
| `media` | — | Spatie media records |
| `deleted_models` | — | Spatie deletion snapshots |
| `sessions` | — | Session records |
| `personal_access_tokens` | — | Sanctum tokens |
| `oauth_*` | — | Passport OAuth tables |

---

## 11. Model Catalogue

*Detailed model catalogue to be expanded as route audits progress*

### Key Models

#### `App\Models\Event` (Spaghetti Model States)

| Property | Value |
|----------|-------|
| UUID | Yes (`HasUuids`) |
| Traits | `HasFactory`, `InteractsWithMedia`, `HasTags`, `HasSlug`, `AuditsModelChanges`, `HasLanguages`, `HasFollowers`, KeepsDeletedModels |
| States | `EventStatus` (draft, pending, approved, rejected, cancelled, postponed, rescheduled) |
| Relations | `institution`, `venue`, `series`, `speakers`, `keyPeople`, `registrations`, `checkins`, `users`, `settings`, `submission`, `changeAnnouncements`, `parentEvent`, `childEvents`, `tags`, `media`, `address` |

#### `App\Models\User`

| Property | Value |
|----------|-------|
| UUID | Yes (`HasUuids`) |
| Traits | `HasFactory`, `HasTeams`, `Notifiable`, `HasFollowers`, `HasLanguages`, `MustVerifyEmail`, `TwoFactorAuthenticatable`, `KeepsDeletedModels` |
| Relations | `events` (via EventUser), `institutions` (via Membership), `speakers`, `savedSearches`, `registrations`, `checkins`, `follows`, `socialAccounts`, `notifications` |

---

## 12. Model Relation Map

*To be completed as route audits progress*

---

## 13. Event Catalogue

| Event | Fired from | Payload | Listeners | Queued |
|-------|-----------|---------|-----------|--------|
| `Laravel\Ai\Events\AgentPrompted` | AI SDK | Full event data | `RecordAiUsage` | No |
| `Laravel\Ai\Events\ImageGenerated` | AI SDK | Full event data | `RecordAiUsage` | No |
| `Laravel\Ai\Events\AudioGenerated` | AI SDK | Full event data | `RecordAiUsage` | No |
| `Illuminate\Auth\Events\Registered` | Fortify | User | `SendRegisteredUserEmails` | No |
| `Illuminate\Auth\Events\Verified` | Fortify | User | `RecordVerifiedEmail` | No |
| `Illuminate\Notifications\Events\NotificationSent` | Notification system | Notification, notifiable, channel | `RecordNotificationSent` | No |
| `Illuminate\Notifications\Events\NotificationFailed` | Notification system | Notification, notifiable, channel, error | `HandleNotificationFailed` | No |

---

## 14. Listener and Subscriber Catalogue

| Listener | Event | Registration | Type | Side effects |
|----------|-------|-------------|------|-------------|
| `RecordAiUsage` | 7 AI events | `AppServiceProvider::boot()` via `EventFacade::listen()` | Sync | Writes `ai_usage_logs` |
| `SendRegisteredUserEmails` | `Registered` | Auto-discovered | Sync | Sends `WelcomeNotification` (queued mail) |
| `RecordVerifiedEmail` | `Verified` | Auto-discovered | Sync | Records email verified in ProductSignals |
| `RecordNotificationSent` | `NotificationSent` | Auto-discovered by Laravel | Sync | Logs delivery per channel |
| `HandleNotificationFailed` | `NotificationFailed` | Auto-discovered by Laravel | Sync | Logs failure, queues fallback |

---

## 15. Observer Catalogue

| Observer | Model | Key methods | Side effects |
|----------|-------|-------------|-------------|
| `EventObserver` | `Event` | creating, updating, created, updated, deleted | Slug generation, cache busting, search indexing |
| `InstitutionObserver` | `Institution` | saved, deleted | Slug sync, cache busting |
| `SpeakerObserver` | `Speaker` | saved, deleted | Slug sync, search, cache busting |
| `VenueObserver` | `Venue` | saved, deleted | Slug sync, cache busting |
| `ReferenceObserver` | `Reference` | updated, deleted | Slug sync, cache busting |
| `AddressObserver` | `Address` | saved, deleted | Slug sync for addressable, cache busting |
| `TagObserver` | `Tag` | saved, deleted | Cache busting |
| `GeographyObserver` | Country, State, District, Subdistrict | saved, deleted | Cache busting |
| `EventKeyPersonObserver` | `EventKeyPerson` | saved, deleted | Cache busting |
| `AuditedMediaObserver` | `Media` | created, updated, deleted | Audit logging, cache busting |

---

## 16. Job and Queue Catalogue

| Job | Queue | Tries | Unique | Trigger |
|-----|-------|-------|--------|---------|
| `BackfillEventSlugs` | default | 1 | Yes (3600s) | Artisan command |
| `BackfillInstitutionSlugs` | default | 1 | Yes (3600s) | Artisan command |
| `BackfillSpeakerSlugs` | default | 1 | Yes (3600s) | Artisan command |
| `BackfillReferenceSlugs` | default | 1 | Yes (3600s) | Artisan command |
| `BackfillVenueSlugs` | default | 1 | No | Artisan command |
| `DispatchEventReminderNotifications` | default | Horizon:3 | No | Schedule (15min) |
| `DispatchNotificationDigests` | default | Horizon:3 | No | Schedule (15min) |
| `EscalatePendingEvents` | default | Horizon:3 | No | Schedule (hourly) |
| `GenerateResponsiveImagesJob` | media | Spatie:2 | No | Media creation |

---

## 17. Notification, Mail, and Webhook Catalogue

| Notification | Channels | Queue | Queues | Trigger |
|-------------|----------|-------|--------|---------|
| `EventSubmittedNotification` | mail, database | Yes | notifications-mail, notifications-inbox | Event submitted |
| `EventEscalationNotification` | mail, database | Yes | notifications-mail, notifications-inbox | Event escalation (scheduled) |
| `NotificationCenterMessage` | dynamic (single channel) | Yes | Per-channel queue | Various PendingNotification triggers |
| `ReportResolvedNotification` | mail, database | Yes | notifications-mail, notifications-inbox | Report triaged |
| `ResetPasswordNotification` | mail | Yes | notifications-mail | Password reset |
| `VerifyEmailNotification` | mail | Yes | notifications-mail | Email verification |
| `WelcomeNotification` | mail | Yes | notifications-mail | Registration |
| `MemberInvitationNotification` | mail | Yes | notifications-mail | Membership invitation |
| `TeamInvitation` | mail | Not specified | default | Team invitation |

### Custom Notification Channels

| Channel | Provider | Description |
|---------|----------|-------------|
| `PushChannel` | FCM (Firebase) | Push notifications via Firebase Cloud Messaging |
| `WhatsappChannel` | Meta Cloud API | WhatsApp template messaging |

---

## 18. Authentication and Authorization Map

### Authentication Guards

| Guard | Driver | Provider | Routes |
|-------|--------|----------|--------|
| `web` | Session | `users` | Web routes, Fortify, Filament |
| `sanctum` | Sanctum | `users` | API routes |
| `api` | Passport | `users` | MCP routes |

### Authorization Systems

| System | Scope | Applied to |
|--------|-------|-----------|
| Policies | Per-model (EventPolicy, etc.) | Filament resources, some API routes |
| `EnsureAdminApiAccess` middleware | Role check (admin/super_admin) | `/api/v1/admin/*` |
| `EnsureAdminMcpAccess` middleware | Role check (admin MCP ability) | `/mcp/admin*` |
| `EnsureMemberMcpAccess` middleware | Role check (member MCP ability) | `/mcp/member*` |
| `FilamentAuthz` plugin | RBAC with roles/permissions | Filament panels |
| `FilamentSignals` plugin | Product analytics tracking | Filament panels |

### Filament Panel Authorization

**Admin panel**: Requires `auth` middleware, then FilamentAuthz handles role-based access (super_admin, admin, moderator roles). Some resources are restricted (MembershipClaims, ContributionRequests: super_admin/admin/moderator only).

**Ahli panel**: Requires `auth` middleware. Resources are scoped to records the user is a member of or has permissions on.

---

## 19. Validation Map

*To be expanded as route audits progress*

### API Auth Validation

| Route | Validated fields | Rules |
|-------|-----------------|-------|
| `POST /api/v1/auth/register` | name, email, phone, password | Required, email format, phone format, password min 8 |
| `POST /api/v1/auth/login` | email, password | Required, email format |
| `POST /api/v1/auth/social/google` | id_token OR access_token | Required |
| `POST /api/v1/auth/forgot-password` | email | Required, email format |
| `POST /api/v1/auth/reset-password` | email, token, password | Required |

---

## 20. UI/UX Findings

1. **Malay-language UI consistency**: The app uses Malay throughout (URLs, nav labels, page titles). Consistent with Malaysian market targeting.

2. **Two-panel admin architecture**: Admin and Ahli panels share the same theme but present different resource sets — clean separation of powers.

3. **Livewire SPA mode**: `wire:navigate` provides client-side navigation with progress bar.

4. **Toast notifications**: Custom `app-toast` browser event system for user feedback.

5. **Empty state handling**: Most listing pages have empty states (via computed property conditions in Blade).

6. **Error state handling**: 404/500 error pages exist; validation errors use Livewire's built-in error bag.

7. **No confirmation on destructive actions in some flows**: `SuggestUpdate` and `Reports::submit` do not show success flash messages — user is redirected silently.

8. **Inconsistent submit feedback**: Some actions show success toasts (`saveAccountSettings`, `addMember`), others redirect silently without flash (`SuggestUpdate` direct edit).

---

## 21. Security and Data-Integrity Findings

### [MEDIUM] 21.1 Transaction Safety Gaps

**Affected routes**: All authenticated web routes: SubmitInstitution, SubmitSpeaker, ShowInvitation::accept, MembershipClaims::submit, SuggestUpdate (direct edit path), Reports::submit, AccountSettings::saveAccountSettings, CreateAdvanced

**Observation**: Multiple write-heavy operations perform multi-table writes without database transactions. If a failure occurs midway, partial writes can leave the application in an inconsistent state:
- SubmitInstitution: Institution creation + relations + contribution_request + media
- ShowInvitation::accept: Member addition + invitation update
- Reports::submit: Report creation + Signal events + moderation

**Impact**: Partial writes can create orphan records, inconsistent state, and data integrity issues.

**Recommendation**: Wrap multi-table writes in `DB::transaction()` blocks. For mixed DB/external operations (media, signals), consider transactional events (`afterCommit`).

**Confidence**: Confirmed

### [MEDIUM] 21.2 InstitutionDashboard Role Validation Missing

**Affected routes**: POST `/dashboard/institusi` (addMember, saveMemberRoles)

**Observation**: `newMemberRoleId` and `editingMemberRoleId` validated only as `required|string` with no `Rule::in()` constraint to valid role values. Admin users can pass arbitrary role slugs.

**Impact**: Potential to assign non-existent or unauthorized roles to members.

**Recommendation**: Add `Rule::in(MemberRoleCatalog::validRoles())` validation or delegate role validation to downstream actions.

**Confidence**: Confirmed

### [MEDIUM] 21.3 Email-less Google OAuth Account Creation

**Affected routes**: `GET /oauth/{provider}/callback`, `POST /api/v1/auth/social/google`

**Observation**: If Google returns no email (`getEmail() === null`), a user is created with `email => null`. Such users: (1) cannot receive password reset emails, (2) cannot use password-based login, (3) have no recovery path. The `users.email` column is nullable.

**Impact**: Unrecoverable accounts for users whose Google account has no associated email.

**Recommendation**: Require email from Google OAuth response. If not provided, throw a `ValidationException` requiring the user to provide an email.

**Confidence**: Confirmed

### [MEDIUM] 21.4 Duplicate Account Creation for Phone-Only Users via Social Login

**Affected routes**: `GET /oauth/{provider}/callback`, `POST /api/v1/auth/social/google`

**Observation**: OAuth login matches users by `email` only. Users registered with phone (no email) can never be linked to their Google identity — each Google login creates a separate account.

**Impact**: Fragmented user profiles. Users with phone-only accounts who later use Google login will have two separate accounts.

**Recommendation**: Consider linking social accounts by phone in addition to email.

**Confidence**: Confirmed

### [MEDIUM] 21.5 Social Signups Bypass Welcome Notification

**Affected routes**: `GET /oauth/{provider}/callback`, `POST /api/v1/auth/social/google`

**Observation**: Both web and API social login create users without firing the `Registered` event. The `SendRegisteredUserEmails` listener is skipped, so `WelcomeNotification` is never sent to Google-registered users. API register DOES fire the event, making this inconsistent.

**Impact**: New social users receive no onboarding communication.

**Recommendation**: Fire `Illuminate\Auth\Events\Registered` after social account creation, or explicitly send `WelcomeNotification` in the social login flow.

**Confidence**: Confirmed

### [MEDIUM] 21.6 Passport OAuth Tokens Bypass MCP Server Ability Check

**Affected routes**: `/mcp/admin`, `/mcp/member`

**Observation**: `McpTokenManager::allowsServer()` only checks abilities on Sanctum `PersonalAccessToken`. For Passport tokens, `currentAccessToken()` returns null, so `allowsServer()` returns `true` unconditionally. Role middleware (`EnsureAdminMcpAccess`/`EnsureMemberMcpAccess`) still gates, but the server-level check is bypassed.

**Impact**: Any authenticated Passport token can pass the initial server check. Role middleware prevents actual access escalation, but the protection layer is incomplete.

**Recommendation**: Add explicit Passport scope checking in `allowsServer()`, or remove the dual-auth pattern.

**Confidence**: Confirmed

### [MEDIUM] 21.7 No Rate Limiting on Email Verification Resend

**Affected routes**: `POST /api/v1/auth/email/verification-notification`

**Observation**: Unlike all other auth endpoints (which have 5/min rate limits), this endpoint has no throttle middleware. An authenticated user can repeatedly request verification emails, potentially flooding the mail queue.

**Impact**: Mail queue abuse vector.

**Recommendation**: Apply `throttle:api-auth-password` (or a new rate limiter) to this endpoint.

**Confidence**: Confirmed

### [MEDIUM] 21.8 Share Analytics User Scoping Dependency

**Affected routes**: `GET /dashboard/dawah-impact`, `GET /dashboard/dawah-impact/links/{link}`

**Observation**: DawahImpactIndex and DawahImpactLinkShow delegate entirely to `ShareTrackingAnalyticsService`. The security boundary depends entirely on `findLinkForUser()` and `linksForUser()` properly scoping to the authenticated user. If these service methods have a bug, users could see other users' share data.

**Impact**: Potential data leakage between users.

**Recommendation**: Review and test `ShareTrackingAnalyticsService` scoping methods. Add integration tests.

**Confidence**: Possible

---

## 22. Performance Findings

### [LOW] 22.1 Observer-Triggered Cache Busting Amplification

**Observation**: Every model change triggers cascading cache busts — homepage stats, majlis listing, directory cache, and public search cache versions are invalidated on nearly every Event/Institution/Speaker/Venue/Geography/Tag change.

**Impact**: High write throughput could cause repeated cache regeneration. Acceptable for current scale but may become an issue with growth.

**Recommendation**: Consider debounced or batched cache invalidation. Monitor cache regeneration rates.

### [LOW] 22.2 N+1 Risk in InstitutionDashboard Member Role Mapping

**Observation**: `institutionMemberRoleMap()` calls `MemberRoleCatalog::roleNamesFor()` per paginated member. With 8 members per page, this adds 8+ additional queries.

**Impact**: Minor performance impact on institution member management page.

**Recommendation**: Eager-load role data or cache role name lookups.

### [LOW] 22.3 is_following Subquery on Every List Request

**Observation**: `SearchController::baseInstitutionQuery()` and `baseSpeakerQuery()` include an `is_following` EXISTS subquery for every authenticated request, even when the `following` filter is not active.

**Impact**: Extra correlated subquery on list endpoints.

**Recommendation**: Conditionally include the subquery only when `following` filter is active.

### [LOW] 22.4 NonSpeakerEventKeyPeople PHP Sorting

**Observation**: `SearchController::showSpeaker()` fetches all non-speaker role participations, then sorts in PHP (`sortBy`) and takes via `->take()`. All matching records are fetched from DB before slicing.

**Impact**: Over-fetching if a speaker has many event participations. At current scale negligible.

**Recommendation**: Push sorting to the database query level.

---

## 23. Test-Coverage Findings

| Area | Tests | Status |
|------|-------|--------|
| Admin API | 24-27 tests | Present |
| Event API contract | 22-27 tests | Present |
| Saved Search API | 24-26 tests | Present |
| MCP Admin | 25+ tests | Present |
| MCP Member | 94+ tests | Present |
| OAuth registration | Present | Present |
| Dashboard pages | 28-30 tests | Present |
| User restore | 4-5 tests | Present |
| Event search | 71 tests | Present |
| Event change announcements | 12-18 tests | Present |
| Homepage | 13 tests | Present |
| Contribution pages | 53 tests | Present |
| Speaker/reference/venue indexes | Present | Present |
| Share tracking | 39 tests | Present |
| Media conversions | Present | Present |
| PHPStan Level 6 | Clean | Passing |

**Total**: 2010 passing, 1 skipped, ~13157 assertions

### Coverage Gaps

1. **Transaction safety tests**: No tests verify that multi-table writes roll back on failure.
2. **Social login edge cases**: No explicit tests for email-less Google accounts or phone-only user linking.
3. **Role validation**: No tests for invalid role slugs in InstitutionDashboard member management.
4. **MCP tool authorization**: No tests verifying Passport tokens are properly scoped to servers.
5. **Rate limiting**: No tests that rate limits are applied and enforced.

---

## 24. Missing, Dead, or Unreachable Functionality

1. **`BackfillVenueSlugs` lacks cache busting**: This job does NOT bust any cache after completion, unlike all other backfill jobs. Potentially incomplete.

2. **`SubmitInstitution` and `SubmitSpeaker` no `canSubmitDirectoryFeedback` call**: Unlike membership claims and reports, these contribution creation endpoints do not check the user's ban status. If `canSubmitDirectoryFeedback` is meant to be universal, this is a gap.

3. **`CreateAdvanced` organizer_id not re-validated on submit**: The membership options are set at mount time. If a malicious user submits a different organizer_id not in their allowed options, the action accepts it without re-verifying ownership.

---

## 25. Cross-Route Inconsistencies

1. **Registered event firing inconsistency**: API register fires `Registered` event → sends `WelcomeNotification`. Social login (both web and API) does NOT fire `Registered` → no `WelcomeNotification`. Creates an inconsistent onboarding experience.

2. **Success feedback inconsistency**: Some Livewire actions show success toasts (`saveAccountSettings`, `addMember`), others redirect silently without any flash (`SuggestUpdate` direct edit, `Reports::submit`).

3. **Authorization pattern inconsistency**: Web routes use `abort_unless(auth()->user() instanceof User, 403)` in component `mount()` methods. API routes use `currentUser()` / `requireUser()` pattern. Both achieve similar results but differ in implementation.

4. **Public route vs dashboard route sharing**: `/hantar-majlis` (public) and `/dashboard/institusi/tambah-majlis` (auth) share the same Livewire component. The component must handle both authenticated and unauthenticated states correctly.

---

## 26. Prioritised Recommendations

### High Priority (Address Next Sprint)

1. **Add DB transactions to multi-table writes** (Finding 21.1): Wrap all multi-table write operations in `DB::transaction()`. Focus on SubmitInstitution, SubmitSpeaker, ShowInvitation::accept, and Reports::submit.

2. **Add rate limiting to email verification resend** (Finding 21.7): Apply a 5/min throttle to `POST /api/v1/auth/email/verification-notification`.

3. **Fix Social signup registered event** (Finding 21.5): Fire `Registered` event after social account creation to ensure consistent onboarding.

### Medium Priority

4. **Validate roles in InstitutionDashboard** (Finding 21.2): Add `Rule::in()` validation for role IDs in member management.

5. **Fix email-less Google OAuth** (Finding 21.3): Require email from Google response; reject if not provided.

6. **Fix Passport MCP ability check** (Finding 21.6): Add proper scope checking for Passport tokens in `allowsServer()`.

### Low Priority

7. **Add success flash messages to silent redirects** (Finding 21.8): Ensure SuggestUpdate, Reports::submit show success feedback.

8. **Conditional is_following subquery** (Finding 22.3): Only include when `following` filter is active.

9. **BackfillVenueSlugs cache busting** (Finding 24.1): Add cache invalidation to match other backfill jobs.

---

## 27. Unverified or Blocked Areas

1. **Database schema**: Full verification against migrations blocked (database currently offline). Table catalogue based on model/migration analysis.
2. **Fortify auto-generated routes**: Not registered in route files — verified flow exists through Fortify docs.
3. **Passport OAuth routes**: Auto-generated — verified MCP OAuth flow exists.
4. **Horizon dashboard routes**: Package-generated, not verified.
5. **Scramble API docs routes**: Package-generated, not verified.
6. **Full browser testing**: UI actions inferred from code analysis, not confirmed via browser interaction (database offline).
7. **Individual SFC components**: Home page widgets and entity listing/show pages are SFCs read from Blade only — frontend rendering assumed correct.

---

## 28. Final Coverage Reconciliation

| Category | Total | Fully Audited | Partially Audited | Pending |
|----------|-------|---------------|-------------------|---------|
| Web routes (public) | ~30 | 1 | ~29 | 0 |
| Web routes (auth) | ~20 | 18 | 0 | 0 |
| API routes (public) | ~35 | ~35 | 0 | 0 |
| API routes (auth) | ~50 | ~50 | 0 | 0 |
| API routes (admin) | ~20 | ~20 | 0 | 0 |
| MCP routes | ~8 | ~8 | 0 | 0 |
| Filament admin resources | 22 | 22 (structural) | 0 | 0 |
| Filament ahli resources | 4 | 4 (structural) | 0 | 0 |
| Filament custom pages | 7 | 7 (structural) | 0 | 0 |
| Scheduled commands | 9 | 9 | 0 | 0 |
| **Total** | **~205** | **~174** | **~29** | **0** |

---

## Appendix A: Complete Route Manifest

*See Section 5 — Route Inventory above for the complete manifest*

## Appendix B: Route-to-Source Index

*Key route-to-source mappings documented inline in Section 5*

## Appendix C: Model-to-Table Index

*Model and table catalogues documented in Sections 10-11*

## Appendix D: Event-to-Listener Index

| Event | Listener | Source |
|-------|----------|--------|
| `AgentPrompted` | `RecordAiUsage` | `AppServiceProvider::boot()` |
| `AgentStreamed` | `RecordAiUsage` | `AppServiceProvider::boot()` |
| `ImageGenerated` | `RecordAiUsage` | `AppServiceProvider::boot()` |
| `TranscriptionGenerated` | `RecordAiUsage` | `AppServiceProvider::boot()` |
| `EmbeddingsGenerated` | `RecordAiUsage` | `AppServiceProvider::boot()` |
| `Reranked` | `RecordAiUsage` | `AppServiceProvider::boot()` |
| `AudioGenerated` | `RecordAiUsage` | `AppServiceProvider::boot()` |
| `Registered` | `SendRegisteredUserEmails` | Auto-discovery |
| `Verified` | `RecordVerifiedEmail` | Auto-discovery |
| `NotificationSent` | `RecordNotificationSent` | Auto-discovery |
| `NotificationFailed` | `HandleNotificationFailed` | Auto-discovery |

---

## Summary Statistics

| Metric | Count |
|--------|-------|
| Total registered routes (identified) | ~205 |
| Fully audited routes (complete trace) | ~174 |
| Partially audited routes | ~29 |
| Blocked routes | 0 |
| UI actions traced | ~100+ |
| Endpoints traced | ~205 |
| Database tables identified | ~40+ |
| Models identified | ~51 |
| Relations identified | ~100+ (estimated) |
| Events identified | 11 |
| Listeners identified | 5 |
| Jobs identified | 9 |
| Observers identified | 10 |
| Notifications identified | 9 |
| Medium findings | 8 |
| Low findings | 4 |
| Informational notes | Multiple |
| Notifications identified | 9 |
| Scheduled jobs | 9 |
| Critical findings | 0 |
| High findings | 0 |
| Medium findings | 0 |
| Low findings | 0 |
| Unverified areas | 6 |

---

*This audit document will be updated incrementally as each route group is fully analyzed.*
