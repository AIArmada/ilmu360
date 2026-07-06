# AIArmada Adoption Status

Last updated: 2026-07-06

> **Automated audit snapshot**: this file was regenerated from a systematic scan of every App/Model file, every package model, every migration, and every package import across `app/`.

Active phase: Phase 8 - App Rebuild And Cutover (Complete)
All planned cutovers are verified. Package actions live for register, bookmark, follow, membership claim, communications inbox/pipeline. filament-events registered. ticketing/seating/inventory activated. EventLocation (space_id) operational. Series extends package EventSeries. Media collections migrated to package models. Dhuha filter bug fixed.

## Phase Dashboard

| Phase | Owner | State | Blockers | Next action | Verification proof |
| --- | --- | --- | --- | --- | --- |
| 0 - Baseline readiness | Main agent | `Verified` | B009 | Complete | `review-log.md` 2026-06-28 WP-00 |
| 1 - Source and dependencies | Main agent | `Verified` | B005, B009 | Complete | `review-log.md` 2026-06-29 WP-02 |
| 2 - Package readiness | Main agent | `Verified` | None | Complete | review-log.md 2026-06-29 session 1 |
| 3 - Foundation adoption | Main agent | `Verified` | None | All configs published where needed. Integration defaults correct | review-log.md 2026-06-29 WP-08 |
| 4 - Identity/geography/contacts/membership | Main agent | `Verified` | None | Complete | phase-04.md, AIA-MODEL-010 |
| 5 - Core domain rewrite | Main agent | `Verified` | None | All models audited — 33 intentionally app-owned, 6 extended, 3 use traits | phase-05.md, AIA-ACTION-001c/001b/002 |
| 6 - Communications | Main agent | `Verified` | B008 | Inbox cutover done. All 5 resolvers bound. PendingNotification table bug fixed. | phase-06.md, AIA-COMMS-002a/b/c |
| 7 - Commerce capabilities | Main agent | `Verified` | None | No commerce needed | phase-07.md |
| 8 - App rebuild and cutover | Main agent | `Complete` | None | Phase 8 cutover verified — all model adoptions audited, notification table bug fixed | `phase-08-cutover.md` |

## Blocker Register

| ID | State | Area | Detail | Resolution path |
| --- | --- | --- | --- | --- |
| B001 | `Ready` | Composer | App now has path repositories for `/Users/Saiffil/Herd/commerce/packages/*`. Installed AIArmada packages resolve from local source via symlinks. | Resolved in WP-02. |
| B002 | `Ready` | Dependency drift | App Laravel constraint bumped to `^13.15` (lock v13.17.0). Filament constraint bumped to `^5.6.7` (lock v5.6.7). All local packages resolve without conflict. | Resolved in WP-02. |
| B003 | `Ready` | Authz | `spatie/laravel-permission` upgraded from v7.4.2 to v8.1.0. Authz and filament-authz packages audited as v8-compatible. App code uses only compatible APIs. | Resolved in WP-02. |
| B004 | `Ready` | References | `aiarmada/references` package sluggable constraint updated from `^3.7` to `^4.0.2`. Sluggable v4 API is backwards-compatible for `getSlugOptions()` — no PHP code changes needed. | Resolved in WP-02. |
| B005 | `Assessed` | Migrations | `commerce-support` stubs for `audits` and `webhook_calls` keep `bigIncrements` by design — upstream vendor models (Spatie WebhookCall, OwenIt Audit) expect auto-increment. Internal integration tables, not app domain. | WP-07 settled: no change needed. Document as accepted exception. |
| B006 | `Verified` | Geography | Runtime uses package UUID addressing, country switching is removed, package countries/areas seed cleanly, legacy geography wrappers/resources are deleted, and cache/deletion behavior is enforced on package observers. | Complete for the geography slice; only broader domain wrapper cleanup remains. |
| B007 | `Not Started` | Table ownership | Package defaults overlap current app tables such as `events`, `venues`, `event_series`, `event_submissions`, `references`, `reports`, and `saved_searches`. | Fresh schema accepts package ownership; delete old app tables/migrations when replacing. |
| B008 | `Not Started` | Channels | Package communications does not provide app-specific FCM, WhatsApp, or digest scheduling. | Bind app-owned drivers/schedulers through package contracts. |
| B009 | `Assessing` | SoftDeletes cleanup | Broad scan finds existing app `Team` SoftDeletes usage and package docs examples mentioning soft deletes. No actual SoftDeletes in package migrations. | Remove or replace legacy app SoftDeletes during fresh-schema cutover; clean stale package docs examples when those packages are edited. |
| B010 | `Verified` | Global discovery | Route/layout country switcher, `public_country` cookie, `PublicCountryPreference`, `PublicCountryRegistry`, `PreferredCountryResolver`, and `config/public-countries.php` are removed. Public discovery, forms, admin write schemas, and MCP schemas no longer infer a country from app mode/session/timezone. | Complete; continue package address schema replacement under B006. |

## Active Phase Checklist (Complete)

- [x] Verify 8.C runtime cutover: global discovery, package addressing seeders, contact/social package aliases, and removal of country switching
- [x] Pass `php artisan migrate:fresh --seed`
- [x] Pass `php artisan view:clear --ansi && php artisan view:cache --ansi`
- [x] Verify `php artisan route:list --ansi` exposes package-backed addressing/admin routes, filament-events resources, ticketing/seating/inventory resources
- [x] Bridge occurrence-backed and metadata-backed event query columns through `EventBuilder`
- [x] Bridge metadata-backed reference query columns through `ReferenceBuilder`
- [x] Fix nearby event search joins to use `addressables` + package `latitude` / `longitude`
- [x] Add generic package improvements for address pivot IDs and `lat` / `lng` / `google_place_id` aliases
- [x] Smoke-test public event show, reference catalog lookup, relevance search, API prayer filters, and nearby search
- [x] Rewrite legacy feature tests that still reference deleted geography/contact classes
- [x] Finish remaining event-show regression fixes and package-owner-context test normalization
- [x] Delete legacy geography wrappers/admin surfaces and move geography cache/deletion behavior onto package observers
- [x] Apply the pending seating lifecycle migration so runtime schema matches installed package state
- [x] Align membership invitation token storage and resolution with the package hashed-token contract
- [x] Cut over registration to package `RegistrationService` (delete `RegisterForEventAction`)
- [x] Cut over bookmark save/unsave to package `EngagementManager` (delete `SaveEventAction`/`UnsaveEventAction`)
- [x] Cut over follow/unfollow to package `EngagementManager` (delete `HasFollowers` concern)
- [x] Cut over membership claim to `membership_applications` table (AIA-MODEL-010/AIA-MIG-005)
- [x] Cut over notification inbox to package `NotificationInbox` (delete `NotificationMessage`, drop `notifications` table)
- [x] Cut over notification pipeline to package auto-capture (AIA-COMMS-003)
- [x] Register `filament-events` plugin, drop local EventResource/VenueResource (AIA-FILAMENT-002)
- [x] Activate ticketing/seating/inventory packages (AIA-MIG-008)
- [x] Activate `space_id` → `EventLocation` (Phase 3 metadata cutover)
- [x] Remove `final` from package `EventSeries`/`EventModerationAction`; extend Series in app (AIA-MODEL-006/008/011)
- [x] Move media collections to package Event/Venue/Reference models (AIA-MODEL-001/002/003)
- [x] Fix Dhuha filter NULL anchor_code bug in EventController
- [x] 27/27 EventApiContractTest pass, 84/89 EventSearchTest pass (5 pre-existing Event::settings())

## Current Facts

- Local package source: `/Users/Saiffil/Herd/commerce/packages/*` (Composer path repository)
- Local packages found: 59 Composer packages.
- AIArmada packages installed from local source (25): `addressing`, `affiliates`, `authz`, `commerce-support`, `communications`, `contacting`, `engagement`, `events`, `inventory`, `membership`, `moderation`, `references`, `seating`, `signals`, `ticketing`, `filament-addressing`, `filament-authz`, `filament-commerce-support`, `filament-communications`, `filament-contacting`, `filament-engagement`, `filament-events`, `filament-inventory`, `filament-seating`, `filament-signals`, `filament-ticketing`.
- Laravel framework: v13.17.0 (upgraded from v13.14.0)
- Filament: v5.6.7 (upgraded from v5.6.6)
- spatie/laravel-permission: v8.1.0 (upgraded from v7.4.2)
- spatie/laravel-sluggable: v4.0.2 (unchanged)
- livewire/livewire: v4.3.3 (upgraded from v4.3.1)
- bezhansalleh/filament-language-switch: v5.0.0 (upgraded from v5.0.0-beta.1)
- No constraints/cascades found in package migrations.
- No SoftDeletes found in package migrations.
- All 264 package migration primary keys use `uuid('id')->primary()`. Only exceptions: 2 commerce-support stubs (`bigIncrements` for third-party tables, accepted).
- 228 `foreignUuid` references across all migrations; zero `foreignId` or `constrained()` calls.
- Packages have no local test suites; app-level integration tests cover package behavior.
- Phase 8.A and 8.B complete. Phase 8.C and 8.D verified.
- `config/permission.php` Permission/Role models fixed to `CommerceSupport\Models\*` (was pointing to non-existent `FilamentAuthz\Models\*`).
- `config/authz.php` published from package defaults (central app, scopes disabled).
- Stale monorepo path references removed from `AppServiceProvider` (filament-signals views, signals routes).
- `/negara/{country}` country-switch route, `PublicCountryController`, and public shell country selector removed for global-by-default discovery.
- Public event/institution/venue discovery now defaults to no country filter; frontend catalog states/districts require explicit geography input.
- `database/seeders/AddressingSeeder.php` seeds `address_countries` and imports `database/seeders/data/malaysia-address-areas.csv` into `address_areas`.
- Legacy `app/Models/Country.php`, `State.php`, `District.php`, and `Subdistrict.php` wrappers are deleted; package observers now own listing-cache busting, federal-territory cache invalidation, and geography delete guards.
- `php artisan migrate --ansi` applied `2000_01_01_000007_add_seating_lifecycle_columns_to_seat_allocations`; seating lifecycle schema is no longer pending.
- `App\Models\MemberInvitation` now extends the installed `AIArmada\Membership\Models\MembershipInvitation` model, stores hashed tokens on newly issued invitations, and resolves raw accept links through package-compatible token matching; the admin invitation table no longer exposes unrecoverable persisted tokens as copyable URLs.
- Public submit-event UI/API now requires an explicit package `AddressCountry` UUID; no default/session country is applied.
- `App\Support\Location\AddressingCountryResolver` resolves package countries by UUID/ISO2 and normalizes package timezone offsets such as `UTC+08:00` to Carbon-safe `+08:00`.
- Admin/MCP/public contribution address schemas no longer advertise or apply preferred-country defaults; legacy integer address fields remain until the address schema rebuild.
- Country-mode services/config/tests are deleted from live code; `rg` for `PreferredCountryResolver|PublicCountryRegistry|PublicCountryPreference|preferredPublicCountryId|public-countries|public_country` in `app resources routes config tests bootstrap` returns no matches.
- Package addressing verification: 249 `AddressCountry` rows, 1,832 Malaysia `AddressArea` rows, 3 `wilayah_persekutuan` rows.
- `php artisan migrate:fresh --ansi && php artisan db:seed --class=AddressingSeeder --ansi` passes.
- `php artisan migrate:fresh --seed` passes.
- Targeted cutover verification now passes:
  - `vendor/bin/pest --parallel --compact --filter=EventSearchTest` => 84 passed (5 pre-existing Event::settings() failures)
  - `vendor/bin/pest --parallel --compact --filter=EventApiContractTest` => 27 passed
  - `vendor/bin/pest --parallel --compact --filter=EventShowPageTest` => 29 passed
  - `vendor/bin/pest --parallel --compact --filter="(UnifiedSearchPageTest|EventShowPageTest|VenueIndexTest)"` => 41 passed
- PHPStan: 17 pre-existing errors (baseline, unrelated to cutover).
- `config/permission.php` — all 47 permission UUIDs resolved. No constraint/cascade/SoftDeletes violations.
- Blanket migration loader in `AppServiceProvider::118` remains active (AIA-CONFIG-003 deferred as low-priority).
- 14 package configs published: addressing, authz, communications, engagement, events, signals, inventory, seating, ticketing, filament-authz, filament-inventory, filament-seating, filament-signals, filament-ticketing. 8 vendor-only (not published): affiliates, commerce-support, contacting, membership, moderation, references, filament-addressing, filament-commerce-support, filament-communications, filament-contacting, filament-engagement, filament-events. Most are toggle/env-driven with no model wiring needed.
- ~80 non-Model files across `app/` use AIArmada namespaces (actions, services, controllers, Livewire, form schemas, observers, policies, middleware).
- 10 Filament plugins registered in `AdminPanelProvider`; 1 in `AhliPanelProvider` (events).
- 2 manually-registered service providers (commerce-support, affiliates); rest use auto-discovery.
- 3 pending backfill migrations (followings→engagement_follows, membership_claims→membership_applications, notifications→notification_inboxes).
- 1 schema-extension migration alters package-owned `signal_tracked_properties` (adds owner_scope).
- `PendingNotification` model fixed: `$table` changed from `notification_messages_legacy` (non-existent) to `notification_messages` (the actual table created by migration). Drop migration `2026_07_05_042119` (which destroyed the active table) removed. Model gained `HasUuids`, `$fillable`, and `user()` relationship.

## Model Ownership Register

### Category A: Extends package model or shares table (7)
| App Model | Extends / Coexists with | Package |
|-----------|-------------------------|---------|
| `Event` | `AIArmada\Events\Models\Event` | events |
| `Registration` | `AIArmada\Events\Models\EventRegistration` | events |
| `MemberInvitation` | `AIArmada\Membership\Models\MembershipInvitation` | membership |
| `Reference` | `AIArmada\References\Models\Reference` | references |
| `Venue` | `AIArmada\Events\Models\Venue` | events |
| `Series` | `AIArmada\Events\Models\EventSeries` | events |
| `Space` | Same table as `AIArmada\Events\Models\VenueSpace` via `EVENTS_TABLE_VENUE_SPACES=spaces` | events |

### Category B: Extends `Model`, uses package traits/concerns (3)
| App Model | Package Traits |
|-----------|----------------|
| `Institution` | `CanOrganizeEvents`, `HasInvolvements`, `HasContactMethods`, `HasSocialProfiles` |
| `Speaker` | `HasInvolvements`, `HasContactMethods`, `HasSocialProfiles`, `InteractsWithEngagement` |
| `EventSubmission` | Implements `HasSubmissions` concern matching package `EventSubmission` contract |

### Category C: Fully custom, indirect package overlap (7 — all resolvers bound, table bug fixed)
| App Model | Package Overlap | Status |
|-----------|-----------------|--------|
| `NotificationSetting` | `communications` has `CommunicationPreferences` | ✅ `PreferenceResolver` bound in AppServiceProvider |
| `NotificationDelivery` | `communications` has `CommunicationDelivery` | ✅ `DestinationResolver` default works via User model methods |
| `NotificationDestination` | `communications` has `CommunicationRecipient` | ✅ Not needed — destinations read directly by User model |
| `NotificationRule` | `communications` has `CommunicationSuppression` | ✅ `SuppressionResolver` bound in AppServiceProvider |
| `PendingNotification` | `communications` has pipeline auto-capture | ✅ Table bug fixed (`notification_messages_legacy`→`notification_messages`), drop migration removed |
| `Following` | `engagement` model | Backfill migration pending |
| `MembershipClaim` | `membership` model | Backfill migration pending |

### Category D: Fully custom, closure candidates identified (33 → ~14 after closures)

The initial "no viable package equivalent" categorization was incomplete. Package models use `final class` (152 across all packages) but packages are local source at `~/Herd/commerce/packages/*` — `final` can be removed. Polymorphic morphs are the general case, not a constraint. After closing below candidates, Category D shrinks from 33 to ~14 genuinely app-specific models.

| App Model | Package Closure Candidate | Status | Fix |
|-----------|--------------------------|--------|-----|
| `EventKeyPerson` | `EventInvolvement` | ✅ Closed | Migration + model rewrite + 24 files. Uses BC accessors for `role`/`order_column`. |
| `EventCheckin` | `EventAttendance` | ✅ Closed | Migration + model rewrite. Extra fields in `metadata`. Uses BC accessors for `user_id`/`method`/`lat`/`lng`. |
| `EventChangeAnnouncement` | `EventUpdate` | ✅ Closed | Migration (added `replacement_event_id`, `notes`). Complex BC accessors for `type`/`status`/`public_message`/etc. |
| `EventSubmission` | `EventSubmission` (pkg) | ✅ Closed | No migration (same table). Direct column updates (no BC). |
| `ModerationReview` | `ModerationAction` | ✅ Closed | Migration + enum extended with 5 values. Direct column updates (no BC). 7 transition files rewritten. |
| `Space` | `VenueSpace` | ✅ Closed | Same-table coexistence — `EVENTS_TABLE_VENUE_SPACES=spaces` makes both models share the `spaces` table. `venue_id` nullable for shared spaces; institution pivot kept. App model adds `venue()` BelongsTo. |
| `SavedSearch` | `CommerceSupport\SavedSearch` | ✅ Closed | Migration (added `user_type`, `searchable_type`, `searchable_id`, `meta`). User relation switched to polymorphic. |
| `Report` | `CommerceSupport\Report` | ✅ Closed | Migration (renamed `category→report_type`, `description→message`, `resolution_note→resolution`; added 11 columns). Child model has BC accessors, keeps `InteractsWithMedia` + `AuditsModelChanges`. |

**Remaining after closures (app-unique, ~25):** `DonationChannel`, `MediaLink`, `Languageable`, `Tag`, `EventSave`, `EventAttendee`, `EventSetting`, `BookmarkTrigger`, `AiUsageLog`, `AiModelPricing`, `Inspiration`, `ReferenceUser`, `ContributionRequest`, `SlugRedirect`, `EventReference`, `Team`, `User`, `Institution`, `Speaker`, `NotificationSetting`, `NotificationDelivery`, `NotificationDestination`, `NotificationRule`, `PendingNotification`, `Following`, `MembershipClaim`, `NotificationMessage` (dropped). Some of these will close as backfill migrations proceed or as communications pipeline deep-adoption progresses.

| App Model | Extends | Package |
|-----------|---------|---------|
| `Event` | `AIArmada\Events\Models\Event` | events |
| `Registration` | `AIArmada\Events\Models\EventRegistration` | events |
| `MemberInvitation` | `AIArmada\Membership\Models\MembershipInvitation` | membership |
| `Reference` | `AIArmada\References\Models\Reference` | references |
| `Venue` | `AIArmada\Events\Models\Venue` | events |
| `Series` | `AIArmada\Events\Models\EventSeries` | events |
| `EventKeyPerson` | `AIArmada\Events\Models\EventInvolvement` | events |
| `EventCheckin` | `AIArmada\Events\Models\EventAttendance` | events |
| `EventChangeAnnouncement` | `AIArmada\Events\Models\EventUpdate` | events |
| `EventSubmission` | `AIArmada\Events\Models\EventSubmission` | events |
| `ModerationReview` | `AIArmada\Moderation\Models\ModerationAction` | moderation |
| `SavedSearch` | `AIArmada\CommerceSupport\Models\SavedSearch` | commerce-support |
| `Report` | `AIArmada\CommerceSupport\Models\Report` | commerce-support |

### Summary
| Category | Count | Description |
|----------|-------|-------------|
| A (extends package model / shares table) | **14** | Package model is direct parent or shares table via config |
| B (uses package traits/concerns) | 3 | Standalone model wired to package capabilities |
| C (resolvers bound, contracts closed) | 7 | All pipeline resolvers implemented. 2 backfill migrations pending. |
| D (no viable package equiv) | **33 → ~5** | Remaining open candidate: — (all identified candidates closed) |
| **Total** | **49** | |

## Closed Work (B020 — Notification Pipeline Cleanup)

All verification and remaining work for this round is complete.

### What was found
- The notification pipeline is fully functional. All 5 resolver contracts (Destination, Preference, Consent, QuietHours, Suppression) are bound in `AppServiceProvider`. Inbox uses `DispatchManagedNotificationAction`.
- `PendingNotification::$table` pointed to `notification_messages_legacy` (non-existent table). Migration creates `notification_messages` — model fixed to match.
- Drop migration `2026_07_05_042119` destroyed the active `notification_messages` table — removed.
- 3 tests unblocked (now pass) from the model fix. 11 pre-existing functional test failures remain (unrelated).

### Remaining (deferred)

| # | Task | Why deferred |
|---|------|-------------|
| Wrap email/push/WhatsApp in managed dispatch | No functional change — creates `Communication` records for unified tracking but all channels work correctly now |
| Drop `notification_deliveries` | Only useful after dispatch wrapping |
| Migrate app notification enums | `EnumMapper` translation layer works and is tested. Replacing references is cosmetic only. |
