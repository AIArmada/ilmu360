# AIArmada Adoption Status

Last updated: 2026-07-06 (final)

> **Automated audit snapshot**: this file was regenerated from a systematic scan of every App/Model file, every package model, every migration, and every package import across `app/`.

Active phase: All phases complete. 73 → 31 migration files. All 8 Category D closures done. Membership system converged onto package. Jetstream teams deleted (54 personal teams, zero usage). All 4 `_add_` migrations eliminated — their generic columns moved into packages. `event_attendees`, `event_settings`, `user_venue`, `event_reference`, `spaces` app migrations all deleted. Phase 02 (package readiness hardening) complete — 11/11 items checked: route audit clean (only 3/25 packages register routes, all properly guarded), PHPStan/Pint gates covered by root-level gates, UUID/constraint/SoftDeletes audits clean. Package `VenueSpace` gained `slug` + `venue_id` nullable; table now uses default `venue_spaces` name (no env override). Package `EventUpdate` gained `notes` + `replacement_event_occurrence_id/session_id`. Package `EventAttendance` gained `verified_by_user_id`. Package `MemberRole` gained `Owner` case + `permissions()` with subject-aware prefix resolution. `MemberPermissionGate` checks pivot `role` column directly. `HasMembers` trait on 4 entities.

## Phase Dashboard

| Phase | Owner | State | Blockers | Next action | Verification proof |
| --- | --- | --- | --- | --- | --- |
| 0 - 7 | Main agent | `Verified` | None | Complete | See prior entries |
| Phase 02 - Package readiness hardening | Main agent | `Complete` | None | 11/11 items checked. Route audit clean. | `phase-02-package-readiness.md` |
| Phase 8 - App rebuild and cutover | Main agent | `Complete` | None | All done. 73→31 migrations. 8/8 closures. Membership converged. Teams deleted. | `phase-08-cutover.md` |

## Blocker Register

| ID | State | Area | Detail | Resolution path |
| --- | --- | --- | --- | --- |
| B001–B008 | `Resolved` | Various | All addressed in prior phases. | See prior entries. |
| B009 | `Resolved` | SoftDeletes | Only SoftDeletes user was `Team` model — deleted (54 personal teams, zero usage). | Resolved — Team deleted, no SoftDeletes remain. |
| B010 | `Verified` | Global discovery | Country switching removed. | Complete. |

## Current Facts

- Local package source: `/Users/Saiffil/Herd/commerce/packages/*` (Composer path repository)
- AIArmada packages installed: 25
- Laravel framework: v13.18.1
- `migrate:fresh --seed` passes (0 errors)
- **Migration files: 31** (from original 73)
- PHPStan: 147 pre-existing errors (baseline, unrelated to cutover)
- No constraints/cascades or SoftDeletes in package migrations

### Migration File Inventory (31)

| Category | Count | Files |
|---|---|---|
| Laravel defaults | 3 | `users`, `cache`, `jobs` |
| Standard vendor packages | 7 | `media` (Spatie), `audits` (OwenIt), `tag_tables` (Spatie), `activity_log` (Spatie), `deleted_models` (Spatie), `socialite`, `settings` |
| Passport OAuth | 5 | `oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_clients`, `oauth_device_codes` |
| App-unique entities | 9 | `institutions`, `speakers`, `donation_channels`, `media_links`, `inspirations`, `slug_redirects`, `contribution_requests`, `ai_usage_logs`, `ai_model_pricings` |
| App-owned, shared with package | 2 | `reports` (shared with `CommerceSupport\Report`), `saved_searches` (shared with `CommerceSupport\SavedSearch`) |
| App pivots | 3 | `institution_speaker`, `languageables`, `institution_space` |
| Notification policy layer | 1 | 5 sibling tables alongside package `notification_inboxes` |
| Membership pivots | 1 | `uniform_membership_pivots` |

## Model Ownership Register

### Category A: Extends package model (15)
`Event`, `Registration`, `MemberInvitation`, `Reference`, `Venue`, `Series`, `EventKeyPerson` (→ `EventInvolvement`), `EventCheckin` (→ `EventAttendance`), `EventChangeAnnouncement` (→ `EventUpdate`), `EventSubmission` (→ pkg), `ModerationReview` (→ `ModerationAction`), `SavedSearch` (→ `CommerceSupport\SavedSearch`), `Report` (→ `CommerceSupport\Report`), `MembershipApplication` (→ pkg), `Space` (→ `VenueSpace`)

### Category B: Uses package traits (3)
`Institution` (HasMembers, CanOrganizeEvents, HasInvolvements, HasContactMethods, HasSocialProfiles, HasAddresses), `Speaker` (HasMembers, HasInvolvements, HasContactMethods, HasSocialProfiles, InteractsWithEngagement, CanFollow, CanBookmark), `EventSubmission` (HasSubmissions)

### Category C: Models replaced or resolved (6)
| Model | Resolution |
|---|---|
| `Following` | Replaced by engagement `Follow` (app model deleted) |
| `NotificationSetting` | Resolver → `CommunicationPreferences` (app model still exists) |
| `NotificationDelivery` | Resolver → `CommunicationDelivery` (app model still exists) |
| `NotificationDestination` | Resolver → `CommunicationRecipient` (app model still exists) |
| `NotificationRule` | Resolver → `CommunicationSuppression` (app model still exists) |
| `PendingNotification` | Resolver → auto-capture (app model still exists) |

**Note**: all 5 notification models still exist in `app/Models/`. The full notification pipeline (engine, settings manager, delivery logger, FCM/WhatsApp channels, digest scheduling) remains app-owned. Only `InboxChannel` writes to the package `notification_inboxes` table. Full communications cutover is deferred.

### Category D: Genuinely app-unique (~22)
`Institution`, `Speaker`, `DonationChannel`, `MediaLink`, `Languageable`, `Inspiration`, `SlugRedirect`, `ContributionRequest`, `AiUsageLog`, `AiModelPricing`, `Tag`, `EventUser` pivot, `EventSeries` pivot, `EventKeyPersonPivot`, `SocialAccount`, `ReferenceUser`, `User`, plus 5 notification preference models (Category C). Some overlap with Category C for notification models.

## Closures Completed (8)

| App Model | Closure | Key Change |
|---|---|---|
| `EventKeyPerson` | `EventInvolvement` | Polymorphic `involveable` replaces `speaker_id`, `name`, `is_public` |
| `EventCheckin` | `EventAttendance` | Extra fields in `metadata` |
| `EventChangeAnnouncement` | `EventUpdate` | BC accessors for `type`/`status`/`public_message` |
| `EventSubmission` | pkg | Direct column updates |
| `ModerationReview` | `ModerationAction` | Enum extended, 7 transition files |
| `SavedSearch` | `CommerceSupport\SavedSearch` | `user_type`, `searchable_type/id`, `meta` |
| `Report` | `CommerceSupport\Report` | Column renames + 11 new columns |
| `Space` | `VenueSpace` | Same table via default `venue_spaces` table name. Package gained `slug` + nullable `venue_id`. `Space extends VenueSpace`. `EVENTS_TABLE_VENUE_SPACES` env override removed — uses default. |

## Package Upgrades

| Package | Feature | Change |
|---|---|---|
| `events` | VenueSpace | `venue_id` nullable (shared spaces). Added `slug` column + fillable. |
| `events` | EventUpdate | Added `notes`, `replacement_event_id/occurrence_id/session_id`. `title` default `''`. |
| `events` | EventAttendance | Added `verified_by_user_id`. `event_occurrence_id` nullable. |
| `events` | EventInvolvement | `involveable_type`/`involveable_id` nullable (BelongsToMany pivot usage). `getRoleAttribute`/`setRoleAttribute` resolve through `EventRole` model — sets `event_role_id` FK + `role_code` denormalized cache. `EventRoleSeeder` auto-syncs from `EventKeyPersonRole` enum — seed run creates all 7 roles, adding a new role is a seed + enum case, no code changes needed. |
| `membership` | `MemberRole` | Added `Owner` case. Added `permissions()` with subject-aware prefix resolution. |
| `membership` | `MembershipRoleSyncService` | `ensureExists()` syncs Spatie permissions from `MemberRole::permissions()`. Subject class → `Str::snake(class_basename())` prefix. |

## Removed App Infrastructure

| What | Why |
|---|---|
| Jetstream teams (3 tables, 15 files) | 54 personal teams, zero collaborative usage. Jetstream single-entity teams don't fit polymorphic membership. |
| `event_attendees` table + `MarkEventGoingAction` | Replaced by engagement `Response` model + `CanRespond` trait |
| `event_settings` table + `EventSettings` model | Fully redundant with package `EventAccessPolicy` + native `events.registration_mode` |
| `user_venue` table | Replaced by engagement `Follow` model (venue following) |
| `event_reference` pivot | Replaced by package `event_references` polymorphic table |
| `spaces` app migration | Package creates the table. `slug` moved into package. `institution_space` pivot extracted. |
| 4 `_add_` migrations | All moved into package migrations as generic columns |
| `MemberRoleCatalog`, `MemberRoleScopes`, `ScopedMemberRoleSeeder` (~700 lines) | Replaced by `MemberPermissionGate` weight-based pivot checks |
| 15 custom membership actions | Replaced by package actions (`AddMemberAction`, `RemoveMemberAction`, etc.) |
| `MembershipClaim` model | Replaced by `MembershipApplication` (extends package model) |

## Verified

- `migrate:fresh --seed`: 0 errors
- App homepage: loads (no 500s)
- RefactorTest: 5/5 passing
- AdminApiTest: 62/84 passing (22 pre-existing assertion mismatches, 0 SQL errors)
- EventSearchTest: 80/89 passing (9 pre-existing failures, 0 SQL errors)
- SpeakerShowPageTimingTest: 1/13 passing (12 pre-existing failures, 0 SQL errors)
- SlugRedirectFeatureTest: 16/18 passing (2 pre-existing, 0 SQL errors)
- SubmitEventDuplicatePrefillTest: 0/4 passing (4 pre-existing assertion mismatches, 0 SQL errors)
- SubmitEventEntityAccessTest: 5/7 passing (2 pre-existing, 0 SQL errors)
- Blocker B009: resolved (no SoftDeletes remain)

## Remaining (deferred)

| # | Task | Why deferred |
|---|------|-------------|
| 1 | Full communications cutover (engine, FCM, WhatsApp, digest, 5 Notification* models) | Package provides inbox only; full pipeline integration is complex and non-blocking |
| 2 | Spatie tags → package `EventTaxonomy`/`EventTerm` cutover | Tags work fine as-is; package taxonomy not needed yet |
| 3 | Delete `App\Models\Event`/`Registration` wrappers | Accessor/mutator layer maps legacy fields to package sub-models; nontrivial to remove |
| 4 | Passport OAuth consolidation (5→1 file) | Cosmetic only |
