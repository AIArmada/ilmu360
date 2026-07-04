# Review Log

## 2026-07-04 - Phase 8.E Membership Invitation Token Alignment

State: `Verified`
Owner: Main agent

Changed:

- `app/Models/MemberInvitation.php`
- `/Users/Saiffil/Herd/commerce/packages/membership/src/Models/MembershipInvitation.php`
- `app/Actions/Membership/InviteSubjectMember.php`
- `app/Actions/Membership/ResolveMemberInvitationByTokenAction.php`
- `app/Filament/RelationManagers/MemberInvitationsRelationManager.php`
- `tests/Feature/MemberInvitationActionsTest.php`
- `docs/commerce-package-readiness-reassessment.md`
- `docs/aiarmada-adoption/status.md`
- `docs/aiarmada-adoption/phase-08-cutover.md`

Verified:

- `vendor/bin/pest --parallel --compact --filter='(MemberInvitationActionsTest|MemberInvitationUiTest|MembershipClaimActionsTest|AhliPanelInstitutionEditingTest|NotificationEmailRoutingTest)'` => 50 passed, 156 assertions.
- `vendor/bin/pest --parallel --compact --filter='(MemberInvitationActionsTest|MemberInvitationUiTest)'` => 18 passed, 43 assertions.
- `vendor/bin/phpstan analyse --ansi app/Models/MemberInvitation.php app/Actions/Membership/InviteSubjectMember.php app/Actions/Membership/ResolveMemberInvitationByTokenAction.php app/Filament/RelationManagers/MemberInvitationsRelationManager.php tests/Feature/MemberInvitationActionsTest.php` => no errors.

Decisions:

- The app should follow the package membership token contract now even before the broader membership workflow is fully cut over, and the invitation wrapper can safely sit on the installed package model once owner scoping is explicitly disabled for the current app behavior.
- Persisted invitation rows should keep only the protected token form; the raw accept token belongs only in outbound delivery.
- Admin surfaces must not pretend the stored token can still be copied back into a working accept URL once hashing is enabled.

Blockers:

- Full membership cutover is still blocked by role-model divergence: the installed package role enum covers `admin` / `editor` / `viewer`, while the app still needs event-specific `organizer` / `co-organizer` invitation flows.

Next:

- Continue the broader membership rewrite from the claim/application side and revisit invitation action/model replacement once upstream role coverage can satisfy the current event member semantics.

## 2026-07-04 - Phase 8.E Geography Wrapper Deletion And Seating Lifecycle Sync

State: `Verified`
Owner: Main agent

Changed:

- `app/Observers/AddressAreaObserver.php`
- `app/Observers/AddressCountryObserver.php`
- `app/Providers/AppServiceProvider.php`
- `app/Support/Location/FederalTerritoryLocation.php`
- `resources/views/livewire/pages/institutions/show.blade.php`
- `resources/views/livewire/pages/speakers/show.blade.php`
- deleted `app/Models/Country.php`, `app/Models/State.php`, `app/Models/District.php`, `app/Models/Subdistrict.php`
- deleted legacy geography admin pages/forms under `app/Filament/Resources/{States,Districts,Subdistricts}`
- deleted `app/Actions/Location/GetGeographyDeletionBlockReasonAction.php`
- `tests/Pest.php`
- `tests/Feature/GeographyDeletionRulesTest.php`
- `tests/Feature/GeographyFormCountryCodeTest.php`
- `tests/Feature/SharedFormSchemaTest.php`
- `tests/Feature/PublicListingCacheInvalidationTest.php`
- `tests/Feature/InstitutionShowPageTest.php`
- `tests/Feature/SpeakerShowPageTimingTest.php`
- `tests/Feature/SlugRedirectFeatureTest.php`
- `phpstan-baseline.neon`
- `docs/commerce-package-readiness-reassessment.md`
- `docs/aiarmada-adoption/status.md`
- `docs/aiarmada-adoption/phase-08-cutover.md`

Verified:

- `vendor/bin/pest --parallel --compact --filter='(GeographyDeletionRulesTest|GeographyFormCountryCodeTest|SharedFormSchemaTest|PublicListingCacheInvalidationTest|InstitutionShowPageTest|SpeakerShowPageTimingTest|SlugRedirectFeatureTest)'` => 109 passed, 1,440 assertions.
- `vendor/bin/pest --parallel --compact --filter='(InstitutionShowPageTest|SpeakerShowPageTimingTest)'` => 44 passed, 177 assertions.
- `vendor/bin/phpstan analyse --ansi` => no errors.
- `php artisan migrate --ansi` => applied `2000_01_01_000007_add_seating_lifecycle_columns_to_seat_allocations`.
- `php artisan migrate:status --ansi | rg 'add_seating_lifecycle_columns_to_seat_allocations|seat_allocations|seat_maps|ticket_passes'` => seating lifecycle migration now `Ran`.
- `git diff --check` => pass.

Decisions:

- Package geography ownership is now strong enough to delete the app-owned country/state/district/subdistrict wrappers instead of keeping thin compatibility models.
- Geography cache busting, federal-territory cache invalidation, and delete guards belong on package observers, not on app wrapper models.
- The outstanding seating package gap was operational rather than architectural; applying the pending lifecycle migration is part of cutover completion, not future redesign work.

Blockers:

- Full Phase 8 exit is still blocked by the remaining app-owned wrappers in event, reference, membership, communications, and taxonomy-adjacent surfaces.

Next:

- Continue the wrapper-deletion packets outside geography, starting with the highest-friction event/venue/reference/membership/communications ownership paths.

## 2026-06-30 - Phase 8.D Runtime And Regression Hardening

State: `Verified`
Owner: Main agent

Changed:

- `app/Livewire/Pages/Events/Show.php`
- `app/Models/Builders/EventBuilder.php`
- `app/Models/Builders/VenueBuilder.php`
- `app/Models/Event.php`
- `app/Models/Venue.php`
- `app/Services/EventSearchService.php`
- `app/Support/Events/PrimaryOccurrenceSql.php`
- `database/factories/VenueFactory.php`
- `/Users/Saiffil/Herd/commerce/packages/addressing/src/Models/Address.php`
- `/Users/Saiffil/Herd/commerce/packages/addressing/src/Models/Addressable.php`
- `resources/views/livewire/pages/events/index.blade.php`
- `tests/Feature/EventSearchTest.php`
- `tests/Feature/EventShowPageTest.php`
- `tests/Feature/VenueIndexTest.php`
- `docs/aiarmada-adoption/status.md`
- `docs/aiarmada-adoption/phase-08-cutover.md`
- `tasks/todo.md`

Verified:

- `vendor/bin/pest --parallel --compact --filter=EventShowPageTest` => 29 passed, 79 assertions.
- `vendor/bin/pest --parallel --compact --filter=EventSearchTest` => 89 passed, 319 assertions.
- `vendor/bin/pest --parallel --compact --filter=VenueIndexTest` => 6 passed, 21 assertions.
- `vendor/bin/pest --parallel --compact --filter="(UnifiedSearchPageTest|EventShowPageTest|VenueIndexTest)"` => 41 passed, 127 assertions.
- `php -l app/Models/Venue.php app/Models/Builders/VenueBuilder.php app/Services/EventSearchService.php tests/Feature/VenueIndexTest.php` => pass.
- `vendor/bin/phpstan analyse --ansi app/Models/Venue.php app/Models/Builders/VenueBuilder.php app/Services/EventSearchService.php` => still reports existing generic typing debt in `EventSearchService`; not a runtime blocker, carry forward to the next cutover packet.

Decisions:

- Event discovery/search must query package addresses and metadata directly instead of relying on legacy relation-existence SQL against removed `events.institution_id` and `events.venue_id` columns.
- Venue runtime compatibility belongs in the app wrapper: `type` maps to `venue_type`, `description` / `facilities` / `is_active` live in venue metadata, and `Venue::events()` points at `events.default_venue_id`.
- Public event discovery continues to exclude parent programs at query level, not only in view logic.

Blockers:

- Full Phase 8 exit is still blocked by remaining legacy app schema/model ownership and the broader `EventSearchService` static-analysis generics debt.

Next:

- Advance from runtime bridges to deletion/rebuild packets for remaining app-owned event/venue/geography surfaces, then widen package-first API/MCP/directory verification.

## 2026-06-28 - WP-00 Documentation Hub Creation

State: `Verified`
Owner: Main agent

Changed:

- `docs/aiarmada-adoption/README.md`
- `docs/aiarmada-adoption/status.md`
- `docs/aiarmada-adoption/package-inventory.md`
- `docs/aiarmada-adoption/domain-mapping.md`
- `docs/aiarmada-adoption/architecture-decisions.md`
- `docs/aiarmada-adoption/agent-work-queue.md`
- `docs/aiarmada-adoption/phase-00-readiness.md`
- `docs/aiarmada-adoption/phase-01-source-dependencies.md`
- `docs/aiarmada-adoption/phase-02-package-readiness.md`
- `docs/aiarmada-adoption/phase-03-foundation.md`
- `docs/aiarmada-adoption/phase-04-identity-geography-contacts-membership.md`
- `docs/aiarmada-adoption/phase-05-core-domain.md`
- `docs/aiarmada-adoption/phase-06-communications.md`
- `docs/aiarmada-adoption/phase-07-commerce-capabilities.md`
- `docs/aiarmada-adoption/phase-08-cutover.md`
- `tasks/todo.md`

Verified:

- `test -d docs/aiarmada-adoption && find docs/aiarmada-adoption -maxdepth 1 -type f | sort` => 16 hub files present.
- `find /Users/Saiffil/Herd/commerce/packages -maxdepth 2 -name composer.json -print | wc -l` => 59.
- `find /Users/Saiffil/Herd/commerce/packages -path '*/database/migrations/*.php' -print | wc -l` => 247.
- `find /Users/Saiffil/Herd/commerce/packages -path '*/src/Models/*.php' -print | wc -l` => 289 raw files; documented curated Eloquent-model baseline remains 257.
- `find /Users/Saiffil/Herd/commerce/packages -path '*/routes/*.php' -print | wc -l` => 14.
- `find /Users/Saiffil/Herd/commerce/packages -path '*Resources*/*.php' -print | wc -l` => 673 raw files; documented curated resource baseline remains 116.
- `rg -n -- "aiarmada/" composer.json` => app currently requires `affiliates`, `commerce-support`, `filament-authz`, `filament-signals`, and `signals`.
- `rg -n -- "constrained\(|cascadeOnDelete\(" /Users/Saiffil/Herd/commerce/packages/*/database` => no matches.
- `rg -n -- "softDeletes\(\)|SoftDeletes" app database /Users/Saiffil/Herd/commerce/packages` => existing app `Team` SoftDeletes usage and package docs examples found; tracked as B009.
- `find /Users/Saiffil/Herd/commerce/packages -maxdepth 3 \( -name tests -o -name Testbench.php -o -name phpunit.xml -o -name package.json \) -print | sort` => no package-local tests/testbench/package.json found at that depth.
- `composer validate --no-check-publish` => pass.
- `git diff --check` => pass.

Decisions:

- Dedicated docs hub is the durable source of truth.
- `tasks/todo.md` mirrors only the active phase.
- AIArmada package standards win over current app conventions in the rewrite target.
- UUID geography from `aiarmada/addressing` replaces the current integer geography design in the fresh schema.

Blockers:

- Dependency blockers B001-B005 remain open for phases 1-2.
- B009 records existing app SoftDeletes usage and package docs examples for later cleanup.

Next:

- Begin Phase 1 package source and dependency alignment.

## 2026-06-28 - Event Ticketing Requirement Captured

State: `Verified`
Owner: Main agent

Changed:

- `docs/aiarmada-adoption/domain-mapping.md`
- `docs/aiarmada-adoption/phase-05-core-domain.md`
- `docs/aiarmada-adoption/phase-07-commerce-capabilities.md`
- `docs/aiarmada-adoption/architecture-decisions.md`
- `docs/aiarmada-adoption/package-inventory.md`
- `docs/aiarmada-adoption/status.md`

Verified:

- Read `AIArmada\Events\Enums\PricingMode` and confirmed package supports `paid`, `free`, and `mixed`.
- Read `EventAccessPolicy` and confirmed flags for registration, approval, payment, ticket, seating, walk-in, capacity, waitlist, and open/close windows.
- Read event ticket type migration and confirmed ticket type price/currency, access type, seating mode, sale window, status, visibility, and quantity fields.
- Read `RegisterForFreeAction` and `CreateRegistrationsFromOrderAction` and confirmed separate free and paid registration paths.

Decisions:

- ilmu360 rewrite target must not remain free-only.
- Paid event tickets are an approved commerce workflow and must be assessed in Phase 7.
- Public UI may phase exposure, but schema/admin/API/MCP contracts must be ready for free walk-ins, free tickets, paid tickets, mixed ticketing, passes, seating, capacity, waitlists, and check-in.

Blockers:

- Paid-ticket package chain still depends on Phase 1 dependency resolution and Phase 7 commerce assessment.

Next:

- During Phase 5, expose the package participation/ticketing primitives instead of adding app-specific free-only event state.

## 2026-06-28 - Global Addressing Requirement Captured

State: `Verified`
Owner: Main agent

Changed:

- `docs/aiarmada-adoption/domain-mapping.md`
- `docs/aiarmada-adoption/phase-04-identity-geography-contacts-membership.md`
- `docs/aiarmada-adoption/architecture-decisions.md`
- `docs/aiarmada-adoption/package-inventory.md`
- `docs/aiarmada-adoption/status.md`
- `docs/aiarmada-adoption/agent-work-queue.md`
- `docs/aiarmada-adoption/phase-08-cutover.md`

Verified:

- Read `address_areas` migration and confirmed UUID IDs, `country_id`, `parent_id`, `country_code`, `type`, `level`, `name`, source fields, metadata, and country/type/name indexing.
- Read `addresses` migration and confirmed `country_id` plus `admin_area_1_id` through `admin_area_4_id`, raw/formatted address fields, geospatial fields, and country/city/postcode indexes.
- Read `AddressAreaData` and confirmed import shape supports per-country source/type/level/parent metadata.
- Searched current app and confirmed existing country-switching and preferred-country assumptions in `/negara/{country}`, layout selector, `PublicCountryPreference`, `PublicCountryRegistry`, `PreferredCountryResolver`, search filters, API contracts, and docs.

Decisions:

- ilmu360° target product is global by default.
- Country is an optional search/filter dimension, not a session or application mode.
- Country-specific administrative terms such as Malaysia `district`, `wilayah_persekutuan`, and `small_district` belong in generic `address_areas.type` values.
- Institution, venue, speaker, and event workflows must support cross-country discovery without users switching countries.

Blockers:

- B010 tracks removal of existing country-switching behavior and global-search contract updates.

Next:

- During Phase 4, replace current integer geography and preferred-country services with package addressing and global filters.

## 2026-06-29 - WP-02 Composer Path Repository Strategy and Dependency Resolution

State: `Verified`
Owner: Main agent

Changed:
- `composer.json` — added path repositories for `/Users/Saiffil/Herd/commerce/packages/*`, bumped `laravel/framework` to `^13.15`, bumped `filament/filament` to `^5.6.7`
- `/Users/Saiffil/Herd/commerce/packages/references/composer.json` — updated `spatie/laravel-sluggable` constraint from `^3.7` to `^4.0.2` (generic package improvement)
- `docs/aiarmada-adoption/status.md` — updated blocker states, Phase 1 checklist, current facts
- `docs/aiarmada-adoption/agent-work-queue.md` — updated WP-02, WP-03, WP-04 states
- `docs/aiarmada-adoption/phase-01-source-dependencies.md` — updated checklist
- `tasks/todo.md` — updated active phase mirror

Verified:
- `composer validate` => valid.
- `composer update [56 packages] -W` => successful, 2 installs (authz, contacting), 56 updates.
- `ls -la vendor/aiarmada/commerce-support` => symlink to local path packages confirmed.
- `composer show "aiarmada/*"` => 7 packages from local source: affiliates, authz, commerce-support, contacting, filament-authz, filament-signals, signals.
- `php artisan tinker` version checks:
  - laravel/framework: v13.17.0 (previously v13.14.0)
  - filament/filament: v5.6.7 (previously v5.6.6)
  - spatie/laravel-permission: v8.1.0 (previously v7.4.2)
  - spatie/laravel-sluggable: v4.0.2 (unchanged, compatible)
  - livewire/livewire: v4.3.3 (previously v4.3.1)
  - bezhansalleh/filament-language-switch: v5.0.0 (previously v5.0.0-beta.1)
- `rg -n -- "constrained\(|cascadeOnDelete\(" /Users/Saiffil/Herd/commerce/packages/*/database` => no matches.
- `rg -n -- "softDeletes\(\)|SoftDeletes" /Users/Saiffil/Herd/commerce/packages/*/database/` => no matches.
- Authz/filament-authz API audit: all used PermissionRegistrar methods, Gate::before patterns, custom Permission/Role models confirmed v8-compatible. No breaking changes found.
- Sluggable v4 → v4 audit: `HasSlug` trait still requires `getSlugOptions(): SlugOptions` — same API as v3. Constraint bump only, no code changes needed.

Decisions:
- Composer path repository is the target development setup for this rewrite.
- `laravel/framework` constraint bumped to `^13.15` (minor bump, contained).
- `filament/filament` constraint narrowed to `^5.6.7` (within `^5.0` range).
- `spatie/laravel-permission` v8 upgrade is safe — the audited packages and app code use only compatible APIs. No v7→v8 migration needed for app code.
- `aiarmada/references` sluggable constraint changed from `^3.7` to `^4.0.2` — generic package improvement, no PHP code changes.
- The `aiarmada/authz` and `aiarmada/contacting` packages are now auto-installed as dependencies of filament-authz and affiliates respectively.
- Livewire, bezhansalleh/language-switch, and other transitives received minor patch bumps during the update — no issues detected.

Blockers:
- None resolved in this packet. B005 (migration stubs) and B009 (app SoftDeletes) remain for later phases.

Next:
- Phase 2 (Package Readiness): Add package test harness strategy (WP-05), audit package migrations for UUID/no-constraint compliance (WP-06), fix commerce-support migration stub behavior (WP-07).
- WP-03 (authz permission) and WP-04 (references sluggable) are resolved and can be marked complete.

## 2026-06-29 - WP-05, WP-06, WP-07 Phase 2 Package Readiness Hardening

State: `Verified`
Owner: Main agent

Changed:
- `docs/aiarmada-adoption/status.md` — updated Phase 2 status, B005 state, current facts
- `docs/aiarmada-adoption/agent-work-queue.md` — WP-05/06/07 marked `Verified`
- `docs/aiarmada-adoption/phase-02-package-readiness.md` — checklist items marked complete, decisions documented
- No package code changed (WP-07 settled as no-change)

Verified:
- `rg -n "bigIncrements" /Users/Saiffil/Herd/commerce/packages/*/database/migrations/` => 2 known commerce-support stubs only (audits, webhook_calls)
- `rg -n "increments\b" /Users/Saiffil/Herd/commerce/packages/*/database/migrations/` => 0
- `rg -n "->id\\(\\)" /Users/Saiffil/Herd/commerce/packages/*/database/migrations/` => 0
- `rg -c "uuid\\('id'" /Users/Saiffil/Herd/commerce/packages/*/database/migrations/` => 264 occurrences
- `rg -c "foreignUuid" /Users/Saiffil/Herd/commerce/packages/*/database/migrations/` => 228 occurrences
- `rg -n "foreignId\b" /Users/Saiffil/Herd/commerce/packages/*/database/migrations/` => 0
- `rg -n "constrained\(|cascadeOnDelete\(" /Users/Saiffil/Herd/commerce/packages/*/database` => 0 (reconfirmed)
- `rg -n "softDeletes\(\)|SoftDeletes" /Users/Saiffil/Herd/commerce/packages/*/database/` => 0 (reconfirmed)
- `find /Users/Saiffil/Herd/commerce/packages -path '*/tests/*.php' -not -path '*/vendor/*'` => empty (no package-local tests)

Decisions:
- WP-07 (stub fix): keep `bigIncrements` in both commerce-support stubs. Upstream vendor models (Spatie WebhookCall, OwenIt Audit) rely on auto-increment. These are third-party integration tables, not app domain. Audits stub already has config-driven morph key type for polymorphic columns — no change needed.
- WP-05 (test harness): packages have no local test suites. App-level integration tests cover package behavior. Add package test boilerplate only when a package receives code changes.
- WP-06 (migration audit): 264/264 package migration primary keys use UUID. 0 constraint/cascade/SoftDeletes violations. Package migrations are phase-3-ready.

Blockers:
- B005 resolved (accepted exception). Remaining blockers: B006-B010 for later phases.

Next:
- Begin Phase 3 (Foundation Adoption) — move installed AIArmada packages to proper local path usage (WP-08).

## 2026-06-29 - WP-08 Foundation Adoption: Fix stale refs, remove monorepo overrides, publish authz config

State: `Verified`
Owner: Main agent

Changed:
- `config/permission.php` — Permission/Role model classes changed from non-existent `AIArmada\FilamentAuthz\Models\*` to `AIArmada\CommerceSupport\Models\*`
- `app/Providers/AppServiceProvider.php` — removed two stale monorepo references:
  - `$filamentSignalsViews = base_path('../commerce/packages/filament-signals/...')` (dead block, never executes)
  - `$signalsRoutes = base_path('../commerce/packages/signals/routes/api.php')` (dead block, never executes)
- `config/authz.php` — published from package defaults via `vendor:publish --tag=authz-config`
- `docs/aiarmada-adoption/status.md` — Phase 2 → Verified, Phase 3 → In Progress, current facts updated
- `docs/aiarmada-adoption/agent-work-queue.md` — WP-03/04/08 marked `Verified`

Verified:
- `php artisan tinker --execute 'echo config("permission.models.permission");'` => `AIArmada\CommerceSupport\Models\Permission` ✓
- `php artisan tinker --execute 'echo config("permission.models.role");'` => `AIArmada\CommerceSupport\Models\Role` ✓
- `php artisan tinker --execute 'echo class_exists(AIArmada\CommerceSupport\Models\Permission::class);'` => `true`
- `php artisan tinker --execute 'echo class_exists(AIArmada\CommerceSupport\Models\Role::class);'` => `true`
- `php artisan optimize` => all caches (config, events, routes, views, blade-icons, filament, laravel-data, laravel-settings) compiled cleanly
- `vendor/bin/pint --format agent` => passed
- `vendor/bin/phpstan analyse --ansi` => 155 errors (all pre-existing, zero in changed files)
- `vendor/bin/pest --parallel --compact --filter=Authz` => 11 failures (all pre-existing `gen_random_uuid` SQLite issue, unchanged)

Decisions:
- `config/authz.php` published with package defaults (`scopes.enabled = false`). Central app doesn't need scope-based team resolver.
- No further Phase 3 actions remain — authz, signals, filament-signals, and affiliates are already adopted and operational.
- growth package remains not installed (YAGNI until telemetry/growth workflows are active).

Blockers:
- None new. Remaining for later phases: B006 (geography rewrite), B007 (table ownership), B008 (communications), B009 (app SoftDeletes cleanup), B010 (global discovery).

Next:
- Begin Phase 4 (Identity/Geography/Contacts/Membership) — WP-09 (addressing/geography), WP-10 (contacting), WP-11 (membership).

## 2026-06-29 - WP-09, WP-10, WP-11 Phase 4 Assessment

State: `Assessed`
Owner: Main agent

Changed:
- `docs/aiarmada-adoption/phase-04-identity-geography-contacts-membership.md` — comprehensive assessment with package vs app mapping, 3 work plans, gap/risk analysis
- `docs/aiarmada-adoption/status.md` — Phase 4 → Assessed, blocker states updated, active phase updated
- `docs/aiarmada-adoption/agent-work-queue.md` — WP-09/10/11 → Assessed
- No application code changed (planning-only)

Verified:
- Read all addressing package models, migrations, traits, actions, Filament resources, form schemas, config
- Read all contacting package models, migrations, traits, actions, Filament resources
- Read all membership package models, migrations, traits, actions, config
- Read app Country, State, District, Subdistrict, City, Address models
- Read app Contact, SocialMedia models + enums
- Read app MembershipClaim, MemberInvitation, TeamRole, MemberSubjectType
- Read 14 membership actions, 6 services, country switching route/layout
- Confirmed `SocialMediaLinkResolver` (435 lines) has 10 platform-specific extractors — package normalizer is simpler (config prefix/suffix only)
- Confirmed `aiarmada/filament-contacting` exists but is NOT installed in composer.json
- Confirmed `aiarmada/membership` exists but is NOT installed in composer.json
- Confirmed `aiarmada/filament-membership` does NOT exist

Decisions:
- **WP-09 (Geography)**: Medium effort. 15 migration touchpoints documented. Country switching removed entirely in fresh schema.
- **WP-10 (Contacting)**: Low effort. Regression risk in social profile normalization — app's `SocialMediaLinkResolver` is more sophisticated than package's `SocialProfileConfig`. Decision deferred to Phase 8.
- **WP-11 (Membership)**: High effort. Deep integration with 5+ app services (authz scopes, role catalog, public submission locks, auditing, media evidence). Package has no "owner" role equivalent, no media support, and uses simpler pivot strategy. Recommended to implement `MembershipHook` and `MembershipApplicationNotifier` contracts during cutover.
- All three work packets deferred to Phase 8 (cutover). No code changes in current app.

Blockers:
- B006, B010 now `Assessed` with migration plan documented.
- Existing remaining blockers: B007 (table ownership), B008 (communications), B009 (SoftDeletes cleanup).

Next:
- Begin Phase 5 (Core Domain Rewrite) — WP-12 (events), WP-13 (engagement), WP-14 (references), WP-15 (moderation). Or skip to Phase 8 if target is fresh-schema planning only.

## 2026-06-29 - WP-12, WP-13, WP-14, WP-15 Phase 5 Core Domain Assessment

State: `Assessed`
Owner: Main agent

Changed:
- `docs/aiarmada-adoption/phase-05-core-domain.md` — comprehensive assessment: 4 work plans, package vs app mapping, gap analysis
- `docs/aiarmada-adoption/status.md` — Phase 5 → Assessed, active phase updated
- `docs/aiarmada-adoption/agent-work-queue.md` — WP-12/13/14/15 → Assessed
- No application code changed (planning-only)

Verified:
- Read `events` package: 71 models, 70 migrations, 34 actions, 44 domain events, 4 state machines, pricing/registration mode inheritance, full ticketing/inventory/seating/check-in
- Read `filament-events`: 9 resources, 5 pages, 1 widget, config
- Read `engagement` package: 10 models, 14 traits, 8 engagement types, 30 events, full Filament plugin
- Read `filament-engagement`: 7 resources, 8 pre-built actions, 6 relation managers, 1 widget
- Read `references` package: 1 model (Reference), part types (Jilid/Juz/Surah/etc.), sluggable
- Read `moderation` package: 2 models (Block, ModerationAction), 2 traits, expiry-based blocking
- Confirmed `filament-references` does NOT exist
- Confirmed `filament-moderation` does NOT exist
- Confirmed `feedback`/`filament-feedback` does NOT exist — reports remain app-owned

Decisions:
- **WP-12 (Events)**: High effort. 71 package models replace app event domain. Key gaps: media collections (package Event has none — app extension needed), Spatie Tags → package taxonomies (different status/moderation model), polymorphic ownership vs direct FK. Pricing modes (free/paid/mixed) fully supported.
- **WP-13 (Engagement)**: Medium effort. Clean replacement for follows/bookmarks/RSVP/reminders/shares. SavedSearch remains app-owned. Share attribution wires into `affiliates` via metadata JSON.
- **WP-14 (References)**: Low effort. Near-identical schema. Keep app's media collections and Filament resources (no package Filament). Keep EventRelation pivot.
- **WP-15 (Moderation)**: Medium effort. Package covers blocking + moderation actions. Reports remain app-owned (no feedback package). Wire ModerationAction into event submission approval flow.
- All 4 work packets deferred to Phase 8 (cutover). No code changes in current app.

Blockers:
- B007 (table ownership) remains — packages own events/venues/references/registrations in fresh schema.
- B008 (communications), B009 (SoftDeletes cleanup) remain for later phases.

Next:
- Phase 6 (Communications) — WP-16 notification engine replacement. Or Phase 7 (Commerce) — WP-17 paid ticket assessment. Or skip to Phase 8 (cutover).

## 2026-06-29 - Phase 8.C Public Country Switch Removal

State: `In Progress`
Owner: Main agent

Changed:
- `routes/web.php` — removed `/negara/{country}` and the `country.switch` route.
- `app/Http/Controllers/PublicCountryController.php` — deleted the public country-switch controller.
- `resources/views/layouts/app.blade.php` — removed desktop and mobile country selector UI, leaving language switching intact.
- `tests/Feature/PublicCountrySelectorTest.php` — deleted obsolete country-switch feature coverage.
- `docs/aiarmada-adoption/status.md`, `docs/aiarmada-adoption/phase-08-cutover.md`, `tasks/todo.md` — updated active Phase 8.C tracking.

Verified:
- `rg -n "country\\.switch|PublicCountryController|data-country-switcher|data-country-selector|publicCountries|currentPublicCountry" routes resources app tests -g '*.php' -g '*.blade.php'` => no matches.
- `php artisan route:list --name=country --ansi` => no matching routes.
- `php artisan view:clear --ansi && php artisan route:clear --ansi` => caches cleared successfully.
- `composer show 'aiarmada/*' --format=json` => 20 local AIArmada packages installed.

Decisions:
- Country is no longer a public application mode. Global discovery remains the default product direction.
- Deeper preferred-country services stay temporarily because unfinished form/API geography defaults still call them. Remove those services after address forms and catalog endpoints move to package addressing.

Blockers:
- None for this slice.

Next:
- Continue 8.C by replacing preferred-country form/API defaults with package addressing defaults and seeding `address_countries` / `address_areas`.

## 2026-06-29 - Phase 8.C Package Addressing Seed And Global Catalog Defaults

State: `In Progress`
Owner: Main agent

Changed:
- `database/seeders/data/malaysia-address-areas.csv` — generated package importer source for Malaysia address areas with stable hierarchical source IDs.
- `database/seeders/AddressingSeeder.php` — added package-addressing country seed and Malaysia area import through `SeedAddressCountriesAction`, `CsvAddressAreaSource`, and `ImportAddressAreasAction`.
- `app/Livewire/Pages/Events/Index.php`, `app/Livewire/Pages/Events/AdvancedFiltersPanel.php`, `resources/views/livewire/pages/events/index.blade.php`, `resources/views/components/pages/institutions/⚡index.blade.php`, `resources/views/components/pages/venues/⚡index.blade.php` — public discovery now defaults to no country filter.
- `app/Http/Controllers/Api/Frontend/CatalogController.php`, `app/Support/Api/Frontend/SearchRequestNormalizer.php`, `app/Support/Api/Frontend/FrontendCatalogService.php`, `app/Data/Api/Frontend/Search/CountryData.php` — frontend catalog/search no longer applies preferred-country fallback or country-switch keys.
- `database/migrations/2026_05_11_230250_convert_settings_id_to_uuid.php` — fresh-schema guard for UUID/string settings primary key to avoid legacy conversion in SQLite tests.
- `database/migrations/2026_06_06_152726_add_owner_scope_to_signals_tracked_properties_table.php` — skip PostgreSQL catalog index surgery for non-PostgreSQL test connections.
- `database/seeders/PermissionSeeder.php` — corrected permission model import to `AIArmada\CommerceSupport\Models\Permission`.
- `docs/application-route-audit.md` — removed stale `/negara/{country}` entry.
- `docs/aiarmada-adoption/status.md`, `docs/aiarmada-adoption/phase-08-cutover.md`, `tasks/todo.md` — updated active 8.C progress.

Verified:
- `php artisan migrate:fresh --ansi && php artisan db:seed --class=AddressingSeeder --ansi` => fresh migrations passed; AddressingSeeder seeded 249 countries and imported 1,832 Malaysia address areas.
- `php artisan db:seed --class=AddressingSeeder --ansi` => seeded 249 countries and imported/updated 1,832 Malaysia address areas.
- `php artisan tinker --execute='echo AddressCountry::query()->count(); echo AddressArea::query()->count();'` => 249 countries, 1,832 areas.
- `php artisan tinker --execute='echo AddressArea::query()->where("country_code", "MY")->where("type", "wilayah_persekutuan")->count();'` => 3.
- `php artisan tinker --execute='echo AddressArea::query()->where("source_id", "my:state:selangor")->value("name"); echo AddressArea::query()->where("source_id", "my:district:selangor:gombak")->value("name");'` => `Selangor`, `Gombak`.
- `composer validate --no-check-publish --ansi` => passed.
- `php artisan route:list --ansi | rg 'negara|country\.switch|address-countries|address-areas'` => only package Filament address routes; no `/negara` route.
- `vendor/bin/pint --dirty --format agent` => passed.
- `php artisan view:cache --ansi` => passed.
- `git diff --check` => passed.

Failed / blocked verification:
- `php artisan migrate:fresh --seed --ansi` still fails when it reaches legacy `DatabaseSeeder` / `SubdistrictSeeder` because old integer geography tables cannot represent federal territory subdistricts without nullable `district_id`. Fresh migrations themselves pass; the remaining failure is the legacy app seeding path.
- `vendor/bin/pest --parallel --compact --filter='InstitutionIndex|Venue|EventsIndex|AdvancedFilters|PublicCountry|PreferredCountry'` now gets past the settings and signals migration blockers, then fails on unfinished table ownership work (`venues`/`addresses` tables removed or replaced before app models/tests move to package-owned models) and old country-switch test expectations.

Decisions:
- Package addressing import is complete for countries and Malaysia area hierarchy; do not harden the old integer geography seeders for the rewrite target.
- Country remains an explicit filter, not an application mode. No new Signals event is needed for removing a selector/default because no meaningful backend workflow outcome changed.

Next:
- Move submit-event, admin mutation, and MCP geography contracts to package addressing UUIDs.
- Delete `PublicCountryPreference`, `PreferredCountryResolver`, and `PublicCountryRegistry` only after those remaining call sites are gone.

## 2026-06-29 - Phase 8.C Submit-Event AddressCountry UUID Contract

State: `In Progress`
Owner: Main agent

Changed:
- `app/Support/Location/AddressingCountryResolver.php` — added package-backed country resolver for `AddressCountry` UUID/ISO2 inputs and Carbon-safe timezone normalization.
- `resources/views/components/pages/submit-event/create.blade.php` — replaced hidden preferred-country default with an explicit searchable package country selector; date/time choices now resolve timezone from selected package country.
- `resources/views/components/pages/submit-event/partials/review-preview.blade.php` — preview time labels now use package-addressing timezone resolution.
- `app/Actions/Events/SubmitFrontendEventAction.php` — submit validation now accepts only package `AddressCountry` UUIDs and no longer accepts legacy integer country IDs.
- `app/Http/Controllers/Api/Frontend/EventSubmissionController.php` — `submission_country_id` validation changed from integer to UUID.
- `app/Support/Api/Frontend/FrontendFormContractService.php` — submit-event contract now exposes package `AddressCountry` UUID allowed values and no default country.
- `docs/aiarmada-adoption/status.md`, `docs/aiarmada-adoption/agent-work-queue.md`, `docs/aiarmada-adoption/phase-08-cutover.md`, `tasks/todo.md` — updated WP-09C tracking.

Verified:
- `php -l app/Support/Location/AddressingCountryResolver.php` => passed.
- `php -l app/Actions/Events/SubmitFrontendEventAction.php` => passed.
- `php -l app/Http/Controllers/Api/Frontend/EventSubmissionController.php` => passed.
- `php -l app/Support/Api/Frontend/FrontendFormContractService.php` => passed.
- `php artisan view:cache --ansi` => passed.
- `php artisan tinker --execute='...'` => Malaysia resolves by AddressCountry UUID and ISO2; package timezone `UTC+08:00` normalizes to `+08:00`; Carbon parses it successfully.
- `vendor/bin/pint --dirty --format agent` => passed.
- `git diff --check` => passed.
- `rg -n "submission_country_id.*integer|submission_country_id.*int|integer.*submission_country_id" app resources docs tasks` => no matches.

Decisions:
- Submit-event has no implicit country default in the global app. Users/API clients must choose a country because prayer-time validation depends on local calendar/timezone context.
- No new Signals tracking was added for the country selector because it is required form input, not a meaningful backend workflow outcome. Event submission outcome tracking remains the higher-signal event.

Still open:
- Full package address field replacement remains in the Phase 8.C address schema/API catalog rebuild.
- Full app tests remain deferred until table ownership and legacy seeder blockers are resolved in Phase 8.

## 2026-06-29 - Phase 8.C Country Mode Service Deletion

State: `Verified`
Owner: Main agent

Changed:
- Deleted `app/Support/Location/PublicCountryPreference.php`, `app/Support/Location/PublicCountryRegistry.php`, `app/Support/Location/PreferredCountryResolver.php`, and `config/public-countries.php`.
- Removed the `public_country` cookie exception from `bootstrap/app.php`.
- Removed preferred-country defaults from admin write schemas and MCP-backed schema output through `AdminResourceMutationService`.
- Removed preferred-country defaults from frontend submit-institution/submit-speaker contracts.
- Updated shared address forms, institution/venue quick-create forms, speaker forms, and contribution form initial state so country is explicit/null instead of inferred from session, cookie, or timezone.
- Updated Google place resolution, speaker slug generation, contribution timezone fallback, and federal-territory detection to avoid country-mode services.
- Deleted obsolete country preference/registry/resolver unit tests and updated broader feature tests away from public-country expectations.
- Removed public-country config from public directory cache version fingerprints.

Verified:
- `rg -n "PreferredCountryResolver|PublicCountryRegistry|PublicCountryPreference|preferredPublicCountryId|public-countries|public_country" app resources routes config tests bootstrap --glob '!docs/**'` => no matches.
- `php -l` on touched app helpers/tests => passed.
- `php artisan view:cache --ansi` => passed.
- `vendor/bin/pint --dirty --format agent` => passed.
- `git diff --check` => passed.

Failed / blocked verification:
- `vendor/bin/pest --parallel --compact --filter='SubmitEventEndTime|CatalogApi|FrontendApiParity|InstitutionContributionLocationPicker|SpeakerSlugGeneration'` still fails under current Phase 8 fresh-schema work because legacy app tests/models hit removed or package-owned tables: `venues`, `addresses`, and `permissions`. This matches B007/table ownership and permission-table migration blockers, not remaining country-mode references.

Decisions:
- Country is no longer inferred from timezone, session, cookie, or `public-countries` config.
- Legacy integer address form fields remain only as temporary pre-package-rebuild surfaces; users/API/MCP clients must provide geography explicitly.
- UI tracking: no new Signals event was added for making country explicit in address forms. The meaningful outcome remains the create/update submission event, not field-selection intent.

Next:
- Rebuild address forms/API catalogs on `aiarmada/addressing` address countries/areas, then delete old integer geography models/migrations.
- Continue WP-10 by replacing app contacts/social profiles with `aiarmada/contacting`.

## 2026-07-04 - Reassessment Closure Audit

State: `In Review`
Owner: Main agent

Changed:
- `docs/commerce-package-readiness-reassessment.md` — rewrote the stale speculative reassessment into a current-state closure audit against the live package-adopted codebase.
- `docs/aiarmada-adoption/status.md` — updated package-installation facts and removed stale “7 installed / 20 installed” language.
- `docs/aiarmada-adoption/phase-08-cutover.md` — replaced the obsolete package-installation plan with the current 22-package installed state and marked contact/social replacement as complete.
- `tasks/todo.md` — closed the stale organizer hard-cutover checklist after graph-backed inspection plus focused Pest and full PHPStan verification.

Verified:
- `composer show 'aiarmada/*' --direct` => 22 AIArmada packages installed from the local path repository, including `seating` and `ticketing`.
- `vendor/bin/pest --parallel --compact --filter='(EventOrganizerInvolvementSyncTest|AdvancedEventCreationTest)'` => 14 passed, 62 assertions.
- `vendor/bin/phpstan analyse --ansi` => no errors.

Decisions:
- The old readiness reassessment is no longer a planning artifact. It is now a closure audit documenting that package installation is complete and remaining work is app cutover cleanup.
- Package installation is not the remaining blocker. The open work is deletion of compatibility wrappers and broader package-first surface migration.

Blockers:
- None for this audit/closure packet.

Next:
- Continue Phase 8 with deletion of remaining app-owned wrappers/resources in package-owned domains, starting with geography, event, reference, membership, and communications surfaces that still preserve legacy contracts.
