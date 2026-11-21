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

After commit `1427e330`, CI Quality run
https://github.com/AIArmada/ilmu360/actions/runs/44859486755 completed
successfully: all 26 jobs passed, including all Pest shards, PHPStan, Rector,
Pint, and browser smoke. A local serial continuation was stopped after 212
passing files and zero failures once that full CI result was available; it is
not a complete local suite run. `pest-serial-results.txt` contains those
partial local results. CI is authoritative for the complete suite and the
environment-dependent files.

## Independent audit follow-up (2026-11-21)

The independent audit at `a29ee601` identified F1–F5. The implementation and
verification are complete; the final gate results are recorded below.

### F1 — immutable Discovery schedule reads

- Events returns readonly `EventScheduleFactSet`, `EventScheduleFact`, and
  `EventScheduleTimeFact` values through `publicScheduleFactSet`,
  `homepageScheduleFacts`, and `scheduleFactsForEventIds`.
- Discovery applies leaf filters, ordering, distance and pagination to those
  facts. Events hydrates only the selected page ids for Livewire rendering.
- Eligibility and visibility remain centralized in Events. Regression
  coverage checks meaningful sessions, private/draft exclusion, fact filtering
  before page hydration, and public occurrence/session rendering.
- The expanded focused run passed **259 tests / 1,716 assertions**, including
  `PublicScheduleDiscoveryTest`, `DashboardPagesTest`, `ShareTrackingTest`,
  `InstitutionDirectoryQueryTest`, `ReferenceFamilyTest`,
  `ContributionPagesTest`, and `ArchitectureBoundaryTest`.

### F2 — checker invariant and limits

- `tools/architecture-check.php` now checks builder mutation/escape, supported
  helper and alias propagation, model/collection mutation, `Api/Orm` consumers,
  Platform and Blade consumers, and missing/union ORM return contracts.
- Negative fixtures cover `whereBetween`, callback propagation, helper/alias
  returns, model and collection mutation, non-feature consumers, `Api/Orm`
  consumers, and absent/mixed/union returns. A positive fixture allows the
  exact correlated `selectSub` composition while rejecting mutation of that
  subquery.
- `php tools/architecture-check.php` reports **0 violations across 28
  features**. `composer architecture:test` passes with PHPStan reporting zero
  errors.
- The scanner is a tested static AST analysis, not general PHP interprocedural
  type inference. Dynamic method names, reflection, runtime macros, and
  framework callbacks still require review; the report is limited to tested
  patterns.

### F3 — bounded reference directory reads

- `ReferenceFamily::expandIdsForMany()` resolves root/edition/part families in
  batched queries. Events counts distinct public events in SQL rather than
  materializing event-reference links in PHP.
- The query-budget test creates nested reference families and asserts four
  queries for 1, 12, and 50 rows (three family-depth reads plus one count
  statement), so the query count stays bounded as the page grows.
- A read-only staging sample had 84 references, 129 events, and 14 event-link
  rows; it showed three queries for each requested size and count-plan times of
  about 0.49, 0.44, and 0.59 ms. This is a small-dataset sanity check.
- A second PostgreSQL plan used transaction-local temporary tables with 50
  roots/150 family references, 20,000 events, 20,000 occurrences, and 60,000
  event-reference links. Query counts remained 4 for 1/12/50 roots; the count
  statement measured about 12.2/73.1/159.6 ms respectively. The transaction
  was rolled back. Plan output is in
  `/tmp/ilmu360-orm-boundary-reference-synthetic-plans.json`.

### F4 — owner-executed page reads and directory SQL choice

- Venue and Series page operations now accept scalar ids, limits and date
  ranges; Events composes, executes and hydrates their schedule queries.
- Institution directory subqueries remained as a narrowly categorized
  `SQL_COMPOSITION_KEEP` at this point; **superseded by O2 below**, which
  replaces the mutable builders with an immutable owner projection. A
  synthetic PostgreSQL comparison used 500
  institutions, 20,000 events and 20,000 occurrences. For 12 institution rows,
  the correlated query ran as one query (8.55 ms client-side, 6.31 ms in
  `EXPLAIN ANALYZE`); batched facts took three queries (15.21 ms combined,
  16.80 ms total `EXPLAIN ANALYZE`). The correlated form is retained because
  it was faster in this measured case and returns count/next-event selection in
  the directory statement. Details are in
  `/tmp/ilmu360-orm-boundary-institution-plan-comparison.json`.
- `ArchitectureBoundaryTest::allows exact correlated SQL subquery composition
  but rejects mutating the foreign subquery` locks the intended exception.

### F5 — inventory evidence

- `tasks/orm-boundary-inventory.csv` now has **318 rows**: 273 current methods,
  16 replaced seams, and 29 removed baseline methods. All current public ORM
  methods are indexed.
- Current classifications: 216 scalar-safe, 44 framework-identity, 8
  owner-model reads, 0 SQL-composition seams, 4 owner-query integration seams,
  and 1 address-resolution seam. Model-carrying readonly schedule leaves and
  the in-memory venue relation hydrator are explicitly inventoried as page
  identity seams; their consumers are limited to the public page surfaces.
- Every retained mutable seam records its named consumer requirement,
  read/write effect, immutable alternative and why it does not fit, checker
  fixture, and behavior/performance evidence. Historical methods replaced by
  the audit work no longer carry live `FRAMEWORK_IDENTITY_KEEP` claims.

### Follow-up verification status

| Gate | Result |
|---|---|
| Focused Pest batch | 259 passed, 1,716 assertions |
| Rector-adjusted schedule/reference tests | 19 passed, 141 assertions |
| Venue/Series public page regression | 1 passed, 12 assertions |
| Frontend API and published schema parity | 111 passed, 1,027 assertions |
| `php tools/architecture-check.php` | 0 violations, 28 features |
| `composer architecture:test` | passed; PHPStan 0 errors |
| `vendor/bin/pint --dirty --format agent` | passed; formatting fixes applied before the gate rerun |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/rector process --dry-run --ansi` | passed; 0 changes |
| API parity | passed; 111 tests, 1,027 assertions |
| Route parity | 738 routes before and after; no route added or removed |
| Schedule parity | 24 entries before and after; cron/command rows identical |
| GitHub Quality on code SHA `6273517d` | passed; [run 44883914408](https://github.com/AIArmada/ilmu360/actions/runs/44883914408) |

All 26 Quality jobs passed, including the 20 Pest shards, PHPStan, Rector,
Pint, and Chromium smoke. The closeout commit changes only these audit records;
a separate `workflow_dispatch` run verifies its resulting branch SHA.

## Final independent audit closeout (2026-11-21)

Audit: `ilmu360-orm-boundary-final-audit-2026-11-21.md`, reviewed HEAD
`0539b391bdc1434d3a4150e809cd90f1d9430dbf`. Functional closeout:
`427569deb1e7a497c74a90ec3e37434ba0bf3d1f`.

### O1 — Current-DB revalidation for search-derived ids

- `PostgresEventDiscovery::scheduleFactsForEventIds()` now applies current
  public parent state (status, `public` visibility, non-null `published_at`)
  and requires at least one public-schedule occurrence before projecting
  facts. `scheduleFactSet()` also drops non-public parents defensively.
- `TypesenseEventDiscovery` mirrors the same constraints in its Eloquent
  rehydration callback for `search`, `nearby`, and `nearbyWithQuery`.
- Regression coverage: stale private/unpublished ids are rehydrated directly
  and through the mocked discovery fallback/nearby page
  (`PublicScheduleDiscoveryTest`), and through the Typesense callback after a
  visibility/publication change (`EventSearchTypesenseFilterTest`).

### O2 — Cross-owner SQL builders removed

- `EventOrmPort::institutionUpcomingCountSubquery()` and
  `institutionNextEventIdSubquery()` are replaced by
  `institutionDirectoryEventColumns(): EventDirectorySqlProjection`.
- `EventDirectorySqlProjection` and `EventDirectorySqlColumn` are readonly
  values carrying SQL text and positional bindings.
  `InstitutionDirectoryQuery` applies them with `selectRaw()` and the query
  grammar's alias wrapping.
- The institutions directory keeps the identical one-query correlated plan,
  bindings and ordering; no mutable Eloquent/Query builder crosses the Events
  boundary. The inventory records both old methods as `REPLACE_QUERY` and the
  new surface as `SCALAR_ALREADY_SAFE`.

### O3 — Checker PHPDoc parsing

- `tools/architecture-check.php` now parses `@return` with the installed
  `phpstan/phpdoc-parser`; the token regex remains only as a fallback for
  malformed documents.
- Nested `mixed`/`object` inside arrays/collections no longer mark a return
  opaque; top-level `mixed`/`object` still do.
- New fixtures cover spaced and unspaced `Collection<int, Order>` generics and
  prove `orm-mutation-escape`. The checker remains bounded AST enforcement,
  not a formal whole-program proof.

### Verification

| Gate | Result |
|---|---|
| `php tools/architecture-check.php` | 0 violations, 28 features |
| `composer architecture:types` | 0 errors |
| `vendor/bin/phpstan analyse` | 0 errors |
| `vendor/bin/rector process --dry-run` | passed, 0 changes |
| `vendor/bin/pint --dirty --format agent` | passed |
| `ArchitectureBoundaryTest` | 78 passed, 312 assertions |
| `PublicScheduleDiscoveryTest` / `EventSearchTypesenseFilterTest` / `InstitutionDirectoryQueryTest` | 29 passed, 117 assertions |
| `InstitutionIndexTest` | 44 passed, 224 assertions |
| `ReferenceFamilyTest` | 10 passed, 72 assertions |
| GitHub Quality on `a66bd4e5` (code + ledgers) | passed; 26/26 jobs, including 20 Pest shards, PHPStan, Rector, Pint and Chromium smoke; [run 44892798081](https://github.com/AIArmada/ilmu360/actions/runs/44892798081) |

Local-only exemptions, unchanged by this work: `EventSearchTest`'s registration
group can hit the persistent local rate-limit cache when several tests share
one process, and one Event show assertion expects the CI `APP_URL`
(`https://ilmu360.test`) while the local `.env` points at another host. Neither
is a code-path regression; CI provides the authoritative run.

## Follow-up audit closeout (2026-11-21, 18:34)

Audit: `ilmu360-orm-audit-2026-11-21-1834.md`, reviewed HEAD
`438cb5349a238dc5966728a826c3c4917def068a`. Functional closeout:
`96a810c4666941b6b289794ab1da1c9f9aa53dfe`.

### G1 — public occurrence state on institution next-event cards

- Extracted `constrainPublicUpcomingOccurrences()` as the single owner predicate
  for occurrence status, visibility and future-start eligibility.
- Applied it to the institution correlated upcoming count, next-event id
  selection, `institutionNextEventDetails()`, `nextPublicEventIds()`,
  `nextPublicEventDetails()` and the batch `institutionDirectoryFacts()` path.
  The detail queries also recheck parent public state.
- Added end-to-end `InstitutionIndexTest` regressions: an earlier draft/private
  occurrence is ignored in favour of the later public occurrence, and a parent
  with only private future occurrences gets no "Next event" card.

### G2 — final public schedule hydration

- `hydrateEventsForIds()` now re-applies `constrainCurrentPublicEventState()`;
  it is private and only used by the public `hydrateScheduleEvents()` path.
- Added `PublicScheduleDiscoveryTest` coverage for direct private/unpublished
  ids and for a visibility change between fact selection and final hydration.

### G3 — true stale-index Typesense fixture

- Added a fake `TypesenseEngine` returning stale private/unpublished hit ids;
  the Scout query callback filters both the hydrated payload and the
  recomputed paginator total. The test documents that totals are
  callback-recomputed, not raw index counts.

### Verification

| Gate | Result |
|---|---|
| `php tools/architecture-check.php` | 0 violations, 28 features |
| `composer architecture:types` | 0 errors |
| `vendor/bin/phpstan analyse` | 0 errors |
| `vendor/bin/pint --dirty --format agent` | passed |
| `InstitutionIndexTest` / `PublicScheduleDiscoveryTest` / `EventSearchTypesenseFilterTest` / `InstitutionDirectoryQueryTest` | 78 passed, 357 assertions |
| `EventSearchTest` | 127 passed, 457 assertions |
| `EventShowPageTest` / `DashboardPagesTest` / `ReferenceFamilyTest` / `ArchitectureBoundaryTest` | 161 passed, 849 assertions |
| `FrontendApiParityTest` | 108 passed, 772 assertions |
| GitHub Quality on the final closeout SHA | pending |

Local suites were run with `APP_ENV=testing DB_CONNECTION=sqlite
DB_DATABASE=:memory: CACHE_STORE=array APP_URL=https://ilmu360.test`; the shell
in this workspace exports staging DB variables that would otherwise override
`phpunit.xml`.


