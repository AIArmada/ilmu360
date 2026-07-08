# AIArmada Package Adoption - Active Phase

Durable source of truth: `docs/aiarmada-adoption/README.md`
Active status dashboard: `docs/aiarmada-adoption/status.md`

Current phase: Phase 8 - App Rebuild And Cutover — `In Progress`

## Verified Checkpoint

- [x] 8.C runtime cutover: global discovery, package addressing seeders, and removal of country switching
- [x] 8.D runtime/test hardening for package-backed event search/show and venue directory behavior
- [x] Legacy event/venue regression slices rewritten to package addressing/contacting (`EventSearchTest`, `EventShowPageTest`, `VenueIndexTest`)

## Current Active Packet

- [x] Re-audit the events/seating/ticketing extraction across ilmu360 package wiring, config, migrations, seeders, and panel registration
- [x] Remove temporary compatibility assumptions and align the app to the extracted package ownership model
- [x] Run `php artisan migrate:fresh --seed`, fix every breakage it exposes, and keep iterating until it passes cleanly
- [x] Add or update focused regression coverage for the cutover and rerun targeted verification
- [x] Delete legacy geography wrappers/admin surfaces and move geography behavior to package observers
- [x] Apply the pending seating lifecycle migration so the installed package schema is fully live

## Current State

- Phase 8.A and 8.B are complete.
- Phase 8.C and 8.D are verified.
- `php artisan migrate:fresh --seed` has been revalidated in this packet after the latest events/seating/ticketing extraction.
- Targeted runtime verification passes:
  - `vendor/bin/pest --parallel --compact --filter=EventSearchTest`
  - `vendor/bin/pest --parallel --compact --filter=EventShowPageTest`
  - `vendor/bin/pest --parallel --compact --filter=VenueIndexTest`
  - `vendor/bin/pest --parallel --compact --filter="(UnifiedSearchPageTest|EventShowPageTest|VenueIndexTest)"`
- Progress evidence lives in `docs/aiarmada-adoption/status.md`, `docs/aiarmada-adoption/phase-08-cutover.md`, and `docs/aiarmada-adoption/review-log.md`.

## Review

- Added `aiarmada/seating` and `aiarmada/ticketing` as direct app dependencies so ilmu360 consumes the extracted packages explicitly.
- Removed local `config/seating.php`, `config/ticketing.php`, and `config/events.php` shadows so package defaults flow straight from `aiarmada/seating`, `aiarmada/ticketing`, and `aiarmada/events`.
- Fixed the Pest bootstrap drift by removing the stale test-only `APP_PACKAGES_CACHE` and `APP_SERVICES_CACHE` overrides from `phpunit.xml`; those cache files were masking newly discovered package providers and breaking package-default migrations.
- Verified the focused regressions `tests/Feature/SeatingConfigTest.php` and `tests/Feature/TicketingConfigTest.php` pass against package defaults.
- Revalidated `php artisan migrate:fresh --seed` after the cutover and test-bootstrap fix.
- Verified the database now creates `seat_maps`, `seat_sections`, `seats`, `seat_holds`, `seat_allocations`, `ticket_ticket_types`, `ticket_ticket_type_components`, `ticket_ticket_type_products`, `ticket_passes`, `ticket_pass_holders`, and `ticket_pass_transfers`.
- Applied `2000_01_01_000007_add_seating_lifecycle_columns_to_seat_allocations`, so the seating lifecycle columns are no longer pending.
- Audited app-owned drift: after removing the published config shadows, ilmu360 no longer keeps local seat/ticket/pass config ownership outside the package tests.
- Audited upstream package ownership: the current `/Users/Saiffil/Herd/commerce/packages/events` checkout still defines `event_ticket_types`, `event_ticket_type_*`, and `event_passes`, so the remaining event-scoped ticket tables are upstream package behavior on this branch, not an ilmu360-local compatibility layer.
- Deleted the app-owned geography wrappers (`Country`, `State`, `District`, `Subdistrict`) plus dead geography admin forms/pages and moved cache/deletion behavior onto package observers.
- Moved `App\Models\MemberInvitation` onto the installed membership model, aligned token handling with the package contract, kept current global invitation behavior by disabling owner scoping on the app wrapper, and removed the admin copy-link affordance that could not work once tokens were protected.

## Current Packet - Organizer Hard Cutover

- [x] Remove model/runtime organizer compatibility aliases so organizer reads resolve only from `event_involvements`
- [x] Update `EventOrganizerInvolvementSyncTest` to assert the hard cutover contract
- [x] Re-index the repo graph and inspect the advanced builder organizer flow for remaining `organizer_type` / `organizer_id` dependencies
- [x] Refactor the advanced builder flow to use the canonical organizer selection without legacy organizer fields
- [x] Update focused advanced-builder Pest coverage for the new organizer contract
- [x] Verify with `vendor/bin/pest --parallel --compact --filter=EventOrganizerInvolvementSyncTest`
- [x] Verify with `vendor/bin/pest --parallel --compact --filter=AdvancedEventCreationTest`
- [x] Verify with `vendor/bin/phpstan analyse --ansi`

## Review - Organizer Hard Cutover

- Removed the model-level `organizer_type` / `organizer_id` compatibility accessor path so organizer reads now come only from `event_involvements`.
- Tightened the organizer bridge regression coverage to reject legacy-metadata reads when no organizer involvement exists.
- Graph-backed inspection of the advanced builder path found no remaining `organizer_type` / `organizer_id` dependencies; the flow now runs on canonical `primary_organizer_id` state.
- Focused organizer verification passes with `EventOrganizerInvolvementSyncTest` and `AdvancedEventCreationTest`.
- Full `vendor/bin/phpstan analyse --ansi` now passes, so the organizer hard-cutover packet no longer carries an open verification gap.

## Current Packet - 360 Degree AIArmada Adoption Audit

- [x] Inventory the current runtime surface with route ownership, endpoint owners, and package-provided entrypoints
- [x] Inventory every installed `aiarmada/*` package from `/Users/Saiffil/Herd/commerce/packages/*` with native capability notes
- [x] Measure actual app-level adoption across controllers, actions, services, models, Livewire, Filament, API, MCP, and support layers
- [x] Classify gaps as `Adopted`, `Partial`, `App-Owned By Design`, `Pending Adoption`, or `Missing Native Package Fit`
- [x] Produce a rich HTML audit report that includes a machine-readable tracking payload for future agents
- [x] Validate the report against `php artisan route:list --json`, Composer package state, and the existing adoption program docs

## Review - 360 Degree AIArmada Adoption Audit

- Added `scripts/generate_aiarmada_adoption_audit.php` to generate a repeatable package-adoption audit from live Composer, route, Graphify, and code-reference data.
- Generated `docs/aiarmada-adoption/aiarmada-360-audit.html`, including a rich HTML report plus embedded JSON payload for future agent updates.
- Verified the report against live route ownership (`php artisan route:list --json`), installed `aiarmada/*` package state (`composer show 'aiarmada/*' --format=json`), Graphify artifacts, and the existing `docs/aiarmada-adoption/*` program documents.
- Highest-signal gaps captured in the report:
  - communications remains the biggest mixed-ownership domain
  - addressing still leaks legacy geography vocabulary through API contracts
  - 15 app models still wrap package models
  - 18 package config files remain as upgrade-drift surface
  - inventory/seating/ticketing are runtime-live but thin in product code
