# Phase 8 - App Rebuild And Cutover

State: `In Progress`

## Objective

Rebuild app surfaces on package-owned domains, delete superseded app code, regenerate docs, and prove the fresh-schema application works end to end.

## Execution Strategy

Phase 8 is broken into ordered sub-phases. Each sub-phase is a bounded unit of work that can be verified independently. Domains are rebuilt in dependency order: foundation first, then core domain, then support domains.

## Current Checkpoint (2026-07-08)

- 8.C runtime cutover is verified: country switching is removed, package addressing data seeds cleanly, public/admin/API/MCP address contracts no longer infer country from session or cookie state, and package contact/address aliases are live in runtime paths. App geography models (`Country`, `State`), enums, and traits (`HasContacts`, `HasSocialMedia`) are deleted. No app geography migrations remain. Scout Typesense schemas updated to use package-backed address fields (`country_code`, `city`, `state`, `postcode`) instead of legacy integer geography IDs.
- 8.D runtime/test hardening is verified: `EventBuilder` bridges occurrence-backed and metadata-backed event query columns through `app/Models/Builders/EventBuilder.php`. `ReferenceBuilder` bridges reference query columns through `app/Models/Builders/ReferenceBuilder.php`. Nearby search joins package `addressables`. Public event engagement uses package `EngagementManager` and `RegistrationServiceInterface`. Venue runtime bridges map metadata-backed fields plus `default_venue_id`. Legacy event/venue regression slices run against package addressing/contacting. The `filament-events` plugin is registered in both admin and ahli panel providers.
- No app event migrations remain (all tables created by `aiarmada/events` package migrations). App `Event`, `Venue`, `Registration`, `EventCheckin`, `Reference`, and `MemberInvitation` models exist as active subclasses extending their package counterparts with substantial app-specific logic — not dead shims.
- The package-native geography cleanup packet is verified: legacy `Country` / `State` / `District` / `Subdistrict` wrappers and dead geography admin surfaces are deleted, package observers own geography cache/deletion behavior, and the seating lifecycle migration is applied.
- Membership handling is aligned with package-native behavior: `MemberInvitation` extends the installed package model with hashed tokens. `MembershipApplication` model extends `AIArmada\Membership\Models\MembershipApplication` using the `membership_applications` table (package-owned). Claim approve/reject/cancel actions use `ApproveMembershipApplicationAction`, `RejectMembershipApplicationAction`, `CancelMembershipApplicationAction` from the package, driven by `ApplicationStatus` enum. The term "MembershipClaim" is used throughout 38 files (filenames, class names, routes) but operates on `MembershipApplication` records. 12 test files import the deleted `App\Models\MembershipClaim` class — these would fatally error.
- `MembershipHook` is implemented: `AppMembershipHook` implements the package contract, bound in `AppServiceProvider`, wires to `PublicSubmissionLockService` for submission locks.
- Engagement cutover complete: registration, bookmark/save, and follow/unfollow all use package contracts via `RegistrationServiceInterface` and `EngagementManager`. Local `RegisterForEventAction`, `SaveEventAction`, `UnsaveEventAction`, and `HasFollowers` concern are deleted.
- Communications inbox runtime is cut over: custom `InboxChannel` writes to package `NotificationInbox` model. Livewire and API read from package `NotificationInbox`. `auto_capture` is enabled. **However**: `PendingNotification` model (23 callers), `NotificationDelivery` model (28 callers), and both notification migrations exist and have NOT been deleted. `NotificationEngine` (543 lines, 3 callers) and `NotificationCenterMessage` (264 lines) are still the central dispatch mechanism. A dual system coexists: the communications package tables exist and resolvers are bound, but the old engine is not replaced. `PreferenceResolver` and `QuietHoursResolver` are implemented; `DestinationResolver` is not. `HasInbox` trait is not adopted. `CommunicationBatch` is unused. Digest scheduling is not wired. `filament-communications` plugin registered in admin panel only (not ahli).
- Remaining work is structural: complete notification engine replacement, delete superseded notification models/migrations, map Spatie Tags to package taxonomies, rebuild API/MCP/public pages package-first, rename MembershipClaim naming, implement MembershipApplicationNotifier, and wire digest scheduling.

## Package Installation State

Package installation is complete. The app depends directly on **25 `aiarmada/*` packages** through the Composer path repository in `composer.json`.

### Installed directly in the app

- `aiarmada/addressing`
- `aiarmada/affiliates`
- `aiarmada/authz`
- `aiarmada/commerce-support`
- `aiarmada/communications`
- `aiarmada/contacting`
- `aiarmada/engagement`
- `aiarmada/events`
- `aiarmada/filament-addressing`
- `aiarmada/filament-authz`
- `aiarmada/filament-communications`
- `aiarmada/filament-contacting`
- `aiarmada/filament-engagement`
- `aiarmada/filament-events`
- `aiarmada/filament-inventory`
- `aiarmada/filament-seating`
- `aiarmada/filament-signals`
- `aiarmada/filament-ticketing`
- `aiarmada/inventory`
- `aiarmada/membership`
- `aiarmada/moderation`
- `aiarmada/references`
- `aiarmada/seating`
- `aiarmada/signals`
- `aiarmada/ticketing`

### Transitive dependencies (in composer.lock, not in root require)

- `aiarmada/cart`, `aiarmada/checkout`, `aiarmada/customers`, `aiarmada/filament-commerce-support`, `aiarmada/orders`, `aiarmada/products`, `aiarmada/shipping`, `aiarmada/vouchers`

## Sub-Phases

### 8.A — Package Installation (non-destructive)

Install all 25 packages via composer. Publish configs. Verify autoload + optimize.

- [x] `composer require` all core packages
- [x] `composer require` all filament adapters
- [x] Make `authz` and `contacting` explicit dependencies
- [x] Publish package configs
- [x] `composer dump-autoload && php artisan optimize:clear`
- [x] Verify no autoload errors

### 8.B — Migration Conflict Analysis (read-only)

Identify all table name overlaps between app migrations and package migrations.

- [x] List all table names from package migrations
- [x] List all table names from app migrations
- [x] Identify conflicts (tables created by both)
- [x] Document what app code depends on each conflicting table
- [x] Plan removal order

### 8.C — Geography & Contacts Rebuild

Replace geography and contact/social models with package models. Remove country switching.

- [x] Remove app geography migrations (none existed; geography tables are package-owned)
- [x] Remove app geography models + traits + enums
- [x] Remove country switching public route/controller and desktop/mobile shell selector
- [x] Remove implicit preferred-country defaults from public discovery and frontend catalog dependent endpoints
- [x] Move submit-event UI/API country contract from preferred-country integer IDs to package addressing UUIDs
- [x] Remove admin/MCP preferred-country defaults from write schemas
- [x] Remove country switching preferences/resolvers/config
- [x] Seed addressing package countries + Malaysia areas
- [x] Replace `Contact` with `ContactMethod`, `SocialMedia` with `SocialProfile`
- [x] Replace `HasContacts`/`HasSocialMedia` traits
- [x] Register package-first geography ownership in admin mutation/runtime flows
- [x] Rebuild address form schemas
- [x] Rebuild API catalog endpoints
- [x] Update Scout searchable arrays — `config/scout.php` Typesense schemas updated for Speaker, Institution, Event to use package-backed `country_code`/`city`/`state`/`postcode` instead of legacy integer `country_id`/`state_id`/`district_id`/`subdistrict_id`

### 8.D — Events Domain Rebuild

Replace the entire event system with the events package. App models extend package models as active subclasses.

- [x] Remove app event migrations (none remained; all event tables owned by `aiarmada/events`)
- [x] App event models are active subclasses: `Event extends AIArmada\Events\Models\Event` (2585 lines), `Venue extends AIArmada\Events\Models\Venue` (217 lines), `Registration extends AIArmada\Events\Models\EventRegistration` (441 lines), `EventCheckin extends AIArmada\Events\Models\EventAttendance` (116 lines)
- [x] Configure events package (`config/events.php` — 55+ table names)
- [x] Extend package Event with media collections (cover, poster, gallery)
- [x] Register `filament-events` plugin in admin and ahli panel providers
- [ ] Map Spatie Tags → package taxonomies (EventTaxonomy/EventTerm) — NOT DONE. `event_taxonomies` and `event_terms` tables exist in DB but are entirely unused. All tagging (search indexing, submission via `syncTags()`, filter panels) still uses Spatie `HasTags`. Zero `EventTaxonomy`/`EventTerm` references in app code.
- [x] Rebuild Filament event resources — DONE. No app-level Filament event resource exists. `AIArmada\FilamentEvents\Resources\EventResource` from the plugin fully replaces it across admin panel, ahli panel, moderation queue, and 5 relation managers.
- [ ] Rebuild public event pages to use package contracts directly — PARTIAL. `app/Livewire/Pages/Events/Show.php` and `Index.php` use package `EngagementManager`/`RegistrationServiceInterface` but still import `App\Models\Event` (shim) and Spatie `Tag` model. `AdvancedFiltersPanel` still uses Spatie Tags for filters.
- [ ] Rebuild API event endpoints to use package contracts directly — NOT DONE. `app/Http/Controllers/Api/EventController.php` imports `App\Models\Event`, `App\Models\EventCheckin`, `App\Models\Registration`. No import of `AIArmada\Events\Models\Event`.
- [ ] Rebuild MCP event tools to use package contracts directly — PARTIAL. Write tools (create/update/batch/moderate) go through `AdminResourceService` → plugin path (no direct `App\Models\Event` import). Image upload tools and all MCP prompts still import `App\Models\Event`.
- [ ] Rebuild event search indexing — NOT DONE. `Event::toSearchableArray()` extracts tag/topic IDs from Spatie Tags. `EventSearchService` filters by legacy `country_id`/`state_id`/`district_id`/`subdistrict_id` (lines 370–570). Typesense schema in `config/scout.php` has been updated to match package address fields, but search filter code still references old field names.
- [x] Configure event submission/approval workflow — DONE. `EventSubmission` extends package model, created in `SubmitFrontendEventAction`, auto-approves for institution-scoped, transitions to pending for public. Tables present: `event_submissions`, `event_submission_logs`, `event_submission_attachments`.
- [x] Configure free registration (no payment required) — DONE. `issue_passes_for_free` column on events table, `auto_issue_passes_for_free` defaults to `true` in `config/events.php`, `registration_mode` column present. No payment integration wired.

#### 8.D Verified Runtime Packet

- [x] Bridge occurrence-backed and metadata-backed event query columns through `EventBuilder`
- [x] Bridge metadata-backed reference query columns through `ReferenceBuilder`
- [x] Normalize public event show engagement/actions to package contracts and owner context
- [x] Rewrite legacy `EventSearchTest`, `EventShowPageTest`, and `VenueIndexTest` fixtures to package addressing/contacting
- [x] Bridge venue metadata-backed fields (`description`, `facilities`, `is_active`) and fix the `Venue::events()` foreign key to `default_venue_id`
- [x] Verify `EventSearchTest`, `EventShowPageTest`, `UnifiedSearchPageTest`, and `VenueIndexTest`

### 8.E — Engagement & Membership Rebuild

Replace engagement behavior and membership models.

- [x] Replace app engagement (Going, Interested, Save, Follow, Share) with package `EngagementManager` and `RegistrationServiceInterface`
- [x] Replace membership record backing: `MembershipApplication` model extends `AIArmada\Membership\Models\MembershipApplication`, uses `membership_applications` table. `App\Models\MembershipClaim` is deleted.
- [x] Replace `MemberInvitation` with package `MembershipInvitation` (`MemberInvitation extends AIArmada\Membership\Models\MembershipInvitation`, hashed tokens)
- [x] Claim approve/reject/cancel actions use package actions (`ApproveMembershipApplicationAction`, `RejectMembershipApplicationAction`, `CancelMembershipApplicationAction`) with `ApplicationStatus` enum
- [ ] Delete "MembershipClaim" naming — PARTIAL. Model is deleted and code uses `MembershipApplication`, but 38 files still carry "MembershipClaim" in filenames/class names/namespaces/URLs. 12 test files import the now-deleted `App\Models\MembershipClaim` (would fatally error). Filament resource directory is `app/Filament/Resources/MembershipClaims/`, Livewire pages in `app/Livewire/Pages/MembershipClaims/`, routes use `/membership-claims`.
- [x] Implement `MembershipHook` for submission locks — DONE. `AppMembershipHook` (`app/Support/Membership/AppMembershipHook.php`) implements the package `MembershipHook` contract, bound as singleton in `AppServiceProvider:138`, wires to `PublicSubmissionLockService` for institution/speaker submission lock management.
- [ ] Implement `MembershipApplicationNotifier` — NOT DONE. Package contract exists (`AIArmada\Membership\Contracts\MembershipApplicationNotifier`) but zero implementations or bindings exist in app code. Package actions check `app()->bound()` and silently skip notifications.
- [x] Register `filament-engagement` plugin — PARTIAL. Registered in `AdminPanelProvider:85`. NOT registered in `AhliPanelProvider`. Package provides 7 resources (Bookmark, Follow, Reaction, Reminder, Response, Subscription, BookmarkCollection) + relation managers + `EngagementOverviewWidget`, all auto-discovered.
- [x] Rebuild engagement-related Livewire/API surfaces — PARTIAL. Surface code uses package `EngagementManager`/`RegistrationServiceInterface` but still depends on `App\Models\Event` shim and Spatie `Tag` model.

### 8.F — References & Moderation Rebuild

Replace references and moderation/report models.

- [ ] Delete `Reference` shim — NOT DONE (and not trivially deletable). `app/Models/Reference.php` (745 lines) is an active subclass extending `AIArmada\References\Models\Reference` with extensive app-specific logic: attribute aliasing (`publication_year↔year`), metadata storage (`is_active`, `part_type`, etc.), Scout search (`toSearchableArray`, `shouldBeSearchable`), custom scopes (`active`, `root`, `part`), slug generation, business logic (`expandRootReferenceIdsForFiltering`, `displayTitle`, `familyRootId`), media conversions, and 100+ files import it. Only 1 file imports the package model directly. Requires wholesale refactor to delete.
- [ ] Keep app Filament resources (no `filament-references` package) — Still needed
- [ ] Replace moderation models with package Block/ModerationAction — PARTIAL. `ModerationReview` extends `AIArmada\Moderation\Models\ModerationAction`. `Block` from the package is entirely unused (zero occurrences in app code). No blocking functionality exists.
- [x] Keep app-owned Reports (no feedback package) — Still present: `app/Models/Report.php` extends `AIArmada\CommerceSupport\Models\Report`
- [ ] Wire reports into event submission approval flow — NOT DONE. `ApproveEvent` transition has no Report import or logic. No code checks for unresolved reports before approving, auto-resolves reports on approval, or links report resolution to moderation workflow.

### 8.G — Communications Rebuild

Replace notification engine with communications package.

- [ ] Delete `PendingNotification` model + migration — NOT DONE. `app/Models/PendingNotification.php` exists (maps to `notification_messages` table). Migration `2026_02_09_190614` still present. 23 callers across `NotificationEngine` and `NotificationCenterMessage`.
- [ ] Delete `NotificationDelivery` model — NOT DONE. `app/Models/NotificationDelivery.php` exists (maps to `notification_deliveries` table). 28 callers across `NotificationDeliveryLogger`, `User`, `NotificationDestination`, and listeners.
- [ ] Delete `2026_07_07_185329` migration (Laravel notifications table) — PARTIAL. Migration file exists, table NOT in database (never migrated/pending), but `Notifiable` trait is still on `User` model.
- [ ] Replace `NotificationEngine`/`NotificationCenterMessage` with communications package pipeline — PARTIAL. Both are still active: `NotificationEngine` (543 lines, 3 callers) is the central dispatch mechanism with a `MIGRATED_TRIGGERS` constant (23 triggers) that partially bypass old `PendingNotification` creation. `NotificationCenterMessage` (264 lines) extends `Illuminate\Notifications\Notification`. Communications package tables (13 tables) exist in DB. Package resolvers are bound. Dual system coexists.
- [ ] Implement `DestinationResolver` — NOT DONE. No app-level implementation exists. Package contract `AIArmada\Communications\Contracts\DestinationResolver` is present but unbound.
- [x] Implement `PreferenceResolver` — DONE. `AppPreferenceResolver` (`app/Support/Communications/AppPreferenceResolver.php`) implements contract, bound in `AppServiceProvider:118`, checks user `NotificationSetting` preferences and per-family notification rules.
- [x] Implement `QuietHoursResolver` — DONE. `AppQuietHoursResolver` (`app/Support/Communications/AppQuietHoursResolver.php`) implements contract, bound in `AppServiceProvider:113`, timezone-aware quiet hours from `NotificationSetting`.
- [x] Additional resolvers implemented: `AppConsentResolver` (bound line 123), `AppSuppressionResolver` (bound line 128)
- [ ] Adopt `HasInbox` trait — NOT DONE. Zero references to `HasInbox` anywhere in app code. `NotificationInbox` model is used directly but not via the trait on `User`.
- [ ] Wire digest scheduling through `CommunicationBatch` — NOT DONE. Zero `CommunicationBatch` usage in app code. No digest jobs in `app/Jobs/`. `NotificationEngine::createDigestMessage()` exists but is never called in a scheduling context. `communication_batches` table exists but unused.
- [x] Register `filament-communications` plugin — PARTIAL. Registered in `AdminPanelProvider:86`. NOT registered in `AhliPanelProvider`.
- [x] Keep app: `NotificationSetting`, `NotificationRule`, `PushChannel`, `WhatsappChannel`, digest jobs

### 8.H — Final Cleanup & Verification

Delete all superseded code, regenerate docs, full verification.

- [ ] Delete superseded app models — NOT DONE. All 9 still exist as active subclasses: `Event` (2585 lines), `Registration` (441 lines), `Reference` (745 lines), `Venue` (217 lines), `EventCheckin` (116 lines), `MemberInvitation` (125 lines), `PendingNotification` (43 lines), `NotificationDelivery` (76 lines), `MembershipApplication` (46 lines). None are dead-weight pass-throughs.
- [ ] Delete superseded app migrations — NOT DONE. `2026_02_09_190614` and `2026_07_07_185329` both exist. The latter is pending (never run).
- [ ] Delete `NotificationEngine`, `NotificationCenterMessage`, and `InboxChannel` — NOT DONE. All 3 exist with active callers. `NotificationEngine` (3 callers), `NotificationCenterMessage` (5 callers), `InboxChannel` (3 callers).
- [ ] Delete superseded traits/enums/services — No clearly superseded items identified. All traits, enums, and services appear actively used.
- [ ] Rename "MembershipClaim" → "MembershipApplication" throughout app code — PARTIAL. Model renamed but 38 files carry old naming. 12 test files import deleted `App\Models\MembershipClaim`.
- [ ] Regenerate API documentation — Command exists: `php artisan scramble:export`
- [ ] Regenerate MCP documentation — Commands exist: `php artisan make:mcp-server`, etc.
- [ ] Fresh migrate + seed — NOT DONE. 1 migration pending: `2026_07_07_185329`
- [ ] Full test suite pass — NOT DONE. 12 test files reference deleted `App\Models\MembershipClaim` (would fatally error).
- [ ] PHPStan pass — Configured: Level 6, `phpstan.neon` + baseline
- [ ] Pint pass — Configured: `pint.json` with `{"preset": "laravel"}`
- [ ] npm build — Configured: `"build": "vite build"`
- [ ] Runtime smoke checks

## Verification

```bash
php artisan migrate:fresh --seed
vendor/bin/pest --parallel --compact
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --dirty --format agent
npm run build
php artisan route:list
```

Runtime smoke checks:

- Filament boots.
- Public event discovery, detail, registration/check-in, and membership flows work.
- Public discovery works globally by default without selecting or switching a country.
- Notification inbox and delivery flows work.
- Signals events record expected outcomes.
- MCP admin/member tools work against package-backed models.
- Media uploads and conversions work.

## Exit Criteria

- Fresh app passes all verification.
- No deleted legacy surface remains referenced by routes, docs, tests, or providers.
- `review-log.md` contains proof for the final cutover.
- `status.md` marks phases 0-8 `Verified`, `Deferred`, or `Removed` with no ambiguous work left.

## Stop And Re-plan Triggers

- Any public API/MCP generated documentation points at removed legacy shapes.
- A package-backed model cannot support a critical public workflow through generic seams.
- Full fresh migrate/seed cannot complete deterministically.
- Migration conflicts require manual schema surgery beyond simple table replacement.
