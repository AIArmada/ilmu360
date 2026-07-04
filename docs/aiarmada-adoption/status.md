# AIArmada Adoption Status

Last updated: 2026-07-04

Active phase: Phase 8 - App Rebuild And Cutover (In Progress)
Session: 8.C and 8.D are verified. Fresh migrate/seed passes, package addressing/contacting are live in runtime paths, event/reference/venue runtime bridges now cover package-owned metadata and occurrences, nearby search joins `addressables`, public event engagement uses package actions, the legacy geography wrappers/admin surfaces are deleted, the pending seating lifecycle migration is now applied, and `App\Models\MemberInvitation` now sits on the installed membership model with package-compatible hashed token storage/resolution.
Next: Continue the deletion/rebuild packets for the remaining app-owned event/venue/reference/membership/communications surfaces. Membership invitation storage is aligned, but full package membership action/model cutover still needs upstream role coverage for event organizer/co-organizer workflows.

## Phase Dashboard

| Phase | Owner | State | Blockers | Next action | Verification proof |
| --- | --- | --- | --- | --- | --- |
| 0 - Baseline readiness | Main agent | `Verified` | B009 recorded for later cleanup | Complete | `review-log.md` entry for 2026-06-28 WP-00 |
| 1 - Source and dependencies | Main agent | `Verified` | B005 (defer), B009 (defer) | Complete | `review-log.md` entry for 2026-06-29 WP-02 |
| 2 - Package readiness | Main agent | `Verified` | None | Complete | review-log.md 2026-06-29 session 1 |
| 3 - Foundation adoption | Main agent | `Verified` | None | WP-08: fix stale refs, publish authz config | review-log.md 2026-06-29 WP-08 |
| 4 - Identity/geography/contacts/membership | Main agent | `Assessed` | None | WP-09/10/11 assessed. Migration deferred to Phase 8. | phase-04.md |
| 5 - Core domain rewrite | Main agent | `Assessed` | B007 | WP-12/13/14/15 assessed. See phase-05.md for detailed migration plan. | Phase 8 cutover |
| 6 - Communications | Main agent | `Assessed` | B008 | WP-16: Replace notification engine w/ `communications`. FCM/WhatsApp/digest bound through contracts. Detailed plan in phase-06.md. | phase-06.md |
| 7 - Commerce capabilities | Main agent | `Assessed` | None | WP-17: App has zero commerce — ticketing via events package, payments via CHIP, donations via Chip Collect. Cart/checkout/orders for paid tickets. No inventory/shipping/tax needed. Detailed plan in phase-07.md. | phase-07.md |
| 8 - App rebuild and cutover | Main agent | `In Progress` | Remaining legacy app schema/model ownership still blocks full cutover exit | 8.C/8.D verified. Geography wrapper deletion, seating lifecycle migration, and membership invitation token alignment are done; continue with remaining event/venue/reference/membership/communications packets and broader package-first verification. | `phase-08-cutover.md` + `review-log.md` |

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

## Active Phase Checklist (Phase 8.D Verified Checkpoint)

- [x] Verify 8.C runtime cutover: global discovery, package addressing seeders, contact/social package aliases, and removal of country switching
- [x] Pass `php artisan migrate:fresh --seed`
- [x] Pass `php artisan view:clear --ansi && php artisan view:cache --ansi`
- [x] Verify `php artisan route:list --ansi` exposes package-backed addressing/admin routes
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

## Next Cutover Packet

- [ ] Delete or replace remaining legacy app-owned event/venue/reference/membership/communications schema surfaces now that runtime bridges are stable
- [ ] Expand package-first verification beyond the targeted event/venue slices (directory/API/MCP coverage)

## Current Facts

- Local package source: `/Users/Saiffil/Herd/commerce/packages/*` (Composer path repository)
- Local packages found: 59 Composer packages.
- AIArmada packages installed from local source (22): `addressing`, `affiliates`, `authz`, `commerce-support`, `communications`, `contacting`, `engagement`, `events`, `filament-addressing`, `filament-authz`, `filament-communications`, `filament-contacting`, `filament-engagement`, `filament-events`, `filament-signals`, `inventory`, `membership`, `moderation`, `references`, `seating`, `signals`, `ticketing`.
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
  - `vendor/bin/pest --parallel --compact --filter=EventSearchTest` => 89 passed
  - `vendor/bin/pest --parallel --compact --filter=EventShowPageTest` => 29 passed
  - `vendor/bin/pest --parallel --compact --filter="(UnifiedSearchPageTest|EventShowPageTest|VenueIndexTest)"` => 41 passed
- Full `vendor/bin/phpstan analyse --ansi` passes as of 2026-07-04 after the geography wrapper deletion packet.
