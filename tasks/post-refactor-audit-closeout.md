# Post-refactor audit closeout — 2026-11-21

Source audit: `ilmu360-post-refactor-audit-2026-11-21.md` (audited `a653732d`).
This file records how each finding was closed, with the code/gate evidence.

## F1 — public owner APIs exposed mutable ORM data — CLOSED

- Ordinary `app/Features/*/Api/*.php` contracts now contain **zero** imports of
  `Features/*/Models`, Eloquent `Builder`/`Collection`, paginator or
  `Illuminate\Support\Collection`, and zero opaque `object` signatures (the
  framework `?object $request` integration parameter is the only allowed form).
- Every model/builder-shaped surface moved under
  `app/Features/<Owner>/Api/Orm/` with an `@internal` marker and a
  `Consumers:` list (38 ports). The checker enforces both.
- Named findings specifically:
  - `EventScheduleQuery` split into `EventScheduleReads` (scalar/card/count)
    and `Api/Orm/EventOrmPort` (models/builders); all consumers migrated.
  - `PublicScheduleReader` → `Api/Orm/PublicScheduleOrmPort`;
    `PublicScheduleLeaf` is scalar-only and
    `Api/Orm/PublicScheduleModelLeaf` owns the model accessors.
  - `EventPageSchedule` → `Api/Orm/EventPageScheduleOrmPort`.
  - `InstitutionCards` / `PersonCards` now take scalar id lists and return
    immutable card maps; `forId` is the single-record read.
  - Lookups, submissions, media, moderation, registration, report,
    share-tracking, contribution, donation and Filament form integrations
    are explicit ORM ports under `Api/Orm`.
- Runtime payloads are unchanged: Frontend API parity 108/108, entity suites
  263/263, admin/MCP 186/186, contributions/checkout/registration 94/94,
  OpenAPI canonical-equal 152/152 paths and 49/49 schemas.

## F2 — checker and fixtures did not prove the model seam — CLOSED

`tools/architecture-check.php` now reports:

| Rule | Meaning |
|---|---|
| `api-model-leak` | ordinary Api imports/references a feature model, Eloquent builder/collection, paginator or `Support\Collection` |
| `api-opaque-param` | ordinary Api has an opaque `object` parameter other than the framework request |
| `api-opaque-return` | ordinary Api returns `object` |
| `orm-port-contract` | an `Api/Orm` file lacks `@internal` or `Consumers:` |
| `foreign-model-domain-operation` | foreign model static query/construction, container resolution (`app`/`resolve`) or instance mutation |

Fixtures in `tests/Unit/Platform/ArchitectureBoundaryTest.php` cover the
positive and negative forms, production `root_models` defaults apply to every
fixture project, and the obsolete root-model allowance was removed/limited to
the focused parent-delete lifecycle fixture.

## F3 — registry silently accepted key collisions — CLOSED

`ResourceDefinitionRegistry` declares the single intentional
`admin:reward-awards` pair (legacy Filament winner + fraud resource, same
`RewardAward` model), verifies the pair and model on registration, keeps the
first definition, and throws for every other `(surface,key)` collision or a
stale allowlist. `ResourceDefinitionRegistryCollisionTest` covers unexpected,
intentional, different-model and stale-pair cases.

## F4 — ledgers were not closed — CLOSED

- `feature-refactor-migration-ledger.csv`: 378/378 rows have a real landed
  commit (377 × `27decde3`, 1 × `554805ec`; four phantom intermediate paths
  resolved to their actual rename targets).
- `feature-refactor-exception-ledger.csv`: 62/62 current write exceptions are
  `PROVEN` with package/table ownership, callsite, gate and `264047c1`
  evidence; 345 historical rows are `REMOVED`; RA-11 is `REMOVED` at
  `9d71d62f` with zero read adapters.
- `RefactorLedgerTest` fails on any PENDING/IN_TREE state, missing commit,
  missing evidence, or exception-count mismatch.

## F5 — published OpenAPI held by a hand-coded patcher — CLOSED

- The patcher remains the single normalization point, but is now coupled to
  runtime behavior by `tests/Feature/Api/PublishedContractSchemaParityTest.php`:
  `tests/Fixtures/OpenApi/published-contract.json` freezes 24 audited
  operations, and live requests for reference/venue/series details, account
  event lists and event state must expose the published top-level/data keys.
- Full OpenAPI remains canonical-equal to the baseline.

## F6 — tracked scratch/backup artifacts — CLOSED

Deleted 11 `config/*.pre-majlisilmu-merge-20260511` backups plus
`resources/lang/ms.json.tmp` and `tests/Feature/PublicPagesTest.php.tmp`
(no consumers; config loader ignores the suffixed files). Added
`RepositoryArtifactTest` to reject `*.tmp`, `*.bak`, `*.orig`, `*~` and dated
`*.pre-*` paths.

## F7 — remaining opportunities (non-blocking)

- `Shared/Contributions/Support/BuildsContributionSubjectState.php` is a
  cross-owner form-state helper used by four owner projections. It remains
  Shared because moving it into one owner would make the other three import a
  private helper; a neutral builder API is the next refinement.
- `PostgresEventDiscovery` remains ~1,900 lines of cohesive public
  search/schedule/read composition; the audit explicitly warned against
  splitting by length alone.

## Watch item — pre-existing read authorization

`FilamentResourceDefinition` still treats any true record ability as sufficient
for `show`/related/media, matching the pre-refactor AdminResourceService. This
is unchanged by the refactor; policy semantics for delete-only/edit-only roles
should be verified with negative access tests before any behavior change.

## Verification at this closeout

- Architecture 0 violations; 62 package-owned writes; 0 read adapters.
- PHPStan level 6, ownership types, Pint, Rector: clean.
- OpenAPI: 152/152 paths, 49/49 schemas, canonical-equal.
- Routes 738; schedule/MCP/Filament inventories unchanged.
- Targeted suites: 108 + 263 + 186 + 94 + 37 docs + 166 event search.
- Full CI is the final gate for the closeout head.
