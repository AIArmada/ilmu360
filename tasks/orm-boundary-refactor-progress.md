# ORM boundary refactor — progress

- Repository: `AIArmada/ilmu360`
- Branch: `refactor`
- Baseline SHA: `3a44843817072d13581db88fa1d0b238749ede94` (Quality run 44837051677, 26/26)
- Environment: PHP 8.5.12, Composer 2.11.2

## Before-state evidence

| Gate | Result | Artifact |
|---|---|---|
| `php tools/architecture-check.php --json` | 0 findings, 28 features | `/tmp/ilmu360-orm-before-architecture.json` |
| `composer architecture:types` | passed, 0 errors | `/tmp/ilmu360-orm-before-types.log` |
| `php artisan route:list --json` | 738 routes | `/tmp/ilmu360-orm-before-routes.json` |
| `php artisan schedule:list` | 24 lines | `/tmp/ilmu360-orm-before-schedule.txt` |

Baseline inventory: 40 `Api/Orm` files, 269 public methods.

## Commits

| SHA | Slice |
|---|---|
| `e459a557` | Delete 14 dead ORM contract methods |
| `31045ca5` | Events owns public schedule queries; `PublicScheduleEventReads` |
| `65923b62` | Submitter candidate queries move into Institutions/Persons/Places |
| `dcf601fe` | Typed schedule seams; Institution page query + dashboard event stats |
| `ced406aa` | Typed registration/submission/context ORM seams |
| `82488874` | Checker gates `orm-opaque-return` / `orm-query-escape` + fixtures |

## Inventory reconciliation

Final: 43 `Api/Orm` files, 272 live public methods + 29 removed baseline
methods = 301 rows in `tasks/orm-boundary-inventory.csv`.

| Classification | Count |
|---|---|
| `SCALAR_ALREADY_SAFE` | 213 |
| `FRAMEWORK_IDENTITY_KEEP` | 59 |
| `DEAD_OR_DUPLICATE` | 16 |
| `REPLACE_QUERY` | 11 |
| `REPLACE_MODEL_DATA` | 2 |

`orm-opaque-return` reports zero opaque native `object`/`mixed` returns in
`Api/Orm`. The 59 framework seams are typed model/collection/builder returns
consumed by Livewire/Filament rendering, policy/media/share subjects, or
owner-scoped directory projections; the new `orm-query-escape` gate rejects
foreign predicate composition on them.

### Removed / replaced methods

| Old | Class | Replacement |
|---|---|---|
| `EventOrmPort::publicEventQuery` | REPLACE_QUERY | `PublicScheduleEventReads::publicScheduleEvents` |
| `EventOrmPort::publicReferenceEventCountSubquery` | REPLACE_QUERY | `EventScheduleReads::referenceDirectoryFacts` / `publicEventCountsForReferenceFamilies` |
| `EventOrmPort::institutionEventCountSubquery` | REPLACE_QUERY | `EventScheduleReads::institutionEventStats` |
| `InstitutionLookup::newInstitutionQuery` | REPLACE_QUERY | `InstitutionAuthoringCandidates` |
| `PersonLookup::newPersonQuery` | REPLACE_QUERY | `PersonAuthoringCandidates` |
| `PlaceLookup::newVenueQuery` | REPLACE_QUERY | `VenueAuthoringCandidates` |
| `ReferenceOrmPort::constrainEventReferenceSubtree` | REPLACE_QUERY | `EventScheduleReads::referenceDirectoryFacts` |
| `EntitySubmissionAccessContract` builder methods | REPLACE_QUERY | owner authoring candidates + option/label arrays |
| `PublicScheduleOrmPort::findOccurrence/findSession` | REPLACE_MODEL_DATA | `PublicScheduleEventReads::findPublicOccurrence/findPublicSession` |
| 16 proven-unused methods across Events/Institutions/Persons/Places/References/Discovery | DEAD_OR_DUPLICATE | deleted |

## Slices

### 1 — Dead contract surface

Deleted 14 methods with zero call sites (token scan + literal search):
`EventOrmPort::{institutionEventsQuery,institutionDetailEventsQuery,referenceDetailEventsQuery}`,
`InstitutionLookup::{findDetailRecord,aggregateSearchItemsQuery,baseDirectoryQuery}`,
`PersonLookup::{findDetailRecord,baseDirectoryQuery}`,
`ReferenceLookup::{findDetailRecord,baseDirectoryQuery}`,
`PlaceLookup::findVenueDetail`, `SeriesAuthoring::findSeriesDetail`,
`PublicScheduleOrmPort::{publicRelations,occurrencePageRelations}`.
No behavior change. Boundary suite 61/61.

### 2 — Events ↔ Discovery

- Added `Events\Api\Orm\PublicScheduleEventReads` (search, homepage selection,
  homepage followed ids, card/page relation graph, schedule hydration,
  public occurrence/session lookup, institution schedule query).
- `PostgresEventDiscovery` owns every schedule predicate and the card graph;
  the former `publicEventQuery` builder is a private method.
- `PublicScheduleDiscoveryService` now receives typed hydrated collections and
  keeps only cross-domain orchestration: search fallback, distance, in-memory
  leaf filters, ordering, pagination.
- `EventCardRelationshipProvider` moved to `Events\Queries` and remains the
  configured package relation provider.
- `PublicScheduleOrmPort` lost occurrence/session lookup (moved to Events).
- Tests: 301 focused schedule/homepage/page assertions, then 111 after the
  public builder deletion.

### 3 — Submitter candidate ownership

- `InstitutionAuthoringCandidates`, `PersonAuthoringCandidates`,
  `VenueAuthoringCandidates` execute the submitter/country/membership queries
  and return final option/label arrays, booleans or the scoped member
  institution identity.
- `EntitySubmissionAccess` is a thin policy orchestrator; the submit-event
  options, form schema, prefill, scoped resolver and country filter consume the
  new contract.
- Typed the remaining Institution/Person/Reference lookup returns and the
  reference visibility mutators.
- Tests: 222 submit/contribution tests + 207 directory tests.

### 4 — Schedule/page seams and dashboard stats

- `PublicScheduleEventReads::institutionScheduleQuery` owns the Institution
  detail schedule predicate/relation graph; the page only splits and renders.
- Typed `EventSchedulePageData`, `EventListPayloads`, all remaining
  `EventOrmPort` returns; removed the unused `EventPageScheduleOrmPort` and
  service binding.
- `EventScheduleReads::institutionEventStats` replaces five dashboard
  count subqueries the page used to constrain itself. The old `upcoming`
  metric referenced a non-existent `events.starts_at`; it now counts events
  with an occurrence in the 30-day window.
- Tests: 219 page/dashboard tests.

### 5 — Registration/submission/context typing

- `SubmissionContext`, `EventSubmissions`, `SeriesAuthoring`,
  `RegistrationLookup`, `PublicScheduleOrmPort` leaf/venue methods,
  notifications, category payloads, contribution state mapper, Filament
  contribution country and member invitation access now declare their real
  owner types. The Platform relation manager narrows through
  `MemberInvitationAccess::canInviteOwnerRecord` instead of importing feature
  models.
- `Api/Orm` opaque native returns reached zero.
- Tests: 123 registration/checkout/submission tests + 59 membership tests.

### 6 — Checker hardening

`tools/architecture-check.php` additions:

| Rule | Rejects |
|---|---|
| `orm-opaque-return` | Any `Api/Orm` public method returning native `object`/`mixed` |
| `orm-query-escape` | A foreign consumer receiving an `Api/Orm` builder and calling `where`, `whereHas`, `with`, `join`, `select`, `orderBy`, `update`, `delete`, … — detected through container resolution, variables, injected properties and typed parameters; `@internal`/`Consumers:` do not exempt it |

Fixtures in `ArchitectureBoundaryTest` (67 tests):

- Negative: chained, variable, injected-property and typed-parameter builder
  composition; opaque `object`/`mixed` ORM returns.
- Positive: immutable owner facts crossing a feature boundary; existing
  foreign-model type/relation allowances, package writes and owner relation
  traits remain allowed.

Static limits (documented): method names are resolved from `Api/Orm`
declarations and file-level interface references, not a full type-inference
engine. Dynamic method names, `DB::table`, reflection and builder use inside a
framework table callback are reviewed manually. `selectSub(ownerSubquery)`
(consuming the builder as an argument) is intentionally not composition.

Reference directory counts moved to batch owner facts so the new gate passes
without a `whereExists` predicate composed by References.

## Residual approved seams

All are typed, `@internal`, list their consumers, and are guarded by
`orm-query-escape`:

- Event schedule models (`PublicScheduleEventReads`, `PublicScheduleOrmPort`)
  for Livewire page identity and card rendering.
- Event institution/person/reference dashboard builders
  (`institutionTableQuery`, `institutionEventCardsQuery`,
  `emptyEventsQuery`, `personDashboardQuery`, `referenceEventsQuery`) consumed
  by Filament tables/cards and the page schedule splitter.
- Directory/institution/person/reference model collections for Filament
  owner-record lists and followed dashboards.
- Registration/check-in/submission collections for dashboard rendering.
- Membership invitation owner-record narrowing and donation QR media
  callbacks.
- Reference visibility constraint (`ReferenceOrmPort::applyPublicVisibility`)
  as a relation-constraint adapter; callers may add reference IDs only.

## Verification

Final tree: `c647f310` plus the restored venue/series port (committed) and the
checker/documentation wave.

| Gate | Result | Artifact |
|---|---|---|
| `php tools/architecture-check.php --json` | 0 findings, 28 features | `/tmp/ilmu360-orm-final-architecture.json` |
| `composer architecture:types` | passed, 0 errors | `/tmp/ilmu360-orm-final-types.log` |
| `vendor/bin/pint --test` | passed | `/tmp/ilmu360-orm-final-pint.log` |
| `vendor/bin/rector process --dry-run` | passed, 0 changes | `/tmp/ilmu360-orm-final-rector.log` |
| `php artisan route:list --json` | 738 routes (baseline 738) | `/tmp/ilmu360-orm-final-routes.json` |
| `php artisan schedule:list` | 24 lines (baseline 24) | `/tmp/ilmu360-orm-final-schedule.txt` |
| `npm run build` | passed | `/tmp/ilmu360-orm-final-build.log` |
| `git diff --check` | passed | — |
| Full Pest | per-file process isolation, see below | `/tmp/pest-by-file-results.txt` |

The repository's local Paratest wrapper (`--parallel`) fails before execution
with the known `WrapperRunner::getTestFiles()` TypeError, and a single-process
serial run exhausts 512 MB/2 GB inside Scramble's `TypeWalker`/`PhpDoc` lexer
after several hundred files. Both are process-lifetime limits, not failures:
`PublishedContractSchemaParityTest` passes alone (3/3). The suite was therefore
run the way CI runs it — one process per test file.

A 6-way per-file run passed 527/549 files. Every non-environmental failure was
re-run serially and passed:

| File | 6-way | Serial |
|---|---|---|
| `Api/EventApiContractTest` | 1 media race | 30/30 |
| `Api/Frontend/FrontendApiParityTest` | 1 media race | 108/108 |
| `ContributionPagesTest` | 1 media race | 85/85 |
| `EventShowPageTest` | 1 media race | 46/46 |
| `EventSearchTest` | poster/cover race | 127/127 |
| `Mcp/EventImageGenerationToolTest` | 7 media races | 14/14 |
| `MediaCollectionSyncTest` | media race | 7/7 |
| `MediaConversionsTest` | media race | 46/46 |

The remaining per-file failures are environment-only and excluded from the
local run:

- `pango-view` is not installed locally, so `Wp3ShareCardTest`, the two
  `PangoShareCardRenderer*` suites and `ShareCardGlyphGuardTest` report
  `shaper_unavailable`. CI installs Pango and fonts.
- The ten `real-driver` files report "No tests found" when individually
  excluded; they run in CI's sharded group and via `composer test:drivers`.

The final serial sweep of the remaining 535 files is recorded in
`/tmp/pest-serial-results.txt`; CI Quality is the authoritative full-suite
gate for the environment-dependent files.


