# Fix /majlis package Venue address lookup

## Plan

- [x] Reproduce the undefined `primaryAddress()` call through the public event index.
- [x] Add regression coverage for schedule-location venues rendered by `/majlis`.
- [x] Use the canonical address contract for both app and package venue models.
- [x] Run focused tests, view compilation, static analysis, and diff checks.

## Review

The public schedule discovery boundary now bulk-resolves package event-location
venue IDs through `App\Models\Venue`, preserving the application's address
relations and morph-map behavior for public cards. The Blade card only calls
`primaryAddress()` on the application subclass, while event-level and
schedule-level venue relations remain available for display.

Verification: `tests/Feature/PublicScheduleDiscoveryTest.php` passed 4 tests /
27 assertions; PHPStan passed on changed services; Pint, Blade view cache,
syntax checks, and `git diff --check` passed. The adjacent `PublicPagesTest`
had 28 passing tests and 3 unrelated pre-existing failures in poster aspect,
Threads icon, and contribution-link assertions.

# Event occurrence publication invariant

## Plan

- [x] Require an occurrence before event approval/public reachability.
- [x] Exclude occurrence-less events from public discovery and search fallbacks.
- [x] Prevent deleting the last occurrence from a published event.
- [x] Add focused regression coverage and verify formatting/static analysis.

## Review

Draft events may remain occurrence-less while being assembled, but the central
approval transition now rejects publication until at least one occurrence exists.
The same invariant is applied to the Event model's public reachability/search
contracts, API and Livewire public listings, Typesense/Postgres hydration,
directory counts, related events, and calendar export. Public save, going,
registration, and check-in actions also refuse orphaned event containers.

Published events cannot delete their final occurrence through the model delete
path; deleting an occurrence is still allowed when another occurrence remains.
Occurrence save/delete observers invalidate the public listing cache and
reconcile the event search index.

Verification: occurrence invariant coverage passed 4 tests / 15 assertions;
occurrence observer coverage passed 4 / 4; public visibility coverage passed
14 / 14; Event Save passed 12 / 62; Event Going passed 10 / 55; registration
safety passed 4 / 13; targeted PHPStan passed with no errors; Pint, Blade view
cache, and targeted git diff checks passed.

# Public schedule-unit discovery

## Plan

- [x] Map the current event index/detail seams and occurrence/session URL fields.
- [x] Make /majlis list sessions when present, otherwise occurrences.
- [x] Add nested event/occurrence/session routes and public page resolution.
- [x] Preserve /majlis/{event} as the programme hub with all occurrences/sessions.
- [x] Add regression coverage and verify UI, formatting, static analysis, and views.

## Review

`/majlis` now discovers public schedule leaves: meaningful public sessions are listed individually, while occurrences without meaningful sessions remain discoverable as occurrence cards. Each result uses session → occurrence → event cover fallback, schedule-owned timing/location/speaker data, and a scoped nested URL.

The public routes now support:

- `/majlis/{event-slug}` as the programme hub.
- `/majlis/{event-slug}/{occurrence-slug}` as the occurrence page.
- `/majlis/{event-slug}/{occurrence-slug}/{session-slug}` as the session page.

Occurrence and session resolution is scoped through the parent event/occurrence, with deterministic fallback slugs when package records do not have a slug. Private or incomplete child schedules are excluded from public discovery and nested pages. Existing event-level saving remains event-owned, while result navigation and sharing identify the actual schedule leaf.

Verification:

- `tests/Feature/PublicScheduleDiscoveryTest.php` — 3 passed (23 assertions).
- PHPStan on all changed PHP files — no errors.
- Pint, Blade view cache, route listing, and `git diff --check` — passed.
- Existing broader event-search/public-page suites retain unrelated pre-existing failures in the dirty worktree; the new schedule discovery coverage and changed PHP/static-analysis scope pass.

# Space model remediation

## Plan

- [x] Centralize catalog and venue-owned space eligibility
- [x] Fix selectors, event validation, and scoped slug uniqueness
- [x] Add taxonomy propagation, capacity overrides, and historical snapshots
- [x] Add venue-scoped admin space management and remove duplicate package resources
- [x] Add regression tests and complete formatting/static-analysis verification

## Review

Audited and corrected the clean-cut contract: catalog rows use venue_id IS NULL,
venue-owned rows are venue-scoped, all event write paths use the shared eligibility
resolver, and both contribution forms expose catalog plus same-venue spaces.
Scoped slug indexes and snapshots now live in the package's canonical create-table
migrations. Taxonomy validation, institution pivot capacity overrides, historical
location snapshots, referenced-space delete protection, and the app-owned venue
resource are in place. Generic package changes contain no app policy or
compatibility aliases.

Verification: remediation suite passed (6 tests / 28 assertions), submit-location
tests passed (7 / 23), admin dashboard passed (4 / 13), admin resource coverage
passed (4 / 35), application PHPStan passed (975 files), package PHPStan passed,
Pint passed, Blade view cache passed, and both repositories passed git diff checks.

# Event seeder upgrade and failure cleanup

## Plan

- [x] Make every event seeder explicitly provision canonical catalog spaces and taxonomy before writing locations.
- [x] Ensure seeded institution and venue events persist valid spaces, taxonomy IDs, and location snapshots.
- [x] Remove stale test expectations and invalid media fixtures exposed by the upgraded contracts.
- [x] Remove the addressing seeder's full-file memory spike from the test path.
- [x] Run focused seeder/API/UI verification, PHPStan, formatting, and diff checks.

## Review

Event seeders now call the idempotent `SpaceSeeder`; catalog seeding is scoped by
`venue_id IS NULL`, and event-owned venue spaces remain venue-scoped. Seeded
locations now exercise the canonical taxonomy and historical snapshot fields.
The addressing city filter reads the compressed JSON stream incrementally, so
the production seeder test no longer exceeds the worker memory limit. Stale
person media, poster-ratio, time-state, import, and hard-coded media-path test
expectations were updated to the current codebase contract.

Verification: seeder and space remediation tests passed; production seeder
coverage passed 4 tests / 11 assertions; admin API passed 81 tests / 1,091
assertions; focused frontend media/time coverage passed; PHPStan passed 975
files; Pint and diff checks passed. The full parallel run reached the
Scramble documentation worker, which exceeded its request timeout before the
test-only console timeout guard was corrected; mocked Scramble cache-path tests
now pass (3 tests / 22 assertions). A cold full Scramble generation remains
CPU-bound in this environment and was not allowed to continue concurrently.

# Task: Optimize penceramah edit loading

## Current Task: Tolerate incomplete Google geography

- [x] Resolve Google subdivisions through the provider hierarchy when the district is omitted.
- [x] Recover the district ancestor and keep normal form hierarchy strict.
- [x] Add focused regression coverage and run verification.

## Review

The generic hierarchy traversal now lives in
`aiarmada/addressing` as `AddressAreaHierarchyResolver`. It supports arbitrary
provider-defined depth through `ancestorsOf()` and typed ancestor selection
through `ancestorOfTypes()`. Provider roles can also resolve non-administrative
branches such as Federal Territory `postal_locality` nodes. The Google place
resolver only supplies provider-derived names/types/roles and consumes the
package result. The normal provider-backed form cascade remains strict and
unchanged.

Verification: `vendor/bin/pest --parallel
tests/Unit/ResolveGooglePlaceSelectionActionTest.php` (8 tests / 46
assertions), Pint, application PHPStan, package PHPStan for the new resolver,
and `git diff --check` passed.

## Current Task: Speaker profile repeaters

- [x] Add alternate-name repeater to the speaker update form.
- [x] Replace single affiliated institution editing with an optimized repeater.
- [x] Update quick-add institution language and address fields.
- [x] Run focused regression coverage and static analysis.

## Review

Verification: `vendor/bin/pest --parallel tests/Feature/ContributionPagesTest.php` (59 tests / 403 assertions), Pint, PHPStan on all changed PHP files, and `git diff --check` passed.

## Current Task: Improve speaker contact section

- [x] Locate the public “Hubungi Penceramah” section.
- [x] Add contact-type icons and improve contact card hierarchy.
- [x] Verify Blade rendering and focused UI coverage.

## Review

The public speaker contact cards now show type-specific icons for phone,
WhatsApp, and email, with a neutral link fallback for other contact types.
Each card keeps its existing destination/value while gaining clearer hierarchy
and hover feedback.

Verification: `php artisan view:cache`, `git diff --check`, live response check
for `/penceramah/idris-ahmad`, and `vendor/bin/pest --parallel
tests/Feature/PersonShowSocialPlacementTest.php` (6 tests / 25 assertions)
passed.

- [x] Trace the speaker edit route/component, query dependencies, and family-name field.
- [x] Establish a reproducible baseline for request timing/query count and add regression coverage.
- [x] Implement query optimization and fix family-name loading.
- [x] Run focused tests, PHPStan, and targeted verification.

## Review

The person update form now hydrates `family_name`. Initial page load no longer
preloads every institution and title record; both catalogs use bounded,
search-backed queries and selected-value label lookups. The initial profile
query inventory was 34 queries and included an unbounded institution catalog;
the catalog path is now deferred to user search/selection.

Verification: `vendor/bin/pest --parallel tests/Feature/ContributionPagesTest.php`
passed 56 tests / 394 assertions; Pint passed; PHPStan passed all 955 files;
all modified PHP files pass syntax checks.

## Media hydration follow-up

- [x] Trace repeated Spatie media relationship hydration in direct-edit fields.
- [x] Override the upload relationship loader to use `loadMissing('media')`.
- [x] Avoid forced media reloads while comparing direct-edit changes on save.
- [x] Re-run person media functional coverage and static checks.

Result: the direct-edit upload fields retain the vendor behavior while reusing
the loaded media relationship and keeping preview URL resolution safe during
Livewire hydration. Focused media coverage passed 4 tests / 28 assertions;
Pint and PHPStan passed for the changed implementation files.

## Admin and shared media audit

- [x] Verify the admin person edit form uses the shared optimized loader.
- [x] Inventory all application `SpatieMediaLibraryFileUpload` usages.
- [x] Confirm the admin person form hydrates five media collections with one media query in isolation.

The same provider-level optimization covers person, institution, reference,
event, venue, report, inspiration, series, donation-channel, membership
evidence, and submission forms. Admin person tests passed 5 tests / 21
assertions; the person tab test passed; Pint and PHPStan passed.

## Institution label hydration

- [x] Reproduce the missing selected institution on the speaker update form.
- [x] Separate institution search visibility from selected-value label lookup.
- [x] Add regression coverage for non-public existing affiliations.

The form now displays an existing affiliated institution even when it is not
currently in the public verified/pending search catalog. Full contribution
coverage passed 58 tests / 405 assertions.

## Institution dropdown search

- [x] Reproduce the dropdown and search behavior through the browser.
- [x] Fix case-sensitive institution and alternate-name matching.
- [x] Verify lowercase `masjid` search returns live institution options.

Browser verification returned institution options for lowercase `masjid` with
no console errors. Institution-focused contribution coverage passed 21 tests /
126 assertions; Pint, PHPStan, and diff checks passed.

## Institution dropdown initial options

- [x] Reproduce the empty list when opening the institution field without typing.
- [x] Add a bounded initial list while preserving server-side search.
- [x] Verify opening and selecting an institution directly in the browser.

The institution catalog now shows bounded initial options immediately on click
and successfully selects an institution without requiring prior text entry.

## Institution search latency

- [x] Trace `/institusi?search=shah+alam` from route to search service, SQL, eager loads, and render.
- [x] Capture baseline query count, timings, and PostgreSQL query plan.
- [x] Remove the redundant scoped-ID round trip from direct search pagination.
- [x] Cache the country catalog and default country lookup through the existing address catalog cache.
- [x] Add focused query-count regression coverage and verify the Livewire page.
- [x] Review Livewire deferred/island loading behavior against official documentation.

## Review

The direct institution search now applies the current location scope, directory ordering, page slice, and total in one hydration query. The total is read from `COUNT(*) OVER ()`; only an out-of-range page falls back to a count query. The country selector and default country resolution reuse the existing address catalog cache.

## Institution search interaction pass

- [x] Create a checkpoint commit before continuing; no stashes were present.
- [x] Validate a deferred results island against the existing public-page contract.
- [x] Keep initial result HTML server-rendered while isolating subsequent result updates in one island.
- [x] Add stable institution card keys and a persistent result wrapper for Livewire morphing.
- [x] Remove the redundant `active()` status predicate from verified public search queries.
- [x] Verify institution rendering, fallback search, Blade compilation, formatting, and PHPStan.

The full deferred island was not retained: it replaced the initial public result
HTML with a skeleton and broke the directory's server-rendered result contract.
The retained design keeps the batched hydration query and existing loading
skeleton, while stable keys and transition wrappers improve search/filter
interactions without sacrificing first-response content.

# Task: Livewire 4 optimization review (2026-08-02)

- [x] Index and map Livewire pages/components, routes, config, and hotspots.
- [x] Read the requested Livewire 4 documentation topics through Context7.
- [x] Compare hydration, navigation, loading, lazy/island, pagination, URL, and JS patterns against the app.
- [x] Validate candidate optimizations with focused code/config/test checks.
- [x] Record prioritized recommendations and review evidence.

## Review

Completed. Public result HTML remains SSR-first; `always` islands isolate result updates without deferring the first response. Stable result keys, transition wrappers, and expanded loading targets cover search, sorting, pagination, filters, and saved-state updates.

## Penceramah and majlis optimization pass (2026-08-02)

- [x] Baseline the four public SSR paths and inspect their database query shapes.
- [x] Update the Laravel query optimization skill with the public Livewire listing lessons.
- [x] Create and validate the Livewire query optimization skill with SSR, hydration, island, loading-state, and morphing guidance.
- [x] Remove redundant verified-status scopes from public person and reference search paths.
- [x] Remove duplicate pivot predicates already supplied by `withPivotValue()` on event speakers and references.
- [x] Eager-load event classification terms to prevent card-level lazy-loading.
- [x] Memoize the event paginator for the current Livewire request so saved IDs do not re-run event hydration.
- [x] Add SSR-first result islands, stable event keys, transitions, reserved result height, and loading targets for both directories.
- [x] Add regression coverage for the verified predicate and event paginator reuse.
- [x] Run PHPStan, Pint, Blade view compilation, focused tests, and isolated HTTP smoke checks.

### Review and audit

- Baseline captured before this pass: `/penceramah` 12 queries / 40.65 ms DB time; `/majlis` 29 queries / 122.70 ms DB time cold; `/majlis?search=halaqah` 33 queries / 103.57 ms DB time.
- Post-change isolated smoke checks: `/penceramah` 12 queries / 21.40 ms; `/penceramah?search=Samad` 5 queries / 5.38 ms; `/majlis` 29 queries / 64.69 ms; `/majlis?search=halaqah` 30 queries / 52.39 ms. Wall-clock and cache state are environment-sensitive, so SQL-shape and regression assertions remain the primary acceptance criteria.
- No new tracking event was needed: this pass changes loading, hydration, and DOM stability for existing search/filter intent rather than introducing a new user workflow.
- Remaining opportunity: measure browser-side interaction latency with production-sized data and a real authenticated session before considering deferred result islands, since fully deferred public results would remove useful initial HTML.

## Livewire 4 documentation review (2026-08-02)

### Already aligned with Livewire 4

- Homepage lower sections already use `lazy.bundle` / `defer.bundle` and matching `@placeholder` views.
- Search/list pages already use `#[Computed]`, `#[Url]`, `WithPagination`, stable `wire:key` values, targeted `wire:loading`, and `wire:navigate`.
- The events, institutions, and persons lists already use islands around result regions; the submit-event editor already uses `wire:ignore` for third-party DOM ownership.
- `Route::livewire()` and v4 config keys (`component_layout`, `component_placeholder`, `smart_wire_keys`) are already in place.

### Prioritized opportunities

1. **High — isolate event-detail engagement actions.** The event detail view and eager relation graph are broad; move action controls into a child component or named island while preserving authorization and outcome tracking.
2. **High — reduce filter request frequency on the events index.** Keep immediate updates only where cascades require them; debounce or batch range and multi-select filters after measuring request/query latency.
3. **Medium — split/defer below-the-fold event detail data.** Defer galleries, announcements, and related context while keeping SEO-critical event information in the initial response.
4. **Medium — reduce unified-search fan-out.** Consider a slightly longer debounce, a minimum query length, or explicit submit mode on slower connections.
5. **Low — fix the homepage institution count query.** Replace PHP-side `pluck()->unique()` with a database-side distinct count.

### Verification

- Livewire 4 docs were reviewed for lazy/defer/bundling, islands/nesting, loading, computed properties, navigation, URL state, JavaScript, pagination, and directives.
- PHP syntax and focused Livewire tests passed; two existing asset assertions still expected `/flux/flux.js` to be absent.

# Majlis filters audit

## Plan

- [x] Inspect project guidance, package contracts, and the `/majlis` filter/query implementation
- [x] Map every current filter to the adopted Events and Addressing schema; identify obsolete, missing, and ambiguous user-facing filters
- [x] Implement the filter/query/UI changes with focused tests and tracking review
- [x] Run focused tests, PHPStan, schema/legacy scans, and verify the rendered route behavior
- [x] Document the audit and verification results in this file

## Review

### Canonical filter mapping

- Schedule/date/time filters now query the package `event_occurrences` primary occurrence, using the package ordering (`starts_at`, `created_at`, `id`) rather than event-table date columns.
- Country, state, and city use `addresses.country_id`, `addresses.state_id`, and `addresses.city_id`.
- Administrative geography uses `address_area_assignments` keyed by package roles: `administrative_division`, `administrative_district`, `administrative_subdivision`, and `postal_locality`. There are no `admin_area_1_id` or `admin_area_2_id` fields.
- Location filters are evaluated against the event's owning address: the primary institution address when `institution_id` is set, otherwise the primary venue address from `default_venue_id`; all state/city/area criteria stay on that one address.
- Categories and knowledge fields use the Events package classification/taxonomy relations. The obsolete `topic_ids` contract is replaced by `discipline_tag_ids`.
- Audience, speaker/PIC roles, references, languages, links, delivery mode, and event categories remain relation/column-backed by the adopted Events schema.

### Product decisions

- Kept the useful filters users need to find a majlis: date scope/range, time mode/prayer relation, geography, institution/venue, people and roles, disciplines/domains/sources/issues, references, audience, languages, delivery, URLs, and distance.
- Added city, division, postal/locality, and “has end time” support.
- District is hidden when the selected geography profile does not provide it (federal territories); locality/subdivision remains available.
- Removed the duplicate advanced-filter component and its event synchronization bridge; the page form is the single filter state owner.
- Retained high-signal Signals attributes on filter controls and sort changes; no blanket click tracking was added.

### Verification

- `vendor/bin/pest --parallel tests/Feature/EventSearchTest.php --compact`: 90 passed, 311 assertions.
- `vendor/bin/pest --parallel tests/Feature/EventSearchTypesenseFilterTest.php --compact`: 10 passed, 20 assertions.
- `vendor/bin/pest --parallel tests/Feature/SavedSearchPageTest.php --compact`: 24 passed, 95 assertions.
- `vendor/bin/pest --parallel tests/Feature/SavedSearchApiTest.php --compact`: 26 passed, 102 assertions.
- `vendor/bin/pest --parallel tests/Feature/Api/EventApiContractTest.php --compact`: 29 passed, 193 assertions.
- `vendor/bin/pest --parallel tests/Unit/EventTest.php --compact`: 9 passed, 56 assertions.
- `vendor/bin/phpstan analyse --ansi`: passed with no errors.
- `php artisan route:list --path=majlis`: confirmed the public listing route.
- Live requests to `https://ilmu360.test/majlis` and a real country/state-filtered URL returned HTTP 200; rendered HTML contains canonical filter state and no obsolete event filter keys.
- A request using the removed API key `filter[administrative_district_id]` returns HTTP 400 (`filter not allowed`), confirming no backward-compatibility alias remains in the event search contract.
- `git diff --check` and modified PHP syntax checks passed.

## Review update: event location ownership

- Read the installed `aiarmada/events` migrations and models: `events.default_venue_id` is package-owned; `event_locations.venue_id` and `event_locations.venue_space_id` describe the concrete venue/place rows; the package has no `events.venue_id` column.
- The app migration `2026_07_18_000002_add_institution_id_to_events.php` therefore adds the missing alternate location owner only. No second venue column should be added.
- Institution-owned events resolve public geography, nearby distance, prayer coordinates, and card address display from `institution_id`. Venue-owned events resolve them from `default_venue_id`. A `VenueSpace`/`Space` is a specific place detail and does not replace the owning address.
- Updated searchable payloads, database location predicates, nearby SQL, event-card eager loading/display, and search-index invalidation to follow that ownership rule.
- Added regression coverage for an institution event using a space linked to a different venue, canonical event schema columns, and institution-based prayer coordinates. Updated an invalid dual-owner nearby fixture and a stale test call that still used removed geography parameter names.
- Final verification: EventSearchTest 90/311, EventSearchTypesenseFilterTest 10/20, EventApiContractTest 29/193, SubmitEventLocationTest 7/23, EventTest 9/56, PHPStan 964 files with no errors, `git diff --check`, route resolution, live `/majlis` HTTP 200, and live schema inspection showing only `default_venue_id` + `institution_id` (not `venue_id`).

## Review update: real Chrome verification

- Chrome MCP opened `https://ilmu360.test/majlis` and exercised the rendered filters, not only HTTP/backend requests.
- Country → state → city cascades selected Malaysia, Selangor, and Petaling Jaya and produced canonical `country_id`, `state_id`, and `city_id` URL parameters.
- Institution selection produced an `institution_id` URL filter after applying and venue selection produced a `venue_id` URL filter backed by the canonical `events.default_venue_id`; selecting an event-backed Balai Islam venue reduced the visible result count to 1.
- The UI rendered no legacy geography keys or labels. Division/district/subdivision controls are data-dependent and were absent because the current local dataset returned no matching address-area options.
- Chrome console error logs were empty after the exercised filter flows.
- Browser review found duplicate same-name institution options (for example, two Akademi Tahfiz Ar-Rahman records in different cities). The IDs map correctly, but labels need locality context before the picker is fully understandable to users.

# Expose hidden Person data in admin View page

## Context
- `ViewPerson` extends package `filament-persons` `PersonInfolist` which shows only Identity (name, family_name, middle_name, gender, date_of_birth, status).
- The edit form has 5 tabs of data the view hides: Profil (bio, languages, titles), Media (avatar/main/profile/cover/gallery), Lokasi (address), Hubungan (contactMethods, socialProfiles), Status (speaker_status, allow_public_event_submission, lifecycle timestamps).
- Hidden relationships: CredentialAssignments (filtered out of `getRelations()` in app PersonResource since refactor 46a20fdd; pre-refactor app had its own RM), MemberInvitations (permission-gated via `person.manage-members` — intentional, leave).
- Data for the seeded person (Azhar): 4 names, 1 title, 1 address, 3 social profiles, 10 events, 1 member, 1 report, speaker_status=active, allow_public_event_submission=true, bio (TipTap JSON).
- `InstitutionInfolist` is the established rich-infolist pattern (Malay labels, Tabs). Its `address.*` (singular) entries DON'T resolve (no singular relation) — use `primaryAddress()` state closures instead.
- Owner context: `EditPerson`/`ViewInstitution` wrap `OwnerContext::withOwner(null, ...)`; package `ViewPerson` doesn't — needed once contactMethods/socialProfiles render in the infolist.

## Plan
- [ ] Create `app/Filament/Resources/Persons/Schemas/PersonInfolist.php` (tabs: Profil, Media, Lokasi, Hubungan, Status, Statistik) modeled on InstitutionInfolist
- [ ] Wire `infolist()` override in app `PersonResource`
- [ ] Re-add package `CredentialAssignmentsRelationManager` to `getRelations()`
- [ ] Add `OwnerContext::withOwner(null, ...)` wrapping to admin + ahli `ViewPerson` pages
- [ ] Add focused pest test asserting view page shows hidden data
- [ ] Run pint, phpstan, focused tests; verify page in browser

## Review update: event seeder venue spaces

- Added the shared `SeedsEventLocations` seeder concern used by `EventSeeder`, `AdvancedEventSeeder`, and `SpeakerEventSeeder`.
- Institution-owned seeded events now receive an institution-linked `Dewan Utama` space; venue-owned seeded events receive a venue-owned `Dewan Utama` space. Both persist through `event_locations.venue_space_id`; online events remain without a physical location.
- `EventSeeder` backfills missing spaces and keeps venue-owned spaces valid instead of clearing them as if spaces were institution-only.
- Focused verification: `AdvancedEventSeederTest` passed 2 tests / 18 assertions; PHPStan passed 966 files; the final local `EventSeeder` run produced 223/223 physical events with spaces, 15/15 venue events with matching spaces, and 0 online events with spaces.
- Browser verification: Chrome rendered `Dewan Utama` on `/majlis` event cards and on a venue-owned event detail page alongside its venue; console errors were empty.
- The existing `ProductionSeederTest` remains blocked by its unrelated 512 MB memory exhaustion in `AddressingSeeder` while decoding the city fixture.

## Follow-up: institutions

- [x] Audit InstitutionInfolist vs hidden data (address.* broken — only primaryAddress() closures resolve; no Status tab; missing names/languages/display_name/reports_count)
- [ ] Rewrite InstitutionInfolist: Profil (display_name, names, languages), fix Lokasi via primaryAddress(), add Status tab (lifecycle + submission lock + created/updated), add reports_count to Statistik
- [ ] Add focused test for institution view infolist
- [ ] Run pint, phpstan, focused tests; verify page in browser
# Task: Rebuild the public event detail view around the AI Armada domain model

## Plan

- [x] Map the current public event route, Livewire component, view, and eager-loaded relationships.
- [x] Audit the installed AI Armada packages and their event-facing contracts (addressing, events, communications, references, persons, contacting, engagement, seating, ticketing, membership, signals, moderation, inventory).
- [x] Define and implement a coherent event detail information architecture with responsive, accessible UI.
- [x] Add or update focused regression coverage for the public event detail surface.
- [x] Run focused tests, browser verification, PHPStan/Pint where applicable, and review the final diff.

## Review

Audited the installed `aiarmada/*` packages as one event-domain graph. The event aggregate remains the public page's root; addressing supplies venue/institution address hierarchy and navigation data, persons supply identities and event involvements, references supply source citations, and contacting supplies organizer contact methods. Engagement supplies bookmark/response/reminder/share actions, while ticketing and seating contribute only when public ticket types or active seat maps exist. Communications, moderation, Signals, inventory, membership, affiliates, authz, and commerce-support remain supporting infrastructure rather than invented page content.

Rebuilt the page as a programme dossier with a date/reference-led hero, schedule timeline, compact speaker and role identities, references, address/map/contact rail, registration/ticket/seating states, related events, sharing, and guest-safe engagement actions. The view now reads relationship-owned data instead of duplicating package concepts.

Verification:

- `vendor/bin/pest --parallel tests/Feature/EventShowPageTest.php --compact` — 30 passed (89 assertions).
- Focused event-search detail contracts — 6 passed (17 assertions).
- `vendor/bin/pest --parallel tests/Feature/SignalsIntegrationTest.php --compact` — 7 passed (35 assertions).
- PHP syntax, Blade view cache, PHPStan, Pint, browser rendering, and console checks passed.

# Follow-up: occurrence and session cover media

## Plan

- [x] Add first-class `cover` media collections to event occurrences and sessions while preserving package event-media records.
- [x] Load public occurrence/session covers and render every public occurrence with all of its public sessions.
- [x] Add resilient cover inheritance and designed fallbacks for image-free schedules.
- [x] Add regression coverage and verify desktop/mobile rendering.

## Review

`EventOccurrence` and `EventSession` now implement Spatie media support with single-file `cover` collections. Their existing package-owned `EventMedia` relations are preserved as `mediaRecords`, and the application morph map now includes `event_occurrence` and `event_session` for media persistence.

The public event schedule now displays all public occurrences and nested sessions, ordered by session `sort_order`, with cover priority `session → occurrence → event` and a typographic fallback when no cover exists.

Verification:

- `vendor/bin/pest --parallel tests/Feature/EventShowPageTest.php --compact` — 31 passed (102 assertions).
- PHPStan, Pint, Blade view cache, syntax checks, diff checks, desktop/mobile browser review, and console checks passed.

# Follow-up: admin edit action on the public event page

## Plan

- [x] Mirror the existing speaker/institution admin edit affordance.
- [x] Restrict the control to `super_admin` and `admin` viewers.
- [x] Add focused authorization regression coverage and verify the public page.

## Review

Added an amber `Edit` action that opens the package-owned Filament event edit page in a new tab. Non-admin viewers do not receive the link or its URL.

Verification:

- Focused event edit contract — 2 passed (7 assertions).
- PHPStan, Pint, Blade view cache, diff checks, browser reload, and console checks passed.

# Follow-up: relationship-aware admin event resources

## Plan

- [x] Map the package-owned event, occurrence, and session resource seams.
- [x] Add app-level event context fields and relationship managers for references, ticket types, and seat maps.
- [x] Add independent occurrence/session cover uploads, schedule fields, and ticket/seat relationship managers.
- [x] Add focused admin resource regression coverage.
- [x] Run focused tests, PHPStan, Pint, syntax, and diff verification.

## Review

The admin Event resource now exposes the event domain graph through its existing extension seam: institution/venue context, speaker involvements, references, schedule type/timezone, delivery links, audience rules, and the existing event media collections. Event-level References, Ticket Types, and Seat Maps are now manageable from relationship tabs. The same ticket and seat-map managers are available from occurrence and session resources.

Occurrence and session resources now support their own 16:9 `cover` collection, optimized `thumb` conversion, timezone, delivery mode, capacity, and session ordering. Existing package event-media records remain available through their original `mediaRecords` relationships.

Verification:

- `vendor/bin/pest --parallel tests/Feature/FilamentEventResourceTest.php --compact` — 2 passed (31 assertions).
- `vendor/bin/phpstan analyse --ansi` — 979 files, no errors.
- Pint passed for all application/config/test files and the linked package files.
- PHP syntax checks and `git diff --check` passed.
- Chrome reached the supplied admin URL but the current browser context was unauthenticated and redirected to the admin login page; authenticated resource rendering is covered by the focused feature tests.

# Follow-up: location-picker fix review

## Plan

- [x] Audit every claim in `docs/location-picker-fix-review.md` against current code and runtime contracts.
- [x] Trace all picker entry points, hierarchy cascades, hidden-field dehydration, and persistence paths for additional gaps.
- [x] Add regression coverage for every confirmed defect and edge case.
- [x] Implement fixes without disturbing unrelated dirty-worktree changes.
- [x] Run focused verification, static analysis, formatting, a bounded full-suite attempt, and document the result.

## Review

Verified the three reported fixes against the resolver, Filament state dehydration, and address persistence paths. Found and fixed an additional gap in `SubmitEvent/Create`: its duplicate picker handler omitted the area-assignment defaults even though nested event location forms use the shared area fields. It now reuses `InteractsWithLocationPickerSelection`, with a regression test covering the missing-key state.

Focused location suites passed: event location 8 tests / 24 assertions, institution picker 9 / 56, admin infolists 7 / 29, and resolver unit coverage 8 / 46. PHPStan, targeted Pint, Blade view cache, and `git diff --check` passed. A fresh full parallel run was attempted but one worker remained CPU-bound without progress for approximately 27 minutes; it was stopped, so that run has no final consolidated result. The report was corrected in `docs/location-picker-fix-review.md`.
