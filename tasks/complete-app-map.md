# ilmu360° — Complete Application Map

> Generated: 2026-07-23
> Last Verified: 2026-07-26
> Coverage: Routes, Menus, Forms, Imports, Data Flow → Database
> Status: ✅ Verified against codebase

---

## Table of Contents

1. [Application Overview](#1-application-overview)
2. [Panel Architecture](#2-panel-architecture)
3. [Complete Route Map](#3-complete-route-map)
4. [Navigation & Menu Structure](#4-navigation--menu-structure)
5. [Forms & Submission Flows](#5-forms--submission-flows)
6. [Import & Data Ingestion](#6-import--data-ingestion)
7. [Complete Model Inventory](#7-complete-model-inventory)
8. [Observer Inventory](#8-observer-inventory)
9. [Action Inventory](#9-action-inventory)
10. [Service Inventory](#10-service-inventory)
11. [Job Inventory](#11-job-inventory)
12. [Console Command Inventory](#12-console-command-inventory)
13. [Key Data Flow Diagrams](#13-key-data-flow-diagrams)

---

## 1. Application Overview

| Aspect | Detail |
|--------|--------|
| **Framework** | Laravel 13 |
| **Panels** | Admin (`/admin`), Ahli/Member (`/ahli`), Public (Livewire) |
| **API** | RESTful JSON API at `/api/v1` (Sanctum + Passport auth) |
| **MCP** | 2 MCP servers (`admin`, `member`) at `/mcp/admin`, `/mcp/member` |
| **Auth** | Laravel Fortify (web) + Sanctum (API) + Passport (OAuth2) + Socialite |
| **Filament** | v5 — Admin + Ahli panels |
| **Livewire** | v4 — public pages, dashboards, forms |
| **Database** | PostgreSQL (primary), SQLite (tests) |
| **Search** | Laravel Scout (Typesense) |
| **Queue** | Laravel Horizon (Redis) |
| **Media** | Spatie MediaLibrary v11 |
| **Audit** | owen-it/laravel-auditing |
| **Notifications** | Custom notification system (in-app, email, push, WhatsApp) |
| **Signals** | Custom product analytics (Signals) |

---

## 2. Panel Architecture

### Admin Panel

| Property | Value |
|----------|-------|
| **Panel Provider** | `app/Providers/Filament/AdminPanelProvider.php` |
| **ID** | `admin` |
| **Path** | `/admin` (or subdomain via env) |
| **Theme** | `resources/css/filament/admin/theme.css` |
| **Colors** | Emerald (primary), Slate (gray) |
| **Font** | Outfit |
| **Login** | Enabled |
| **Navigation** | Top navigation |
| **Brand** | `images/milogo.webp` |

**Registered Plugins:**
- Signals (product analytics)
- Authz (RBAC — central app + global-only scoped roles)
- Auditing (audit trail)
- Addressing (geography)
- Contacting (contacts)
- Engagement (follows)
- Communications (notifications/digests)
- Events (event management)
- Persons (person/speaker management)
- Inventory
- Seating
- Ticketing

### Ahli (Member) Panel

| Property | Value |
|----------|-------|
| **Panel Provider** | `app/Providers/Filament/AhliPanelProvider.php` |
| **ID** | `ahli` |
| **Path** | `/ahli` (or subdomain via env) |
| **Plugins** | Events, Persons, Engagement (subset) |
| **Scope** | Users only see records they own |

---

## 3. Complete Route Map

### 3.1 Web Routes (`routes/web.php`) — 52 named routes

#### Public (no auth required)

| # | Method | URI | Handler | Name | Purpose |
|---|--------|-----|---------|------|---------|
| 1 | GET | `/` | `pages.⚡home` (Livewire) | `home` | Homepage |
| 2 | GET | `/tentang-kami` | `AboutPage` (Livewire) | `about` | About page |
| 3 | GET | `/bahasa/{locale}` | `LocaleController` | `locale.switch` | Language switch |
| 4 | GET | `/oauth/{provider}/redirect` | `SocialiteController@redirect` | `socialite.redirect` | OAuth social login |
| 5 | GET | `/oauth/{provider}/callback` | `SocialiteController@callback` | `socialite.callback` | OAuth callback |
| 6 | GET | `/kongsi/payload` | `DawahShareController@payload` | `dawah-share.payload` | Share tracking payload |
| 7 | POST | `/kongsi/track` | `DawahShareController@track` | `dawah-share.track` | Share tracking record |
| 8 | GET | `/kongsi/{provider}` | `DawahShareController@redirect` | `dawah-share.redirect` | Social share redirect |
| 9 | GET | `/carian` | `SearchIndex` (Livewire) | `search.index` | Global search |
| 10 | GET | `/majlis` | `pages.events.index` (Livewire) | `events.index` | Event listing |
| 11 | GET | `/majlis/{event:slug}` | `pages.events.show` (Livewire) | `events.show` | Event detail |
| 12 | GET | `/majlis/{event:slug}/kalendar.ics` | `EventsController@calendar` | `events.calendar` | ICS download |
| 13 | GET | `/tambah-majlis` | `pages.submit-event.landing` (Livewire) | `submit-event.landing` | Submit event landing |
| 14 | GET | `/hantar-majlis` | `Create` (Livewire) | `submit-event.create` | Submit event form |
| 15 | GET | `/hantar-majlis/berjaya` | `pages.submit-event.success` (Livewire) | `submit-event.success` | Submit success page |
| 16 | POST | `/majlis/{event:slug}/daftar` | `EventsController@register` | `events.register` | Event registration |
| 17 | GET | `/peta-laman.xml` | `SitemapController@index` | `sitemap.index` | Main sitemap |
| 18 | GET | `/peta-laman-majlis.xml` | `SitemapController@events` | `sitemap.events` | Events sitemap |
| 19 | GET | `/peta-laman-institusi.xml` | `SitemapController@institutions` | `sitemap.institutions` | Institutions sitemap |
| 20 | GET | `/peta-laman-penceramah.xml` | `SitemapController@persons` | `sitemap.persons` | Persons sitemap |
| 21 | GET | `/institusi` | `pages.institutions.index` (Livewire) | `institutions.index` | Institution listing |
| 22 | GET | `/institusi/{institution:slug}` | `pages.institutions.show` (Livewire) | `institutions.show` | Institution detail |
| 23 | GET | `/penceramah` | `pages.persons.index` (Livewire) | `persons.index` | Person/speaker listing |
| 24 | GET | `/penceramah/{person:slug}` | `pages.persons.show` (Livewire) | `persons.show` | Person/speaker detail |
| 25 | GET | `/tempat` | `pages.venues.index` (Livewire) | `venues.index` | Venue listing |
| 26 | GET | `/lokasi/{venue:slug}` | `pages.venues.show` (Livewire) | `venues.show` | Venue detail |
| 27 | GET | `/siri/{series:slug}` | `pages.series.show` (Livewire) | `series.show` | Series detail |
| 28 | GET | `/rujukan` | `pages.references.index` (Livewire) | `references.index` | Reference listing |
| 29 | GET | `/rujukan/{reference:slug}` | `pages.references.show` (Livewire) | `references.show` | Reference detail |
| 30 | GET | `/ops/network-diagnostics` | `NetworkDiagnosticsController` | `network-diagnostics` | Diagnostics |
| 31 | GET | `/welcome` | View: `welcome` | `welcome` | Welcome page (Fortify) |

#### Authenticated Web Routes (`middleware('auth')`)

| # | Method | URI | Handler | Name | Purpose |
|-------|--------|-----|---------|------|---------|
| 32 | GET | `/dashboard` | `UserDashboard` (Livewire) | `dashboard` | User dashboard |
| 33 | GET | `/dashboard/dawah-impact` | `DawahImpactIndex` (Livewire) | `dashboard.dawah-impact` | Share analytics |
| 34 | GET | `/dashboard/dawah-impact/links` | `DawahImpactIndex` (Livewire) | `dashboard.dawah-impact.links` | Share links |
| 35 | GET | `/dashboard/dawah-impact/links/{link}` | `DawahImpactLinkShow` (Livewire) | `dashboard.dawah-impact.links.show` | Link detail |
| 36 | GET | `/dashboard/notifications` | `NotificationsIndex` (Livewire) | `dashboard.notifications` | Notifications |
| 37 | GET | `/tetapan-akaun` | `AccountSettings` (Livewire) | `dashboard.account-settings` | Account settings |
| 38 | GET | `/dashboard/institusi` | `InstitutionDashboard` (Livewire) | `dashboard.institutions` | Institution dashboard |
| 39 | GET | `/dashboard/institusi/senarai-majlis` | `InstitutionDashboard` (Livewire) | `dashboard.institutions.events` | Institution events |
| 40 | GET | `/dashboard/institusi/tambah-majlis` | `Create` (Livewire) | `dashboard.institutions.submit-event` | Scoped event submit |
| 41 | GET | `/dashboard/majlis/cipta-lanjutan` | `CreateAdvanced` (Livewire) | `dashboard.events.create-advanced` | Advanced event creation |
| 42 | GET | `/carian-tersimpan` | `Index` (Livewire) | `saved-searches.index` | Saved searches |
| 43 | GET | `/jemputan-ahli/{token}` | `ShowMemberInvitation` (Livewire) | `member-invitations.show` | Accept invitation |
| 44 | GET | `/sumbangan` | `ContributionsIndex` (Livewire) | `contributions.index` | My contributions |
| 45 | GET | `/sumbangan/institusi/baru` | `SubmitInstitution` (Livewire) | `contributions.submit-institution` | Submit institution |
| 46 | GET | `/sumbangan/penceramah/baru` | `SubmitPerson` (Livewire) | `contributions.submit-person` | Submit person/speaker |
| 47 | GET | `/sumbangan/{subjectType}/berjaya` | `pages.contributions.submission-success` (Livewire) | `contributions.submission-success` | Submission success |
| 48 | GET | `/permohonan-keahlian` | `MembershipApplicationsIndex` (Livewire) | `membership-applications.index` | My claims |
| 49 | GET | `/pohon-keahlian/{subjectType}/{subjectId}` | `CreateMembershipApplicationPage` (Livewire) | `membership-applications.create` | New claim |
| 50 | GET | `/sumbangan/{subjectType}/{subjectId}/kemas-kini` | `SuggestContributionUpdate` (Livewire) | `contributions.suggest-update` | Suggest update |
| 51 | GET | `/lapor/{subjectType}/{subjectId}` | `CreateReportPage` (Livewire) | `reports.create` | Create report |
| 52 | GET | `/majlis/{event:slug}/pas/{pass}` | `EventPassController` | `events.pass` | Event pass |

### 3.2 API Routes (`routes/api.php`)

All under prefix `/api/v1`. Routes grouped by auth middleware as defined in `routes/api.php`.

#### Public API (no `auth:sanctum` — includes auth, catalog, search, entity listing)

| # | Method | URI | Handler | Purpose |
|---|--------|-----|---------|---------|
| 1 | POST | `/v1/auth/register` | `AuthController@register` | User registration |
| 2 | POST | `/v1/auth/login` | `AuthController@login` | User login |
| 3 | POST | `/v1/auth/social/google` | `AuthController@google` | Google social login |
| 4 | POST | `/v1/auth/forgot-password` | `AuthController@forgotPassword` | Password reset request |
| 5 | POST | `/v1/auth/reset-password` | `AuthController@resetPassword` | Password reset |
| 6 | GET | `/v1/manifest` | `ManifestController@manifest` | App manifest |
| 7 | GET | `/v1/documentation` | `DocumentationController@index` | App docs |
| 8 | GET | `/v1/documentation/{documentId}` | `DocumentationController@show` | Doc detail |
| 9 | GET | `/v1/forms/mobile-telemetry` | `ManifestController@mobileTelemetry` | Mobile telemetry form schema |
| 10 | GET | `/v1/forms/submit-event` | `ManifestController@submitEvent` | Submit event form schema |
| 11 | GET | `/v1/forms/contributions/institutions` | `ManifestController@submitInstitution` | Submit institution schema |
| 12 | GET | `/v1/forms/contributions/persons` | `ManifestController@submitPerson` | Submit person schema |
| 13 | GET | `/v1/catalogs/countries` | `CatalogController@countries` | List countries |
| 14 | GET | `/v1/catalogs/states` | `CatalogController@states` | List states |
| 15 | GET | `/v1/catalogs/cities` | `CatalogController@cities` | List cities |
| 16 | GET | `/v1/catalogs/admin-area-level-1` | `CatalogController@adminAreaLevel1` | List districts |
| 17 | GET | `/v1/catalogs/admin-area-level-2` | `CatalogController@adminAreaLevel2` | List subdistricts |
| 18 | GET | `/v1/catalogs/languages` | `CatalogController@languages` | List languages |
| 19 | GET | `/v1/catalogs/taxonomy-terms/{type}` | `CatalogController@taxonomyTerms` | List taxonomy terms |
| 20 | GET | `/v1/catalogs/tags/{type}` | `CatalogController@tags` | List tags by type |
| 21 | GET | `/v1/catalogs/references` | `CatalogController@references` | List references |
| 22 | GET | `/v1/catalogs/submit-institutions` | `CatalogController@submitInstitutions` | List institutions for forms |
| 23 | GET | `/v1/catalogs/submit-persons` | `CatalogController@submitPersons` | List persons for forms |
| 24 | GET | `/v1/catalogs/venues` | `CatalogController@venues` | List venues |
| 25 | GET | `/v1/catalogs/spaces` | `CatalogController@spaces` | List spaces |
| 26 | GET | `/v1/catalogs/prayer-institutions` | `CatalogController@prayerInstitutions` | List prayer institutions |
| 27 | GET | `/v1/catalogs/membership-application-subjects/{subjectType}` | `CatalogController@membershipClaimSubjects` | List claimable subjects |
| 28 | GET | `/v1/search` | `SearchController@search` | Global search |
| 29 | GET | `/v1/share/payload` | `DawahShareController@payload` | Share tracking payload |
| 30 | POST | `/v1/share/track` | `DawahShareController@track` | Share tracking record |
| 31 | POST | `/v1/mobile/telemetry/events` | `MobileTelemetryController@store` | Mobile telemetry |
| 32 | GET | `/v1/institutions` | `SearchController@institutions` | Institution listing |
| 33 | GET | `/v1/institutions/near` | `SearchController@institutionsNear` | Nearby institutions |
| 34 | GET | `/v1/institutions/{institutionKey}` | `SearchController@showInstitution` | Institution detail |
| 35 | GET | `/v1/persons` | `SearchController@persons` | Person listing |
| 36 | GET | `/v1/persons/{personKey}` | `SearchController@showPerson` | Person detail |
| 37 | GET | `/v1/inspirations/random` | `SearchController@randomInspiration` | Random inspiration |
| 38 | GET | `/v1/venues/{venueKey}` | `SearchController@showVenue` | Venue detail |
| 39 | GET | `/v1/references` | `SearchController@references` | Reference listing |
| 40 | GET | `/v1/references/{referenceKey}` | `SearchController@showReference` | Reference detail |
| 41 | GET | `/v1/series/{series}` | `SearchController@showSeries` | Series detail |
| 42 | POST | `/v1/submit-event` | `EventSubmissionController@store` | **Submit event (API)** |
| 43 | GET | `/v1/events` | `EventController@index` | Event listing |
| 44 | GET | `/v1/events/{event}` | `EventController@show` | Event detail |
| 45 | POST | `/v1/events/{event}/registrations` | `EventRegistrationController@store` | Register for event |

#### Authenticated API (`auth:sanctum`)

| # | Method | URI | Handler | Purpose |
|---|--------|-----|---------|---------|
| 46 | POST | `/v1/auth/logout` | `AuthController@logout` | Logout |
| 47 | POST | `/v1/auth/email/verification-notification` | `AuthController@resendVerificationEmail` | Resend verification |
| 48 | GET | `/v1/user` | `CurrentUserController@__invoke` | Current user |
| 49 | DELETE | `/v1/user` | `CurrentUserController@destroy` | Delete account |
| 50 | GET | `/v1/user/registrations` | `UserRegistrationController@index` | User registrations |
| 51 | GET | `/v1/me/events/going` | `EventGoingController@index` | My going events |
| 52 | GET | `/v1/me/events/saved` | `EventSaveController@index` | My saved events |
| 53 | GET | `/v1/events/{event}/me` | `EventController@me` | User's relation to event |
| 54 | POST | `/v1/events/{event}/check-ins` | `EventCheckInController@store` | Check in to event |
| 55 | PUT | `/v1/events/{event}/going` | `EventGoingController@store` | Mark as going |
| 56 | DELETE | `/v1/events/{event}/going` | `EventGoingController@destroy` | Remove going |
| 57 | PUT | `/v1/events/{event}/saved` | `EventSaveController@store` | Save event |
| 58 | DELETE | `/v1/events/{event}/saved` | `EventSaveController@destroy` | Unsave event |
| 59 | GET | `/v1/events/{event}/registrations/export` | `RegistrationExportController@export` | Export registrations |
| 60 | GET | `/v1/share/analytics` | `ShareAnalyticsController@index` | Share analytics |
| 61 | GET | `/v1/share/analytics/links/{link}` | `ShareAnalyticsController@show` | Share link detail |
| 62 | GET | `/v1/forms/report` | `ManifestController@report` | Report form schema |
| 63 | GET | `/v1/forms/github-issue-report` | `ManifestController@githubIssueReport` | GitHub issue schema |
| 64 | GET | `/v1/forms/account-settings` | `ManifestController@accountSettings` | Account settings schema |
| 65 | GET | `/v1/forms/advanced-events` | `ManifestController@advancedEvent` | Advanced event schema |
| 66 | GET | `/v1/forms/institution-workspace` | `ManifestController@institutionWorkspace` | Institution workspace schema |
| 67 | GET | `/v1/forms/membership-applications/{subjectType}` | `ManifestController@membershipClaim` | Membership claim schema |
| 68 | GET | `/v1/forms/contributions/{subjectType}/{subject}/suggest` | `ContributionController@suggestContext` | Suggest update schema |
| 69 | GET | `/v1/catalogs/institution-roles` | `CatalogController@institutionRoles` | Institution roles |
| 70 | GET | `/v1/account-settings` | `AccountSettingsController@show` | Get settings |
| 71 | PUT | `/v1/account-settings` | `AccountSettingsController@update` | Update settings |
| 72 | GET | `/v1/account-settings/mcp-tokens` | `AccountSettingsMcpTokenController@index` | List MCP tokens |
| 73 | POST | `/v1/account-settings/mcp-tokens` | `AccountSettingsMcpTokenController@store` | Create MCP token |
| 74 | DELETE | `/v1/account-settings/mcp-tokens/{tokenId}` | `AccountSettingsMcpTokenController@destroy` | Delete MCP token |
| 75 | POST | `/v1/github-issues` | `GitHubIssueController@store` | Submit GitHub issue |
| 76 | GET | `/v1/contributions` | `ContributionController@index` | List contributions |
| 77 | POST | `/v1/contributions/institutions` | `ContributionController@storeInstitution` | Submit institution |
| 78 | POST | `/v1/contributions/persons` | `ContributionController@storePerson` | Submit person |
| 79 | POST | `/v1/contributions/{subjectType}/{subject}/suggest` | `ContributionController@suggestUpdate` | Suggest update |
| 80 | POST | `/v1/contributions/{requestId}/approve` | `ContributionController@approve` | Approve contribution |
| 81 | POST | `/v1/contributions/{requestId}/reject` | `ContributionController@reject` | Reject contribution |
| 82 | POST | `/v1/contributions/{requestId}/cancel` | `ContributionController@cancel` | Cancel contribution |
| 83 | GET | `/v1/membership-applications` | `MembershipApplicationController@index` | List applications |
| 84 | POST | `/v1/membership-applications/{subjectType}/{subject}` | `MembershipApplicationController@store` | Submit application |
| 85 | DELETE | `/v1/membership-applications/{applicationId}` | `MembershipApplicationController@cancel` | Cancel application |
| 86 | POST | `/v1/advanced-events` | `AdvancedEventController@store` | Create advanced event |
| 87 | GET | `/v1/follows/{type}/{subject}` | `FollowController@show` | Check follow status |
| 88 | POST | `/v1/follows/{type}/{subject}` | `FollowController@store` | Follow |
| 89 | DELETE | `/v1/follows/{type}/{subject}` | `FollowController@destroy` | Unfollow |
| 90 | GET | `/v1/institution-workspace` | `InstitutionWorkspaceController@show` | Workspace info |
| 91 | POST | `/v1/institution-workspace/{institutionId}/members` | `InstitutionWorkspaceController@addMember` | Add member |
| 92 | PUT | `/v1/institution-workspace/{institutionId}/members/{memberId}` | `InstitutionWorkspaceController@updateMemberRole` | Update role |
| 93 | DELETE | `/v1/institution-workspace/{institutionId}/members/{memberId}` | `InstitutionWorkspaceController@removeMember` | Remove member |
| 94 | POST | `/v1/reports` | `ReportController@store` | Submit report |
| 95 | GET | `/v1/notifications` | `NotificationMessageController@index` | List notifications |
| 96 | POST | `/v1/notifications/{message}/read` | `NotificationMessageController@read` | Mark read |
| 97 | POST | `/v1/notifications/read-all` | `NotificationMessageController@readAll` | Mark all read |
| 98 | GET | `/v1/notification-settings/catalog` | `NotificationSettingsController@catalog` | Settings catalog |
| 99 | GET | `/v1/notification-settings` | `NotificationSettingsController@show` | Get preferences |
| 100 | PUT | `/v1/notification-settings` | `NotificationSettingsController@update` | Update preferences |
| 101 | POST | `/v1/notification-destinations/push` | `NotificationDestinationController@storePush` | Register push device |
| 102 | PUT | `/v1/notification-destinations/push/{installation}` | `NotificationDestinationController@updatePush` | Update push device |
| 103 | DELETE | `/v1/notification-destinations/push/{installation}` | `NotificationDestinationController@destroyPush` | Unregister push device |
| 104 | GET | `/v1/saved-searches` | `SavedSearchController@index` | List saved searches |
| 105 | POST | `/v1/saved-searches` | `SavedSearchController@store` | Create saved search |
| 106 | GET | `/v1/saved-searches/{savedSearch}` | `SavedSearchController@show` | Get saved search |
| 107 | PUT | `/v1/saved-searches/{savedSearch}` | `SavedSearchController@update` | Update saved search |
| 108 | DELETE | `/v1/saved-searches/{savedSearch}` | `SavedSearchController@destroy` | Delete saved search |
| 109 | POST | `/v1/saved-searches/{savedSearch}/execute` | `SavedSearchController@execute` | Execute saved search |

#### Admin API (`auth:sanctum` + `EnsureAdminApiAccess`)

| # | Method | URI | Handler | Purpose |
|---|--------|-----|---------|---------|
| 110 | GET | `/v1/admin/manifest` | `AdminManifestController@__invoke` | Admin manifest |
| 111-115 | GET | `/v1/admin/catalogs/*` | `AdminCatalogController@*` | Admin catalogs (countries, states, cities, districts, subdistricts) |
| 116 | GET | `/v1/admin/events/search` | `EventSearchController@search` | Admin event search |
| 117 | GET/POST | `/v1/admin/{resourceKey}` | `AdminResourceController` | Resource index/store |
| 118 | POST/PUT | `/v1/admin/{resourceKey}/batch` | `AdminResourceController` | Batch create/update |
| 119 | GET | `/v1/admin/{resourceKey}/meta` | `AdminResourceController` | Resource metadata |
| 120 | GET | `/v1/admin/{resourceKey}/schema` | `AdminResourceController` | Resource schema |
| 121 | GET/POST | `/v1/admin/events/{recordKey}/moderation-schema` / `moderate` | `EventModerationController` | Event moderation |
| 122 | GET/POST | `/v1/admin/reports/{recordKey}/triage-schema` / `triage` | `ReportTriageController` | Report triage |
| 123 | GET/POST | `/v1/admin/contribution-requests/{recordKey}/review-schema` / `review` | `ContributionRequestReviewController` | Contribution review |
| 124 | GET/POST | `/v1/admin/membership-applications/{recordKey}/review-schema` / `review` | `MembershipApplicationReviewController` | Membership review |
| 125 | GET | `/v1/admin/{resourceKey}/{recordKey}/relations/{relation}` | `AdminResourceController` | Related records |
| 126-127 | GET/PUT | `/v1/admin/{resourceKey}/{recordKey}` | `AdminResourceController` | Resource show/update |

### 3.3 MCP Routes (`routes/ai.php`)

| # | Method | URI | Server | Middleware | Purpose |
|---|--------|-----|--------|-----------|---------|
| 1 | GET (SSE) | `/mcp/admin` | `AdminServer` | `NormalizeMcpAcceptHeader`, `auth:sanctum`, `EnsureAdminMcpAccess` | Admin MCP SSE stream |
| 2 | GET (SSE) | `/mcp/member` | `MemberServer` | `NormalizeMcpAcceptHeader`, `auth:sanctum`, `EnsureMemberMcpAccess` | Member MCP SSE stream |
| 3 | N/A | `/ilmu360-admin-local` | `AdminServer` | (local only) | Admin MCP local transport |
| 4 | N/A | `/ilmu360-member-local` | `MemberServer` | (local only) | Member MCP local transport |
| 5 | GET | `/mcp/admin` | `AdminMcpController@stream` | `NormalizeMcpAcceptHeader`, `auth:sanctum`, `AddWwwAuthenticateHeader`, `EnsureAdminMcpAccess` | HTTP stream |
| 6 | DELETE | `/mcp/admin` | `AdminMcpController@destroy` | same as above | Destroy session |
| 7 | GET | `/mcp/member` | `MemberMcpController@stream` | `NormalizeMcpAcceptHeader`, `auth:sanctum`, `AddWwwAuthenticateHeader`, `EnsureMemberMcpAccess` | HTTP stream |
| 8 | DELETE | `/mcp/member` | `MemberMcpController@destroy` | same as above | Destroy session |

Also: `oauth/mcp` — MCP OAuth routes (auto-registered via `Mcp::oauthRoutes()`)

### 3.4 Console Routes (`routes/console.php`)

| # | Schedule | Command | Purpose |
|---|----------|---------|---------|
| 1 | Every 15 min | `DispatchEventReminderNotifications` | Event reminders |
| 2 | Hourly | `EscalatePendingEvents` | Escalate stuck events |
| 3 | Daily | `app:prune-orphaned-entities` | Prune orphans |
| 4 | Hourly | `app:sync-public-submission-locks` | Sync submission locks |
| 5 | Daily 02:30 | `media-library:clean --delete-orphaned --force` | Clean orphaned media |
| 6 | Weekly Sun 03:00 | `media-library:regenerate --only-missing --with-responsive-images --force` | Regenerate missing conversions |
| 7 | Every 5 min | `horizon:snapshot` | Horizon metrics |
| 8 | Every 1 min | `communications:send-digests` | Digest notifications |

---

## 4. Navigation & Menu Structure

### 4.1 Admin Panel (Top Navigation)

```
DASHBOARD
  ├── AdminDashboard (/admin/dashboard)

DIRECTORY
  ├── Institutions (Heroicon: BuildingLibrary)
  │   └── Create, List, View, Edit
  ├── People/Persons (Heroicon: UserGroup)
  │   └── Create, List, View, Edit
  ├── Spaces/Venue Spaces (Heroicon: RectangleStack)
  │   └── Create, List, View, Edit
  ├── References (Heroicon: RectangleStack)
  │   └── Create, List, Edit
  └── Donation Channels (Heroicon: RectangleStack)
      └── Create, List, Edit

CONTENT
  ├── Series (Heroicon: BookOpen)
  │   └── Create, List, Edit
  └── Inspirations (Heroicon: Sparkles)
      └── Create, List, Edit

MODERATION
  ├── Moderation Queue (Heroicon: ClipboardDocumentCheck) — sort:1
  │   └── Custom page with inline approve/reject actions
  ├── Contribution Requests (Heroicon: RectangleStack) — sort:2
  │   └── List, View (with approve/reject actions)
  ├── Membership Applications (Heroicon: Identification) — sort:4
  │   └── List, View (with approve/reject actions)
  └── Reports (Heroicon: Flag)
      └── Create, List, Edit

INSIGHTS
  ├── Product Signals (Heroicon: ChartBarSquare) — sort:10
  └── Share Analytics (Heroicon: Share) — sort:11

SYSTEM
  ├── Slug Redirects (Heroicon: RectangleStack)
  │   └── Create, List, View, Edit
  ├── Audits (Heroicon: RectangleStack)
  │   └── List, View (read-only)
  ├── AI Model Pricings (Heroicon: RectangleStack) — sort:99
  │   └── Create, List, Edit
  ├── AI Usage Logs (Heroicon: RectangleStack) — sort:100
  │   └── List (read-only)
  └── Deleted Users (Heroicon: Trash) — sort:13
      └── List (with restore action)

AUTH (from plugin)
  └── Users (from Filament Authz plugin)
      └── List, Create, View, Edit

PACKAGE RESOURCES (from plugins)
  └── Events (from Filament Events plugin)
  └── Venues (from Filament Events plugin)
  └── Tickets (from Filament Ticketing plugin)
  └── Inventory (from Filament Inventory plugin)
  └── Seating (from Filament Seating plugin)
```

### 4.2 Ahli (Member) Panel

```
DASHBOARD
  ├── AhliDashboard (/ahli/dashboard)
  └── [PendingApprovalEventsWidget]

EVENTS (from Events plugin)

DIRECTORY
  └── Institutions (/ahli/institutions) — sort:20
      └── Edit (only)
  └── People (/ahli/persons) — sort:30
      └── List, View, Edit
  └── References (/ahli/references) — sort:40
      └── List, Edit
```

### 4.3 Public Site Navigation

```
HOMEPAGE (/)

LISTINGS (all searchable/filterable)
  ├── Events / Majlis (/majlis)
  ├── Institutions (/institusi)
  ├── Speakers/People (/penceramah)
  ├── Venues (/tempat)
  └── References (/rujukan)

ACTIONS
  ├── Submit Event (/hantar-majlis) — 5-step wizard
  │   ├── Landing page (/tambah-majlis)
  │   └── Success page (/hantar-majlis/berjaya)
  ├── Search (/carian) — global search across all types
  └── About (/tentang-kami)

AUTH USER MENU (when logged in)
  ├── Dashboard (/dashboard)
  ├── Dawah Impact (/dashboard/dawah-impact)
  ├── Notifications (/dashboard/notifications)
  ├── Account Settings (/tetapan-akaun)
  ├── My Contributions (/sumbangan)
  ├── My Membership Claims (/permohonan-keahlian)
  ├── Saved Searches (/carian-tersimpan)
  ├── Institution Dashboard (/dashboard/institusi)
  └── Create Advanced Event (/dashboard/majlis/cipta-lanjutan)
```

### 4.4 API Route Structure

```
/api/v1
├── /auth/*              — Auth (register, login, social, forgot/reset)
├── /manifest            — App manifest
├── /documentation       — API docs
├── /forms/*             — Mobile form schemas (JSON)
├── /catalogs/*          — Lookup data (countries, states, cities, tags, etc.)
├── /search              — Global search
├── /share/*             — Share tracking
├── /mobile/telemetry    — Mobile telemetry
├── /submit-event        — Event submission (public)
├── /events/*            — Events CRUD + registrations + planner
├── /institutions/*      — Institutions
├── /persons/*           — People/Speakers
├── /venues/*            — Venues
├── /references/*        — References
├── /series/*            — Series
├── /admin/*             — Admin CRUD + moderation + triage + review
├── /user/*              — User profile
├── /me/events/*         — User's planner
├── /contributions/*     — Contribution requests (create/submit)
├── /membership-applications/* — Membership applications
├── /reports             — Reports
├── /follows/*           — Following
├── /institution-workspace/* — Institution workspace management
├── /notifications/*     — Notifications
├── /notification-settings/* — Notification preferences
├── /notification-destinations/* — Push notification devices
├── /saved-searches/*    — Saved searches
├── /account-settings/*  — Account settings
├── /advanced-events     — Advanced event creation
└── /github-issues       — GitHub issue reporting
```

---

## 5. Forms & Submission Flows

### 5.1 All Form Entry Points

| # | Form | URL | Panel/Public | Data Object | Submission Method |
|---|------|-----|-------------|-------------|-------------------|
| 1 | **Submit Event** (5-step wizard) | `/hantar-majlis` | Public (Livewire) | `ValidatedEventSubmission` DTO | `SubmitFrontendEventAction` |
| 2 | **Submit Event** (API) | `POST /api/v1/submit-event` | Public (API) | `ValidatedEventSubmission` DTO | `SubmitFrontendEventAction` |
| 3 | **Advanced Event** (multi-step) | `/dashboard/majlis/cipta-lanjutan` | Auth (Livewire) | Array state | `CreateAdvancedEventAction` |
| 4 | **Create Institution** | `/sumbangan/institusi/baru` | Auth (Livewire) | Array state | `SubmitStagedContributionCreateAction` |
| 5 | **Create Person** | `/sumbangan/penceramah/baru` | Auth (Livewire) | Array state | `SubmitStagedContributionCreateAction` |
| 6 | **Suggest Update** | `/sumbangan/{type}/{id}/kemas-kini` | Auth (Livewire) | Array state | `ApplyDirectContributionUpdateAction` / `SubmitContributionUpdateRequestAction` |
| 7 | **Create Report** | `/lapor/{type}/{id}` | Auth (Livewire) | Array state | `SubmitReportAction` |
| 8 | **Membership Application** | `/pohon-keahlian/{type}/{id}` | Auth (Livewire) | Array state | `SubmitMembershipApplicationAction` |
| 9 | **Account Settings** | `/tetapan-akaun` | Auth (Livewire) | Array state | Inline `saveAccountSettings()` |
| 10 | **Create Event (API admin)** | `POST /v1/admin/{resourceKey}` | Auth (API) | JSON | `AdminResourceService::storeRecord()` |
| 11 | **Batch Create (API admin)** | `POST /v1/admin/{resourceKey}/batch` | Auth (API) | JSON array | `AdminResourceService::batchStoreRecords()` |
| 12 | **Event Registration** | `POST /api/v1/events/{event}/registrations` | Public (API) | Request | `EventRegistrationController@store` |
| 13 | **Event Registration** (web) | `POST /majlis/{event:slug}/daftar` | Public (web) | Request | `EventsController@register` |
| 14 | **User Registration** | Fortify `/register` | Public (Fortify) | Request | `CreateNewUser` |
| 15 | **Saved Search** | `/carian-tersimpan` | Auth (Livewire) | Array state | `CreateSavedSearchAction` |

### 5.2 Filament Admin Forms (All Resources)

| # | Resource | Model | Form Fields | Has Media |
|---|----------|-------|-------------|-----------|
| 1 | User | `User` | name, email, phone, timezone, email_verified_at, phone_verified_at, password, roles | No |
| 2 | Institution | `Institution` | type, name, nickname, slug, description, contactMethods, address (country/state/city/district/subdistrict), status, allow_public_event_submission, socialProfiles | Yes (logo, cover, gallery) |
| 3 | Person | `Person` | name, gender, is_freelance, job_title, honorific, pre_nominal, post_nominal, bio, languages, address (region only), qualifications, contactMethods, status, socialProfiles | Yes (avatar, cover, gallery) |
| 4 | Series | `Series` | title, slug, description, visibility, status, languages | Yes (cover, gallery) |
| 5 | Space | `Space` | name, slug, capacity, status, visibility, institutions | No |
| 6 | Reference | `Reference` | title, author, type, parent_id, part_type, part_number, part_label, year, publisher, is_canonical, status, description | Yes (front_cover, back_cover, gallery) |
| 7 | DonationChannel | `DonationChannel` | donatable (type+id), label, recipient, method, bank fields, duitnow fields, ewallet fields, status, is_default, reference_note | Yes (qr) |
| 8 | Inspiration | `Inspiration` | category, locale, title, content, source, status | Yes (main) |
| 9 | Report | `Report` | entity_type, entity_id, category, description, evidence, status, reporter, handler, resolution_note | Yes (evidence) |
| 10 | MembershipApplication | `MembershipApplication` | read-only (infolist) | Yes (evidence) |
| 11 | ContributionRequest | `ContributionRequest` | read-only (infolist) | No |
| 12 | SlugRedirect | `SlugRedirect` | redirectable_type, redirectable_id, source_slug, tracking fields | No |
| 13 | AiModelPricing | `AiModelPricing` | provider, model_pattern, operation, tier, currency, priority, status, token rates, per-request/image/audio rates, dates, notes | No |
| 14 | AiUsageLog | `AiUsageLog` | read-only (table) | No |
| 15 | Audit | `Audit` | read-only (infolist) | No |

### 5.3 Complete Submission Flow: Submit Event

**Entry points:**
- Web: `POST /hantar-majlis` (anonymous Livewire component in Blade)
- API: `POST /api/v1/submit-event` (`EventSubmissionController@store`)

**Form fields (5-step wizard):**
- Step 1: event_category_ids, title, description, submission_country_id, event_date, prayer_time, custom_time, end_time, event_format, visibility, event_url, live_url, gender, age_group, languages, children_allowed, is_muslim_only
- Step 2: domain_tags, discipline_tags, source_tags, issue_tags, references
- Step 3: primary_organizer_kind, primary_organizer_institution_id, primary_organizer_person_id, location_same_as_institution, location_type, location_institution_id, location_venue_id, space_ids
- Step 4: speakers, other_key_people (repeater), cover, poster, gallery
- Step 5: submitter_name, submitter_email, submitter_phone, notes, captcha_token

**Data flow:**
```
Form → Livewire submit()
  → SubmitFrontendEventAction::handle()
    → Normalize enums, captcha, conditional requirements
    → Resolve startsAt/endsAt (prayer time or custom)
    → Resolve organizer & location
    → Generate slug
    → Build ValidatedEventSubmission DTO
    → DB::transaction()
      └→ PersistValidatedEventSubmissionAction::handle()
        ├── Event::create([title, slug, desc, tz, gender, age_group, ...data])
        ├── $event->syncLocation(venueId, spaceIds)           → event_locations
        ├── SyncEventScheduleAction                            → event_occurrences
        ├── CreateEventSessionAction (if session)              → event_sessions
        ├── $event->setPrimaryOrganizer(organizer)             → event_involvements
        ├── EventKeyPersonSyncService::sync()                  → event_key_people
        ├── $event->syncLanguages(languageIds)                 → event_languages
        ├── SyncEventClassificationsAction                     → event_classifications
        ├── Save media (cover/poster/gallery)                  → media
        └── EventSubmission::create([event_id, status, submitter]) → event_submissions

    → DB::afterCommit()
      └→ CompleteFrontendEventSubmissionAction::handle()
        ├── ShareTrackingService::recordOutcome()
        ├── Store submitter contacts (if guest)
        ├── Set registration defaults (accessPolicy)
        └── Auto-approve OR → Pending status
```

**Tables written:**
- `events` — core event
- `event_occurrences` — schedule
- `event_sessions` — (if session)
- `event_involvements` — primary organizer
- `event_locations` — venue/institution location
- `event_key_people` — speakers + roles
- `event_languages` — language links
- `event_classifications` — categories, domains, disciplines, sources, issues
- `event_submissions` — submission record
- `media` — cover, poster, gallery
- `spaces` pivot — space links

### 5.4 Complete Submission Flow: Contribution (Create Institution/Person)

**Entry points:**
- Web: `/sumbangan/institusi/baru`, `/sumbangan/penceramah/baru` (Livewire)
- API: `POST /v1/contributions/institutions`, `POST /v1/contributions/persons`

**Data flow:**
```
Form → SubmitStagedContributionCreateAction::handle()
  → Creates ContributionRequest (type: create, status: pending)
    → Proposed data stored as JSON in proposed_data column
    → ContributionRequest created with:
      - type: 'create'
      - subject_type: 'institution'|'person'
      - entity_type: null (entity not yet created)
      - proposer_id: auth user
      - status: 'pending'
      - proposed_data: full JSON of submitted data

Review (Admin) → ApproveContributionRequestAction / RejectContributionRequestAction
  → Approve: Entity created from proposed_data JSON
  → Reject: Status changed, reviewer_note stored
```

### 5.5 Complete Submission Flow: Contribution (Suggest Update)

**Entry points:**
- Web: `/sumbangan/{type}/{id}/kemas-kini` (Livewire)
- API: `POST /v1/contributions/{subjectType}/{subject}/suggest`

**Data flow:**
```
SuggestUpdate (Livewire)
  → If user canDirectEdit(): ApplyDirectContributionUpdateAction
    → Direct diff + update on the entity model
    → Audit trail recorded
  → Else: SubmitContributionUpdateRequestAction
    → Creates ContributionRequest (type: update)
    - subject_type, entity_type, entity_id set
    - original_data: snapshot of current entity values
    - proposed_data: diff of only changed fields
    - status: 'pending'

Review (Admin) → Approve → entity updated from proposed_data
                  → Reject → status changed, reviewer_note
```

### 5.6 Complete Submission Flow: Report

**Entry points:**
- Web: `/lapor/{type}/{id}` (Livewire)
- API: `POST /v1/reports`

**Data flow:**
```
Report form → SubmitReportAction::handle()
  → Detects duplicate (same reporter + entity + category)
  → Report::create([
      reporter_id, reporter_type, reporter_fingerprint,
      entity_type, entity_id,
      category, description,
      status: 'open'
    ])
  → Stores evidence media
```

### 5.7 Complete Submission Flow: Membership Application

**Entry points:**
- Web: `/pohon-keahlian/{type}/{id}` (Livewire)
- API: `POST /v1/membership-applications/{subjectType}/{subject}`

**Data flow:**
```
MembershipApplication form → SubmitMembershipApplicationAction::handle()
  → Validates no duplicate pending application
  → MembershipApplication::create([
      subject_type, subject_id,
      applicant_id (auth user),
      status: 'pending',
      justification
    ])
  → Saves evidence media
  → Dispatches notification to subject admins

Review (Admin) → Approve → granted_role set, user added as member
                  → Reject → reason stored
```

### 5.8 Filament Form Submission Flow (Admin CRUD)

For admin resources with create/edit forms:

```
Filament Form → Resource::mutateFormDataBeforeCreate()
              → Resource::createRecord()
                → Model::create() / Model::update()
                  → Model boot hooks fire (saving, saved)
                  → Observers fire (created, saved, updated)
                    → EventObserver (slug, cache, search)
                    → InstitutionObserver (slug, cache)
                    → PersonObserver (slug, cache)
                    → etc.
              → Resource::afterCreate()
                → SaveRelationships (Repeater, media, BelongsToMany)
```

---

## 6. Import & Data Ingestion

### 6.1 CSV-Based Seeders

| Seeder | File | Records | Target Models | Env Gate |
|--------|------|---------|---------------|----------|
| `MasjidSeeder` | `database/seeders/senarai_masjid.csv` | 6,936 | `Institution` (masjid) + addresses + contacts | `SEED_MASJID_DIRECTORY=true` |
| `InstitutionSeeder` | Inline array | ~59 | `Institution` + contacts + addresses | `seedWhenEmpty` |
| `PersonSeeder` | Inline array | ~30 | `Person` | `seedWhenEmpty` |
| `VenueSeeder` | Factory | 50 | `Venue` | `seedWhenEmpty` |
| `ReferenceSeeder` | Inline array | 13 | `Reference` | `seedWhenEmpty` |
| `EventTaxonomySeeder` | Inline tree (in `AIArmada/` subdir) | 29 terms | `EventTerm` | Always |
| `LanguageSeeder` | Inline array | 7 | `Language` | Always |
| `FacilityTypeSeeder` | Inline array | 4 | `FacilityType` | Always |
| `AddressingSeeder` | Package internal | Full MY geography | `AddressCountry`, `State`, `City`, `AddressArea` | Always |
| `AdvancedEventSeeder` | Inline array | ~5 | `Event` (advanced programs) | Seed |
| `DistrictSeeder` | Inline array | ~16 | `AddressArea` (districts) | Seed |
| `EventSeeder` | Factory | ~20 | `Event` | Seed |
| `EventSubmissionSeeder` | Factory | ~10 | `EventSubmission` | Seed |
| `DonationChannelSeeder` | Inline array | ~10 | `DonationChannel` | Seed |
| `InspirationSeeder` | Inline array | ~20 | `Inspiration` | Seed |
| `MalaysiaCitySeeder` | Inline array | ~150 | `City` | Seed |
| `MediaLinkSeeder` | Factory | ~10 | `MediaLink` | Seed |
| `ModerationReviewSeeder` | Factory | ~5 | `ModerationReview` | Seed |
| `PermissionSeeder` | Inline array | ~30 | `Permission` (Authz) | Seed |
| `ProductionSeeder` | Inline array | ~5 | Mixed production data | Seed |
| `RegistrationSeeder` | Factory | ~10 | `Registration` | Seed |
| `ReportSeeder` | Factory | ~5 | `Report` | Seed |
| `RoleSeeder` | Inline array | ~5 | `Role` (Authz) | Seed |
| `SavedSearchSeeder` | Factory | ~5 | `SavedSearch` | Seed |
| `ScopedMemberRolesSeeder` | Inline array | ~3 | `Role` (scoped) | Seed |
| `SeriesSeeder` | Factory | ~5 | `Series` | Seed |
| `SpaceSeeder` | Factory | ~5 | `Space` | Seed |
| `SubdistrictSeeder` | Inline array | ~180 | `AddressArea` (subdistricts) | Seed |
| `TitleCategorySeeder` | Inline array | ~5 | `TitleCategory` | Seed |
| `TitleSeeder` | Inline array | ~10 | `Title` | Seed |
| `UserSeeder` | Factory | ~5 | `User` | Seed |
| `WorldSeeder` | CSV | ~200 | `AddressCountry` | Seed |

**Orphaned (on disk, not in DatabaseSeeder):** `GeneratedFileFinalFixedPoskodSeeder` — reads CSV with `fgetcsv()`, resolves state/district/subdistrict via sophisticated matching (aliases, overrides, Jengka inference, Pusa/Betong special case), creates missing subdistricts on-the-fly, enforces strict validation, creates/finds institutions with `firstOrNew` + idempotent slugs.

**Key: MasjidSeeder** — reads CSV with `fgetcsv()`, resolves state via aliases, resolves district via `AddressArea`, limits to 300 rows, stores phone contacts.

### 6.2 Scout Index Commands

| Command | Model | Action |
|---------|-------|--------|
| `search:index-events` | `Event` | `scout:import` wrapper |
| `search:index-persons` | `Person` | `scout:import` wrapper |
| `search:index-institutions` | `Institution` | `scout:import` wrapper |
| `search:index-references` | `Reference` | `scout:import` wrapper |
| `persons:reindex-search` | Person names | Custom reindex (not Scout) |

### 6.3 MCP Batch Tools

| Tool | Input | Max | Target |
|------|-------|-----|--------|
| `admin-batch-create-events` | JSON array | 50 | Events (full creation) |
| `admin-batch-create-records` | JSON array | 100 | Any writable resource |

### 6.4 Backfill Jobs

| Job | Model | Purpose |
|-----|-------|---------|
| `BackfillEventSlugs` | `Event` | Regenerate slugs |
| `BackfillPersonSlugs` | `Person` | Regenerate slugs |
| `BackfillInstitutionSlugs` | `Institution` | Regenerate slugs |
| `BackfillVenueSlugs` | `Venue` | Regenerate slugs |
| `BackfillReferenceSlugs` | `Reference` | Regenerate slugs |

---

## 7. Complete Model Inventory

### 7.1 First-Party Models

| # | Model | Table | Primary Key | Traits | Factory | Extends |
|---|-------|-------|-------------|--------|---------|---------|
| 1 | `Event` | `events` (package) | UUID | Audits, HasAddresses, HasDonationChannels, HasFactory, HasMembers, HasResponses, HasStates, KeepsDeletedModels, Searchable | `EventFactory` | `AIArmada\Events\Models\Event` |
| 2 | `User` | `users` | UUID | Audits, CanBookmark, CanRespond, HasApiTokens, HasFactory, HasRoles, HasUuids, HasUserRestoration, KeepsDeletedModels, MustVerifyEmail, CanFollow, HasInbox, Notifiable | `UserFactory` | `Illuminate\Foundation\Auth\User` |
| 3 | `Institution` | `institutions` | UUID | Audits, HasAddresses, HasContactMethods, HasDonationChannels, HasFactory, HasLanguages, HasMembers, HasSocialProfiles, HasUuids, InteractsWithMedia, KeepsDeletedModels, Searchable | `InstitutionFactory` | `Model` |
| 4 | `Person` | `persons` | UUID | Audits, HasAddresses, HasContactMethods, HasDonationChannels, HasFactory, HasLanguages, HasMembers, HasSocialProfiles, HasUuids, InteractsWithMedia, KeepsDeletedModels, Searchable | `PersonFactory` | `AIArmada\Persons\Models\Person` |
| 5 | `Venue` | `venues` (package) | UUID | Audits, HasAddresses, HasContactMethods, HasFactory, HasSocialProfiles, KeepsDeletedModels | `VenueFactory` | `AIArmada\Events\Models\Venue` |
| 6 | `Series` | `event_series` | UUID | Audits, HasFactory, HasLanguages, InteractsWithMedia | `SeriesFactory` | `AIArmada\Events\Models\EventSeries` |
| 7 | `Reference` | `references` (package) | UUID | Audits, HasSocialProfiles, KeepsDeletedModels, Searchable, HasFactory, HasMembers | `ReferenceFactory` | `AIArmada\References\Models\Reference` |
| 8 | `Registration` | `registrations` (package) | UUID | Audits, HasFactory | `RegistrationFactory` | `AIArmada\Events\Models\EventRegistration` |
| 9 | `Tag` | `tags` | UUID | HasUuids, SortableTrait | — | `Spatie\Tags\Tag` |
| 10 | `Report` | `reports` | UUID | Audits, HasFactory, HasUuids, InteractsWithMedia | `ReportFactory` | `AIArmada\CommerceSupport\Models\Report` |
| 11 | `DonationChannel` | `donation_channels` | UUID | Audits, HasFactory, HasUuids, InteractsWithMedia | `DonationChannelFactory` | `Model` |
| 12 | `Audit` | `audits` | UUID | HasUuids | — | `OwenIt\Auditing\Models\Audit` |
| 13 | `SocialAccount` | `socialite` | UUID | HasFactory, HasUuids | `SocialAccountFactory` | `Model` |
| 14 | `SlugRedirect` | `slug_redirects` | UUID | HasUuids | — | `Model` |
| 15 | `MemberInvitation` | `member_invitations` (package) | UUID | Audits | — | `AIArmada\Membership\Models\MembershipInvitation` |
| 16 | `ContributionRequest` | `contribution_requests` | UUID | Audits, HasFactory, HasUuids | `ContributionRequestFactory` | `Model` |
| 17 | `EventChangeAnnouncement` | `event_change_announcements` (package) | UUID | — | `EventChangeAnnouncementFactory` | `AIArmada\Events\Models\EventUpdate` |
| 18 | `SavedSearch` | `saved_searches` | UUID | HasFactory, HasUuids | `SavedSearchFactory` | `AIArmada\CommerceSupport\Models\SavedSearch` |
| 19 | `EventSubmission` | `event_submissions` (package) | UUID | Audits, HasContactMethods | — | `AIArmada\Events\Models\EventSubmission` |
| 20 | `EventCheckin` | `event_checkins` (package) | UUID | — | `EventCheckinFactory` | `AIArmada\Events\Models\EventAttendance` |
| 21 | `ModerationReview` | `moderation_reviews` (package) | UUID | Audits | — | `AIArmada\Moderation\Models\ModerationAction` |
| 22 | `MediaLink` | `media_links` | UUID | Audits, HasFactory, HasUuids | `MediaLinkFactory` | `Model` |
| 23 | `MembershipApplication` | `membership_applications` (package) | UUID | Audits, HasFactory, InteractsWithMedia | `MembershipApplicationFactory` | `AIArmada\Membership\Models\MembershipApplication` |
| 24 | `EventKeyPerson` | `event_key_people` (package) | UUID | — | — | `AIArmada\Events\Models\EventInvolvement` |
| 25 | `EventKeyPersonPivot` | `event_involvements` | UUID | HasUuids | — | `Pivot` |
| 26 | `EventReferencePivot` | `event_references` | UUID | HasUuids | — | `MorphPivot` |
| 27 | `Inspiration` | `inspirations` | UUID | Audits, HasFactory, HasUuids, InteractsWithMedia | `InspirationFactory` | `Model` |
| 28 | `AiModelPricing` | `ai_model_pricings` | UUID | Audits, HasFactory, HasUuids | — | `Model` |
| 29 | `AiUsageLog` | `ai_usage_logs` | UUID | HasFactory, HasUuids | — | `Model` |
| 30 | `Affiliation` | `affiliations` (package) | UUID | HasUuids, HasFactory | `AffiliationFactory` | `AIArmada\Persons\Models\Affiliation` |
| 31 | `Language` | `languages` | UUID | HasUuids, HasFactory | `LanguageFactory` | `Model` |
| 32 | `Space` | `spaces` (package) | UUID | Audits | `SpaceFactory` | `AIArmada\Events\Models\VenueSpace` |
| 33 | `PassportUser` | `users` | UUID | HasApiTokens | — | `Authenticatable` |

### 7.2 Concern Traits (in `app/Models/Concerns/`)

| Trait | Used By | Purpose |
|-------|---------|---------|
| `AuditsModelChanges` | Most models | Wraps owen-it/auditing with custom tags, custom audit methods |
| `HasDonationChannels` | Event, Institution, Person | Polymorphic donation channels |
| `HasLanguages` | Institution, Person, Series | Polymorphic language links |
| `HasUserRestoration` | User only | Full user-deletion cascade + restoration logic (social accounts, follows, bookmarks, memberships, registrations, etc.) |

**Package traits (not local):**
- `KeepsDeletedModels` (spatie/laravel-deleted-models) — Event, User, Institution, Venue, Person, Reference

### 7.3 Model Events (Booted)

| Model | Event | Handler |
|-------|-------|---------|
| `Event` | `saved` | `syncUrlLinks()`, `syncAudiences()`, `syncAttributes()` |
| `Event` | `deleting` | Cascade cleanup (members, involvements, key people, submissions, etc.) |
| `User` | `updated` | Sync public submission locks on phone_verified_at change |
| `User` | `deleting` | Massive cleanup (social accounts, auth, memberships, follows, etc.) |
| `Institution` | `saving` | Auto-set last_state_change_at, verified_at/rejected_at/published_at |
| `Person` | `saving` | Auto-set last_state_change_at, verified_at/rejected_at/published_at |
| `Venue` | `saving` | Set verified_by on status change |
| `Reference` | `saving` | Auto-generate slug, normalize parts, set verified_by |
| `Report` | `creating` | Set default reporter_type |
| `DonationChannel` | `saved` | Ensure single default per donatable |
| `Registration` | `deleting` | Cascade delete attendances, answers, items, participants |
| `EventChangeAnnouncement` | `creating` | Auto-set title, visibility defaults |
| `SavedSearch` | `creating` | Set default user_type |
| `EventReferencePivot` | `creating` | Set defaults for type, visibility, sort_order |

---

## 8. Observer Inventory

Observers registered in `AppServiceProvider::registerModelObservers()` (13) + `AuditedMediaObserver` (registered in package).

| Observer | Model | Events | Key Actions |
|----------|-------|--------|-------------|
| `EventObserver` | `Event` | creating, created, updating, updated, saved, deleted | Slug generation, cache busting, Scout search, slug redirect sync |
| `VenueObserver` | `Venue` | saved, deleted | Slug sync, cache busting |
| `PersonObserver` | `Person` | saved, deleted | Slug sync (name/honorific changes), search record sync, cache busting |
| `InstitutionObserver` | `Institution` | saved, deleted | Slug sync, cache busting (listings + directory + search) |
| `ReferenceObserver` | `Reference` | updated, deleted | Slug sync |
| `EventOccurrenceObserver` | `EventOccurrence` | saved, deleted | Re-sync parent Event searchability |
| `EventTimeExpressionObserver` | `EventTimeExpression` | saved, deleted | Re-sync parent Event searchability |
| `EventKeyPersonObserver` | `EventKeyPerson` | created, updated, deleted | Cache busting |
| `EventTermObserver` | `EventTerm` | saved, deleted | Cache busting |
| `AddressObserver` | `Address` | saving, saved, deleted | Default country, slug sync, cache busting |
| `AddressableObserver` | `Addressable` | created, deleted | Cache busting, slug/search sync |
| `AddressAreaObserver` | `AddressArea` | saved, deleted, deleting | Location cache flush, guard delete if referenced |
| `AddressCountryObserver` | `AddressCountry` | saving, saved, deleted | Entity type, cache flush, guard delete if referenced |
| `AuditedMediaObserver` | `Media` | created, updating, updated, deleting, deleted | Audit trail on owner model, cache busting |

---

## 9. Action Inventory

### 9.1 Auth (5)

| Action | Purpose |
|--------|---------|
| `AuthenticateApiUserAction` | Authenticate API user by credentials |
| `AuthenticateSocialiteApiUserAction` | Authenticate via social provider |
| `RegisterApiUserAction` | Register new API user |
| `ResolveSocialiteUserAction` | Find/create user from social account |
| `RevokeCurrentApiTokenAction` | Revoke current API token |

### 9.2 Contributions (19)

| Action | Purpose |
|--------|---------|
| `ApplyDirectContributionUpdateAction` | Apply direct edits (bypasses moderation) |
| `ApproveContributionRequestAction` | Approve a pending contribution request |
| `CanReviewContributionRequestAction` | Check if user can review |
| `CancelContributionRequestAction` | Cancel a contribution request |
| `EnsureUniqueContributionCreateAction` | Prevent duplicate create requests |
| `RejectContributionRequestAction` | Reject with reason |
| `ResolveContributionChangedPayloadAction` | Diff original vs proposed |
| `ResolveContributionEntityMetadataAction` | Entity type/label for UI |
| `ResolveContributionSubjectAction` | Resolve subject model from route key |
| `ResolveContributionSubjectPresentationAction` | Subject display data |
| `ResolveContributionSubmissionStateAction` | Check submission validity |
| `ResolveContributionUpdateContextAction` | Build update context |
| `ResolveLatestPendingContributionRequestAction` | Find latest pending request |
| `ResolveOwnContributionRequestAction` | Find user's own request |
| `ResolvePendingContributionApprovalsAction` | List pending approvals |
| `ResolveReviewableContributionRequestAction` | Check if reviewable |
| `SubmitContributionCreateRequestAction` | Submit new create request |
| `SubmitContributionUpdateRequestAction` | Submit update request |
| `SubmitStagedContributionCreateAction` | End-to-end staged create flow |

### 9.3 Events (17)

| Action | Purpose |
|--------|---------|
| `CompleteFrontendEventSubmissionAction` | Post-persist side effects (share tracking, auto-approve) |
| `CreateAdvancedEventAction` | Create advanced event (parent program) |
| `GenerateEventSlugAction` | Generate unique slug |
| `MarkEventGoingAction` | Mark user as going |
| `PersistValidatedEventSubmissionAction` | **Write event to DB** (core persistence) |
| `PrepareAdvancedParentProgramSubmissionAction` | Prepare parent program for session submission |
| `PublishEventChangeAnnouncement` | Publish change announcement |
| `RecordEventCheckInAction` | Record attendance check-in |
| `RemoveEventGoingAction` | Remove going status |
| `ResolveAdvancedBuilderContextAction` | Resolve builder context |
| `ResolveAdvancedBuilderMembershipOptionsAction` | Membership options |
| `ResolveEventCheckInStateAction` | Resolve check-in availability |
| `SaveAdminEventAction` | Save event from admin |
| `SubmitFrontendEventAction` | **Orchestrate full event submission** |
| `SyncEventClassificationsAction` | Sync category/domain/discipline/source/issue terms |
| `SyncEventResourceRelationsAction` | Sync speakers, references, series |
| `SyncEventScheduleAction` | Sync occurrence schedule |

### 9.4 Institutions (2)

| Action | Purpose |
|--------|---------|
| `GenerateInstitutionSlugAction` | Generate slug |
| `SaveInstitutionAction` | Save institution (used by Filament) |

### 9.5 Persons (2)

| Action | Purpose |
|--------|---------|
| `GeneratePersonSlugAction` | Generate slug |
| `SavePersonAction` | Save person (used by Filament) |

### 9.6 References (2)

| Action | Purpose |
|--------|---------|
| `GenerateReferenceSlugAction` | Generate slug |
| `SaveReferenceAction` | Save reference (used by Filament) |

### 9.7 Reports (6)

| Action | Purpose |
|--------|---------|
| `ResolveReportCategoryOptionsAction` | Category options for form |
| `ResolveReportEntityMetadataAction` | Entity types & labels |
| `ResolveReportFormContextAction` | Build form context |
| `ResolveReporterFingerprintAction` | Track repeat reporters |
| `SaveReportAction` | Save report (Filament) |
| `SubmitReportAction` | Submit report with duplicate detection |

### 9.8 Membership (3)

| Action | Purpose |
|--------|---------|
| `AcceptSubjectMemberInvitation` | Accept member invite |
| `InviteSubjectMember` | Invite new member |
| `SubmitMembershipApplicationAction` | Submit membership claim |

### 9.9 Other Actions (22)

| Domain | Actions |
|--------|---------|
| **AddressAreas** | *(empty directory — reserved)* |
| **DonationChannels** | `SaveDonationChannelAction` |
| **Fortify** | `CreateNewUser`, `PasswordValidationRules`, `RecordSuccessfulLogin`, `ResetUserPassword` |
| **GitHub** | `SubmitGitHubIssueReportAction` |
| **Inspirations** | `SaveInspirationAction` |
| **Location** | `NormalizeGoogleMapsInputAction`, `ResolveGooglePlaceSelectionAction` |
| **Notifications** | `MarkAllNotificationMessagesReadAction`, `MarkNotificationMessageReadAction` |
| **SavedSearches** | `CreateSavedSearchAction`, `ExecuteSavedSearchAction`, `UpdateSavedSearchAction` |
| **Series** | `SaveSeriesAction` |
| **Signals** | `RecordMobileTelemetryBatchAction` |
| **Slugs** | `ResolvePublicSlugAction`, `SyncCanonicalSlugAction`, `SyncSlugRedirectAction` (4 concern traits) |
| **Spaces** | `SaveSpaceAction` |
| **Venues** | `GenerateVenueSlugAction`, `SaveVenueAction` |

---

## 10. Service Inventory

| Service | Domain | Purpose |
|---------|--------|---------|
| `CalendarService` | Events | Generate iCal/ICS files |
| `ContributionEntityMutationService` | Contributions | Apply contribution changes to entities |
| `EventCategoryCatalog` | Events | Category catalog with rules |
| `EventCategoryPolicy` | Events | Category-based policies |
| `EventKeyPersonSyncService` | Events | Sync key people relationships |
| `EventSearchService` | Events | Advanced event search with filters |
| `ModerationService` | Moderation | State machine transitions for moderation |
| `PostgresEventDiscovery` | Events | Postgres-based event discovery |
| `PrayerTimeExpressionResolver` | Events | Resolve prayer time expressions |
| `PrayerTimeService` | Events | Prayer time data |
| `ShareTrackingAnalyticsService` | Sharing | User-facing share analytics |
| `ShareTrackingService` | Sharing | Core share tracking |
| `TypesenseEventDiscovery` | Events | Typesense-based event discovery |

### Services (subdirectories)

**`Services/Ai/` (5):**
| Service | Purpose |
|---------|---------|
| `AiBudgetDecision` | Budget decision logic |
| `AiBudgetPolicy` | Budget policy rules |
| `AiCostResolver` | Resolve AI usage cost |
| `AiUsageLedger` | Record AI usage to `AiUsageLog` |
| `EventMediaExtractionService` | Extract event info from images |

**`Services/Captcha/` (1):**
| Service | Purpose |
|---------|---------|
| `TurnstileVerifier` | Cloudflare Turnstile captcha |

**`Services/Communications/` (empty dir):**
| Service | Purpose |
|---------|---------|

**`Services/Diagnostics/` (1):**
| Service | Purpose |
|---------|---------|
| `NetworkDiagnosticsService` | System diagnostics |

**`Services/GitHub/` (1):**
| Service | Purpose |
|---------|---------|
| `GitHubIssueReporter` | Submit GitHub issues |

**`Services/Notifications/` (4):**
| Service | Purpose |
|---------|---------|
| `ContributionRequestNotificationService` | Contribution notifications |
| `EventNotificationService` | Event notifications |
| `NotificationMessageRenderer` | Render notification templates |
| `NotificationSettingsManager` | CRUD notification preferences |

**`Services/ShareTracking/` (5):**
| Service | Purpose |
|---------|---------|
| `AdminShareAnalyticsService` | Admin share analytics dashboard |
| `AffiliateRuntimeDataPurger` | Purge old affiliate data |
| `AffiliatesShareTrackingAnalyticsService` | Affiliate analytics |
| `AffiliatesShareTrackingService` | Affiliate share tracking |
| `ShareTrackingUrlService` | Build share URLs |

**`Services/Signals/` (5):**
| Service | Purpose |
|---------|---------|
| `AffiliateSignalsBridge` | Affiliate → Signals bridge |
| `ProductSignalSchemaRegistry` | Schema management |
| `ProductSignalsInsightsService` | Signal insights dashboard |
| `ProductSignalsService` | Core signal recording |
| `SignalsTracker` | Low-level signal tracking |

---

## 11. Job Inventory

| Job | Queue | Purpose |
|-----|-------|---------|
| `DispatchEventReminderNotifications` | default (every 15 min) | Send event reminders |
| `EscalatePendingEvents` | default (hourly) | Escalate stuck pending events |
| `BackfillEventSlugs` | default | Regenerate event slugs |
| `BackfillPersonSlugs` | default | Regenerate person slugs |
| `BackfillInstitutionSlugs` | default | Regenerate institution slugs |
| `BackfillVenueSlugs` | default | Regenerate venue slugs |
| `BackfillReferenceSlugs` | default | Regenerate reference slugs |
| `GenerateResponsiveImagesJob` | media | Generate responsive image conversions |

---

## 12. Console Command Inventory

| Command | Signature | Purpose |
|---------|-----------|---------|
| `AbstractIndexToScout` | (abstract base) | Base class for Scout imports |
| `IndexEventsToTypesense` | `search:index-events {--fresh} {--chunk=500}` | Import events to Scout |
| `IndexInstitutionsToTypesense` | `search:index-institutions {--fresh} {--chunk=500}` | Import institutions to Scout |
| `IndexReferencesToTypesense` | `search:index-references {--fresh} {--chunk=500}` | Import references to Scout |
| `IndexPersonsToTypesense` | `search:index-persons {--fresh} {--chunk=500}` | Import persons to Scout |
| `ReindexPersonSearch` | `persons:reindex-search {--chunk=100}` | Rebuild person search index |
| `IssueMcpToken` | `mcp:token {email} {name=opencode-mcp} {--server=admin}` | Issue MCP auth token |
| `MigrateMediaToNewStructure` | `app:media:migrate-structure {--dry-run} {--force}` | Migrate media file structure |
| `PruneOrphanedEntities` | `app:prune-orphaned-entities {--hours=48} {--dry-run}` | Remove orphaned records |
| `QueueBackfillEventSlugs` | `events:queue-slug-backfill` | Queue slug backfill job |
| `QueueBackfillInstitutionSlugs` | `institutions:queue-slug-backfill` | Queue slug backfill |
| `QueueBackfillReferenceSlugs` | `references:queue-slug-backfill` | Queue slug backfill |
| `QueueBackfillPersonSlugs` | `persons:queue-slug-backfill` | Queue slug backfill |
| `QueueBackfillVenueSlugs` | `venues:queue-slug-backfill` | Queue slug backfill |
| `SendDigestNotificationsCommand` | `communications:send-digests` | Send digest emails |
| `SyncPublicSubmissionLocks` | `app:sync-public-submission-locks` | Sync submission lock state |

---

## 13. Key Data Flow Diagrams

### 13.1 Event Submission Flow (Public)

```
User fills 5-step form (Livewire Anonymous Component)
  │
  ├─ Step 1: Details (title, date, prayer time, format, visibility, audience)
  ├─ Step 2: Categories & Tags (domain, discipline, source, issue, references)
  ├─ Step 3: Organizer & Location (institution/person, venue, spaces)
  ├─ Step 4: Speakers & Media (speakers, roles, cover, poster, gallery)
  └─ Step 5: Review & Submit (submitter name/email/phone, notes, captcha)
        │
        ▼
  SubmitFrontendEventAction::handle()
    ├── Normalize enums (Livewire enum objects → string values)
    ├── Verify Turnstile captcha
    ├── Assert conditional requirements
    ├── Assert entity access permissions
    ├── Resolve startsAt/endsAt/tz (prayer time or custom time)
    ├── Resolve organizer & location (Institution|Person + Venue)
    ├── Generate slug (title + date + person slugs)
    ├── Build ValidatedEventSubmission DTO
    │
    └── DB::transaction()
        └── PersistValidatedEventSubmissionAction
            ├── events: title, slug, description, timezone, status, visibility, gender, etc.
            ├── event_occurrences: starts_at, ends_at, timing_mode
            ├── event_locations: venue_id, institution_id, space_ids
            ├── event_involvements: primary organizer (institution/person)
            ├── event_key_people: speakers + other roles
            ├── event_languages: language IDs
            ├── event_classifications: category/domain/discipline/source/issue
            └── event_submissions: submitter info, status
            └── media: cover, poster, gallery
        │
        └── DB::afterCommit()
            └── CompleteFrontendEventSubmissionAction
                ├── ShareTrackingService::recordOutcome()
                ├── Store submitter contacts (if guest)
                ├── Set registration defaults (none, walk-in ok)
                └── Auto-approve OR → Pending status
                    └── ModerationService::approve()
```

### 13.2 Contribution Flow (Create Institution/Person)

```
User fills contribution form (Livewire)
  │
  ├── Institution: name, type, address, contact, media, social
  └── Person: name, gender, bio, qualifications, contact, media
        │
        ▼
  SubmitStagedContributionCreateAction::handle()
    └── ContributionRequest:create
        ├── type: 'create'
        ├── subject_type: 'institution'|'person'
        ├── proposer_id: auth user
        ├── status: 'pending'
        └── proposed_data: {full form JSON}
              │
              ▼  (Admin reviews via Filament or API)
  ApproveContributionRequestAction
    └── Institution|Person::create(proposed_data)
              │
              ▼  OR
  RejectContributionRequestAction
    └── status: 'rejected', reviewer_note stored
```

### 13.3 Contribution Flow (Suggest Update)

```
User fills suggest-update form (Livewire)
  │
  ├── Loads current entity data for reference
  └── User edits fields, adds proposer_note
        │
        ▼
  canDirectEdit? ──Yes──→ ApplyDirectContributionUpdateAction
        │                    └── Entity::update(diff fields)
        No                   └── Audit trail recorded
        │
        ▼
  SubmitContributionUpdateRequestAction
    └── ContributionRequest:create
        ├── type: 'update'
        ├── entity_type: the model class
        ├── entity_id: the UUID
        ├── original_data: snapshot
        ├── proposed_data: {only changed fields}
        └── status: 'pending'
```

### 13.4 Report Flow

```
User fills report form (Livewire)
  │
  ├── category (select)
  └── description (textarea)
        │
        ▼
  SubmitReportAction::handle()
    ├── Duplicate detection (same reporter + entity + category)
    └── Report:create
        ├── entity_type, entity_id
        ├── reporter_id, reporter_fingerprint
        ├── category, description
        ├── status: 'open'
        └── evidence media
```

### 13.5 Membership Application Flow

```
User fills membership claim form (Livewire)
  │
  ├── justification (textarea)
  └── evidence (file uploads)
        │
        ▼
  SubmitMembershipApplicationAction::handle()
    ├── Validate no duplicate pending
    └── MembershipApplication:create
        ├── subject_type, subject_id
        ├── applicant_id
        ├── status: 'pending'
        ├── justification
        └── evidence media
              │
              ▼  (Admin reviews)
  Approve → granted_role set, user added as member
  Reject  → reason stored
```

### 13.6 Admin CRUD Flow (Filament)

```
Filament Form → submit
  │
  ├── Create: Model::create()
  │     └── Observers fire (created/saved)
  │     └── Model boot hooks fire
  │     └── Repeater relationships saved
  │     └── Media saved via SpatieMediaLibrary
  │     └── Audit recorded
  │
  └── Edit: Model::update()
        └── Observers fire (updated/saved)
        └── Model boot hooks fire
        └── Changed fields audited
        └── Cache busted
        └── Scout index updated
```

### 13.7 Key Database Tables & Relationships

```
users ──hasMany──→ event_submissions
users ──hasMany──→ contribution_requests (proposer)
users ──hasMany──→ membership_applications (applicant)
users ──hasMany──→ reports (reporter)
users ──belongsToMany──→ institutions (as member)
users ──belongsToMany──→ persons (as member)
users ──belongsToMany──→ references (as member)

events ──belongsTo──→ institutions (owner)
events ──belongsTo──→ venues (default venue)
events ──hasMany──→ event_occurrences
events ──hasMany──→ event_involvements (organizer, key people)
events ──hasMany──→ event_classifications (categories, domains, etc.)
events ──hasMany──→ event_submissions
events ──hasMany──→ event_checkins
events ──hasMany──→ event_change_announcements
events ──morphMany──→ reports
events ──morphMany──→ bookmarks
events ──morphMany──→ responses (going/saved)
events ──belongsToMany──→ persons (via involvements)
events ──belongsToMany──→ references
events ──belongsToMany──→ series

institutions ──hasMany──→ events
institutions ──belongsToMany──→ persons
institutions ──belongsToMany──→ spaces
institutions ──hasMany──→ donation_channels (morph)
institutions ──hasMany──→ member_invitations (morph)

persons ──belongsToMany──→ events (via involvements)
persons ──belongsToMany──→ institutions
persons ──hasMany──→ event_key_people

addresses ──morphTo──→ addressable (institution, person, venue, event)
contact_methods ──morphTo──→ contactable
media ──morphTo──→ model
```

---

## Appendix: Directory Structure Summary

```
app/
├── Actions/                 82 files (21 subdirs)
│   ├── AddressAreas/ (0 — reserved)
│   ├── Auth/ (5)
│   ├── Contributions/ (19)
│   ├── DonationChannels/ (1)
│   ├── Events/ (17)
│   ├── Fortify/ (4)
│   ├── GitHub/ (1)
│   ├── Inspirations/ (1)
│   ├── Institutions/ (2)
│   ├── Location/ (2)
│   ├── Membership/ (3)
│   ├── Notifications/ (2)
│   ├── References/ (2)
│   ├── Reports/ (6)
│   ├── SavedSearches/ (3)
│   ├── Series/ (1)
│   ├── Signals/ (1)
│   ├── Slugs/ (7 — 3 actions + 4 concerns)
│   ├── Spaces/ (1)
│   ├── Persons/ (2)
│   └── Venues/ (2)
├── Console/Commands/        16 files
├── Data/                    56 files (19 subdirs incl. Api/, Events/, ShareTracking/, GitHub/)
├── Filament/
│   ├── Ahli/Resources/      3 resources
│   ├── Pages/               8 pages
│   ├── Resources/           15 resources (grouped by entity)
│   ├── Tables/Filters/      1 custom filter
│   └── Widgets/             3 widgets
├── Http/Controllers/        Web + API controllers
├── Jobs/                    8 files (7 root + 1 in Media/)
├── Livewire/Pages/          25 components (13 groups)
│   ├── About/ (1)
│   ├── Contributions/ (4)
│   ├── Dashboard/ (7 — incl. Events subdir)
│   ├── Events/ (3)
│   ├── Institutions/ (1)
│   ├── Membership/ (1)
│   ├── MembershipApplications/ (2)
│   ├── References/ (1)
│   ├── Reports/ (1)
│   ├── SavedSearches/ (1)
│   ├── Search/ (1)
│   ├── Persons/ (1)
│   └── SubmitEvent/ (1)
├── Mcp/                     MCP servers (Admin, Member) + tools + prompts
├── Models/                  38 files (33 top-level + 4 concerns + 1 builder)
├── Observers/               15 files (14 observers + 1 concern)
├── Services/                35 files (13 root + 22 in subdirs)
└── Support/                 Media, slugs, formatters, timezone
```

---

*End of report*