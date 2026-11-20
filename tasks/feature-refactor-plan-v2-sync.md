# Plan v2 sync — requirement by requirement

Bundle: `ilmu360-feature-ownership-refactor-plan-v2.zip` (Revision v2, 2026-11-18).
Branch `refactor`. This file tracks the plan's binding sections `06` and `07`
against the repository.

## 07 §6 — Completion acceptance table

| Gate | Plan expectation | Status | Evidence |
|---|---|---|---|
| Root business families | Empty except native Laravel bridges | ✅ | 0 files in 14 retired dirs; only `app/Models/{User,PassportUser}.php` |
| Feature manifests | Unique owner, existing writers, no extra folder | ✅ | 28 features validated; `Moderation` added |
| Model seam | Zero unauthorized foreign model data access; no per-type waivers | ✅ | 0 per-type waivers; 0 read adapters; owner controllers/readers only |
| Admin + Member registry | Single deterministic engine + owner adapters | ✅ | `ResourceDefinitionRegistry`; owner `EntryPoints/Api/{Admin,Member}`; 231/647/92 + 184/259/137 lines |
| Catalog + Contributions | Split by owner; original services deleted | ✅ | `FrontendCatalogService` gone; per-owner `Api/ContributionProjection`; `ContributionOrganizer` value |
| Event search | Owner-isolated SQL/lookup; parity | ✅ | SQL in `Features/Events/Queries`; immutable `Discovery\Api\Data\EventSearchPage`; `Events\Api\EventCards`; model-bearing `EventSearchPage` deleted |
| Architecture exceptions | Only justified package writes | ✅ | 62 entries, all package/framework-owned (Affiliates, Communications, Engagement, Orders, Sanctum, Spatie, users, audit) |
| Route and schedule | Identical to baseline | ✅ | 738 routes, same normalized hash; schedule identical |
| Filament and MCP | Same resources/tools/access | ✅ | admin 100 / ahli 24 resources, widgets and nav identical; MCP class sets identical |
| Static/tests/CI | Executed with evidence | ✅ | architecture 0, PHPStan L6 0, ownership types 0, Pint, Rector; `npm run build` exit 0; CI Quality 26/26 at `12701a88` (run 44764013556) |
| No legacies/leftovers | No obsolete classes/aliases/wrappers | ✅ | 0 stale namespaces, 0 empty app dirs |
| Final report | Categorized deltas and evidence | ✅ | R6 report + this tracker + evidence directory |

## 06 §A — Model seam

| Requirement | Status |
|---|---|
| Foreign owner `Models` allowed for relationships/types/framework callbacks | ✅ structural checker allowance |
| No per-model/relation waiver entries | ✅ 0 |
| No foreign business queries/writes without an owner API | ✅ zero read adapters |
| `Discovery/EntryPoints/Http/SearchController` | ✅ reduced to the aggregate-search gateway only (97 lines); directory/detail endpoints moved to `Institutions`, `Persons`, `References`, `Places`, and `Events` entrypoints |

## 06 §B1 — Resource engine

| Requirement | Status |
|---|---|
| One `ResourceDefinitionRegistry` | ✅ |
| `ResourceDefinition` / `ResourceReader` / `ResourceWriter` values | ✅ |
| Owner adapters under `Features/<Owner>/EntryPoints/Api/{Admin,Member}` | ✅ |
| Feature-owned registration | ✅ feature providers |
| No static Platform list, single discovery | ✅ `Filament::getPanel('admin')->getResources()` supplies order; no hard-coded list |
| Old mixed engines reduced to dispatchers | ✅ 2,323 → 970 lines; no feature imports, no model switches |
| Admin/Member manifest, metadata, CRUD, batch, MCP parity | ✅ identical hashes; admin MCP 87/87, admin API + frontend parity 204/204, member 58/58 |

## 06 §B2 — FrontendCatalogService

| Requirement | Status |
|---|---|
| Mixed 557-line service deleted | ✅ |
| Neutral transport dispatcher | ✅ `Platform/Api/Frontend/CatalogDispatcher` |
| Owner catalogs | ✅ Address/Language (Platform), Events taxonomy, References, Institutions, Persons, Places, Membership, Prayer |

## 06 §B3 — Event search

| Requirement | Status |
|---|---|
| Event SQL owned by Events | ✅ `Features/Events/Queries/PostgresEventDiscovery` |
| Discovery orchestrates via owner readers | ✅ |
| Public Api returns immutable values | ✅ `EventSearchPage` (cards + distances + pagination); no models/paginators |
| Cached default search without a model-bearing Api | ✅ `Events\Api\EventCards` |
| Legacy `Events\Api\EventSearchPage` removed | ✅ deleted with `CachedEventSearchPage` |
| Parity | ✅ EventSearch 166/166, HomePage, MCP search, SavedSearch, frontend parity |

## 06 §B4 — Contribution projections

| Requirement | Status |
|---|---|
| Per-owner `ContributionProjection` interfaces | ✅ Events, Institutions, Persons, References |
| Contributions thin dispatcher by persisted alias | ✅ `ContributionProjectionResolver` |
| No Shared projection interface / Platform registry | ✅ deleted |
| Venue projection removed (not a subject) | ✅ |

## 06 §C / 07 §3 — Exception governance

| Requirement | Status |
|---|---|
| Zero model-seam waivers | ✅ |
| Zero Platform god-class waivers | ✅ |
| Zero domain-private waivers | ✅ (151 → 0) |
| Only justified package writes | ✅ 62, all package/framework |
| No wildcard/stale/expired entries | ✅ |
| Categorized delta | ✅ this file + R6 report |

## 04 — Validation gates

| Gate | Status |
|---|---|
| Route/schedule snapshots | ✅ |
| MCP tool/prompt/resource parity | ✅ |
| Filament admin/ahli inventory | ✅ 0 differences |
| OpenAPI | ✅ 152/152 paths canonical-identical; zero schema drift; `PublishedContractSchemasContributor` freezes the published shapes |
| Static/architecture/types/Pint/Rector | ✅ |
| Frontend build | ✅ `npm run build` exit 0 |
| Full Pest | ✅ CI 20 shards green (Quality run 44764013556, 26/26 jobs) |

## Remaining

None. The two completion-wave gaps (the shared directory/detail controller
adapter and the OpenAPI response-schema drift) are closed; see the completion
sections below.

## Completion wave — owner controller split

| Requirement | Status |
|---|---|
| No read adapters | ✅ `read_adapters` empty; architecture 0 |
| Institution endpoints owned by Institutions | ✅ `InstitutionDirectoryController` + `InstitutionCards` |
| Person endpoints owned by Persons | ✅ `PersonDirectoryController` + `PersonCards` |
| Reference endpoints owned by References | ✅ `ReferenceDirectoryController` |
| Venue endpoint owned by Places | ✅ `VenueDirectoryController` |
| Series endpoint owned by Events | ✅ `SeriesDirectoryController` |
| Aggregate search stays neutral | ✅ `Discovery\SearchController` (97 lines) via owner cards/search APIs |
| Cross-owner event cards | ✅ `Events\Api\EventCollections` |
| Follow state | ✅ `Engagement\Api\SubjectFollows` (no raw follows table reads) |
| Published OpenAPI | ✅ 152/152 canonical-identical (contract freeze contributor) |
