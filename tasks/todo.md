# Remove baked checkerboard from speaker hero artwork

## Plan

- [x] Inspect the artwork at native resolution and confirm the edge mosaic is encoded in the source image.
- [x] Generate a flat, non-gradient extraction plate and create a real alpha cutout from it.
- [x] Remove disconnected background fragments without damaging the arch, plant, microphone, books, or platform.
- [x] Replace the WebP and verify the live desktop/mobile hero plus the preserved result count.

## Review

The hero artwork now uses a cleaned, alpha-preserving WebP produced from a flat extraction plate and a conservative eroded foreground alpha. The checkerboard fragments around the arch, leaves, and platform are removed; no artwork-side gradient, blend mode, or CSS mask is used. The `29 penceramah ditemui` summary remains visible.

## Verification

- `webpinfo public/images/speakers/penceramah-hero-art.webp` — `Alpha: 1`, canvas `1254 × 1254`, no error.
- Collaborative browser preview at 1280×800 — the cutout loads with transparent corners and renders cleanly in the hero field.
- Collaborative browser preview at 390×844 — the decorative artwork remains hidden and the search/count content remains unobstructed.

# Match speaker hero artwork to the hero field

## Plan

- [x] Confirm the `x penceramah ditemui` summary remains in the results header.
- [x] Regenerate the artwork background using the hero's neutral paper palette.
- [x] Replace the WebP asset and correct its intrinsic dimensions in the markup.
- [x] Recheck desktop and mobile rendering, then run the focused verification suite.

## Review

The speaker count was not removed; it remains rendered from the results summary and displays as `29 penceramah ditemui`. The hero artwork is now an alpha-preserving cutout with no artwork-side gradient or rectangular field, so the hero background shows through naturally around the preserved arch, microphone, books, plant, and soft shadow. The existing decorative-only implementation and responsive mobile fallback remain unchanged.

## Verification

- Collaborative browser preview at 1280×800 — transparent regenerated asset loaded at 1254×1254 and blends into the hero field without an artwork-side background.
- Collaborative browser preview at 390×844 — artwork remains hidden and the search/count content is unobstructed.
- `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --test`, `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php`, and `git diff --check` — passed.

# Add a generated speaker hero illustration

## Plan

- [x] Inspect the `/penceramah` hero structure, styles, and responsive layout.
- [x] Generate and optimize a speaker-themed arch, microphone, books, and plant illustration.
- [x] Place the artwork in the desktop hero with a responsive small-screen fallback.
- [x] Verify Blade compilation, frontend build, focused tests, static checks, and browser rendering.

## Review

The public speaker directory hero now has a dedicated right-side visual built from the ilmu360° palette: a warm plaster niche, emerald microphone, stacked books, and plant. The WebP asset is an alpha-preserving cutout shown as a full right column on desktop, reduced to a quiet tablet accent, and hidden on narrow mobile screens so the search remains the primary action. The artwork is decorative and does not add a new tracking event.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 40 passed (152 assertions).
- `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --test`, and `git diff --check` — passed.
- Collaborative browser preview — artwork loaded from `/images/speakers/penceramah-hero-art.webp` at desktop width; mobile hero kept the artwork hidden and the search unobstructed.
- Full PHPStan still reports the two pre-existing errors in `app/Http/Controllers/Api/EventController.php` and `app/Support/Location/VisitorCountryResolver.php`; no new errors were introduced by this change.

# Align speaker and institution update-form copy

## Plan

- [x] Trace the shared contribution schemas and update-only media fields.
- [x] Correct Malay labels and contextual hints for speaker and institution updates.
- [x] Add regression coverage and run validation checks.

## Review

Speaker and institution update pages now use the corrected Malay name labels and guidance. Speaker owner-only media fields share the localized labels and hints from the public contribution form, while institution names, alternative names, descriptions, addresses, and media fields now provide clear localized guidance.

## Verification

- Pest focused update-form coverage — passed (2 tests, 23 assertions).
- PHPStan — no errors across 1000 files.
- Targeted Pint, PHP syntax checks, translation JSON validation, and diff checks — passed.

# Match speaker involvement cards to event-list information

## Plan

- [x] Trace the event details and relations already used by the upcoming-event cards.
- [x] Render involvement entries with the same event information and a distinct role-focused color treatment.
- [x] Add regression coverage and verify the rendered profile in Chrome.

## Review

Speaker involvement entries now render the same date, time, location, format, category, and event-link information as the upcoming-event cards. The section headings sit above the card list, each entry clearly identifies the person's role, and the cards use a violet/indigo treatment to distinguish them from the emerald upcoming-event listing.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonShowPageTimingTest.php --filter='shows linked non-person roles in a separate section on the person page'` — passed (11 assertions).
- Chrome DevTools — the profile renders two role entries with `Peranan: Moderator/Khatib`, date/time, location, event category, violet/indigo card accents, no console errors, and all page requests return 200.
- `vendor/bin/phpstan analyse --ansi`, `php artisan view:cache`, `npm run build`, `git diff --check`, and targeted Pint — passed.
- The full timing suite has one unrelated existing failure in the federal-territory address formatter assertion; the role-list test passes independently.

# Localize speaker contribution form in Bahasa Melayu

## Plan

- [x] Audit every visible field, action, dependent location label, and enum option on the new speaker form.
- [x] Translate the form copy and add concise guidance for fields whose purpose or optionality is not obvious.
- [x] Add regression coverage for the localized schema and preserve required/default/conditional behavior.
- [x] Verify the rendered form in Chrome, tests, static analysis, syntax, and diff hygiene.

## Review

The new speaker contribution flow now uses Bahasa Melayu consistently across its field labels, actions, enum choices, location hierarchy, media guidance, and nested institution quick-add form. Contextual hints explain the purpose of names, affiliations, contact details, social links, location, biography, and optional media without changing the existing required or conditional rules.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/ContributionPagesTest.php` — 69 passed (484 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 1000 files.
- Targeted Pint, `php artisan view:cache`, `npm run build`, translation JSON validation, PHP syntax checks, and `git diff --check` — passed.
- Chrome verification — all tabs, dependent Malaysian location labels, contact/social options, media guidance, and institution quick-add copy render in Bahasa Melayu; Filament `Search`/`Clear selection` accessibility names are localized too.

# Restore searches for unindexed speaker records

## Plan

- [x] Reproduce `/penceramah?search=dus` in Chrome and trace the local search path.
- [x] Add a database fallback for verified profiles missing local search-term rows, including alternate names.
- [x] Invalidate the cached empty result and add regression coverage.
- [x] Verify the exact URL, no-match behavior, tests, static analysis, and diff hygiene.

## Review

The speaker search index was only populated for part of the verified directory. Searches now retain the indexed path for indexed profiles while checking canonical person and alternate-name fields for profiles without index rows. The public search cache key was versioned so prior empty `dus` responses cannot survive the fix.

## Verification

- `vendor/bin/pest --parallel --compact tests/Unit/SearchServiceFallbackTest.php` — 25 passed (35 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 36 passed (127 assertions).
- `vendor/bin/phpstan analyse --ansi`, targeted Pint, `php artisan view:cache`, and `git diff --check` — passed.
- Chrome verification — `dus` returns `Ustaz Ahmad Dusuki Abdul Rani`, `zzz` remains no-match, and `ka` shows the neutral typing state.

# Prevent false empty state during speaker search

## Plan

- [x] Reproduce the short-query transition in Chrome and confirm the server response state.
- [x] Skip search work below the minimum query length and render a neutral typing prompt.
- [x] Add regression coverage and update the lesson notes.
- [x] Re-test slow typing, real no-match searches, and matching searches in Chrome.

## Review

The directory now treats one- and two-character input as an incomplete search rather than a failed search. The Livewire computed path returns before resolving search IDs, and the empty-state panel renders a localized typing prompt. Three-character matches and genuine no-match searches retain their existing result behavior.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 36 passed (127 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors.
- `php artisan view:cache`, PHP syntax checks, and `git diff --check` — passed.
- Chrome DevTools — `Ka` shows the neutral typing prompt, `Kaz` returns `Ustaz Kazim Elias`, `zzz` shows the genuine no-results state, the performance trace recorded 75ms worst interaction, and no console errors were observed.

# Make speaker directory search snappy

## Plan

- [x] Reproduce live search in Chrome and inspect the Livewire request timing.
- [x] Remove unused catalog resolution and eager loading from the directory render path.
- [x] Reduce the live-search debounce while preserving live results, URL state, and loading markup.
- [x] Run focused regression coverage and re-measure in Chrome.

## Review

The speaker directory no longer resolves unused title/language/state catalogs or eager-loads title assignments on every search update. Live search now waits 150ms instead of 300ms before sending the Livewire update.

Chrome DevTools verification showed the Livewire response application timing drop from 381–415ms before the change to 197ms on the optimized path, with database timing dropping from 42–68ms to 32ms. The search still returns the expected Kazim and Ahmad results, and the performance trace recorded a 24ms INP for the optimized interaction.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/PersonIndexTest.php` — 35 passed (122 assertions).
- Chrome DevTools — live search returns `Ustaz Kazim Elias` and `3 penceramah ditemui` for `Ahmad`; no console errors observed.

# Require country on speaker contribution location and progress

## Plan

- [x] Pass the country-required option through the person contribution schema.
- [x] Enable it for the new speaker submission Location tab.
- [x] Add focused form-schema regression coverage and verify the change.
- [x] Count the default selected country in speaker form progress.

## Review

The new speaker contribution form now marks `address.country_id` as required in the `Lokasi` tab and rejects submission when the country is cleared. Both the server-rendered and Alpine progress calculations include the default country, so the indicator starts at 33% because that required field is already selected. Other person form consumers retain the existing optional-country behavior.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/ContributionPagesTest.php --filter='submission progress|requires a country|selected country'` — 3 passed (12 assertions).
- Targeted PHPStan at level 6 — no errors.
- Targeted Pint check, Blade cache, PHP syntax checks, and `git diff --check` — passed.
- Browser verification — `/sumbangan/penceramah/baru` displays 33% with Malaysia selected; clearing the country updates progress to 0%, and the Location tab shows `Negara*`.

# Fix /majlis package Venue address lookup

# Event submission category vocabulary

## Plan

- [x] Replace the Islamic-specific hierarchical event categories with a flat, activity-first vocabulary.
- [x] Update affected category fixtures and taxonomy tests without retaining legacy category codes.
- [x] Reseed the local taxonomy, clear the category catalog cache, and verify the submission form and focused tests.

## Review

The event category taxonomy is now a flat eight-option activity vocabulary. `Kuliah / Ceramah` is the primary talk category for subjects including Islamic studies, mathematics, science, technology, and IT. The manual form and poster extraction each accept one primary activity type, while the moderation workflow remains responsible for rejecting harmful submissions.

Legacy category terms and their event classifications are removed by the reseeder; no compatibility aliases are retained. The local database was reseeded and the event-category selection cache was busted.

Verification:

- AIArmada taxonomy tests: 10 passed / 30 assertions.
- Poster extraction tests: 2 passed / 23 assertions.
- Public page suite: 30 passed / 213 assertions; 3 unrelated pre-existing failures remain for poster aspect, Threads icon, and contribution-link assertions.
- Targeted PHPStan: passed with no errors.
- PHP syntax and `git diff --check`: passed.
- Pint: the new seeder import order passes; the existing `Create.php` still reports its pre-existing unrelated fixers.

Seeder audit: only `EventSeeder` required category-code behavior updates. `AdvancedEventSeeder` consumes the catalog dynamically, and no other seeder contains legacy event-category codes.

# Optional event topics and fields

## Plan

- [x] Add the broad optional topic vocabulary without conflating it with activity type.
- [x] Update seeded demo event topic defaults and the submission form/extraction labels.
- [x] Reseed topics and verify topic options, free-text detail support, and focused tests.

## Review

Added eight optional broad topics to the existing domain taxonomy: Agama & Kerohanian, Pendidikan, Sains & Matematik, Teknologi & IT, Kerjaya & Kemahiran, Kesihatan, Keluarga & Masyarakat, and Lain-lain / Tulis sendiri. The activity type remains a separate single-choice field, while the existing optional specific-topic field provides free-text detail such as Machine Learning or Matematik.

Updated seeded event defaults, submission-form labels, review copy, and extraction-backed options. Reseeded the Foundation taxonomy and cleared the application cache.

Verification:

- Foundation taxonomy tests — 11 passed (32 assertions).
- Submit-event form coverage — 5 passed (32 assertions).
- AI extraction coverage — 2 passed (23 assertions).
- Advanced event seeder coverage — 2 passed (20 assertions).
- PHPStan targeted analysis, Pint, PHP syntax checks, and `git diff --check` passed.

# Surface topics on public submission and listing filters

## Plan

- [x] Map the shared domain taxonomy through `/hantar-majlis` and `/majlis`.
- [x] Make broad topics visible immediately in both public filter controls.
- [x] Add focused Livewire/listing coverage and verify the filter query path.

## Review

The public `/hantar-majlis` topic field now uses a versioned option cache and explicitly preloads its dynamic option list, and the `/majlis` sidebar has a dedicated `Topik & rujukan` section. Its `Topik / bidang` filter preloads the same eight broad topics, while `Topik lebih khusus` remains searchable for detailed fields. The existing `domain_tag_ids` URL/query contract remains the shared backend filter path.

Verification:

- Public event filter coverage — 3 passed (16 assertions).
- Submit-event topic coverage — 3 passed (18 assertions).
- PHP syntax checks passed; the filter changes use the existing taxonomy cache and search service seams.
- Chrome verified all eight choices in both public controls and successfully applied `Pendidikan` on `/majlis`; no new console errors appeared during the filter interaction.

# Reorder submit wizard topic placement

## Plan

- [x] Place the broad topic immediately after `Jenis Majlis` in the first wizard step.
- [x] Keep specific topics and references in the follow-up step.
- [x] Verify the rendered order in Livewire tests and Chrome.

## Review

The submission wizard now follows the natural sequence `Jenis Majlis → Topik / bidang → Tajuk Majlis`. The broad topic selector is optional and visible early, while detailed topics, sources, issues, and book references remain in `Topik & Rujukan`.

Verification:

- Wizard-order regression — 1 passed (1 assertion).
- AI extraction coverage — 2 passed (23 assertions).
- Targeted PHPStan and syntax checks passed.
- Chrome confirmed the rendered field order.
- The broader PublicPages run had an intermittent existing `mkdir(): File exists` parallel-test setup collision; the isolated order test passed.


# Reusable organizations tenancy

## Plan

- [x] Create and wire `aiarmada/organizations` core package.
- [x] Create and wire `aiarmada/filament-organizations` adapter.
- [x] Hard-cut event organizer naming and table boundary.
- [x] Integrate the package Organization model into ilmu360 without a duplicate app model.
- [x] Add focused package and application tests.
- [x] Run formatting, static analysis, migration linting, Composer audit, and focused parallel tests.

## Review

Implemented reusable organization tenancy, the Filament v5 adapter, the hard-cut
event-organizer rename, application API/workspace integration, and ownership-safe
membership mutation guards. This is a clean-schema cutover: no backfill,
one-time data migration, legacy table rename, runtime alias, or fallback was
added. The audit also corrected event creator mass-assignment, panel-specific
Filament authentication, configurable membership pivot resolution, workspace
context errors and query counts, lifecycle-history retention, fail-closed owner
transfer behavior, idempotent organization migrations, model defaults, and the
required package documentation structure.

Verification:

- Organizations package: 6 parallel tests / 20 assertions.
- Filament adapter: 2 parallel tests / 3 assertions.
- ilmu360 organization API: 5 parallel tests / 14 assertions.
- Advanced event API: 4 parallel tests / 17 assertions, including creator metadata.
- Event rename and migration lint checks: 5 parallel tests / 360 assertions;
  no data migration is included.
- Commerce PHPStan targeted scope: no errors.
- ilmu360 PHPStan (988 files): no errors.
- Pint, Composer validate/audit, package discovery, legacy-reference scans, and
  `git diff --check`: passed.

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

# Organization frontend and ticketed events

## Plan

- [x] Add frontend organization creation and workspace routing.
- [x] Add organization invitations, role management, ownership transfer, and lifecycle controls.
- [x] Add organization-owned event creation with free/paid ticket types and inventory.
- [x] Add optional assigned/general seating setup to the event builder.
- [x] Add focused Livewire and workflow tests, then run formatting and static analysis.

## Review

Added the authenticated organization workspace at `/dashboard/organisasi`: users
can create organizations, invite members by email, change non-owner roles,
remove members, transfer ownership, revoke invitations, and apply visibility or
lifecycle actions. Organization membership is checked on every workspace read
and mutation, and non-members receive a forbidden response.

Added the organization event builder at
`/dashboard/organisasi/{organization}/majlis/cipta`. It creates an
organization-owned draft with UTC-normalized schedule data, free or paid ticket
types, ticket inventory, per-order limits, registration mode, and optional
general-admission, assigned, or hybrid seating maps. General-admission tickets
are connected to their seat sections, while assigned/hybrid maps generate
owner-scoped seats. Paid events cannot disable registration/ticketing, and
seating capacity is validated in the action boundary as well as the form.

The membership subject guard was corrected so global Organization aggregates do
not require an unrelated owner context, while owner-scoped models remain
protected. Required inventory and ticket morph-map entries were also registered
for the ticketing workflow.

Verification: frontend organization suite passed 7 tests / 25 assertions;
organization tenancy passed 5 / 14; advanced event API passed 4 / 17; invitation
UI passed 8 / 22; invitation actions passed 10 / 20; Commerce membership actions
passed 8 / 15; owner isolation passed 2 / 7; organization actions passed 6 / 20;
Pint, Blade view cache, application PHPStan, and Commerce PHPStan passed.

Chrome verification initially exposed the three pending additive package
migrations in the local PostgreSQL database. After applying them, the
authenticated organization index and create form rendered successfully at
`/dashboard/organisasi` and `/dashboard/organisasi/cipta`, with no browser
console errors. No backfill or data migration was run.

## Follow-up: expose organization creation in navigation

- [x] Show the organization creation link to authenticated users before they have an organization.
- [x] Keep organization management navigation conditional on existing membership.
- [x] Add a regression test for the dashboard navigation and verify the rendered link in Chrome.

Review: the original header incorrectly gated the entire organization menu on
`organizations()->exists()`, which made the first-organization workflow
undiscoverable. The create link is now always rendered in desktop and mobile
authenticated navigation, while the management link remains membership-aware.

Chrome end-to-end testing then exposed an omitted `owner` entry in the
application membership role mapping. The owner pivot was created correctly but
could not be resolved during workspace authorization; the mapping and a
frontend create-to-workspace regression assertion were corrected.

The final Chrome pass also covered the live Filament admin resource after
clearing package metadata and restarting Herd services: Organizations appeared
in navigation, the list and record pages loaded, the owner row rendered, the
Members and Invitations relation managers opened, and Make public / Make
private completed through confirmation dialogs. The test organization was
restored to private and Chrome reported no console errors.

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

# Follow-up: simpler free-event submission entry point

## Plan

- [x] Keep the existing manual Livewire form and AI extraction workflow as the implementation seam.
- [x] Make `/hantar-majlis` the canonical destination for manual submissions instead of linking with `mode=manual`.
- [x] Improve the form header, poster-assisted extraction card, stepper treatment, loading states, and submission tracking markup.
- [x] Run focused tests, Blade/static checks, and browser verification.

## Review

The clean `/hantar-majlis` route now opens the same manual submission form previously reached with `?mode=manual`. The form keeps the existing validation, moderation review, media uploads, and AI poster extraction behavior while making the free-submission purpose clearer and the upload path easier to discover.

Verification:

- `vendor/bin/pest --parallel tests/Feature/SubmitEventAiExtractionTest.php --compact` — 2 passed (23 assertions).
- `vendor/bin/pest --parallel tests/Feature/SubmitEventReviewPreviewTest.php --compact` — 4 passed (15 assertions).
- The selected-locale upload-copy test — 1 passed (8 assertions).
- `php artisan view:cache`, targeted PHPStan for `Create.php`, Pint, translation JSON validation, and `git diff --check` passed.
- Browser verification confirmed one form and matching poster-assist UI at both `/hantar-majlis` and `/hantar-majlis?mode=manual`; desktop rendering was reviewed visually.
- The broader public-page and media suites still report unrelated existing failures outside this change: 3 public detail assertions and 1 poster-ratio assertion. They do not touch the updated submit-event view, route entry link, or translation keys.

# Follow-up: adaptive submit-event form flow

## Plan

- [x] Audit the current `/hantar-majlis` Livewire form, field dependencies, defaults, and tests.
- [x] Make event type and broad topic required driver fields, with dependent sections shown only when relevant.
- [x] Add sensible defaults for downstream options and a progress indicator that reflects completed/defaulted form state.
- [x] Ensure Livewire field bindings initialize and the progress indicator advances after the driver selections change.
- [x] Add regression coverage for validation, defaults, conditional visibility, progress, and at least one representative topic path.
- [x] Verify the browser flow on desktop/mobile, console/network health, formatting, static analysis, and focused parallel tests.

## Review

`event_category_ids` and `domain_tags` now act as the required driver selections. Broad topics are limited to three and explain that they control the follow-up questions. Religious context is detected from taxonomy codes, so the Muslim-only audience toggle and prayer-relative time choices appear only for religious events; changing away from that context clears stale religious state and restores a direct start-time default. The review preview now follows the same context, explicit custom times are preserved when the category changes, taxonomy lookups are memoized per Livewire request, organization memberships are eager-loaded for admin role management, and Feature/Browser Pest scopes share the test bootstrap correctly.

Downstream choices begin with useful defaults: physical format, public visibility, all genders, all ages, children allowed, Malay, institution organizer, same-as-institution location, and a sensible start-time fallback. The progress card now counts each required field relevant to the current form state, so valid defaults contribute individually while factual inputs such as title/date remain incomplete. Conditional required fields are added or removed from the denominator as the user chooses a religious time, online delivery, a person organizer, or a speaker-dependent category. Guest contact validation is represented as one name check plus one email-or-phone check, matching the form's conditional rules. Guest contact fields are live, and quick-added titles are normalized server-side so their progress updates cannot depend on generated client-side JavaScript.

The blank form now selects Kuliah / Ceramah and Agama & Kerohanian by taxonomy code. Because that topic is religious, the initial prayer-time default is Selepas Maghrib and the custom-time field remains empty until Lain Waktu is chosen.

The standalone CSS block was moved into the layout head stack so the Livewire component has one actual root element. Before that, the style tag became the component root and the form's `wire:model.live` bindings were rendered inert in the browser.

Verification:

- `./pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 10 passed (59 assertions).
- `./pest --parallel --compact tests/Feature/RefactorTest.php` — 3 passed (23 assertions).
- `./pest --parallel --compact tests/Feature/AuthzUserResourceTest.php` — 8 passed (57 assertions).
- `./pest --parallel --compact tests/Browser/PlaywrightSmokeTest.php` — 1 passed (2 assertions).
- Targeted PHPStan for the changed Livewire component and adaptive-form test — no errors.
- Targeted PHPStan, Pint, PHP syntax checks, Blade view cache, and git diff --check passed.
- Browser verification confirmed the Livewire root is the form container, category + broad-topic selection produced live requests, and Chrome MCP completed a real form path from 53% to 67% to 73% to 80% to 85% and finally 100% after organizer, title, date, and guest contact fields were filled.
- The quick-add title path previously produced a generated-script syntax error and left the visible value out of Livewire state; server-side normalization removed that error. The final Chrome 100% path reported no console errors and the form was not submitted.
- A fresh full `./pest --parallel --compact` run emitted failures but was stopped after approximately 27 minutes before a consolidated result; targeted suites above are the completed verification.

## Follow-up: client-side progress research

Context7's Filament 5 documentation confirms that `afterStateUpdatedJs()` runs in the browser with `$state`, `$get()`, and `$set()` without a Livewire request. The current progress section is Blade-rendered from `formProgress()`, so replacing `live()` with `afterStateUpdatedJs()` alone would not update it; the progress markup must also move to Alpine/client-side state (for example, watching the form's client state with `$wire.watch()`). Server-side required validation remains authoritative, while `live()` should remain only for PHP-dependent options or conditional schema.

## Follow-up: client-side progress implementation

- [x] Move progress rendering and recalculation to Alpine/client-side state.
- [x] Remove `live()` bindings that only existed to refresh the progress counter.
- [x] Preserve Livewire bindings needed for PHP-dependent options, conditional schema, and server synchronization.
- [x] Add regression coverage and verify network/console behavior in Chrome.

Implementation review:

The progress card now uses Alpine state inside a `wire:ignore` region. Relevant Filament fields dispatch a native `afterStateUpdatedJs()` progress event without a network request; `$wire.watch()` also covers programmatic/server-synchronised state changes. It mirrors the server-side required-field rules, including religious time, online location, organizer, speaker, repeater, and guest-contact conditions, while taxonomy/policy IDs are cached for five minutes. Independent fields such as format, visibility, gender, language, and speakers no longer use `live()` solely for progress; guest contact fields sync on blur. Server validation and the existing `formProgress()` calculation remain authoritative on submit.

Verification:

- `./pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 11 passed (69 assertions).
- `./pest --parallel --compact tests/Browser/PlaywrightSmokeTest.php` — 1 passed (2 assertions).
- Targeted PHPStan, Pint, Blade view cache, PHP syntax checks, and `git diff --check` passed.
- Chrome MCP verified that changing the client-side event format state updated the progress value from 61% to 63% without a Livewire network request, and the client calculator reached 100% when all active required values were populated; the form was not submitted and the refreshed page had no JavaScript errors.

# Follow-up: conditional topic-reference wizard step

## Plan

- [x] Make `Topik & Rujukan` visible only when `Agama & Kerohanian` is selected in `Topik / bidang`.
- [x] Add regression coverage for religious, non-religious, and restored topic selections.
- [x] Run focused tests, static checks, and review whether the visibility-only UI change needs Signals tracking.

## Review

The `Topik & Rujukan` wizard step now uses the canonical `agama_kerohanian` topic code and is hidden for other `Topik / bidang` selections. The existing Muslim-only audience field reuses the same predicate so the contextual controls remain consistent. No Signals event was added because this is a visibility-only adjustment without a new user-intent or completed workflow transition.

Verification:

- `vendor/bin/pest tests/Feature/SubmitEventAdaptiveFormTest.php --compact` — 12 passed (77 assertions).
- Targeted PHPStan — no errors.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.

# Follow-up: Malaysia-relevant language field options

## Plan

- [x] Trace the language catalog, submit form options, related filters, and cache behavior.
- [x] Add a shared Malaysia-relevant language catalog using supported ISO 639-1 records.
- [x] Reuse the catalog in the submit form, event language filter, and review preview.
- [x] Add regression coverage for the complete curated option list.
- [x] Run focused tests, static analysis, formatting, syntax, and diff checks.

## Review

The `/hantar-majlis` `Bahasa` field now offers 28 relevant languages: Malay, Arabic, English, Indonesian, Chinese, Tamil, Javanese, Punjabi, Hindi, Malayalam, Telugu, Bengali, Nepali, Thai, Myanmar, Vietnamese, Tagalog, Urdu, Sinhala, Khmer, Gujarati, Kannada, Odia, Sindhi, Persian, Sundanese, Japanese, and Korean. These use the existing commerce-support ISO 639-1 catalog, so no duplicate language records or migration are required.

The shared `MalaysiaLanguageCatalog` keeps labels and ordering consistent across event submission, public event filtering, and the submission review preview. The event filter cache key was bumped to `v3` so existing cached seven-language payloads expire immediately. No Signals event was added because expanding select options does not create a new meaningful workflow transition.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventLanguageTest.php` — 4 passed (19 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/Laravel13CacheSerializationTest.php` — 4 passed (23 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 12 passed (77 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, package-code availability check, and `git diff --check` — passed.

# Follow-up: client-side age-group selection normalization

## Plan

- [x] Trace the existing `Kumpulan Umur` state logic and no-request form patterns.
- [x] Collapse all four specific age groups into `Semua Peringkat Umur` in the browser.
- [x] Remove `Semua Peringkat Umur` when another specific age group is selected afterward.
- [x] Keep a server-side normalization fallback for submitted/programmatic state.
- [x] Add regression coverage for the behavior and absence of live synchronization.
- [x] Run focused tests, PHPStan, Pint, syntax, and diff checks.

## Review

The submit-event age field no longer uses `->live()`, so selecting age groups does not trigger a Livewire request. Its `afterStateUpdatedJs()` watcher compares `$state` with `$old`: selecting all four specific groups selects only `all_ages`; selecting a specific group after `all_ages` removes `all_ages`; and selecting `all_ages` keeps only that sentinel without clearing the field. The children toggle’s disabled state follows the same client-side state. Server-side normalization remains in place before submission as a defensive fallback.

The browser watcher explicitly preserves `all_ages` when it is the only selected value. This prevents watcher re-entry from interpreting the normalized `[all_ages]` state as a request to remove the sentinel itself.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAgeGroupTest.php` — 5 passed (17 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 12 passed (77 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.
- Chrome verified that choosing `Semua Peringkat Umur` leaves that single selection visible, and choosing `Dewasa` afterward removes it.

# Follow-up: audit remaining submit-form live bindings

## Plan

- [x] Inspect every `->live()` and `wire:model.live` binding in the submit-event form.
- [x] Move pure browser state synchronization and conditional required/disabled behavior to `afterStateUpdatedJs()` and Alpine bindings.
- [x] Preserve live bindings whose PHP callbacks provide database-backed lookup, contextual defaults, dynamic options, or server-driven schema.
- [x] Defer the Turnstile token until submit because it is only consumed by the server during submission.
- [x] Add regression coverage and review whether the UI behavior needs Signals tracking.

## Review

The organizer selectors, linked key-person repeater, guest contact requirement toggles, and Turnstile token no longer cause intermediate Livewire requests. Their server-side callbacks and validation rules remain as fallbacks/authorities for programmatic state and submission.

The six remaining live fields are intentional: event category, title, country, event date, prayer time, and religious domain topic. Each drives PHP-side defaults, database-backed title lookup, country/date-dependent prayer options, or conditional schema and contextual religious behavior. No Signals event was added because this is a request/performance refactor without a new user-intent or completed workflow transition.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventReactiveFieldTest.php` — 3 passed (38 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventCaptchaTest.php` — 2 passed (9 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 12 passed (77 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventOrganizerAutoSelectTest.php` — 3 passed (12 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.
- A broader `tests/Feature` run still has unrelated existing failures in fixtures/seeders, geography, prayer-option data, and other admin/public workflows; the focused submit-form suites above pass.

# Follow-up: audit contribution forms and public majlis filters

## Plan

- [x] Trace the create/edit person and institution forms, their shared schemas, and the `/majlis` filter form.
- [x] Reduce social-handle normalization from every keystroke to blur-only server synchronization.
- [x] Preserve live bindings for address cascades, Google Maps normalization, dynamic social/contact fields, and server-side event filtering.
- [x] Add regression coverage and review whether the interaction changes need Signals tracking.

## Review

The dedicated contribution page classes and Blade views already use deferred state; no unnecessary page-level `wire:model.live` bindings were found. Their shared schema still uses live state only where PHP must update dependent address options/visibility, normalize Google Maps input, or render dynamic social/contact fields. Social-media handle parsing now runs on blur instead of every keystroke.

The `/majlis` page intentionally keeps its live bindings: search, location, date/time, taxonomy, audience, language, format, and availability controls all change the server-side event query. Search and radius already use debounce, and the PIC text search already updates on blur. No Signals event was added because this preserves existing filter behavior and only reduces redundant normalization requests.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/ContributionReactiveFieldTest.php` — 1 passed (3 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/PersonContributionOptimizationTest.php` — 6 passed (17 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/InstitutionContributionLocationPickerTest.php` — 9 passed (56 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.
- `tests/Feature/EventSearchTest.php` — 90 passed, 1 unrelated existing fixture assertion failed (`Domain Hidden Filter Payload Test`).
- `vendor/bin/pest --parallel --compact tests/Feature/ContributionPagesTest.php` — 65 passed, 1 unrelated existing assertion failed because the test expects initial speaker progress `0` while the current component returns `50`.

# Follow-up: direct membership claiming from public profiles

## Plan

- [x] Trace the existing membership application route, claimability rules, and public speaker/institution page actions.
- [x] Add the direct membership claim action to public institution pages using the canonical institution identifier.
- [x] Extend regression coverage for unclaimed and already-managed institution profiles while preserving speaker behavior.
- [x] Review responsive presentation, browser errors, and whether the new navigation action needs Signals tracking.

## Review

Public institution profiles now show the same membership claim card already used by speaker profiles when no admin member exists. The action opens the existing membership application form directly with the institution preselected; it does not send users through the general `/sumbangan` selector. Profiles with only non-admin members continue to show the claim action, while profiles with an admin member hide it.

No Signals event was added: this is a navigational entry point into the existing claim workflow, while the membership application submission remains the meaningful server-confirmed outcome.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 11 passed (61 assertions).
- Chrome checked the institution profile at desktop and mobile widths: the direct claim URL rendered, the card remained responsive, and there was no horizontal overflow.
- Chrome console check found no errors.
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, Blade view caching, PHP syntax checks, and `git diff --check` — passed.

# Follow-up: position profile membership CTAs after feedback

## Plan

- [x] Move the institution claim card after `Bantu Semak Maklumat Ini`.
- [x] Move the speaker claim card after the same feedback section.
- [x] Add order assertions for both public profile types and re-run verification.

## Review

Both public profile pages now present the information-feedback actions first and the “Tuntut Pengurusan” card immediately afterward. The existing direct claim routes and approved-member visibility guards are unchanged, and speaker profiles retain the claim CTA for unclaimed records.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 11 passed (61 assertions).
- Chrome confirmed the institution order at desktop and mobile widths, with no horizontal overflow or console errors.
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, Blade view caching, and `git diff --check` — passed.

# Follow-up: simplify membership claim submission

## Plan

- [x] Inspect the claim page navigation and evidence upload configuration.
- [x] Remove the “Tuntutan Saya” action from the claim form page.
- [x] Preserve and regression-test multi-file evidence uploads.
- [x] Verify the focused membership tests, static checks, Blade compilation, and browser rendering.

## Review

The claim form now keeps only the submit action; claim history remains available through its separate authenticated route. The evidence field already used Filament's `multiple()` configuration, and the submission regression now uploads two valid files and verifies both are persisted in the `evidence` collection.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 12 passed (67 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, Blade view caching, and `git diff --check` — passed.
- Chrome navigation to the protected URL correctly redirected the unauthenticated browser session to `/login`; authenticated Livewire coverage verified the form behavior.

# Follow-up: institution claim-page parity

## Plan

- [x] Confirm the institution route uses the shared membership claim form.
- [x] Add an institution-specific assertion that the claims-history button is absent.
- [x] Re-run the focused membership tests and final checks.

## Review

Institution claims use the same Livewire component and evidence field as speaker claims, so the removed “Tuntutan Saya” action and multi-file upload behavior apply consistently to both subject types. The institution-specific page test now explicitly verifies the history button is absent.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 13 passed (70 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, and `git diff --check` — passed.

# Follow-up: admin-role membership CTA visibility

## Plan

- [x] Trace the membership pivot role values and existing permission conventions.
- [x] Show the claim CTA when only non-admin members exist.
- [x] Hide the claim CTA when an admin member exists on either profile type.
- [x] Verify focused tests, static checks, Blade compilation, and browser rendering.

## Review

The public institution and speaker profile guards now query the membership pivot for `MemberRole::Admin` instead of treating any member as a reason to hide the CTA. This keeps “Tuntut Pengurusan” available for profiles with no members or only non-admin members, while an existing admin can reliably invite and manage members without a duplicate claim entry point.

No Signals event was added: this changes visibility of the existing navigation CTA; the membership application submission remains the meaningful server-confirmed outcome.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 12 passed (67 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 995 files.
- Targeted Pint, PHP syntax checks, Blade view caching, and `git diff --check` — passed.
- Chrome confirmed Kazim Elias shows the direct speaker claim link, the CTA follows the feedback section, there is no horizontal overflow, and no console errors.

# Follow-up: unified institution and speaker workspaces

## Plan

- [x] Extend `/dashboard/organisasi` with institution and speaker records the signed-in user belongs to.
- [x] Add a speaker management workspace with scoped member management and profile editing.
- [x] Scope institution member-management checks to the selected institution.
- [x] Add focused regression coverage and complete code, view, and template verification.

## Review

The authenticated workspace entry point now groups organization, institution, and speaker memberships. Institution cards open the existing institution dashboard with the selected institution preserved; speaker cards open the new member-management workspace, where authorized admins can invite, change, and remove non-owner members and edit the public profile.

Institution member-management authorization now evaluates the selected institution itself, so an admin role on institution A does not grant management access to institution B. The institution table location column was also aligned with its existing test contract without changing the displayed location value.

No new Signals event was added: this is a navigation and authorization-surface change, while the existing invitation/profile-update workflows remain the meaningful actions to track.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 5 passed (26 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/OrganizationFrontendTest.php` — 8 passed (29 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/DashboardPagesTest.php` — 30 passed (304 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 996 files.
- `php artisan view:cache`, targeted Pint, PHP syntax checks, and `git diff --check` — passed.

# Follow-up: review latest commit and working tree

## Plan

- [x] Audit the latest commit and uncommitted workspace changes against their form, public-page, and authorization contracts.
- [x] Normalize single-select category/topic state in duplicate and AI extraction flows.
- [x] Restore the public event poster aspect marker and add shared event feedback actions.
- [x] Verify focused behavior, static analysis, Blade compilation, formatting, and final diff hygiene.

## Review

The review found and fixed stale array hydration for the new single-select event category/topic fields, an invalid duplicate-event eager-load (`tags` is not an Event relationship), and the missing poster aspect data attribute on the event detail page. Test fixtures were aligned with the intentionally hidden non-religious reference step, and the remaining affiliated-institution visibility toggle now updates locally in the browser.

Verification:

- Focused form, AI extraction, media, public-page, contribution, and workspace tests passed; the media file passes sequentially and with a single parallel worker, while the full parallel media invocation showed the existing shared fake-storage race.
- `vendor/bin/phpstan analyse --ansi` — no errors across 996 files.
- `php artisan view:cache`, Pint, and `git diff --check` — passed.

Additional review finding fixed after the broad feature run: `SaveSpaceAction` treated Filament's empty default `institution_space_overrides` state as an explicit empty sync and detached all institutions. The action now ignores an empty overrides-only payload while still syncing explicit institution IDs and non-empty overrides.

Additional verification:

- `vendor/bin/pest --parallel --compact tests/Feature/AdminAuditFollowUpTest.php` — 6 passed (57 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SpaceModelRemediationTest.php` — 6 passed (28 assertions).
- The broad `tests/Feature` run was stopped after confirming the known Scramble documentation worker memory problem; focused reviewed-path suites remain the reliable verification set.

The same review also found that `EventLocation`'s model save hook rewrote an existing historical space-name snapshot while `Event::syncLocation()` recreated the row. Existing snapshots are now restored with a quiet update after creation, while new locations continue to receive the current space name.

# Follow-up: Scramble documentation contract review

## Plan

- [x] Trace the Scramble test suite, API documentation route filter, cache resolver, and generated route set.
- [x] Fix the real route-alignment failure without removing the contract suite.
- [x] Verify the complete suite at the normal 512 MB PHP memory limit.

## Review

`ScrambleDocsTest` is a 33-case API documentation contract suite. It covers API-host-only exposure, lazy UI loading, cached/stale/ETag behavior, route/auth alignment, operation summaries and responses, schemas, tags, security metadata, request examples, and documentation copy. The suite is valuable coverage, so it was retained.

The failure was genuine: the four organization API endpoints had no `Endpoint` metadata, leaving their generated OpenAPI summaries empty. `OrganizationController` now defines an `Organizations` group and explicit endpoint titles/descriptions for listing, viewing, creating, and opening an organization workspace.

Verification:

- `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/ScrambleDocsTest.php` — 33 passed (402 assertions).

# Follow-up: owner-aware profile claim CTA

## Plan

- [x] Trace the institution and speaker profile claim guards and scoped membership roles.
- [x] Hide “Tuntut Pengurusan” when either an admin or owner member exists.
- [x] Add owner-role regression coverage for both profile types and verify the change.

## Review

The institution and speaker profile pages now query their scoped membership pivots for both `admin` and `owner` roles. Viewer/editor-only members still leave “Tuntut Pengurusan” visible, while either management role hides it. The computed property and Blade references were renamed to reflect the broader rule.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 14 passed (76 assertions).
- PHPStan — no errors.
- Pint, Blade cache, and `git diff --check` — passed.

# Speaker workspace event management

## Plan

- [x] Add speaker-scoped event visibility and mutation authorization.
- [x] Add event management cards, filters, and actions to the speaker workspace.
- [x] Preselect the managed speaker in the existing event submission workflow.
- [x] Add regression coverage for role boundaries, event scoping, and create context.
- [x] Run focused tests, formatting, Blade compilation, static analysis, and diff checks.

## Review

Speaker members can now see events linked to their profile, while only owner/admin roles receive event edit/create controls under the existing event permission thresholds. Event policy and member API mutation/listing paths use the same speaker scope, and the event wizard preserves the originating speaker context with a validated preselected speaker.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 7 passed (47 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 996 files.
- `php artisan view:cache`, targeted Pint, and `git diff --check` — passed.

# Managed event builder for member workspaces

## Plan

- [x] Map the existing managed event, organization authorization, ticketing, and session seams.
- [x] Add shared managed-event context and scoped member access for institutions, speakers, and organizations.
- [x] Reuse ticketing/seating configuration for all managed event owners.
- [x] Expose the managed builder and session-ready workflow from each member workspace.
- [x] Add regression tests and run focused/full verification.
- [x] Review the final diff and document results.

## Review

Managed event creation now has one shared transaction workflow for the event container, first occurrence, registration policy, ticket types, quotas, and optional seating maps. Institution and speaker members use the advanced builder; organization members use the organization builder, with creation authorization available to every active member role.

Organization-owned events now participate in the same scoped event policy and member resource listing. Organization owners/admins can manage those events, while the creating member can continue working on their own draft. Existing public \`/hantar-majlis\` submission behavior remains unchanged, and the advanced builder continues into the existing session submission workflow.

## Verification

- \`vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php\` — 8 passed (55 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/OrganizationFrontendTest.php\` — 9 passed (36 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/EventPolicyTest.php\` — 30 passed (30 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/DashboardPagesTest.php\` — 30 passed (304 assertions).
- \`vendor/bin/phpstan analyse --ansi\` — no errors across 999 files.
- \`vendor/bin/pint --dirty\`, \`php artisan view:cache\`, and \`git diff --check\` — passed.

# Advanced event builder UX refresh

## Plan

- [x] Simplify the page header and make the four-step journey explicit.
- [x] Reorganize fields into clear sections with progressive disclosure for optional ticketing and seating.
- [x] Replace technical workflow copy with concise, user-facing guidance and a clearer final handoff.
- [x] Preserve all existing field bindings, validation, authorization, and submission behavior.
- [x] Verify Blade compilation, formatting, focused tests, and the final diff.

## Review

The advanced builder now uses one calm, single-column workspace with four focused steps: Event basics, Date & details, Registration, and Review & create. The duplicated step navigation, technical workflow sidebar, and dense default panels were removed. Templates and advanced ticket metadata are tucked behind optional disclosure controls, category selection uses touch-friendly checkboxes, and seating configuration appears only when a ticket actually needs it.

Organizer switching now uses deferred Livewire state plus local Alpine presentation for the optional location field, avoiding a server request solely to change the visible form fields. All existing form keys, authorization, validation, ticketing, seating, and post-create session handoff remain intact.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 8 passed (55 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 1000 files.
- `vendor/bin/pint --dirty`, `php artisan view:cache`, `npm run build`, and `git diff --check` — passed.

# Advanced/public event submission parity

## Plan

- [x] Map the public submission fields to the advanced event and first-session model boundaries.
- [x] Define which values are derived and locked for institution- and speaker-originated advanced flows.
- [x] Add the complete public event metadata set to the advanced builder and persist it through the managed-event workflow.
- [x] Keep registration, ticket, package, quota, seating, and program timeframe controls as advanced-only additions.
- [x] Add regression coverage for parity, contextual defaults, and server-side context enforcement.
- [x] Run focused tests, Blade/build checks, static analysis, formatting, and diff validation.

## Review

The advanced builder now contains the public event profile fields: title, description, category, topic taxonomy, references, first-session date/time, country, format, visibility, audience, languages, speakers, other key people, location, links, and media. The public-only submitter and captcha fields remain out of the authenticated managed flow.

Institution-originated creation locks the institution as organiser and location context. Speaker-originated creation locks the speaker as organiser and adds that speaker to the event people list; it does not guess an institution venue from a speaker membership. Venue, format, audience, content, and session details remain editable because they are not reliably known from the source page.

The advanced-only controls remain separate for program timeframe, registration, ticket/package definitions, quotas, and seating. The created parent event stores the public profile and first-session metadata, then opens the existing public session submission flow with those values prefilled.

The advanced timing rules now match the public flow for Friday/Ramadhan prayer options and end-time ordering, and the first session must fall inside the program timeframe.

## Verification

- \`vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php\` — 10 passed (77 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/EventActionsTest.php\` — 8 passed (39 assertions).
- \`vendor/bin/pest --parallel --compact tests/Feature/AdvancedEventApiTest.php\` — 4 passed (17 assertions).
- \`vendor/bin/phpstan analyse --ansi\` — no errors.
- \`vendor/bin/pint\` on modified PHP files, testing-environment \`php artisan view:cache\`, \`npm run build\`, and \`git diff --check\` — passed.

# Advanced builder Filament parity refresh

## Plan

- [x] Compare the public Filament wizard structure and field presentation with the advanced builder.
- [x] Convert the advanced form to Filament schema components and the same friendly wizard shell.
- [x] Preserve context locking, local conditional behavior, registration extras, and existing persistence.
- [x] Add or update UI regression coverage.
- [x] Run Blade, focused tests, static analysis, build, and diff checks.

## Review

The advanced event builder now follows the public Hantar Majlis interaction model: the custom multi-panel HTML was replaced with a responsive Filament wizard, grouped sections, helper text, native Filament validation presentation, repeaters for tickets/people/seating, and the same asset shell and scrollable step header. Managed-event context remains authoritative, while the registration, ticket, seating, program timeframe, and media controls remain available.

The refactor also accounts for Filament-specific state: RichEditor JSON is accepted and persisted, empty single-file upload arrays are normalized before validation, and repeaters retain numeric state keys for the existing workflow. Age-group normalization remains client-local, and the seating review panel is client-local as well.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 10 passed (80 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/EventActionsTest.php` — 8 passed (39 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/AdvancedEventApiTest.php` — 4 passed (17 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors.
- `php artisan view:cache`, `npm run build`, targeted `vendor/bin/pint`, and `git diff --check` — passed.

# Advanced feature discoverability follow-up

## Plan

- [x] Make registration, ticketing, quota, and seating capabilities visible before the wizard.
- [x] Clarify how ticket seating activates the seating-map fields.
- [x] Add regression assertions and verify the corrected UI path.

## Review

The advanced capabilities were not removed from the workflow, but the Filament refactor made them too easy to miss: registration and tickets were only visible on a later wizard step, while seating was conditionally hidden until a ticket selected a seating mode. The form now advertises these capabilities before the wizard, uses the clearer `Pendaftaran & tiket` step label, and explains the seating activation rule.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 10 passed (82 assertions).
- `php artisan view:cache`, targeted Pint, and `git diff --check` — passed.

# Advanced builder browser fill-through verification

## Plan

- [x] Fill the managed builder with harmless event, speaker, ticket, quota, and seating values.
- [x] Verify registration, ticket, quota, seating, and seating-map controls in the rendered browser DOM.
- [x] Correct the ticket-to-seating mode mismatch discovered during the fill-through.
- [x] Leave the form at review without submitting an event.

## Review

Browser verification found that choosing an `Assigned` ticket left the seating map on its default `General Admission` mode, which would make the completed form fail later. Ticket seating now updates the seating-map mode locally: assigned-only tickets select `Assigned`, general-admission-only tickets select `General Admission`, and mixed modes select `Hybrid`.

Verification:

- Browser fill-through reached the final review step with registration enabled, a paid ticket at RM25.00, quota 50, assigned seating, a 50-seat map, and all seating fields visible; no event was submitted.
- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 10 passed (82 assertions).
- `vendor/bin/phpstan analyse --ansi`, `php artisan view:cache`, targeted Pint, and `git diff --check` — passed.

# Membership claim contact input and applicant notes

## Plan

- [x] Trace the membership claim form and existing account phone input.
- [x] Add the Ysfkaya phone field and persist optional applicant notes in application metadata.
- [x] Surface applicant notes to the claimant and reviewer, then add regression coverage.
- [x] Run focused tests and verification checks.

## Review

The membership claim form now uses the same Ysfkaya phone configuration as Tetapan Akaun: Malaysia as the initial country, international display format, and E.164 submission format. Applicants can optionally add a Catatan up to 2,000 characters; the note is stored in the existing application metadata and shown in both the applicant history and admin review page.

## Verification

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 16 passed (99 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationAdminResourceTest.php` — 5 passed (32 assertions).
- Full PHPStan and targeted PHPStan — no errors.
- Targeted Pint, `php artisan view:cache`, JSON validation, PHP syntax checks, and `git diff --check` — passed.
- Chrome verification — Malay page shows the Ysfkaya telephone input and Catatan textarea with the expected placeholder and 2,000-character limit.

# Majlis filters and search cleanup

## Plan

- [x] Trace every Majlis filter, search scope, URL state, and active-filter summary.
- [x] Align filter state updates, search scopes, date shortcuts, and saved/share links.
- [x] Represent every active filter with a contextual label and complete the visible Malay vocabulary.
- [x] Add focused regression coverage for the filter vocabulary and state-preserving search flows.
- [x] Run browser, test, formatting, Blade, translation, diff, and PHPStan verification.
- [x] Migrate the remaining sidebar and toolbar fields to Filament while preserving the existing `filterData` state and location cascade.

## Review

The Majlis page now keeps all filter controls in the same Livewire `filterData` state, so search scopes and secondary filters reset pagination and stay synchronized with the URL. Date shortcuts preserve the current search and filters. Active-filter chips now cover location, event type, language, format, audience, speakers and roles, topics, references, timing, links, and search scopes with clear field labels. The unused Apply button was replaced with an automatic-update message, and language choices now use a searchable, preloaded Filament multi-select showing full names alongside their codes.

The remaining location, date, event-type, format, radius, search-scope, hero-search, and result-sort controls are now Filament schemas as well. Dependent geography fields keep their existing cascade and canonical address keys, while the branded search toolbar, Escape-to-clear behavior, nearby permission gate, date shortcuts, and distance-sort availability remain intact.

Existing Signals intent tracking for search, filter changes, nearby search, clearing, saving, and sharing remains in place; no blanket cosmetic click tracking was added.

## Verification

- Focused filter/search regression tests — 6 passed (59 assertions).
- Filament field-set regression test — passed for location, dates, category, format, radius, search scopes, hero search, and sorting.
- Full `EventSearchTest` — 95 passed; the existing hidden domain payload test remains failing because broad domain options are preloaded into the initial response.
- `vendor/bin/phpstan analyse --ansi` — no errors across 998 files.
- Targeted Pint, `php artisan view:cache`, JSON validation, and `git diff --check` — passed.
- Chrome verification — Malay filter options render correctly; search scopes and date shortcuts preserve state in the URL.
# Commit audit through 26 Aug 2026

## Plan

- [x] Define the cutoff, inspect repository state, and establish a test/static-analysis baseline.
- [x] Review the cutoff commits and trace changed behavior through the code graph and tests.
- [x] Reproduce confirmed defects and add focused regression tests.
- [x] Fix confirmed bugs; explain and pause on ambiguous logic or workflow choices.
- [x] Run verification and document findings, fixes, and any decisions still needed.

## Review

Scope: `a339e092`, `e82eec9f`, and `55c94af2`, through 2026-08-26 23:59:59 (+08:00). Later commits were checked only to avoid duplicating fixes that had already landed.

Confirmed bugs fixed:

- Membership claims now share one guard across the web, API, and MCP paths. Already-members, duplicate pending applications, and pending invitations are rejected before a new application is created.
- The web claim form now validates role and relationship values server-side, shows claim conflicts on a visible field, validates unique phone numbers, and only saves a new phone number after the application succeeds.
- Empty institution capacity overrides now clear an existing pivot override when the institution remains selected.
- Person workspaces now detect existing members without email-case sensitivity, count an event once even when multiple speaker rows exist, normalize invalid URL filters, render state-object labels, and avoid links to private/draft events that the public route cannot open.
- Membership relationship and role labels are translated in the claim form.
- Claim evidence is now mandatory on the web form as well as the API and MCP contracts, with a regression test for an empty submission.
- Hidden-event duplication now preserves the source visibility server-side, even if the hidden form field is omitted or tampered with.
- A current-head PHPStan warning in the membership application resource was removed; PHPStan is clean.

Findings already repaired by commits after the cutoff, so no duplicate patch was needed:

- `laravel/ai` is in runtime `require`, not only `require-dev`.
- AI-extracted taxonomy values are normalized to the scalar shape expected by the form.
- Public institution/person claim buttons are limited to records the viewer can actually claim.
- The advanced event builder preserves the requested person as the primary organizer context.

Decisions resolved during this review:

- Claim evidence is required consistently across web, API, and MCP. A claim without at least one supporting file is rejected.
- Duplicating a hidden event keeps it hidden. The duplicate cannot silently become public because a hidden form field was not submitted.
- A person-profile admin is a user with the `admin` membership role on that one person record. They can manage/delete the profile and its linked events at owner level; an owner can remove an admin, but an admin cannot remove the owner.
- Any member of a person profile, including `viewer` and `editor`, can see that profile's private and draft linked events in the member workspace. Guests still cannot.
- Only the exact `Topik / bidang` value `Agama & Kerohanian` activates religion-specific questions and the topic/reference step. `Jenis Majlis` does not determine religious behavior.
- `Waktu` is always shown. Prayer-relative choices such as `Selepas Asar` and `Sebelum Maghrib` are treated as scheduling/cultural labels, not as evidence that an event is religious.

Further logic and workflow decisions still needed:

- Space override API semantics: an omitted override field preserves existing values; the web form now sends an empty field when an override is removed and clears that pivot. Decide whether an explicitly empty override list in every API client should also mean “clear all.”
- Invitation history and viewer wording are UX choices: the workspace currently shows invitation history in one list and uses “management” wording for viewers. Decide whether to split active/history invitations and use neutral wording for non-managers.
- Topic optionality: the form currently requires a `Topik / bidang` value even though the topic is otherwise an optional classifier. Decide whether events may be submitted without a broad topic; if yes, the form will simply skip religion-specific behavior.
- Religious default time: when the form starts with `Agama & Kerohanian`, it currently preselects `Selepas Maghrib`; decide whether that helpful default should remain, or whether every event should start at `Lain waktu` and let the submitter choose.

Verification:

- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationPagesTest.php` — 21 passed (124 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/MembershipApplicationActionsTest.php` — 8 passed (28 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SpaceModelRemediationTest.php` — 7 passed (29 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/ManagedWorkspacesTest.php` — 12 passed (91 assertions).
- `vendor/bin/pest --parallel --compact tests/Feature/SubmitEventAdaptiveFormTest.php` — 14 passed (83 assertions).
- Member API parity — 5 passed (37 assertions); member MCP server — 40 passed (599 assertions).
- `vendor/bin/phpstan analyse --ansi`, `vendor/bin/pint --dirty`, `php artisan view:cache`, JSON validation, and `git diff --check` — passed.

No new Signals event was added: these fixes preserve existing workflow intent tracking and do not introduce a new user intent path.

# Follow-up decisions: member permissions and event classification

## Plan

- [x] Trace the permission hierarchy, member-removal authorization, linked-event visibility, and religious/time form behavior.
- [x] Implement the confirmed owner/admin, member-visibility, topic-based religious, and always-visible time decisions.
- [x] Add regression tests for each changed rule and flow.
- [x] Run focused tests, formatting, static analysis, and final diff review.
- [x] Document the remaining product decisions in plain language.

## Review

Person-profile admins now receive owner-level deletion for the person profile and events linked through that profile without widening deletion rights for admins of other resource types. The person model also protects the owner membership at the shared mutation boundary, so a direct or Filament action cannot remove the owner accidentally.

Person workspaces deliberately expose all linked event statuses and visibility levels to members, while public event links remain gated by the event's public reachability rules. The submission wizard now uses only the exact broad topic `Agama & Kerohanian` for religion-specific behavior and keeps `Waktu` available for every event.

## Verification

- `vendor/bin/pest --parallel tests/Feature/ManagedWorkspacesTest.php` — 13 passed (101 assertions).
- `vendor/bin/pest --parallel tests/Feature/MemberPermissionGateTest.php` — 5 passed (34 assertions).
- `vendor/bin/pest --parallel tests/Feature/SubmitEventAdaptiveFormTest.php` — 14 passed (91 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,005 files.
- `vendor/bin/pint --dirty --test`, `php artisan view:cache`, translation JSON validation, and `git diff --check` — passed.

The full parallel suite also completed with 2,156 passing tests and 26 failures. The failures were existing unrelated/parallel-sensitive cases (geography fixture setup, locale-sensitive copy, cache/search expectations, public-page behavior, and other pre-existing tests); the two event mutation paths that looked potentially related passed when isolated.

# Person ownership transfer and timing clarification

## Plan

- [x] Add a formal owner-only transfer workflow for person profiles.
- [x] Make `Sebelum Maghrib` available every day and keep `Selepas Tarawih` Ramadan-only.
- [x] Add regression coverage for transfer authorization, membership roles, and timing options.
- [x] Run focused tests, formatting, static analysis, and document the space-override question with a concrete example.

## Review

The person workspace now has a formal ownership transfer action. The current owner can transfer ownership to an existing profile member; the old owner becomes an admin, the new member becomes the sole owner, and direct owner removal or role changes remain blocked. The transfer is intentionally owner-only until the product decides whether an admin may initiate this security-sensitive operation.

`Sebelum Maghrib` is now a daily scheduling label in the public submit form, advanced builder, and contribution form. `Selepas Tarawih` remains Ramadan-only. There is no `Sebelum Tarawih` option in the current taxonomy.

Space API semantics now match the documented contract: omitting `institution_space_overrides` preserves existing per-institution capacities, while sending `[]` clears those capacities without unlinking the institutions. Sending `institutions` still controls the institution links themselves.

## Verification

- Focused timing, workspace, contribution-form, space, and admin API tests passed: 52 tests, 311 assertions.
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,006 files.
- `vendor/bin/pint --dirty --test` and `git diff --check` — passed.

# Pest 5 test-impact audit and remediation

## Plan

- [x] Run Pest 5 Test Impact Analysis with coverage over the uncommitted-change impact set.
- [x] Audit each reported failure against the current application source and contracts.
- [x] Fix genuine regressions and update stale expectations or unstable fixtures.
- [x] Replay the residual failures, rerun TIA, and complete static checks.
- [x] Prove the changed submission flow in live Chrome DevTools MCP.

## Review

The initial TIA run reported 22 failures. The audit separated stale expectations from real regressions: canonical geography traversal, API null-field parity, generated Filament JavaScript encoding, public-page robots semantics, lazy taxonomy search, event-change rendering, report/subject translations, production seeder expectations, membership cleanup and race safety, and event timing behavior. Tests were updated only where the current codebase intentionally defines a different contract. The final residual failures were caused by an admin fixture combining explicit absolute timestamps with randomized prayer-relative metadata and a Tarawih test that was being normalized by the UI before it reached the shared submit guard; both are now covered by stable, source-aligned setup.

## Verification

- `XDEBUG_MODE=coverage vendor/bin/pest --parallel --tia --compact` — 2,193 passed (14,079 assertions; 102 directly affected, 2,091 replayed), 0 failed.
- Residual replay — 5 passed (39 assertions), parallel.
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,007 files.
- `vendor/bin/pint --dirty --test` — passed.
- `git diff --check` — passed.
- Live Chrome DevTools MCP at `https://ilmu360.test/hantar-majlis` — page title `Hantar Majlis - ilmu360°`; date and prayer controls updated through Livewire; an invalid end time was cleared client-side; five Livewire XHR requests returned HTTP 200; no console errors or warnings; generated validation JavaScript contained encoded message text and no raw `@js(` directive.

No additional Signals event was needed: the event replacement navigation retains its explicit existing intent-tracking attributes.

# Final hard-cut verification

## Plan

- [x] Apply the canonical geography contract at every catalog boundary and caller.
- [x] Remove legacy geography parameter names without compatibility aliases or remapping.
- [x] Re-run Pest 5 Test Impact Analysis after the hard cut.
- [x] Re-run static, formatting, Blade, and repository-integrity checks.

## Review

Catalog controllers, the admin mutation service, and their tests now use the canonical `administrative_district` parameter. No legacy alias, translation layer, or backward-compatibility path was added. The source contract is authoritative.

## Verification

- `XDEBUG_MODE=coverage vendor/bin/pest --parallel --tia --compact` — 2,193 passed (14,079 assertions; 419 directly affected, 1,774 replayed), 0 failed.
- Canonical catalog replay — 2 passed (22 assertions).
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,007 files.
- `vendor/bin/pint --dirty --test`, `php artisan view:cache`, and `git diff --check` — passed.
- Legacy geography, SoftDeletes, and debug-call scans — no matches.
- No `packages` directory exists for the package migration constraint scan.

# Pest 5 tooling and AI guidance

## Plan

- [x] Verify the Pest 5 Agent, PHPStan, and Rector packages are required, locked, and installed.
- [x] Verify the Pest PHPStan extension, Pest Rector set, and local TIA configuration.
- [x] Document plugin usage, coverage-backed TIA commands, and agent-probe rules in the AI guidance.
- [x] Validate the installed commands and repository diff.

## Review

The requested Pest 5 plugins were already present in `composer.json`, `composer.lock`, and `vendor/`: Agent `v5.0.0`, PHPStan `v5.2.0`, Rector `v5.0.4`, and Rector core `2.6.4`. No dependency churn was needed. The AI guidance now points agents to the `./pest` wrapper for Xdebug-backed TIA, the Agent plugin's safe one-off syntax, the Pest PHPStan extension, and the Pest Rector coding-style set.

## Verification

- `composer validate --no-check-publish` — valid.
- `vendor/bin/pest --version` — Pest 5.1.3.
- `vendor/bin/pest --help` — exposes `--tia`, `--filtered`, `--locally`, `--baselined`, and `--baseline`.
- `vendor/bin/rector --version` — Rector 2.6.4.
- `phpstan.neon` resolves `vendor/pestphp/pest-plugin-phpstan/extension.neon`.
- `vendor/bin/pest --agent='expect(true)->toBeTrue();'` — 1 passed (1 assertion).
- `./pest --parallel --tia --filtered --compact --filter='requires an explicit administrative district'` — 1 passed (4 assertions); the runner correctly bypasses TIA for a partial filtered selection.
- `vendor/bin/rector process --dry-run --no-progress-bar tests/Feature/Api/Frontend/CatalogApiTest.php` — no changes proposed.

# Block duplicate pending contribution requests

## Plan

- [x] Trace the contribution routes/components, request model/status semantics, and existing duplicate-submission tests.
- [x] Implement a shared pending-request guard for speaker, institution, event, and reference contributions.
- [x] Add focused Pest coverage for each resource type and allowed non-pending cases.
- [x] Run focused tests, PHPStan/format checks, and review the final diff.

## Review

- Added an entity-wide pending-request guard shared by the web page, update-request action, and frontend API.
- Hid the update form while blocked, placed the Malay alert beneath the heading with responsive spacing, and localized the new message across supported locales.
- Preserved the existing proposer-scoped API request details while exposing only a boolean block indicator for other pending requests.

## Verification

- Focused parallel Pest run: 7 passed, 24 assertions.
- `vendor/bin/phpstan analyse --ansi`: no errors.
- Targeted Pint check, Blade cache compilation, PHP syntax checks, locale JSON validation, and `git diff --check`: passed.

## Live verification

- Submitted a speaker update in the browser, confirmed the Malay alert appeared beneath the heading with a 16px gap, and confirmed the update form was hidden.
- Approved the request through the admin panel, revisited the exact speaker URL, and confirmed the alert disappeared while the `Hantar Permintaan Kemas Kini` form returned.
- Restored the speaker's original test value after verification so the provided URL remained valid; the test request remains approved and no pending request remains.

# Remove unused institution unverified status

## Plan

- [x] Audit institution status usages and confirm whether existing unverified institution rows need migration.
- [x] Remove unverified from institution form, filters, admin API choices, and supporting documentation.
- [x] Add regression coverage for the reduced institution status set.
- [x] Run focused tests, static checks, and review the diff.

## Review

- Institution status is now limited to `pending`, `verified`, `rejected`, and `inactive` in the admin form, table filter, and admin API.
- Legacy institution rows with `unverified` are normalized to `pending` for review by the migration; the current local database has no such rows.
- This follow-up extends the removal to donation-account and venue status contracts.

## Verification

- Focused parallel Pest run: 2 passed, 22 assertions.
- `vendor/bin/phpstan analyse --ansi`: no errors.
- Pint check, Blade cache compilation, migration syntax check, and `git diff --check`: passed.
- The live admin URL returned `Forbidden` because the attached browser session is not an administrator; form/API regression coverage passed instead.

# Remove unverified record status globally

## Plan

- [x] Inventory record-status usages and separate them from unrelated email/address-validation terminology.
- [x] Remove the status from donation channels and venue copies, normalizing defaults and validators to `pending`.
- [x] Update tests, moderation labels, and status documentation.
- [x] Run the complete relevant test slice, static checks, and review the final diff.

## Review

- Removed `unverified` from all supported record-status contracts: institutions, speakers, references, venues, and donation channels.
- Legacy institution, venue, and donation-channel rows are normalized to `pending`; new donation-channel defaults and admin schemas now use `pending`.
- Preserved unrelated verification concepts for email, phone, address validation, and historical migration compatibility.

## Verification

- Relevant parallel Pest slice: 35 passed, 296 assertions.
- `vendor/bin/phpstan analyse --ansi`: no errors.
- Pint, Blade cache compilation, PHP syntax checks, locale JSON validation, and `git diff --check`: passed.

# Use custom Filament selects throughout the application

## Plan

- [x] Inventory application Select fields, SelectFilters, and explicit native overrides.
- [x] Configure Filament Select and SelectFilter components to use `native(false)` by default.
- [x] Replace the remaining explicit native Select override and add regression coverage.
- [x] Run representative frontend tests, static checks, and review the final diff.

## Review

- Added an application-wide Filament configuration so new form selects and table select filters use the JavaScript select automatically.
- Preserved `native()` on date and time picker components, which are separate controls and not Select fields.

## Verification

- Global configuration test: 1 passed, 2 assertions.
- Representative public and dashboard form tests: 9 passed, 106 assertions.
- PHPStan, Pint, Blade cache compilation, PHP syntax checks, and `git diff --check`: passed.

# Align public reference visibility and contribution snapshots

## Plan

- [x] Enforce `published_at` plus `verified`/`pending` for every public reference surface.
- [x] Align pending institution/reference detail authorization and public child-reference rendering.
- [x] Use the locked entity when capturing contribution-request original data.
- [x] Update factories, seed data, documentation, and regression tests.
- [x] Run focused Pest, static-analysis, formatting, and diff checks.

## Review

Public reference visibility is now consistently `published_at IS NOT NULL` plus `status IN ('verified', 'pending')` across directories, search/index payloads, event relations, detail authorization, family expansion, follow/share resolution, and public catalogs. Contribution updates now lock and re-read the target before snapshotting and reject duplicate pending requests across entity types.

## Verification

- Focused reference, event API, public-read, event-show, search, and searchable-model suites passed after the final fixes.
- `vendor/bin/phpstan analyse --ansi` — no errors across 1,011 files.
- Targeted Pint, syntax, translation JSON, view-cache, and `git diff --check` verification completed.
- Broad parallel suites previously showed nondeterministic unrelated failures; the affected tests were rerun individually or with focused filters and passed.
# Rebuild event detail page hierarchy and admission experience

## Plan

- [x] Inspect the target event’s real occurrence, session, access, ticket, seating, and location data.
- [x] Audit the events package and its commerce, ticketing, seating, media, and registration integrations.
- [x] Rebuild the public detail view with seamless single-occurrence/single-session presentation and explicit multi-level schedules.
- [x] Make registration, ticketing, capacity, and seating states appear only when supported and verify responsive behavior.
- [x] Run focused regression coverage, static checks, asset build, and browser verification.

## Review

The event detail page now presents the package graph as a coherent programme folio. A lone public occurrence is merged into the event’s date, time, location, time-expression, and admission presentation; a lone session is shown without redundant “Occurrence 1”/“Sessions” scaffolding. Multiple occurrences retain date boundaries, and each occurrence can show its session programme, scoped resources, speakers, location, and capacity.

Admission is now data-driven across event, occurrence, and session scopes. Public ticket types expose price, quantity, seating mode, inventory, sales windows, and scope; registration exposes opening/closing/full states; access policies expose approval, waitlist, capacity, notes, and walk-in status; seat maps expose sections and capacity. Unsupported blocks stay absent. Paid ticket states remain informative because the current application has no enabled public paid checkout integration.

## Verification

- vendor/bin/pest --parallel --compact tests/Feature/EventShowPageTest.php — passed (37 tests, 138 assertions).
- vendor/bin/pest --parallel --compact tests/Feature/EventSearchTest.php --filter=... — passed (14 tests, 39 assertions).
- vendor/bin/phpstan analyse --ansi app/Support/Events/EventDetailPresenter.php app/Livewire/Pages/Events/Show.php — passed.
- php artisan view:cache, npm run build, PHP syntax checks, and git diff --check — passed.
- Supplied event URL — HTTP 200; live HTML shows the location/reference content and omits schedule, admission, registration, and seating blocks because the stored event has no sessions or admission configuration.
- Full-project PHPStan still reports two pre-existing errors in app/Http/Controllers/Api/EventController.php and app/Support/Location/VisitorCountryResolver.php, both outside this change.
- Collaborative preview navigation/snapshot timed out repeatedly after reconnect; live HTTP verification was used instead.
