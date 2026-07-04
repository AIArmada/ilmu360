# Phase 8 - App Rebuild And Cutover

State: `In Progress`

## Objective

Rebuild app surfaces on package-owned domains, delete superseded app code, regenerate docs, and prove the fresh-schema application works end to end.

## Execution Strategy

Phase 8 is broken into ordered sub-phases. Each sub-phase is a bounded unit of work that can be verified independently. Domains are rebuilt in dependency order: foundation first, then core domain, then support domains.

## Current Checkpoint (2026-07-04)

- 8.C runtime cutover is verified: country switching is removed, package addressing data seeds cleanly, public/admin/API/MCP address contracts no longer infer country from session or cookie state, and package contact/address aliases are live in runtime paths.
- 8.D runtime/test hardening is verified: event occurrence/metadata query bridges are in place, nearby search joins package `addressables`, public event engagement uses package actions/contracts, venue runtime bridges now map metadata-backed fields plus `default_venue_id`, and the legacy event/venue regression slices now run against package addressing/contacting.
- The package-native geography cleanup packet is now verified: legacy `Country` / `State` / `District` / `Subdistrict` wrappers and dead geography admin surfaces are deleted, package observers own geography cache/deletion behavior, and the pending seating lifecycle migration is applied.
- Membership invitation handling is now closer to package-native behavior: the app wrapper sits on the installed package model, new invitations store hashed tokens, raw accept links still resolve correctly, and admin no longer exposes persisted invitation tokens as reusable URLs.
- Remaining work is no longer basic runtime compatibility. The next packet is structural: delete or replace the remaining app-owned event/venue/reference/membership/communications surfaces and widen package-first API/MCP/directory verification ahead of the final 8.H gate.

## Package Installation State

Package installation is complete. The app now depends directly on **22 local AIArmada packages** through the Composer path repository in `composer.json`.

### Installed directly in the app

- `addressing`
- `affiliates`
- `authz`
- `commerce-support`
- `communications`
- `contacting`
- `engagement`
- `events`
- `filament-addressing`
- `filament-authz`
- `filament-communications`
- `filament-contacting`
- `filament-engagement`
- `filament-events`
- `filament-signals`
- `inventory`
- `membership`
- `moderation`
- `references`
- `seating`
- `signals`
- `ticketing`

### What remains in Phase 8

Installation is no longer the task. Remaining work is app cutover:

- delete compatibility wrappers for package-owned domains
- rebuild remaining public/API/MCP/admin surfaces package-first
- finish communications, membership, and taxonomy cleanup
- clear final verification and PHPStan debt

## Sub-Phases

### 8.A — Package Installation (non-destructive)

Install all 15 packages via composer. Publish configs. Verify autoload + optimize.

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

- [ ] Remove app geography migrations (countries, states, cities, districts, subdistricts)
- [x] Remove app geography models + traits + enums
- [x] Remove country switching public route/controller and desktop/mobile shell selector
- [x] Remove implicit preferred-country defaults from public discovery and frontend catalog dependent endpoints
- [x] Move submit-event UI/API country contract from preferred-country integer IDs to package addressing UUIDs
- [x] Remove admin/MCP preferred-country defaults from write schemas
- [x] Remove country switching preferences/resolvers/config after submit/form/admin/MCP defaults move off country mode
- [x] Seed addressing package countries + Malaysia areas
- [x] Replace `Contact` with `ContactMethod`, `SocialMedia` with `SocialProfile`
- [x] Replace `HasContacts`/`HasSocialMedia` traits
- [x] Register package-first geography ownership in admin mutation/runtime flows
- [x] Rebuild address form schemas
- [x] Rebuild API catalog endpoints
- [ ] Update Scout searchable arrays

### 8.D — Events Domain Rebuild (largest)

Replace the entire event system with the events package (71 models, 70 migrations).

- [ ] Remove app event migrations (events, event_settings, registrations, event_checkins, etc.)
- [ ] Remove app event models (Event, EventSettings, Registration, EventCheckin)
- [ ] Configure events package (config/events.php — 103 table names)
- [ ] Extend package Event with media collections (cover, poster, gallery)
- [ ] Map Spatie Tags → package taxonomies (EventTaxonomy/EventTerm)
- [ ] Rebuild Filament event resources (or use filament-events)
- [ ] Rebuild public event pages (Livewire)
- [ ] Rebuild API event endpoints
- [ ] Rebuild MCP event tools
- [ ] Rebuild event search indexing
- [ ] Configure event submission/approval workflow
- [ ] Configure free registration (no payment required)

#### 8.D Verified Runtime Packet

- [x] Bridge occurrence-backed and metadata-backed event query columns through `EventBuilder`
- [x] Bridge metadata-backed reference query columns through `ReferenceBuilder`
- [x] Normalize public event show engagement/actions to package contracts and owner context
- [x] Rewrite legacy `EventSearchTest`, `EventShowPageTest`, and `VenueIndexTest` fixtures to package addressing/contacting
- [x] Bridge venue metadata-backed fields (`description`, `facilities`, `is_active`) and fix the `Venue::events()` foreign key to `default_venue_id`
- [x] Verify `EventSearchTest`, `EventShowPageTest`, `UnifiedSearchPageTest`, and `VenueIndexTest`

### 8.E — Engagement & Membership Rebuild

Replace engagement behavior and membership models.

- [ ] Replace app engagement (Going, Interested, Save, Follow, Share) with engagement traits
- [ ] Replace `MembershipClaim` with `MembershipApplication`
- [ ] Replace `MemberInvitation` with `MembershipInvitation`
- [x] Move `MemberInvitation` onto the installed package model and align token storage/resolution with the package hashed-token contract while preserving current app-specific role semantics
- [ ] Implement `MembershipHook` for submission locks
- [ ] Implement `MembershipApplicationNotifier`
- [ ] Register filament-engagement resources
- [ ] Rebuild engagement-related Livewire/API surfaces

### 8.F — References & Moderation Rebuild

Replace references and moderation/report models.

- [ ] Replace `Reference` model with package Reference
- [ ] Keep app Filament resources (no filament-references package)
- [ ] Replace moderation models with package Block/ModerationAction
- [ ] Keep app-owned Reports (no feedback package)
- [ ] Wire reports into event submission approval flow

### 8.G — Communications Rebuild

Replace notification engine with communications package.

- [ ] Remove app notification models (PendingNotification, NotificationMessage, NotificationDelivery)
- [ ] Implement DestinationResolver for FCM/WhatsApp
- [ ] Implement PreferenceResolver wrapping NotificationSetting
- [ ] Implement QuietHoursResolver
- [ ] Adopt HasInbox trait
- [ ] Wire digest scheduling through CommunicationBatch
- [ ] Register filament-communications plugin
- [ ] Keep app: NotificationSetting, NotificationRule, PushChannel, WhatsappChannel, digest jobs

### 8.H — Final Cleanup & Verification

Delete all superseded code, regenerate docs, full verification.

- [ ] Delete all superseded app models/actions/migrations/tests
- [ ] Delete superseded traits/enums/services
- [ ] Regenerate API documentation
- [ ] Regenerate MCP documentation
- [ ] Fresh migrate + seed
- [ ] Full test suite pass
- [ ] PHPStan pass
- [ ] Pint pass
- [ ] npm build
- [ ] Runtime smoke checks (Filament boots, public discovery, registration, notifications, MCP)

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
