# Friendliness + Lifecycle Hard Cut

## Comprehensive aiarmada package-first refactor (current pass)

- [x] Inventory every direct `aiarmada/*` requirement and its installed package source, exports, extension seams, and host-model configuration points.
- [x] Map app actions, services, contracts, interfaces, enums, models, concerns, data objects, listeners, jobs, controllers, and Filament integrations to package capabilities using the codebase graph.
- [x] Classify each overlap as: delegate to package, extend package generically, keep app-owned, or remove as dead/legacy code; record evidence before editing.
- [x] Implement the highest-confidence package-native refactors without disturbing unrelated user changes.
- [x] Add or update package-level generic seams when a capability is reusable outside ilmu360°; keep app-specific policy and presentation in the app.
- [x] Run focused tests, package/app static analysis where available, and boundary searches; record failures caused by environment or pre-existing work.
- [x] Review the final diff for duplicate orchestration and update the lessons section if a correction is made.

### Review

- Completed. The detailed package-by-package evidence and retained-boundary decisions are in `docs/aiarmada-adoption/comprehensive-package-audit.md`.
- Hard cutovers removed the app's duplicate event-series pivot, dead communications/rendering listeners, invalid affiliate conversion aliases, and direct app persistence for affiliate links/outcomes.
- The full affiliate package slice passed: 1,201 tests, 2,732 assertions, with five intentional skips.
- Focused package conversion tests passed: 49 tests, 156 assertions. Focused app share/Signals tests passed: 2 tests, 15 assertions. The package focused command still reports the repository's unrelated `RuntimeException` unused-import warning as a non-test exit failure.
- Series relation tests no longer fail on the package pivot's missing polymorphic identifiers; remaining failures are unrelated existing status/reference fixtures.

## App-to-Aiarmada Architecture Audit

- [x] Inspect project guidance, index status, and high-level package architecture.
- [x] Inventory `app/*` definitions and trace their package/domain relationships.
- [x] Classify relocation candidates with evidence, confidence, and non-candidates.
- [x] Write the audit report and review findings against the codebase.

### Architecture audit review

- Reindexed the repository in full before auditing; the graph transport closed afterward, so current filesystem and installed package source were used for the detailed evidence.
- Strong candidates: rename the app-only `RegistrationMode` scope to `RegistrationScope`; consolidate event lifecycle/moderation with `aiarmada/events`; move generic event search depth behind package search seams; retire the Spatie Tag path in favor of package taxonomy; extract generic event resource synchronization.
- Secondary candidates: address hierarchy formatting, slug-or-UUID lookup, reusable media mechanics, API pagination/response primitives, and the overlapping audit concern.
- Keep app-owned: public UX/API/MCP/Filament composition, Islamic/prayer/product rules, AI, donation, media presentation, timezone, and integration adapters.
- First refactor slice completed: renamed `app/Enums/RegistrationMode.php` to `app/Enums/RegistrationScope.php` and migrated all app/test callers without changing the public `registration_mode=event` value. Detailed HTML report: `/tmp/ilmu360-app-aiarmada-audit.html`.

### Registration scope refactor

- [x] Rename the app-only whole-event scope enum to `RegistrationScope`.
- [x] Migrate advanced builder, API, MCP, support, and test callers.
- [x] Verify package `AIArmada\Events\Enums\RegistrationMode` remains the policy enum.
- [x] Run syntax checks and focused event/API tests.

#### Review

- Syntax checks passed for all changed PHP files.
- The focused advanced-builder context test passed: 1 test, 7 assertions.
- The broader `EventActionsTest` run had two unrelated pre-existing failures (`Event::settings` relation and active organizer fixture); the refactor-specific test passed.
- The selected admin event API test remains blocked by unrelated missing `event_format` and stale `status=active` fixture data.
- Composer autoload metadata was regenerated; its post-autoload `package:discover` hook could not complete because PostgreSQL is not running, but direct autoload checks confirm `RegistrationScope` and the package `RegistrationMode` load while the old app enum no longer exists.

### Communications and Signals package cutover

- [x] Delegate notification read-state persistence to `AIArmada\Communications\Services\NotificationInboxService`.
- [x] Preserve app-owned archived filtering, user-owned 404 behavior, API/Livewire payloads, and Signals events.
- [x] Make the Signals adapter’s package `trusted` argument explicit: internal product/affiliate events are trusted; mobile telemetry remains untrusted.
- [x] Verify notification API/inbox/delivery tests and focused Signals telemetry tests.

#### Review

- Notification API: 5 passed, 9 assertions.
- Notification inbox page: 2 passed, 10 assertions.
- Notification delivery flow: 3 passed, 9 assertions with two parallel workers; the default eight-worker run hit a Paratest temporary-output race.
- Focused Signals telemetry: 4 passed, 20 assertions.

### Event registration and check-in cutover review

- [x] Compare package free-registration and check-in interfaces with both app entrypoints.
- [x] Upgrade the generic events package model seam so host applications can configure event, registration, and attendance subclasses.
- [x] Cut public registration entrypoints over to package `RegisterForFreeAction` and remove the app’s low-level registration persistence path.
- [x] Cut app check-in persistence over to the package `EventCheckInService`; keep only app eligibility, Signals/share tracking, notifications, and API/UI presentation in the app.
- [x] Remove legacy check-in aliases and make package canonical fields authoritative.

#### Review

- Package model resolution now supports configured host subclasses through `events.models` and `AIArmada\\Events\\Support\\ModelResolver`.
- Registration API focused suite: 4 passed, 30 assertions; registration safety suite: 3 passed, 10 assertions.
- Check-in API package-backed suite: 3 passed, 33 assertions.
- Full check-in files still contain unrelated stale Livewire/access-policy fixtures outside this cutover; those are not compatibility paths retained by the refactor.

### Remaining package-boundary review

- [x] Re-audit the taxonomy dual path: current event writes/forms/search projections use package `EventTaxonomy/EventTerm/EventClassification`, while the app `Tag` model, admin resource, factories, and legacy tests still expose a separate Spatie tag contract.
- [x] Move generic classification synchronization into `AIArmada\\Events\\Actions\\SyncEventClassificationsAction`; keep only ilmu360 taxonomy vocabulary mapping in the app adapter.
- [x] Replace event-facing Spatie tag reads in public event pages, duplicate-event prefilling, submission preview/options, home category links, and saved-search labels with package classifications/terms.
- [x] Upgrade package event search/query/write scopes and queued indexing to resolve configured host event subclasses through `ModelResolver`.
- [x] Hard-cut the event-facing Spatie Tag path to package taxonomy; the remaining `App\\Models\\Tag` surface is isolated to a legacy admin taxonomy editor and stale fixtures, with no event persistence, public event rendering, search classification, or submission path consuming it.
- [x] Re-audit lifecycle/moderation and search: app status vocabulary, moderation side effects, prayer-relative filters, Typesense configuration, and public payloads remain app-owned; package workflows/search interfaces are valid seams for generic package upgrades.
- [x] Upgrade package search/query seams for configured host models while keeping prayer-relative and Islamic presentation rules app-owned.
- [x] Prove lifecycle/moderation is product-specific: the app `draft/pending/approved/needs_changes/rejected/cancelled` workflow carries ilmu360 review policy, reason codes, Signals, notifications, and public visibility semantics that do not match the package event lifecycle states; package lifecycle remains authoritative for generic occurrence/session operations.
- [x] Prove the remaining app search facade is product-specific at its outer boundary: Typesense fallback, user-timezone date boundaries, prayer-relative filters, Islamic taxonomy presentation, nearby discovery, and API payload shaping stay in the app; generic package criteria/search/indexing seams are upgraded and reusable underneath.
- [x] Complete the package-first refactor audit: each remaining app boundary is either delegated to an aiarmada package or explicitly retained as product policy/presentation/integration code.

## Done
- [x] Lifecycle helper, migrations (Postgres applied), drop entity `is_active`
- [x] Event transitions + ContributionRequest + Report timestamps
- [x] Captcha / GitHub / ShareTracking contracts (+ null objects)
- [x] Fixed bad bulk rewrite: `is_active` → wrong `status in (verified,pending,active)` on **events** → `published_at` + `PUBLIC_STATUSES`
- [x] Spaces stay on `status=active` (not verified/pending)
- [x] Speakers show/index no longer call broken relation `->active()` scope
- [x] EventFactory no longer overwrites status with invalid `active`
- [x] EventKeyPerson mapping + sync package columns
- [x] Migration fix: pgsql `jsonb_exists` instead of `?` (PDO binding conflict)

## Verified green
- LifecycleHardCutTest, EventTest, ActiveScopeTest, SpeakerIndexTest, InspirationTest, EventVisibilityAccessTest

## Remaining known failures (not blocking hard-cut core)
- InstitutionIndexTest: 4 cases (duplicate name validation, location hierarchy/filter/dedupe) — look address-scope related, not `is_active` column
- ModerationServiceTest: package `moderation_actions` vs app expectations (`moderator_id`, `decision`) — pre-existing package cutover debt
- Mega-service splits still optional

## Intentional `is_active`
- Package catalog (EventTerm/Taxonomy/Role), signals TrackedProperty, migration strings, lifecycle assertion names

## Package boundary cleanup

- [x] Inventory app actions/services/support classes that implement reusable package-domain behavior.
- [x] Extract the generic AddressArea save workflow into `aiarmada/addressing`.
- [x] Update app consumers and remove the app-owned duplicate.
- [x] Audit adjacent package integrations for additional clearly generic extractions.
- [x] Run focused tests, PHPStan, and boundary searches.

## International addressing State/City refactor

- [x] Reindex app and commerce repositories; fall back to targeted local inspection if the graph service is unavailable.
- [x] Model `State` as an optional country-specific first-level administrative region, not a Malaysia-only concept.
- [x] Make `City` independently country-scoped with an optional `state_id` relationship.
- [x] Add a generic package extension seam for country-specific geography rules and AddressArea mappings.
- [x] Update package consumers, seeders, validation, factories, and tests for countries without state/province relationships.
- [x] Run focused package/app tests, PHPStan, migration/schema checks, and boundary searches.

#### Review

- `codebase-memory-mcp` reindex/search was attempted for both repositories but the graph transport closed; targeted filesystem/package inspection was used instead.
- `aiarmada/addressing` now owns generic country address profiles/providers, nullable country-scoped City→State relationships, package-owned Malaysia geography data, and explicit State↔AddressArea links with no name matching.
- ilmu360 forms, contribution mutation, catalog APIs, directory filters, saved-search validation, MCP event search, API payloads, deletion rules, JSON-LD, and display formatting now consume country/profile-scoped address data. Structured area slots 1–4 are preserved instead of being forcibly nulled.
- The app-only Malaysian federal-territory helper was removed. Malaysia’s direct State-to-area behavior is package provider data; country-specific support can be added through another provider.
- Focused app suite passed: 19 tests, 112 assertions for geography deletion/forms, Google place selection, API schema serialization, and institution location picking. Additional Google/location suite passed: 14 tests, 93 assertions. The country-aware formatter test passed standalone: 1 test, 6 assertions.
- Public catalog API passed: 4 tests, 22 assertions. API documentation schema serialization passed: 11 assertions with existing test warnings and no failures.
- Package PHPStan passed from `/Users/Saiffil/Herd/commerce`: no errors. App PHPStan bootstrap remains blocked by PostgreSQL not running on `127.0.0.1:5432`.

### Review

- AddressArea persistence now lives in `aiarmada/addressing`; the Filament package and app admin API both consume it.
- `AddressCountryResolver` and `AddressAreaStateBridge` now live in `aiarmada/addressing`; product-specific formatting and notification/signals adapters remain app-owned.
- Package addressing tests passed: 119 tests, 223 assertions; extracted files pass focused PHPStan and syntax checks.
- App focused tests passed for geography forms, submit-event end times, Google place selection, and institution location picking. EventSearchTest remains blocked by unrelated pre-existing event/tag cutover failures.
- App focused PHPStan reports five pre-existing issues outside this extraction; the full app baseline currently reports 184 existing issues. No new errors were found in the extracted package files.

## Slug architecture review

- [x] Inventory app and `commerce-support` slug definitions, traits, services, actions, model casts, routes, and tests.
- [x] Trace slug generation, normalization, persistence, lookup, and redirect behavior across the application.
- [x] Compare app-owned slug behavior with the shared package contract and classify overlap versus product policy.
- [x] Record findings, risks, and recommended boundary in the review section below.

#### Review

- `aiarmada/commerce-support` is installed as a local path package (`/Users/Saiffil/Herd/commerce/packages/commerce-support`) and exposes only the final `AIArmada\\CommerceSupport\\Support\\SlugGenerator` utility: generic `generate()` and `exists()` methods. It has no slug contract, trait, persistence hook, route resolver, or redirect workflow.
- The app uses the shared utility only in `app/Actions/References/GenerateReferenceSlugAction.php:62`, for the uniqueness query. `GenerateEventSlugAction`, `GenerateInstitutionSlugAction`, `GenerateSpeakerSlugAction`, and `GenerateVenueSlugAction` each contain equivalent private `slugExists()` queries.
- Full slug composition is intentionally app-specific: events combine title, speaker slugs, and local date; institutions and venues add geography/country; speakers use normalized display-name parts plus geography; references use the product fallback and ordered duplicate sequence. `commerce-support::generate()` cannot replace these policies because it only derives one attribute and appends generic `-1`, `-2` suffixes.
- `app/Models/Reference.php` extends the package Reference model, which uses Spatie `HasSlug`; the app intentionally overrides `bootHasSlug()` with an empty method and generates through its own saving hook/action. This is a valid product override, but leaves the inherited `getSlugOptions()` contract present while package auto-generation is disabled.
- Public slug resolution/history is app-owned: `SlugOrUuidResolver`, `PublicSlugPathResolver`, route bindings in `AppServiceProvider`, `ResolvePublicSlugAction`, `ResolvePublicSlugRedirect`, `SyncCanonicalSlugAction`, and `SyncSlugRedirectAction`. The redirect workflow records visited non-event paths and intentionally records event slug changes even when unvisited; the tests document that exception.
- Series and Spaces are separate manual-slug surfaces: their forms/actions accept writable slugs and perform local uniqueness validation. Tag slugs are Spatie Tags JSON/localized data and are unrelated to the shared utility.
- Recommended boundary: keep composition, geography, public routes, redirects, and product lifecycle in the app; immediately replace the four duplicate app uniqueness queries with `SlugGenerator::exists()`. Consider a later package extension only for a model-aware/scoped slug contract if multiple packages need it; do not move the current product policies wholesale.
- Verification: commerce-support `GenerateReferenceSlugActionTest` passed (4 tests, 6 assertions); app `SpeakerSlugGenerationTest` passed (25 tests, 64 assertions); app `SlugOrUuidResolverTest` passed (2 tests, 5 assertions). `InstitutionSlugGenerationTest` had 12 passing and 1 existing geography slug expectation mismatch; `SlugRedirectFeatureTest` had 17 passing and 1 unrelated view fixture failure. The broad slug-filtered run also picked up unrelated failures (65 passed, 7 failed).

## Slug cleanup implementation

- [x] Replace app-local slug existence queries with `AIArmada\\CommerceSupport\\Support\\SlugGenerator::exists()`.
- [x] Restore the canonical institution geography slug segment required by `InstitutionSlugGenerationTest`.
- [x] Run focused slug tests and static checks; document unrelated failures separately.

#### Review

- Completed. Event, institution, speaker, and venue slug actions now delegate final candidate existence checks to the shared `commerce-support` `SlugGenerator::exists()` utility.
- Institution geography slugs now derive a missing district from the selected subdistrict's parent, preserving the canonical city-district-state-country expectation.
- Focused verification passed: `InstitutionSlugGenerationTest` (13 tests, 33 assertions), `SpeakerSlugGenerationTest` (25 tests, 64 assertions), and PHPStan on all four changed slug actions.
- Full app PHPStan remains non-green because the existing codebase reports 154 unrelated errors; the default bootstrap also needs PostgreSQL, which is not running locally. `git diff --check` reports existing trailing whitespace in `docs/aiarmada-adoption/status.md`, outside this change.

## Whole-application API and MCP audit (2026-07-14)

- [x] Preserve the existing dirty-worktree baseline and record the review scope.
- [x] Map all application, API, and MCP entry points with the codebase graph.
- [x] Run independent read-only audits of API contracts, MCP registration/tools, core domain orchestration, and Pest failures.
- [x] Apply only evidence-backed fixes in isolated write scopes, preserving other in-progress work.
- [x] Run focused suites, parallel Pest, PHPStan, and contract/boundary checks; document every remaining failure with its root cause.

### Review

- The audit retained the existing dirty worktree and treated the canonical package schema and current application behavior as the contract; stale Pest fixtures were updated instead of restoring legacy aliases.
- Repaired API/MCP event taxonomy writes to validate package `EventTerm` records by taxonomy, corrected metadata-backed event-type filtering, and aligned MCP event descriptions with event-term UUIDs.
- Repaired package-bound event writes: named non-speaker key people now survive sparse updates, venue `type` updates persist, membership-review responses refresh their transition state, and app `Space::factory()` creates the host model.
- Corrected stale event/reference/series pivot fixtures from `order_column` to `sort_order`, event taxonomy fixtures from Spatie Tags to package terms, and reference-family fixtures from the removed `parent_reference_id` column to `parent_id`.
- Verification passed: Admin API 84 tests / 1,156 assertions; Admin MCP server; MCP image generation; MCP debug-log 7 tests / 61 assertions; MCP security checklist 3 tests / 18 assertions; reference-family 5 tests / 32 assertions; and frontend API parity. Focused PHPStan for all changed API/MCP write-path classes passed. `git diff --check` passed.

## API P1 defect repair (2026-07-14)

- [x] Trace the catalog, event, and registration API paths with codebase-memory before source inspection.
- [x] Wire the public membership subject catalog route to the existing controller method and cover the endpoint.
- [x] Update event index filters to expose only canonical package columns and metadata; cover all affected filters.
- [x] Remove the stale event institution column from the registrations API select and verify the response contract.
- [x] Run the focused Pest files in parallel and record the exact outcomes.

### Review

- Catalog API: 5 passed, 26 assertions; user registrations API: 2 passed, 28 assertions.
- Event API contract: 28 passed, 190 assertions. PHP syntax and `git diff --check` passed for the scoped files.

## Whole-application hard-cut audit (2026-07-14)

- [x] Inventory all route groups, input forms, and workflow entry points; trace each mutation to one canonical action.
- [x] Audit every observer/listener for stale models, duplicate side effects, and package-incompatible event hierarchy assumptions.
- [x] Replace app-level parent-event semantics with the package `Event -> EventOccurrence -> EventSession` hierarchy where any remain.
- [x] Inspect commerce commit `cd6d23c0da6b438d106541974f949204a3bb32ff`, adopt applicable template implementations, and cover the integration.
- [x] Audit every Filament resource, schema, table, page, and relation manager for stale fields, models, workflows, and authorization.
- [x] Run focused parallel Pest/PHPStan per repaired slice, repeat the audit until findings are resolved, and record final evidence.

### Review

- Completed as a no-legacy hard cut. The historical `EventStructure`, `parent_event_id`, self-referential event relations, and child-event UI/API contracts were removed. Advanced creation now produces an `Event` and initial `EventOccurrence`; subsequent standard submissions create `EventSession` records beneath that occurrence.
- The commerce template implementation is active through `FilamentEventsPlugin`; `EventTemplateResource` is registered in both panels and covered by `AdminResourcesCoverageTest`.
- Observer/listener review made side effects idempotent, moved safe side effects after commit, corrected stale package aliases in `EventObserver`, and verified framework event discovery.
- Verification: `EventTest` 6/34, `CalendarServiceTest` 10/34, `AdminResourcesCoverageTest` 3/35; focused PHPStan on the hierarchy implementation passed; `php artisan route:list --json` and `git diff --check` passed. The codebase-memory index transport was unavailable, so targeted local discovery was used as the documented fallback.
# Full test repair and touched-code audit

- [x] Establish the current full-suite failure inventory and separate environment/bootstrap failures from product failures.
- [x] For each failure cluster, use the codebase graph and current implementation as the source of truth; audit the production code exercised by the tests.
- [x] Apply hard-cut fixes without compatibility aliases or legacy behavior, updating tests only where their contract is stale.
- [x] Run focused tests after each cluster and then the complete Pest suite in parallel.
- [x] Run PHPStan and relevant boundary/static checks; inspect the final diff and document findings.

### Review

- Repaired API event metadata serialization, frontend membership authorization fixtures, engagement-based event tests, communications destinations, notification routing tests, contribution owner-context boundaries, event submission relation hydration, and membership audit hooks.
- Removed tests for deleted scoped-role/tag APIs instead of retaining compatibility shims.
- Focused repaired suites are green; the full feature suite remains resource-limited by the nested Scramble documentation process and PHPStan still reports pre-existing refactor errors outside the touched paths.

# Scramble documentation hardening

- [x] Reproduce the Scramble memory failure from a cold documentation cache.
- [x] Align Scramble assertions and the membership application manifest route with the refactored codebase.
- [x] Make stale OpenAPI responses immediate and prevent the cache pointer from advancing to an uncached document.
- [x] Run the cold-cache Scramble suite and adjacent documentation boundary checks.

### Review

- `DocsJsonController` now uses stale-while-revalidate behavior when a prior OpenAPI artifact exists: lock contention does not wait for large regeneration, and the latest-key pointer changes only after the new artifact is cached.
- The membership application manifest route now calls the canonical `membershipClaim` controller method; stale Scramble expectations were updated for canonical admin geography, catalog, filter, and schema contracts.
- Verification passed: `ScrambleDocsTest` 30 tests / 387 assertions from a cold cache; API documentation schema serialization 2 / 11; documentation support 1 / 3; documentation URL resolver 2 / 6; and route caching guard 1 / 1. `git diff --check` passed.
- The parallel frontend parity command remains non-green because its large stateful fixture file is not parallel-safe (17 failures / 82 passed); this is unrelated to Scramble and is not included in the Scramble change.

# Scramble documentation discovery index

- [x] Add a lightweight JSON index linking to the complete and focused OpenAPI contracts.
- [x] Add focused section specifications derived from the canonical cached Scramble document.
- [x] Update AI quickstart discovery order and cover host, response, and section behavior.
- [x] Run the complete Scramble suite and scoped PHPStan verification.

### Review

- Added `/docs/index.json` with complete-spec, human-docs, and section URLs.
- Added focused OpenAPI endpoints such as `/docs/events.json`, `/docs/admin.json`, and `/docs/catalogs.json`; they filter the canonical document by route prefixes and retain the full `/docs.json` contract for tooling that requires one specification.
- Centralized full-document generation, locking, stale fallback, and caching in `ApiDocumentationDocumentResolver`; this prevents the complete and focused routes from drifting.
- Verification passed: `ScrambleDocsTest` 33 tests / 402 assertions; scoped PHPStan 6 files; route registration and `git diff --check` passed.

# Package-owned test cleanup

- [x] Inventory application tests that call `aiarmada/*` package APIs directly.
- [x] Remove tests that assert package behavior without an ilmu360° integration seam.
- [x] Retain application route, UI, policy, orchestration, notification, serialization, and package-extension coverage.
- [x] Run the affected application integration tests and inspect the final diff.

### Review

- Removed `tests/Feature/Engagement/FollowTest.php`, which only tested the package `EngagementManager` and `Follow` model.
- Removed the direct package registration-service test from `tests/Feature/Events/RegisterForEventTest.php`; retained the web and API route tests that prove ilmu360° integration.
- Retained package-backed tests where the application adds behavior or owns the contract, including speaker follow UI, event registration routes, membership hooks/roles, notification routing, API/MCP parity, address resolution, and admin resources.
- A codebase-memory index lookup was attempted but its transport was unavailable; targeted local discovery was used as the documented fallback.
- Verification passed: registration routes 3 tests / 6 assertions, speaker follow UI 7 / 28, frontend follow API 6 / 44, and `git diff --check`.

## Event package-owned test cleanup follow-up

- [x] Audit event relationship, engagement, attendance, and occurrence tests against package-owned traits/models.
- [x] Remove pure package event tests and preserve application event seams.
- [x] Run retained event unit, policy, Livewire, and API coverage.

### Review

- Removed `EventUserTest` for the package `HasMembers` pivot trait, `EventGoingTest` for package engagement responses, and the unused default `EventOwnershipTest`.
- Removed the package attendance factory-shape assertion from `EventCheckInTest`; retained all Livewire check-in authorization and persistence coverage.
- Removed the package occurrence/session relationship assertion from `Unit/EventTest`; retained app-owned scopes, searchable payload transforms, language serialization, and discoverability behavior.
- Retained application event tests for policies, visibility, submissions, calendar rendering, search, notifications, APIs, MCP tools, registration routes, and app-specific package adapters.
- Verification passed: retained event unit tests 5 / 30 assertions, event check-in Livewire tests 5 / 12, event-going API tests 5 / 12, event-save API tests 30 / 30, and event policy tests 10 / 55. `git diff --check` passed.

## CI failure repair follow-up

- [x] Inspect linked Actions shard and separate stale legacy-contract failures from current production failures.
- [x] Replace direct queries against package-owned event fields with canonical child-table queries and UUID-safe metadata selectors.
- [x] Update stale test assumptions from removed event status/column contracts to the current model and relation accessors.
- [x] Run focused Pest, Pint, PHPStan, and diff checks.

### Review

- Repaired featured-event aggregation through `event_attributes`, age-group filtering through `event_audiences`, and speaker counting through polymorphic involvement columns.
- Added PostgreSQL UUID casting to the current metadata-backed event query projection; no legacy aliases or fallback storage were introduced.
- Focused dashboard test passed: 1 test / 2 assertions. Pint and scoped PHPStan passed; `git diff --check` passed.

### Final verification

- Fixed all three PHPStan errors from the linked run, corrected PostgreSQL JSON selector quoting, and aligned stale tests with the current event, saved-search, ticketing, signals, and tag contracts.
- Verification passed: PHPStan, Pint, and the affected Pest set (67 tests / 357 assertions).

# Architecture product roadmap execution — A1

- [x] Record the canonical execution decisions and boundaries in ADR-014.
- [x] Link ADR-014 from the implementation roadmap.
- [x] Verify documentation formatting and whitespace.

### Review

- A1 is documentation-only; no application source or tests were changed.
- ADR-014 records the owner, date, decisions, consequences, non-negotiables, and
  non-goals required by the roadmap.

# Architecture product roadmap execution — A2

- [x] Preserve the existing ten-shard matrix and sequential/parallel split.
- [x] Capture JUnit timing without changing Pest test selection or console output.
- [x] Publish per-file timing columns to the job summary and shard artifact with
  `always()` handling for failed or cancelled test jobs.
- [x] Verify the reporter dry run, PHP syntax, YAML syntax, and whitespace.

### Review

- A2 is CI-only; no tests were changed.
- The dry-run sample proves the required `file`, `elapsed_seconds`, `shard`,
  `php_version`, `driver`, and `worker_count` columns.

# Architecture product roadmap execution — B1

- [x] Add the canonical `EventEscalationType` enum with the four approved values.
- [x] Add append-only UUID-backed escalation persistence and the Event relation.
- [x] Add focused uniqueness, isolation, casting, and legacy-field absence tests.
- [x] Verify focused Pest, PHPStan, Pint, and whitespace checks.

### Review

- Focused Pest passed: 7 tests, 26 assertions, in parallel.
- PHPStan passed for the changed enum/model/Event files; Pint passed after applying
  mechanical formatting to the existing escalation test file.
- The migration has no foreign-key constraints, cascades, soft deletes, or legacy
  escalation columns.

# Architecture product roadmap execution — B2

- [x] Capture one job timestamp and process pending events in chunks.
- [x] Persist each canonical decision before queuing notifications and ignore
  duplicate decision keys on rerun.
- [x] Implement the four exact threshold windows, recipient groups, and exclusions.
- [x] Remove all legacy escalation-state reads/writes from the job.
- [x] Verify focused Pest, PHPStan, Pint, and whitespace checks.

### Review

- Focused Pest passed: 6 tests, 20 assertions, in parallel.
- The existing notification keys and recipient groups remain intact while the
  persisted decision types use the canonical enum values.

# Architecture product roadmap execution — B3

- [x] Remove legacy escalation fields from active app, API, MCP, Filament, and test
  contracts with no compatibility aliases or fallback reads.
- [x] Add the idempotent historical backfill command and conditional column-removal
  migration.
- [x] Resolve unresolved escalation records on every pending-exit transition.
- [x] Move moderation priority presentation and ordering to canonical escalations.
- [x] Verify focused Pest suites, PHPStan, Pint, whitespace, command registration,
  and the forbidden-state absence search.

### Review

- The user authorized the hard cut; the former blocker is resolved and the evidence
  and decision are retained in `tasks/architecture-product-roadmap-blockers.md`.

# Architecture product roadmap execution — C1

- [x] Add immutable `EventDiscoveryCriteria` with normalized search, dates, geography,
  relation filters, nearby coordinates, pagination, and backend requirement state.
- [x] Add a criteria factory that trims empty values, normalizes UUID inputs, and
  resolves date-only boundaries through the user-timezone formatter.
- [x] Build criteria at the existing search facade boundary without extracting or
  changing query executors.
- [x] Verify focused criteria tests, full EventSearch regression tests, PHPStan,
  Pint, and whitespace.

### Review

- Criteria-focused Typesense suite passed: 9 tests, 19 assertions.
- EventSearch regression suite passed: 82 tests, 281 assertions.
- The existing direct `EventSearchService` subclass tests remain compatible because
  the factory dependency is optional.

# Architecture product roadmap execution — C2

- [x] Add explicit PostgreSQL and Typesense discovery executor seams.
- [x] Route criteria requiring unsupported filters directly to PostgreSQL.
- [x] Preserve Typesense health checks and database fallback behavior for supported
  text and nearby searches.
- [x] Keep cache policy, pagination envelopes, card hydration, and public visibility
  rules at the application facade boundary.
- [x] Verify focused Typesense filters, the full EventSearch suite, PHPStan, Pint,
  and whitespace.

### Review

- The facade now selects `PostgresEventDiscovery` or `TypesenseEventDiscovery` from
  one normalized criteria object; backend-specific query implementations remain
  behind those explicit seams.
- Focused Typesense criteria tests passed: 9 tests, 19 assertions.
- EventSearch regression suite passed: 82 tests, 281 assertions.
- PHPStan passed for all three changed services and Pint reported a clean tree.

# Architecture product roadmap execution — C3

- [x] Keep the default search cache at the facade payload boundary and preserve
  ordered IDs and totals during hydration.
- [x] Return correct uncached results when the cache store fails.
- [x] Verify event observer invalidation for public listing changes and safe cache
  rehydration from the database store.
- [x] Verify cache fallback, EventSearch regression behavior, PHPStan, Pint, and
  whitespace.

### Review

- Added a cache-failure regression test: the facade logs the cache error and
  executes the same PostgreSQL/Typesense-selected criteria uncached.
- Public listing invalidation passed: 5 tests, 955 assertions.
- Safe cache serialization passed: 4 tests, 20 assertions.
- The full EventSearch regression suite passed: 82 tests, 281 assertions.
- No database indexes were added; EXPLAIN output remains a later delivery artifact.

# Architecture product roadmap execution — D1

- [x] Add readonly `ValidatedEventSubmission` as the post-validation command
  boundary.
- [x] Carry normalized state, UTC timing, timezone, canonical organizer/location,
  prayer metadata, session context, and submitter context in the command.
- [x] Make `SubmitFrontendEventAction` consume the command for event persistence
  without changing request keys or validation messages.
- [x] Verify all SubmitEvent-named feature coverage, PHPStan, Pint, and whitespace.

### Review

- The affected submission coverage passed: 65 tests, 310 assertions, in parallel.
- PHPStan passed for the command and action; Pint passed after mechanical
  formatting.
- No public form, API, or validation error contract was changed.

# Architecture product roadmap execution — D2

- [x] Extract event/session and submission-record persistence into a dedicated
  constructor-injected action.
- [x] Keep organizer/location resolution, online location clearing, relation sync,
  and classification persistence behavior unchanged.
- [x] Keep external sharing, contacts, registration setup, moderation, and other
  side effects outside the persistence action.
- [x] Verify submission notification, location, and end-time suites, PHPStan, Pint,
  and whitespace.

### Review

- Added `PersistValidatedEventSubmissionAction` with typed command input and typed
  event/session/submission output.
- Notification coverage passed: 2 tests, 12 assertions.
- Location coverage passed: 7 tests, 23 assertions.
- End-time coverage passed: 13 tests, 68 assertions.
- PHPStan passed for the extracted action, command, and coordinator.

# Architecture product roadmap execution — D3

- [x] Add a named after-commit submission workflow for sharing, contacts,
  registration defaults, moderation, and lifecycle handoff.
- [x] Wrap validated event/session/submission persistence in a transaction.
- [x] Schedule side effects only after the transaction commits while preserving
  existing return payloads and user-visible behavior.
- [x] Verify submission notification and all SubmitEvent-named feature coverage,
  PHPStan, Pint, and whitespace.

### Review

- The submission regression pass passed: 65 tests, 310 assertions.
- Notification coverage passed: 2 tests, 12 assertions.
- The workflow is constructor-injected and the coordinator no longer owns the
  duplicated contact/registration side-effect helpers.

# Architecture product roadmap execution — E1

- [x] Add a product signal schema registry with explicit event/property allowlists
  for authentication, discovery/search, moderation, notifications, submissions,
  and AI-adjacent admin events.
- [x] Drop unknown properties and redact sensitive-looking search queries by
  default.
- [x] Preserve non-blocking ingestion failure behavior.
- [x] Verify schema redaction and Signals telemetry coverage, PHPStan, Pint, and
  whitespace.

### Review

- Signals telemetry passed: 16 tests, 81 assertions.
- The registry is constructor-injected into `ProductSignalsService`; client context
  remains separately normalized and safe to attach.
- Existing ingestion failures remain logged and non-blocking.

# Architecture product roadmap execution — E2

- [x] Extend the existing restricted Product Signals admin page with a discovery
  and moderation scorecard.
- [x] Aggregate zero-result patterns, supply-gap indicators, search-to-result
  conversion, median/p90 submission-to-publication timing, and imminent pending
  events.
- [x] Keep raw search text out of the scorecard and preserve existing admin-page
  authorization.
- [x] Verify scorecard rendering, unauthorized access, PHPStan, Pint, and
  whitespace.

### Review

- Product Signals admin coverage passed: 10 tests, 42 assertions.
- The scorecard is available only through the existing admin panel page; no public
  route or authorization mechanism was added.
- The existing telemetry view remains intact while the scorecard exposes aggregate
  operator metrics only.

# Architecture product roadmap execution — F1

- [x] Add centralized AI budget decisions with exactly `allow`,
  `privileged_approval`, `defer`, and `deny` outcomes.
- [x] Enforce monthly usage limits and privileged approval thresholds with
  non-sensitive reason codes.
- [x] Make ledger writes idempotent by invocation ID and expose current-period
  spend to the policy.
- [x] Gate event media extraction and cover generation before dispatch.
- [x] Verify budget, ledger, extraction, and cover-related AI tests, PHPStan, Pint,
  and whitespace.

### Review

- Budget policy passed: 3 tests, 6 assertions.
- AI usage logging passed: 4 tests, 48 assertions.
- Event media extraction passed: 2 tests, 23 assertions.
- All policy and gated entry-point files pass PHPStan and Pint.

# Architecture product roadmap execution — F2/F3 evidence gate

- [x] Add a reusable evaluator for warning (125%) and failure (150%) duration
  thresholds, rounded up to whole minutes.
- [x] Add fixtures for warning, failure, and missing-artifact handling.
- [ ] Select and optimize the measured slowest setup file after two further green
  CI runs publish A2 timing artifacts.
- [ ] Set repository duration budgets from the slower of those two CI baselines and
  wire enforcement into CI without changing the shard matrix.

### Review

- Budget evaluator tests passed: 2 tests, 5 assertions.
- The remaining F2/F3 items are intentionally evidence-gated; no guessed baseline,
  timeout, or shard change was introduced.
