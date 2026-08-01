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
- [x] Run focused regression tests, formatting, and static analysis.

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

- [x] Reproduce the dropdown and search behavior through Chrome.
- [x] Fix case-sensitive institution and alternate-name matching.
- [x] Verify lowercase `masjid` search returns live institution options.

Chrome verification returned institution options for lowercase `masjid` with
no console errors. Institution-focused contribution coverage passed 21 tests /
126 assertions; Pint, PHPStan, and diff checks passed.

## Institution dropdown initial options

- [x] Reproduce the empty list when opening the institution field without typing.
- [x] Add a bounded initial list while preserving server-side search.
- [x] Verify opening and selecting an institution directly in Chrome.

Chrome now shows the first 50 institutions immediately on click and successfully
selects an institution without requiring prior text entry.

## Institution search latency

- [x] Trace `/institusi?search=shah+alam` from route to search service, SQL, eager loads, and render.
- [x] Capture baseline query count, timings, and PostgreSQL query plan.
- [x] Remove the redundant scoped-ID round trip from direct search pagination.
- [x] Cache the country catalog and default country lookup through the existing selection catalog cache.
- [x] Add focused query-count regression coverage and verify the Livewire page.
- [x] Review Livewire deferred/island loading behavior against official documentation.

## Review

The direct institution search now applies the current location scope, directory ordering, page slice, and total in one hydration query. The total is read from
`COUNT(*) OVER ()`; only an out-of-range page falls back to a count query. The
country selector and default country resolution reuse the existing address
catalog cache. A deferred Livewire island remains a UX option, but it would
move the full result query into a second request rather than make the query
itself faster, and must be synchronized carefully with live search state.

Baseline: cold local `/institusi?search=shah+alam` was 16 SQL queries / ~57 ms
reported DB time; the optimized path is 15 SQL queries with one institution-ID
scope query removed. Focused InstitutionIndex coverage passed 29 tests / 126
assertions; targeted PHPStan passed.

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
skeleton, while `always` island updates, stable keys, and a transition improve
search/filter interactions without sacrificing SEO or first-response content.
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

- Homepage lower sections already use `lazy.bundle` / `defer.bundle` and matching `@placeholder` views (`resources/views/components/pages/⚡home.blade.php:350-502`).
- Search/list pages already use `#[Computed]`, `#[Url]`, `WithPagination`, stable `wire:key` values, targeted `wire:loading`, and `wire:navigate`.
- The events, institutions, and persons lists already use islands around result regions; the submit-event editor already uses `wire:ignore` for third-party DOM ownership.
- `Route::livewire()` and v4 config keys (`component_layout`, `component_placeholder`, `smart_wire_keys`) are already in place.

### Prioritized opportunities

1. **High — isolate event-detail engagement actions.** `Events\\Show` has a 2,774-line Blade view and eagerly loads a wide relation graph in `mount()` (`app/Livewire/Pages/Events/Show.php:68-150`). Its `toggleGoing`, `checkIn`, and `toggleSave` actions live on the page component and therefore can cause the whole detail tree to re-render. Move the action toolbar into a child component or named island, keeping only the small stateful region interactive. Preserve the current server-side authorization and outcome tracking.
2. **High — reduce filter request frequency on the events index.** The advanced filter view uses `wire:model.live` for discrete filters and the radius range input (`resources/views/livewire/pages/events/index.blade.php:545-651`). The backing computed query invokes `EventSearchService` and paginates 12 results (`app/Livewire/Pages/Events/Index.php:1289-1377`). Keep immediate updates only where the cascade requires them (state → district → subdistrict); change the range to a debounced or change-triggered update and consider batching multi-select/date filters behind an Apply action. Measure request count and query latency before/after.
3. **Medium — split/defer below-the-fold event detail data.** The event detail page loads galleries, announcements, occurrences, related entities, and registration data up front. A lower-fold child component/island with a placeholder could defer gallery/related/context sections while keeping SEO-critical event information in the initial response.
4. **Medium — reduce unified-search fan-out.** One debounced keystroke on `resources/views/livewire/pages/search/index.blade.php:85` can execute event search plus person, institution, and reference result queries; each result family also performs a count and a limited fetch (`app/Livewire/Pages/Search/Index.php:66-190`). Consider a slightly longer debounce, a minimum query length, or an explicit submit mode on slower connections. Validate with query/request telemetry because the current UX may justify the cost.
5. **Low/adjacent — fix the homepage institution count query.** `home.stats` counts institutions by loading all active upcoming events and doing `pluck()->unique()` in PHP (`resources/views/components/home/⚡stats.blade.php:32-40`). Replace it with a database-side `distinct()->count('institution_id')` query. This is an Eloquent/database optimization rather than a Livewire feature, but it directly improves a lazy homepage component's server work.

### Deliberately not recommended from the supplied docs

- Do not add `#[Js]`, `wire:text`, `wire:replace`, `wire:sort`, or `wire:intersect` globally. The codebase has no matching interaction that clearly benefits; Alpine already handles client-only toggles/geolocation, and existing pagination/loading behavior is intentional.
- Do not convert all class-based components to SFCs. The project already has a mixed convention and the v4 docs recommend following the established convention; format conversion is not a performance win by itself.
- Do not add full-page lazy loading to primary routes. It would defer the page itself rather than just expensive lower sections and could worsen perceived first content.

### Verification

- Livewire 4 docs were reviewed for lazy/defer/bundling, islands/nesting, loading, computed properties, navigation, URL state, Alpine/JS, pagination, and the listed directives.
- `php -l` passed for the reviewed Events Index, Events Show, and Search components.
- Focused parallel Pest run: **147 passed, 2 failed**. Both failures are existing asset assertions expecting `/flux/flux.js` to be absent in `HomePageTest` and `EventSearchTest`; no review code changes caused them.
- The worktree already contained unrelated user modifications in application/test files; this review only added this task entry.
