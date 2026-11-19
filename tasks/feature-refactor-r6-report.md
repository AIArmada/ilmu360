# R6 — Feature-ownership refactor completion report

Branch `refactor`. Final verified commit: `554805ec` (CI Quality 26/26).

## 1. Target architecture

| Boundary | Contents | Count |
|---|---|---:|
| `app/Features/<Owner>/` | domain models, actions, entry points, owner Api contracts | 27 features, 1,657 PHP files |
| `app/Platform/` | generic infrastructure, registries, transport, media, location, docs engine | 271 PHP files |
| `app/Shared/` | genuinely cross-boundary values and contracts only | 33 PHP files |
| root domain folders | `Services`, `Support`, `Data`, `Forms`, `Checkout`, `Listeners`, `Observers`, `Policies`, `States`, `Contracts`, `Enums`, `Filament`, `Mcp`, `Livewire` | **0 files** |
| `app/Models` | `User`, `PassportUser` only | **2 files** |

No compatibility aliases, forwarding wrappers, duplicate implementations, legacy
namespaces or empty legacy directories remain. `tools/architecture-check.php`
reports `0 architecture violation(s); 27 feature(s) checked`.

## 2. What was delivered

### 2.1 Ownership moves (R5)

`tasks/platform-shared-classification.csv` has **zero remaining move rows**:

- 88 `MOVE-TO-OWNER` + 77 `MOVE-TO-OWNER-API` moves completed, plus the
  `DELETE-DUPLICATE` set. Each move is a `git mv` to the owner namespace with
  every reference updated; old files are gone.
- Cross-feature contracts were reshaped to neutral types rather than moved
  unchanged: `AccountAccess`, `SignupAttribution*Resolver`, `SignupRecorder`,
  `EngagementShareOutcomes`, `SavedSearchShareOutcomes`,
  `ShareOutcomeBusinessKey`, `PublicScheduleSlug`, `ContributionEntityState`,
  `ContributionPayload`, `ContributionSubjectType`, `ParticipantIdentity`.
- `SharedFormSchema` (1,571 lines) was split into
  `Platform/Ui/Forms/{AddressFields,ContactFields,SocialFields,FormOptions}`
  plus `DonationQrFields`/`DonationQrUploads`; a duplicate
  `normalizeLocationId` was deleted in favour of the canonical
  `AddressFormState::normalizeLocationId`.
- API documentation schemas moved to `Features/*/Api/Documentation/Schemas`
  with neutral `Shared/ApiDocumentation/{Country,AddressSelection}`;
  `Arrayable` was dropped while `toArray()` output stayed identical.

### 2.2 Platform god-class decomposition (R2/R5)

| Former god class | Result |
|---|---|
| `AdminServer` / `MemberServer` | registry-driven MCP surfaces (`Platform/Mcp/McpSurfaceRegistry`), owners register their own tools/prompts/resources |
| `AdminRecordActionService` / `MemberRecordActionService` | `RecordActionContributor` registry + owner contributors (Events, Reports, Contributions, Membership) |
| `AdminResourceRegistry` / `AdminResourceService` | owner `AdminResourceHooks` now own filters, MCP image tools, record metadata; `ResourceReadSupport` de-duplicates read helpers |
| `MemberResourceRegistry` / `MemberResourceService` | owner `MemberResourceHooks` + `MemberWorkflowToolContributor` registry (Contributions priority 10, Membership priority 20) |
| `ProductSignalSchemaRegistry` | `SignalSchemaContributor` registry + 12 owner contributors |
| `ApiRequestBodyExamplesExtension` | `RequestExampleContributor` registry + 10 owner contributors |
| `FrontendFormContractService` | `FrontendFormContractContributor` registry + owner contributors; thin dispatcher (116 lines) |
| `ApiWorkflowSchemasTransformer` / `PublicDirectorySchemasTransformer` | deleted (1,494 lines); `DocumentSchemaContributor` registry + 17 focused contributors/support classes |

All owner-specific behaviour now enters Platform through a neutral contract;
Platform contains **no `App\Features\*` imports** in these engines and no
per-resource-key branching.

## 3. Contract parity proof

Snapshots were taken from a pristine checkout of `09c00750` and compared with
the final tree (`554805ec`). Evidence files live in
`tasks/feature-refactor-evidence/`.

| Surface | Baseline | Final | Verdict |
|---|---|---|---|
| HTTP routes | 738, normalized sha `c1e55f7a…` | identical | ✅ |
| Schedule | full event list | identical | ✅ |
| Morph map | 96 aliases | identical | ✅ |
| Admin resources | 99 keys, 96 accessible metadata hashes | identical | ✅ |
| Member resources | 4 keys, metadata hashes | identical | ✅ |
| Admin manifest | sha `85bd7a7f…` | identical | ✅ |
| Member manifest | sha `d3017222…` | identical | ✅ |
| MCP surfaces | 18 admin + 13 member tools, 3+3 prompts, 1+1 resources | identical class sets | ✅ |
| OpenAPI | 152 paths, 49 schemas, normalized sha256 `171a93dd…` | identical | ✅ |

The metadata hashes include `mcp_tools` (including the `null` image-tool
placeholders for non-event resources), filters, abilities, relations and panel
routes, so the manifest contract is byte-identical.

## 4. Gates

| Gate | Command | Result |
|---|---|---|
| Architecture boundaries | `php tools/architecture-check.php` | 0 findings, exit 0 |
| Ownership types | `phpstan -c tools/architecture.neon` (OwnedWriteRule) | 0 errors |
| Static analysis | `phpstan analyse` (level 6) | 0 errors |
| Style | `vendor/bin/pint --test` | pass |
| Rector | `vendor/bin/rector process --dry-run` | 0 changes |
| Autoload/boot | `php artisan about`, `route:cache`/`config:cache` | pass (cleared after) |
| CI | GitHub Actions `Quality` | 26/26 jobs green |

Final CI runs: `554805ec` → 44624316134, `fd904528` → 44619151349,
`09c00750` → 44609914477, `bd6a3d7d` → 44601292137, `bf829db6` →
44593312867, `60b17cf0` → 44587185843, `5cab80ff` → 44581778730,
`11dd95b8` → 44574356341, `4063a706` → 44563760423, `90dc6305` → 44545842220.

Focused Pest selections executed during the waves covered MCP servers, docs,
admin/member API, manifests, contributions, engagement, signup attribution,
auth, share tracking, prayer, architecture fixtures and Filament resources.
Full suite execution is delegated to CI (`Quality` runs 20 Pest shards plus
browser smoke).

### New architecture gates

`tools/architecture-check.php` now rejects:

- any class in a retired root domain folder (`root-domain`);
- any new `app/Models/*` file outside the checked-in `root_models` allowlist
  (`root-model`);
- any `App\Services\…`/`App\Models\Event…` style stale reference in
  `app`, `config`, `bootstrap`, `routes` (`root-namespace`).

Positive and negative fixtures live in
`tests/Unit/Platform/ArchitectureBoundaryTest.php` (51 passing tests).

## 5. Remaining exceptions (deliberate, documented)

`tools/architecture.json` — 235 exceptions + 69 read adapters:

- **151 `feature-private`** — cross-feature composition that is correct but not
  yet expressed as an owner API. The dominant families are Contributions
  Filament forms reusing `Institutions`/`Persons`/`References`/`Places` form
  schemas, contribution getters/updaters consuming Events services, and
  Discovery importing owner API-documentation response contracts. Burning these
  down requires publishing owner form-schema/contract APIs (a design decision,
  not a mechanical move).
- **84 `architecture.writeLocation`** — package-owned models
  (`AIArmada\Affiliates`, Orders/Commerce, Sanctum tokens) written from the
  owning feature adapter. Each has an exact file, model, reason and expiry.
- **69 `read_adapters`** — documented temporary read-only ORM adapters created
  by owner form schemas.

`api-leak` and `platform-direction` exception categories are now **empty**.

## 6. Deviations from the v2 plan

1. **`ResourceDefinition`/`ResourceDefinitionRegistry` adoption was not
   completed.** The neutral seam exists in `app/Platform/Api/Resources`, and the
   behaviour it must protect is now owner-registered through
   `AdminResourceHooks`/`MemberResourceHooks`/`MemberWorkflowToolContributor`.
   Forcing 99 Filament-discovered resources (most from third-party packages)
   into per-resource definition classes would add ~99 Platform-visible classes
   with no ownership gain. The residual engines are generic surface/serialization
   code (936 / 891 / 649 / 523 / 496 lines) with zero feature imports and zero
   per-key branching; a `ResourceReader`/`ResourceWriter` migration remains a
   separate, behaviour-heavy design task.
2. **`ParticipantIdentity`** moved to `Registration/Support` rather than
   `Registration/Api`: it is a single-owner cryptographic adapter that needs
   `Crypt`/`config('app.key')`, which an Api value may not import.
3. **`PublicScheduleSlug`** now takes primitives (slug/title/id). The ASCII
   transliteration uses `voku/helper\ASCII::to_ascii(..., false)`; behaviour is
   identical for spaces/accents/`@` and pinned by a regression test, but letters
   with no ASCII mapping (Arabic/CJK) are preserved rather than dropped by
   `Str::ascii`.

## 7. Environment caveats

- `pango-view` is not installed locally, so Pango share-card rendering tests
  fail here; CI installs Pango and those tests pass there.
- The local Paratest/Pest parallel wrapper is broken; per-file runs were used
  for focused feedback and CI for the full parallel suite.
- PHPStan needs `-d memory_limit=-1 -d max_memory_limit=-1 --debug`; Rector
  needs `PHP_INI_SCAN_DIR="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;'):/tmp/opencode/php-conf"`.
- Database-backed snapshots were taken against the local SQLite database; CI is
  the authoritative full-suite execution.

## 8. Evidence index

`tasks/feature-refactor-evidence/`

- `before-baseline.txt` — environment and branch at the evidence baseline.
- `before-schedule.txt` — schedule snapshot.
- `before-contracts.json` / `after-contracts.json` — routes, MCP surfaces,
  resource keys/metadata hashes, manifest hashes, morph map.
- `before-openapi.txt` / `after-openapi.txt` — OpenAPI path/schema counts and
  normalized hash with the parity verdict.
- `root-domain-gate.txt` — retired root folders and root models.
- `stale-namespace-gate.txt` — forbidden legacy namespace scan (the single
  `App\Services\` hit is a negative assertion in a test).
- `final-gates.txt` — final architecture result, exception categories, and
  Platform files over 400 lines.

## 9. Recommended follow-ups

1. Publish owner form-contract APIs so the 151 `feature-private` Filament
   composition exceptions can be burned down.
2. Decide whether the `ResourceDefinition` seam should be adopted for app-owned
   resources only (Events, Institutions, Persons, References, Contributions,
   Membership, Reports) while package resources stay Filament-discovered.
3. Burn down the 69 read adapters as owner read contracts land.
