# A06 — Location and key-person corrections (superseded by first-class metadata hard cut)

This historical checklist is retained for traceability only. Its former metadata,
alias, and compatibility recommendations are not implementation guidance; the
canonical package relations and columns described in
`tasks/first-class-metadata-hard-cut-plan.md` supersede them.

## Location Tasks
- [x] 1. Event.institution() - already uses institution_id column ✓
- [x] 2. Event.venue() - already uses default_venue_id column ✓
- [x] 3. Store Space in EventLocation.venue_space_id (already done) + fix location_role → 'primary'
- [x] 4. Use location_role = primary consistently
- [x] 5. Replace Event.space() belongsTo with query through primary EventLocation
- [x] 6. Validate Space belongs to Institution (already done in SaveAdminEventAction) ✓
- [x] 7. Validate institution/venue mutual exclusion (already done in SaveAdminEventAction) ✓
- [x] 8. Update serializers to show institution+space OR venue+venueSpace

## Key-Person Tasks
- [x] 1. Update EventKeyPersonSyncService: use display_name consistently (already done ✓)
- [x] 2. Delete HasEventInvolvementRole trait
- [x] 3. Update EventKeyPerson model: replace metadata name with display_name
- [x] 4. Replace metadata->name search with display_name in EventSearchService + EventController
- [x] 5. Eager-load involveable/speaker

---

# Metadata hard-cut cross-repository audit

## Plan
- [x] Refresh codebase-memory indexes for `commerce` and `ilmu360`.
- [x] Audit `commerce` package contracts, migrations, models, tests, docs, and CI for removed metadata/legacy paths.
- [x] Audit `ilmu360` callers, migrations, serializers, tests, docs, and CI for removed metadata/legacy paths.
- [x] Delegate an independent Terra Extra High review and reconcile findings.
- [x] Implement fixes without compatibility aliases, shims, dual keys, or legacy fallbacks.
- [ ] Run focused tests, PHPStan/Pint, full quality workflows, and monitor GitHub Actions with `gh`.
- [ ] Repeat audit until both repositories are clean.

## Review

The cross-repository audit is in progress; local focused gates are clean after
the final regression batch. Commerce is green at commit `81da2f3f1c137f4442b0425c8d603a04843dd93b`
with Monorepo Split `29645941528`, CI `29645941526`, and style `29645941523`.
The app Composer lock/vendor tree is refreshed to events split SHA
`f10ce8de7b9180b2f529cf4d8560f10577165dc5`; the app refactor branch still needs
the final commit and Quality workflow confirmation.

### Final local regression batch

- [x] Refresh vendor packages before app verification.
- [x] Fix all failures from Quality workflow `29647038093` in one batch.
- [x] Re-run the affected app tests: Admin API (85 passed / 1,160 assertions) plus the remaining CI-failure files (293 passed / 2,337 assertions before the final five; the final five now pass).
- [x] Run Pint, Rector dry-run, PHPStan level 6, and `git diff --check`.
- [x] Confirm app production/test scans have no forbidden geography, metadata, or Event alias references.

# Person location country + speaker seeder

- [x] Locate the person edit location schema and speaker seeder.
- [x] Make country required in the location tab and ensure the saved path validates it.
- [x] Make speaker seeding assign Malaysia to every speaker.
- [x] Add or update focused tests and run formatting/static checks relevant to the change.

## Review

The Location tab now shows Country first and marks it required. `PersonSeeder`
assigns a primary Malaysia address when creating each seeded speaker; it does
not backfill existing records. The person edit page now hydrates and persists
the address state. Focused seeder tests pass.

# Non-production city seed reduction

- [x] Keep production city seeding unchanged.
- [x] Seed all Malaysian cities and a deterministic 10% sample for other countries outside production.
- [x] Verify the addressing seeder and production seeder behavior.

# Fix person institution attach selector

## Plan
- [x] Reproduce the Filament inverse-relationship failure from the reported stack trace.
- [x] Add a regression assertion for the explicit institution/person inverse relation.
- [x] Configure the relation manager and verify focused tests plus static checks.

## Review

Filament inferred `Person`'s inverse relation as `people`, but the application
model exposes the canonical inverse as `Institution::persons()`. The person
institution relation manager now declares `inverseRelationship = 'persons'`,
and the regression test passes.

Verification: focused Pest tests passed (2 tests / 10 assertions), Pint passed,
PHPStan passed for the changed PHP files, and `git diff --check` passed.

# Remove legacy person education tab

## Plan
- [x] Remove the obsolete Pendidikan tab from the person edit schema.
- [x] Add a regression assertion that the tab is absent while credentials remain in the profile flow.
- [x] Run focused tests, formatting, and static analysis.

## Review

The legacy Pendidikan tab was removed from the person edit form. The existing
Titles & Credentials section remains in Profil as the supported workflow.

Verification: focused Pest test passed (1 test / 2 assertions), Pint passed,
PHPStan passed for the changed PHP files, and `git diff --check` passed.

# Reactive primary toggles in Person form

## Plan
- [x] Inspect the Person form and existing repeater state patterns.
- [x] Implement live mutual exclusivity for contact and social repeaters.
- [x] Verify syntax, static analysis, and focused tests where available.

## Review

- Filament v5's `Toggle::fixIndistinctState()` now handles live sibling exclusivity
  for both relationship-bound repeaters. It uses the parent Repeater and absolute
  state paths, so UUID item keys are supported without custom `../` traversal.
- Verification: PHP syntax passed, Pint passed, PHPStan passed with no errors,
  and 3 focused Pest tests passed (5 assertions).

# Audit: Person media path architecture

- [x] Refresh the codebase graph and map Person/Speaker/media-related symbols.
- [x] Inspect Spatie media configuration, path generation, model collections, and upload callers.
- [x] Trace the Speaker-to-Person path cutover and existing media migration command.
- [x] Validate the local storage tree and targeted media regression coverage.

## Review

`Person` is now the `HasMedia` owner and the enforced morph alias is `person`, so
new media resolves under `persons/{uuid-shard}/{person-uuid}/{collection}/`.
The old custom path was `speakers/...`; existing files therefore require an
explicit storage migration. The current `media:migrate-structure` command only
assumes legacy `{media_id}/{file_name}` paths, so it does not by itself detect
or move already-customized `speakers/...` paths. The local storage tree contains
both prefixes. The codebase graph refresh completed in degraded mode after its
integrity check removed the index; the audit used direct source inspection as
the fallback. The focused Pest command was started but did not produce a
completion result in the current test environment and was stopped.
# Fix Person edit page timeout

## Plan
- [x] Map the timeout report to the Person edit form query paths.
- [x] Add a focused regression test for the excessive-query pattern.
- [x] Implement the root-cause fix.
- [x] Run focused tests, formatting, static analysis, and diff checks.

## Review

The Person edit timeout was caused by repeated address-profile country lookups
while Filament evaluated location field labels and visibility/options closures.
SharedFormSchema now caches the resolved hierarchy per country for the request,
and a regression test verifies that repeated label construction performs one
country lookup instead of three.

Verification: focused Person tests passed (3 assertions), the performance
regression passed, Pint passed, and PHPStan passed for the changed schema.

# Speaker social links visual refinement

- [x] Replace plain social links with branded icon tiles.
- [x] Add platform-aware icon treatment, handles, hover states, and keyboard focus states.
- [x] Verify the Wadi Annuar page and focused speaker tests.

## Review

The speaker sidebar now presents official social profiles as compact branded tiles using the bundled SVG assets, platform colors, profile handles, external-link affordances, and responsive two-column behavior on smaller widths. Verification: 5 focused tests passed (20 assertions), Pint passed, PHPStan passed, live HTML includes the Facebook, Instagram, and YouTube icon links, and `git diff --check` passed.

# Shared share-button icon refinement

- [x] Remove nested visual containers from share icons.
- [x] Enlarge the bundled brand SVGs and preserve accessible focus states.
- [x] Verify supported public share surfaces.

Verification: public share modal coverage passed (25 assertions), tracked-share UI coverage passed (24 assertions), and `git diff --check` passed.

# Profile review action icons

- [x] Add edit and flag icons to the profile update/report actions.
- [x] Preserve destinations, labels, hover motion, and keyboard focus states.

Verification: 5 focused speaker tests passed (20 assertions), Pint passed, PHPStan passed, and the live speaker page still renders both actions.

# YouTube social profile URL canonicalization

- [x] Trace YouTube URL construction through the contacting package, form schemas, save normalizer, API, and public profile rendering.
- [x] Change YouTube profile links from `/@handle` to `/handle`.
- [x] Update package fixtures and form hints, and add regression coverage for handle, current URL, public page, and admin API paths.
- [x] Complete final formatting, static analysis, and diff verification.

## Review

The contacting package now configures YouTube with `www.youtube.com/`. Form hints
no longer imply that every platform requires an at-sign.

Verification: focused normalization tests passed (9 tests / 29 assertions),
speaker-page tests passed (7 tests / 20 assertions), the admin API regression
passed (1 test / 19 assertions), the contribution workflow regression passed
(1 test / 6 assertions), Pint passed, PHPStan passed with no errors, and both
repositories passed `git diff --check`. The live Wadi Annuar record now resolves
YouTube to `https://www.youtube.com/UstazWadiAnnuarOfficial`.

# Move profile overview into hero

## Plan

- [x] Inspect the speaker view and identify the hero, biodata, summary, contact, and social blocks.
- [x] Keep contact and social media in their existing sidebar sections.
- [x] Move biodata and future/past event totals into the hero; remove the separate biodata and Ringkasan Profil sections.
- [x] Verify the corrected speaker view layout and final diff.

## Review

The speaker profile keeps contact and social media in the existing sidebar.
Biodata and future/past event totals are displayed within the hero, with long
biodata contained in a scroll region. Wadi Annuar's local biodata was expanded
to 1,146 characters for visual verification. Verification: Blade cache, focused
speaker coverage (5 tests / 22 assertions), and `git diff --check` passed.

# Keep hero biodata compact

- [x] Set a fixed compact biodata viewport so long content scrolls without increasing hero height.
- [x] Verify view compilation, diff checks, and focused speaker coverage.

Verification: fixed `h-48 max-h-48` biodata viewport, Blade cache, 5 focused
tests / 22 assertions, and `git diff --check` passed.

# Conditional biodata display

- [x] Hide the biodata block when no biodata exists.
- [x] Show the scroll hint only when biodata exceeds the scroll threshold.
- [x] Verify the empty, short, and long biodata states.

Verification: Blade cache, 6 focused tests / 25 assertions, and `git diff --check` passed.

# Reduce hero height

- [x] Tighten image minimum height, spacing, and biodata viewport while preserving scrolling.
- [x] Verify Blade compilation, focused speaker coverage, and diff checks.

Verification: compact `h-24 max-h-24` biodata viewport, 24rem desktop image
minimum, Blade cache, 5 focused tests / 22 assertions, and `git diff --check` passed.

# Optimize speaker page loading

- [x] Measure the route timing and SQL profile for a real speaker page.
- [x] Avoid eager-loading unused title assignments and keep count queries free of event relations.
- [x] Batch event classifications needed by the category presenter.
- [x] Verify successful warm requests, focused tests, Blade cache, and diff checks.

Review: the speaker route now returns successfully with warm repeated requests
measured around 0.6–0.8s locally, compared with the earlier 9–19s baseline.
Focused coverage passes (6 tests / 25 assertions).

# Investigate Wadi Annuar speaker performance

- [x] Profile Wadi Annuar against another speaker and identify the multi-event N+1 path.
- [x] Batch-load address area assignments and state data for event locations.
- [x] Remove repeated address formatter deprecation warnings.
- [x] Verify Wadi returns HTTP 200 with warm requests around 1.5–1.7s and 6 focused tests pass.

Review: Wadi’s multi-event profile was triggering repeated address hierarchy
lookups while rendering event locations. The page now measures about 0.66s in a
warm direct application request with 80 queries and 145ms SQL time; browser
requests include local Herd warm-up overhead.

# Deep speaker-page query optimization

- [x] Trace repeated relationship accessors and global page query families.
- [x] Eager-load active title assignments and title categories used by `formatted_name`.
- [x] Consolidate upcoming and past event totals into one conditional aggregate query.
- [x] Verify query-count reduction, focused speaker coverage, and diff checks.

Review: the Wadi Annuar page dropped from 76 to 48–49 application queries. The
repeated title-assignment queries dropped from 10 to 1, and the two event count
queries now share one aggregate query. HTTP timing remains variable because
global session, signals, address, and random inspiration queries sometimes have
high local PostgreSQL latency; the application-side duplicate query paths are
now removed.

# Further reduce speaker-page query count

- [x] Batch-load primary occurrences for other-role events used in sorting.
- [x] Memoize event taxonomy paths for the current request only.
- [x] Verify unchanged successful rendering, query inventory, tests, PHPStan,
  formatting, Blade cache, and diff checks.

Review: the Wadi Annuar page now completes with 39 in-process queries in the
same request profile. The other-role event occurrence N+1 path was replaced by
one eager-load query, and repeated event taxonomy loading was reduced to one
query pair per request. Session, Signals, random inspiration, contact, and
social-media queries remain intentionally intact because they provide page
behavior or tracking results.

# Exhaustive speaker query consolidation

- [x] Inventory every speaker request query and map it to rendering behavior.
- [x] Share one eager-load graph across upcoming, past, and other-role event
  data without loading full card relations for other-role cards.
- [x] Join address state and area display names into the required address loads
  while preserving hierarchy formatting and missing-data fallbacks.
- [x] Skip an empty upcoming/past event-page query when the exact aggregate
  total is already zero.
- [x] Sort other-role events by the primary occurrence in SQL and remove their
  standalone occurrence eager-load query.
- [x] Verify Wadi and Rozaimi responses, focused tests, PHPStan, Pint, Blade
  cache, and diff checks.

Review: Wadi now returns HTTP 200 with 33 in-process queries (Rozaimi: 29 in
the same profile). The inventory is now: speaker core (person, media,
contacts, socials, active titles, addresses), one event total aggregate, only
the non-empty event list queries, one shared event-card relation graph, one
other-role participation query plus its minimal event titles, one taxonomy
pair, random inspiration plus media, two Signals configuration queries, and
session lifecycle queries. The shared middleware queries remain because
removing them would change session, tracking, inspiration, or authentication
behavior rather than merely remove duplication.

# Route-map optimization and package ownership audit

- [x] Review the complete public web/API route map for the same event-list and
  relation-loading patterns found on speaker pages.
- [x] Audit whether the speaker optimizations belong in `aiarmada/events` or
  `aiarmada/addressing`; keep product-specific status, visibility, occurrence,
  and presentation logic in the application.
- [x] Apply shared event-page totals/list loading to institution, venue, and
  series detail pages.
- [x] Remove the extra API detail count query by carrying the exact total in a
  window value on the bounded event result query.
- [x] Verify web/API route responses, focused tests, PHPStan, Pint, Blade cache,
  and diff checks.

Review: the reusable part is application-owned because the page queries depend
on ilmu360's public event statuses, visibility policy, occurrence-backed date
mapping, and card payloads. The package audit found no safe extraction into
`aiarmada/events` or `aiarmada/addressing` without making those packages aware
of product policy. Institution, venue, and series web detail routes now use
one upcoming/past totals query and skip empty list queries; the five public
API entity-detail families use one bounded event query per section instead of
an additional count query. All sampled web/API routes returned HTTP 200.

# Seed speaker history and verify past-event performance

- [x] Add deterministic approved/public historical events for Wadi Annuar and
  Rozaimi Ramle through a dedicated speaker event seeder.
- [x] Register the seeder after the main event seeder.
- [x] Run `php artisan migrate:fresh --seed`.
- [x] Verify past-event rows and profile query behavior for both speakers.
- [x] Invalidate the speaker event-page cache when load-more limits change.

Review: the fresh seed creates four guaranteed past events for each target
speaker. Wadi has 4 past and 1 upcoming event; Rozaimi has 4 past and 2
upcoming events. Both speaker pages render with 28 in-process queries, and the
existing combined totals/shared eager-load path covers the populated past
branch without an additional per-event query.

# Restore canonical person location selections in admin

- [x] Trace Rozaimi Ramle's stored address and admin edit-form hydration.
- [x] Hydrate state/city IDs and preserve text fields for person and institution
  address edit forms.
- [x] Resolve exact text-only state/city locations within the stored country.
- [x] Add a regression test for a text-only address containing Johor and Kota
  Tinggi.
- [x] Verify the real Rozaimi record resolves to canonical selections.

Review: Rozaimi's address contains `state = Johor` and `city = Kota Tinggi`,
but its canonical IDs are null. The shared hydration path now resolves those
values to the matching package State and City records, so the admin selects
the correct options without changing the public location fallback. The save
path also derives denormalized text from canonical selections and clears a
stale city when the admin saves with no city selected, allowing person slugs
to drop obsolete city segments.

State-clear follow-up: admin edit handlers now explicitly persist a cleared
state and clear dependent city values/text instead of allowing omitted null
fields to restore the previous address.

# Show country-only person locations in the public hero

- [x] Trace the public hero location formatter for country-only addresses.
- [x] Add country fallback when no city, area, or state is available.
- [x] Add a public person-page regression test for Malaysia-only location data.
- [x] Verify focused person-page tests and static checks.

Review: public location formatting now displays the canonical country name when
an address contains only a country, while regional addresses retain their
existing city/area/state output.

Follow-up audit: all 21 formatter consumers inherit this country-only fallback,
including person, institution, venue, event, series, search, API, and MCP
location displays. The venue-specific detail view already includes the shared
hierarchy and an explicit country fallback.

# Audit canonical location and slug synchronization across entities

- [x] Inventory person, institution, venue, contribution, and admin address
  persistence paths.
- [x] Preserve text-only state/city values through shared address preparation.
- [x] Add canonical state/city fallbacks to person, institution, and venue
  slug builders.
- [x] Force fresh address relations before direct slug regeneration.
- [x] Add regression coverage for text-only persistence and canonical-ID slug
  generation.
- [x] Run focused slug/admin tests, Pint, PHPStan, Blade cache, and diff checks.

Review: the same stale-location risk was present in shared create/update
preparation and in slug builders that depended only on denormalized text. The
address observer already refreshes person, institution, and venue slugs after
address changes; the audit now makes its inputs canonical-safe as well.

## Speaker upcoming-event filter loading fix

- [x] Add a hidden fallback to the filter loading status.
- [x] Verify initial render and filter interaction in Chrome.
- [x] Run focused regression checks.

Review: Chrome confirmed the loading badge was visible on first render because
the raw `wire:loading` element had no fallback visibility state. The status now
starts hidden and is only shown during an active filter request.

## Upcoming-event filter interaction refinement

- [x] Replace the select with a stable segmented button filter.
- [x] Keep loading and result-count slots from changing the header layout.
- [x] Verify the selected state and empty result state in Chrome.

Review: the filter now uses Flux's segmented radio group in a contained,
horizontally scrollable panel, with a reserved loading slot and stable result
count so changing ranges does not move the surrounding header content.

## Upcoming-event filter placement refinement

- [x] Move the filter below the schedule heading.
- [x] Keep the filter directly above the event results.
- [x] Verify vertical spacing and filtering in Chrome.

Review: the filter now sits in the schedule flow between the heading and the
event content, with a responsive inline layout on larger screens and a clean
stack on smaller screens.

## Header count and filter label refinement

- [x] Remove the redundant “Paparkan:” label.
- [x] Restore the active-count badge to the schedule header row.
- [x] Verify the final hierarchy and filter behavior in Chrome.

Review: the header now owns the schedule title and active count, while the
segmented date filter remains directly above the event results.

## Custom upcoming-event date range

- [x] Align the active-count badge to the bottom edge of the header.
- [x] Add a Flux segmented “Tarikh pilihan” option.
- [x] Add start/end calendar inputs and apply the range with timezone-aware boundaries.
- [x] Verify custom range filtering in Chrome and regression tests.

Review: custom ranges now open inline calendar inputs beneath the segmented
filter and apply only after the user confirms the selected start and end dates.

## Calendar trigger and centered filter refinement

- [x] Replace the custom-range segmented option with a calendar icon trigger.
- [x] Open the range inputs in a Flux modal and retain an active icon state.
- [x] Center the preset filter row and verify modal/application behavior in Chrome.

Review: preset filters remain the centered primary control, while custom ranges
are available through a compact calendar action with a clear selected state.

## Loading and calendar icon polish

- [x] Replace the visually weak loader with a clearly animated border spinner.
- [x] Redesign the calendar trigger as a circular ghost icon button.
- [x] Verify the in-flight spinner state and final filter behavior in Chrome.

Review: the loading state now visibly animates during the request, and the
calendar action has a lighter circular treatment with clear active contrast.
