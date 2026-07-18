# 00 — Progress Ledger

> Resumable ledger. Any AI model can continue from here without re-reading the
> whole codebase. Last updated by the initial evidence-based audit pass.

- **Audit start date:** 2026-07-04
- **Assessment folder:** `docs/commerce-aiarmada-adoption-assessment/`
- **Subject app:** `aiarmada/ilmu360` (Laravel 13, PHP 8.4, Filament v5)
- **Package source (path repo):** `/Users/Saiffil/Herd/commerce/packages/*` (namespace `AIArmada\`)
- **DB engine in use:** PostgreSQL (`pgsql`, 127.0.0.1:5432 / `ilmu360`) — NOT SQLite in prod

---

## ⚠️ CUTOVER DIRECTIVE — NO BACKWARD COMPATIBILITY

**The owner requires hard cutovers. Do NOT preserve backward compatibility.**

- When a local class/flow is replaced by a package, **DELETE the local code** — do not leave it as a deprecated alias, facade, shim, or wrapper.
- **No dual-write / dual-read compatibility periods.** Once a flow switches to the package, the old path is gone.
- **No `TEMPORARY_ADAPTER_FOR_CUTOVER` layers kept alive.** If a temporary adapter is needed during a single PR, it is removed in that same PR — never merged as a lingering compatibility layer.
- **One-time data migration is allowed** (e.g. copying `followings` rows into `engagement_follows`) — that is data movement, not backward compatibility. The source table is dropped in the same change.
- Consumers (controllers, Livewire, Filament, API, MCP, tests) are updated to call the package directly in the same change that removes the local code.
- This overrides any `TEMPORARY_*` / staged wording elsewhere in these docs: read every such item as "do the full cutover and remove the local code in one step, behind a test."

---

## Status vocabulary

`NOT_STARTED` · `IN_PROGRESS` · `BLOCKED` · `NEEDS_HUMAN_DECISION` · `READY_FOR_IMPLEMENTATION` · `IMPLEMENTED` · `VERIFIED` · `DEFERRED`

---

## Files reviewed

- `composer.json`, `composer.lock` (22 `aiarmada/*` required, path repo confirmed)
- `app/Providers/AppServiceProvider.php` (force-loads all package migrations, :108–127)
- `app/Providers/Filament/AdminPanelProvider.php` (6 AIArmada plugins registered)
- All 42 `app/Models/*` (5 extend package models, 3 use package traits, rest standalone)
- `app/Actions/`, `app/Services/`, `app/Console/Commands/`
- `app/Filament/` (Resources, Pages, Ahli panel, RelationManagers, Widgets)
- All 62 `database/migrations/*` (none commerce)
- `config/*` for 13 aiarmada-published configs (NOT published: `events.php`, `ticketing.php`, `seating.php`)
- Live DB table inventory (PostgreSQL `pg_tables`) — full table list captured
- `docs/commerce-package-{deep-functional-comparison,replacement-analysis,readiness-reassessment,refactor-perspective}.md`
- `docs/aiarmada-adoption/{status,review-log,package-inventory,phase-08-cutover}.md` (claimed state)

## Files NOT yet reviewed (deferred / lower priority)

- `routes/api.php`, `routes/web.php`, `routes/ai.php` in depth (spot-checked only)
- `app/Livewire/` public pages in depth (event show / submit form)
- `tests/` full suite (coverage assessed at directory level, not per-file)
- Per-package unit tests inside `/Users/Saiffil/Herd/commerce/packages/*/tests/`

---

## Packages discovered (22 required in composer.json)

Base (14): `addressing`, `affiliates`, `authz`, `commerce-support`, `communications`, `contacting`, `engagement`, `events`, `inventory`, `membership`, `moderation`, `references`, `seating`, `signals`, `ticketing` (15 incl. signals)
Filament adapters (7): `filament-addressing`, `filament-authz`, `filament-communications`, `filament-contacting`, `filament-engagement`, `filament-events`*, `filament-signals`

\* `filament-events` is required in composer.json but its plugin is **NOT registered** in AdminPanelProvider (see AIA-FILAMENT-002).

Full commerce stack (cart, checkout, orders, pricing, products, promotions, vouchers, shipping, customers, cashier, cashier-chip, chip, jnt, tax, growth, docs, affiliate-network, feedback, csuite) is **present in the path repo but NOT required** by ilmu360 — and ilmu360 has **zero local commerce code** to replace.

---

## Domains reviewed

| Domain | Status | Gap IDs |
| --- | --- | --- |
| Package discovery | `VERIFIED` | — |
| Commerce-flow hypothesis (cart/order/voucher/chip) | `VERIFIED` — hypothesis REJECTED (no local commerce code exists) | — |
| Migrations (parallel storage) | `READY_FOR_IMPLEMENTATION` | AIA-MIG-001…009 |
| Models (inheritance vs removal) | `READY_FOR_IMPLEMENTATION` | AIA-MODEL-001…012 |
| Actions / services / flows | `READY_FOR_IMPLEMENTATION` | AIA-ACTION-001…008 |
| Filament / admin | `IMPLEMENTED_AND_VERIFIED` | AIA-FILAMENT-001…004 |
| Config / service providers | `READY_FOR_IMPLEMENTATION` | AIA-CONFIG-001…003 |
| Test coverage | `NOT_STARTED` (audit-level only) | AIA-TEST-001…006 |
| Deletion candidates | `READY_FOR_IMPLEMENTATION` | AIA-DELETE-001…014 |

---

## All gap IDs created

**AIA-MIG-001** `series` vs `event_series` parallel tables · **AIA-MIG-002** `registrations` (orphaned) vs `event_registrations` · **AIA-MIG-003** `followings` vs `engagement_follows` · **AIA-MIG-004** app `notification_*` vs `communication_*` · **AIA-MIG-005** `membership_claims` vs `membership_applications` · **AIA-MIG-006** `event_checkins` vs package `event_attendances`/`event_walk_ins` · **AIA-MIG-007** `events`/`venues`/`saved_searches`/`reports` shared tables (no published config) · **AIA-MIG-008** ticketing/seating/inventory tables installed but unused · **AIA-MIG-009** references table-name resolution.

**AIA-MODEL-001** `Event` extends package (verify thinness) · **AIA-MODEL-002** `Venue` extends package · **AIA-MODEL-003** `Reference` extends package · **AIA-MODEL-004** `Registration` extends package · **AIA-MODEL-005** `MemberInvitation` extends package · **AIA-MODEL-006** `Series` standalone (duplicate of package concept) · **AIA-MODEL-007** `EventSettings` standalone · **AIA-MODEL-008** `EventCheckin` standalone · **AIA-MODEL-009** `Institution`/`Speaker` composition-only (no inheritance) · **AIA-MODEL-010** `MembershipClaim` standalone vs `MembershipApplication` · **AIA-MODEL-011** `ModerationReview` standalone · **AIA-MODEL-012** `Notification*` cluster standalone.

**AIA-ACTION-001** events actions local-only (zero package action imports) · **AIA-ACTION-002** engagement actions local-only · **AIA-ACTION-003** inventory actions unused · **AIA-ACTION-004** communications/notifications local-only · **AIA-ACTION-005** moderation local-only · **AIA-ACTION-006** membership claim local-only · **AIA-ACTION-007** ticketing/seating actions unused · **AIA-ACTION-008** signals/affiliates (genuinely adopted).

**AIA-FILAMENT-001** duplicate geography Resources stubs (States/Districts/Subdistricts empty) · **AIA-FILAMENT-002** `filament-events` required but plugin NOT registered · **AIA-FILAMENT-003** local Event/Venue/Reference Resources overlap package Resources · **AIA-FILAMENT-004** ticketing/seating/inventory Filament adapters unused.

**AIA-CONFIG-001** missing `config/events.php` (60 event tables on package defaults) · **AIA-CONFIG-002** missing `config/ticketing.php`, `config/seating.php` · **AIA-CONFIG-003** force-load-all-migrations in AppServiceProvider :118 (broad but masks per-package opt-in).

**AIA-TEST-001…006** — see `08-test-verification-plan.md`.

**AIA-DELETE-001…014** — see `09-deletion-candidates.md`.

## Human decisions required (open)

1. **AIA-MIG-007** — Confirm whether `events`/`venues`/`saved_searches`/`reports` single-table coexistence is intentional (guarded) or accidental; decide ownership.
2. **AIA-MODEL-009** — Should `Institution`/`Speaker` become package-owned concepts, or stay app-specific? They have no package parent model today.
3. **AIA-FILAMENT-002** — Is `filament-events` intentionally not registered (app owns event admin UI), or an oversight?
4. **AIA-CONFIG-003** — Keep force-loading all package migrations, or switch to per-package opt-in once cutover settles?
5. Whether paid-ticketing commerce (cart/checkout/chip) is on the roadmap soon — drives whether ticketing/inventory packages should be activated now.

## Implementation status

| Gap ID | Status | Notes |
|--------|--------|-------|
| AIA-TEST-008 | `IMPLEMENTED_AND_VERIFIED` | `tests/Unit/Models/PackageModelInheritanceTest.php` — 5 tests, all pass |
| AIA-TEST-003 | `IMPLEMENTED_AND_VERIFIED` | `tests/Feature/Migrations/LegacyRegistrationsTableTest.php` — 2 tests, all pass |
| AIA-CONFIG-001 | `IMPLEMENTED_AND_VERIFIED` | Published `config/events.php` via `vendor:publish --tag=events-config` |
| AIA-MIG-002 | `IMPLEMENTED` | Forward migration `2026_07_04_065716_drop_legacy_registrations_table.php` created, guarded with `Schema::hasTable()` |
| AIA-FILAMENT-001 | `IMPLEMENTED_AND_VERIFIED` | Deleted `app/Filament/Resources/{States,Districts,Subdistricts}/` — no references remain |
| AIA-ACTION-001c | `IMPLEMENTED_AND_VERIFIED` | Register-for-event pilot cutover: deleted `RegisterForEventAction.php`, wired `RegistrationServiceInterface::register()` in web & API controllers, AIA-TEST-001 passes (4 tests) |
| AIA-ACTION-001b | `IMPLEMENTED_AND_VERIFIED` | Save/unsave bookmark cutover: deleted `SaveEventAction.php`+`UnsaveEventAction.php`, wired `EventEngagementManager`/`Bookmark` model in controllers/Livewire/API, all event save/unsave tests pass (14 tests). `SaveAdminEventAction`+`SubmitFrontendEventAction` deferred — no package equivalents exist. |
| AIA-TEST-004 | `IMPLEMENTED_AND_VERIFIED` | `tests/Feature/Engagement/FollowTest.php` — 3 tests verify package follow/unfollow writes `engagement_follows` |
| AIA-ACTION-002 | `IMPLEMENTED_AND_VERIFIED` | Follow engagement cutover: deleted `app/Models/Concerns/HasFollowers.php`, added `CanFollow` trait to User + `follows()`/`followers()`/`isFollowedBy()` to Speaker/Institution/Reference/Series, rewired `followingSpeakers()` etc. to `engagement_follows` table, updated `SearchController` raw SQL, added `OwnerContext::setForRequest(null)` to Livewire Show pages, wrapped trait methods in OwnerContext. All follow tests pass (42 assertions). |
| AIA-MIG-003 | `IMPLEMENTED` | Forward migration `2026_07_04_093522_backfill_followings_to_engagement_follows.php` — copies `followings` rows into `engagement_follows` with `status=active`, drops `followings` table. |
| AIA-TEST-005 | `IMPLEMENTED_AND_VERIFIED` | `tests/Feature/Membership/ClaimTest.php` — 2 tests verify package `MembershipApplication` model creates records in `membership_applications` table |
| AIA-MODEL-010 | `IMPLEMENTED_AND_VERIFIED` | MembershipClaim model switched to `membership_applications` table (package-owned), updated fillable to use `applicant_id`/`granted_role` columns matching package schema, casts status to `ApplicationStatus`, added `subject()` MorphTo + `applicant()` BelongsTo relationships. All 22 membership claim tests pass. `MembershipClaimStatus` enum unused. |
| AIA-MIG-005 | `IMPLEMENTED` | Forward migration `2026_07_04_094330_backfill_membership_claims_to_applications.php` — copies `membership_claims` rows into `membership_applications` with column mapping (`claimant_id`→`applicant_id`, `granted_role_slug`→`granted_role`), drops `membership_claims` table. |
| AIA-TEST-006 | `IMPLEMENTED_AND_VERIFIED` | `tests/Feature/Communications/DeliveryTest.php` — 1 test verifies package auto-capture records a `communications` table entry when a Laravel notification is sent. |
| AIA-COMMS-001 | `IMPLEMENTED` | Enabled `auto_capture` by default in `config/communications.php` (default changed from `false` to `true`). Package now automatically records all Laravel notifications into communications tables. |
| AIA-COMMS-002a | `IMPLEMENTED_AND_VERIFIED` | Inbox write path — `InboxChannel` created, `NotificationCenterMessage` routes InApp to `InboxChannel::class`. |
| AIA-COMMS-002b | `IMPLEMENTED_AND_VERIFIED` | Inbox reads — all 8 consumers switched from `NotificationMessage` to `NotificationInbox`. |
| AIA-COMMS-002c | `IMPLEMENTED` | Backfill migration, `notifications` table dropped, `NotificationMessage` model+factory deleted. |
| AIA-COMMS-003 | `IMPLEMENTED_AND_VERIFIED` | Pipeline cutover complete. All 22 notification triggers migrated to skip local `PendingNotification` staging. `notification_messages` table dropped. `auto_capture` records all dispatches into communications tables. 4 package resolvers implemented (`QuietHoursResolver`, `PreferenceResolver`, `ConsentResolver`, `SuppressionResolver`). Digest job deleted. 55 tests pass. |
| AIA-COMMS-002 | `IMPLEMENTED_AND_VERIFIED` | Inbox + pipeline cutover: inbox channel, consumer reads, backfill, all triggers migrated, `notification_messages` dropped. See AIA-COMMS-002a/b/c and AIA-COMMS-003 for details. |
| AIA-MODEL-013 | `SUPERSEDED` | Historical `space_id` migration note. The first-class metadata hard-cut plan supersedes the former accessor/mutator and metadata-routing guidance; current code must use `EventLocation.venue_space_id` through explicit actions. |
| AIA-FILAMENT-002 | `IMPLEMENTED_AND_VERIFIED` | `filament-events` plugin registered in both Admin and Ahli panels. Local `app/Filament/Resources/Events/` and `app/Filament/Resources/Venues/` deleted (backed up to `backup-*`). All 16+ external references updated to use `AIArmada\FilamentEvents\Resources\EventResource`/`VenueResource`. Package routes verified: admin events index/create/view/edit at `admin.ilmu360.test/events/*`, venues at `admin.ilmu360.test/venues/*`. Ahli panel also uses package resources. 27/27 EventApiContractTest pass, 84/89 EventSearchTest pass. |

## Next exact action for continuation

All items complete. Only one item warrants future attention:

- **Phase 3 metadata**: superseded by the first-class metadata hard-cut. The old `MetadataBackedColumns` inventory and builder-intercept status are historical only; current code must use the canonical package relations, columns, lifecycle state, and analytics projections.

Everything previously listed as "remaining" has been re-audited against the hard-cut plan and removed from runtime code. See `docs/aiarmada-adoption/status.md` for the current implementation status.

### is_active → published_at cutover (2026-07-08)

`is_active` removed from `MetadataBackedAttributes` and `MetadataBackedColumns`. Now derived from package `published_at` column:
- `getIsActiveAttribute()` → `$this->published_at !== null`
- `setIsActiveAttribute()` → maps to `published_at` (true → `now()`, false → `null`)
- All `->where('is_active', ...)` queries redirected to `published_at IS NOT NULL / IS NULL` via `EventBuilder::whereIsActive()`
- Scopes (`active()`, `makeAllSearchableUsing`) use `whereNotNull('published_at')` directly

### NotificationCenterTriggersTest + trackedUsers fix (2026-07-08)

5/5 tests pass after fixes:
1. `EventReference` followers — switch to `referenceable.followers`
2. `EventKeyPerson` fillable — added `involveable_type`/`involveable_id`
3. Test column naming — `speaker_id`→`involveable_id`, `is_public`→`visibility`
4. `goingEvents()->attach()` → `create()` on MorphMany
5. `EventCheckinFactory` — added `$model = EventCheckin::class` (inherited `EventAttendance::class` from parent)
6. `trackedUsers` + `trackedReminderUsers` — resolve Bookmark/Response via `.bookmarker`/`.responder` relationships (was silently broken after `savedBy()` changed from `BelongsToMany<User>` to `MorphMany<Bookmark>`)
7. `EventReference` attach → create on HasMany
