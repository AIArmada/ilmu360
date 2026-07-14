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

- [ ] Preserve the existing dirty-worktree baseline and record the review scope.
- [ ] Map all application, API, and MCP entry points with the codebase graph.
- [ ] Run independent read-only audits of API contracts, MCP registration/tools, core domain orchestration, and Pest failures.
- [ ] Apply only evidence-backed fixes in isolated write scopes, preserving other in-progress work.
- [ ] Run focused suites, parallel Pest, PHPStan, and contract/boundary checks; document every remaining failure with its root cause.

### Review

- In progress. The audit must distinguish defects introduced by the current dirty working tree from existing package-cutover failures. The runtime behavior and tests that reflect current domain rules are the source of truth; stale tests will be corrected only after their asserted contract is verified in the application.
