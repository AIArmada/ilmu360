# Refactor test and CI gap report (stream F)

> **Read-only inventory.** No application code, tests, tools, providers, config or workflows were changed to produce this report.
> **Snapshot:** branch `refactor`, HEAD `27decde3`. The worktree is **actively being edited by another stream** while this report was written:
> at first inspection only `tools/architecture-check.php`, `tools/architecture.json`, `tests/Unit/Platform/ArchitectureBoundaryTest.php` and untracked
> `app/Platform/Api/Resources/*` differed; by 13:47 UTC 21 app files were modified (Platform stats/sitemap/observer pruning, workflow contract additions)
> and the exception total had moved from 366 at HEAD to 337. Treat every worktree-specific statement below as a timestamped observation and re-read the
> worktree before acting. **The contradictions and coverage gaps are stated against HEAD**, which is stable.
> **Method:** source inspection only. No test suite was executed, so no pass/fail claim is made.

Binding inputs: `06-MODEL-SEAMS-AND-DESCRIPTOR-CONTRACTS.md` §A (model seams), §B1–B4 (splits), §C (exception governance);
`07-EXCEPTION-BURNDOWN-AND-RESUME.md` §2, §4 stream F, §5 R1–R6; `04-VALIDATION-CLEANUP.md` §4–6.

---

## 0. Why this matters now

- R1 (model-seam correction) is **not complete** at HEAD: the architecture test asserts the opposite of `06-A2/A3`, and the checker has no
  `foreign-model-domain-operation` rule. The worktree fixes part of this but adds no complete negative/positive fixture set and no category names.
- R2–R4 have **not started at HEAD**: all nine audited god classes still exist at essentially their audited sizes (`07` §2). `AdminResourceService` still imports
  `EventResource`, `PersonResource`, `Event` and `User`; `AdminResourceMutationService` switches over 9 concrete resource classes; `ContributionEntityStateService`
  switches over 5 concrete models. The worktree is doing Platform pruning and B1 groundwork, but no descriptor split has landed. Their external contracts are only
  partially frozen by tests, so a split would currently be free to drift.
- R6 cannot be evidenced: there is no route-inventory parity test, no schedule-count/`once` test, and CI never runs route/schedule parity.

---

## 1. Contradicted tests

### 1.1 `tests/Unit/Platform/ArchitectureBoundaryTest.php` (HEAD)

| HEAD test (line) | Current assertion | Contradiction with v2 | Replacement |
|---|---|---|---|
| `rejects references to another feature private model` (47–91) | Exit 1 + `feature-private` for aliased import, grouped import, FQCN type, literal class string of `App\Features\Orders\Models\Order`. | `06-A2/A3`: a foreign **Models** import/type/string in an ORM/type context is **allowed**; it is `foreign-model-reference`, not a violation, and gets **no exception**. | Convert to a positive fixture asserting exit 0 and no `foreign-model-reference`/`foreign-model-domain-operation`/`foreign-feature-private` finding. Keep all four syntax variants and add relation + morph + Api cases. |
| `allows a feature to use an app model whose table it owns` (92–115) | Exit 0 when `Orders` imports `App\Models\Order` and calls `Order::query()->create()`. | `06-A4` + `04` §2: no root business model may exist (`app/Models` is allowlisted only for `User`/`PassportUser`). This test blesses a permanent root domain model and is no longer a valid allowance. | Replace with: `app/Models/Order.php` → **violation** (`root-domain-model`, new rule); `app/Models/User.php` → **allowed** (native bridge). Move the positive case to `App\Features\Orders\Models\Order`. |
| `accepts parent delete only from the exact declared root model` (116–203) | Creates root `App\Models\Space`/`Venue`, expects write exceptions to make them pass; expects stale-exception exit 2 when the nested parent delete is removed. | Root models must be gone at completion. The provenance logic itself is still worth testing, but not as an allowance for root domain models. | Rewrite against `App\Features\Places\Models\Space`/`Venue`; keep the stale-exception assertion. |
| `rejects a feature dependency on an app model owned by another feature` (204–230) | Exit 1 + `feature-external` for `App\Models\Order` + `Order::query()->create()`. | With feature models, the import is allowed and only the **operation** is illegal. The expected rule is now `foreign-model-domain-operation`, not `feature-external`. | Rewrite both the source and expectation to `App\Features\Orders\Models\Order` + `foreign-model-domain-operation`. |
| `rejects an owned mutable model in a public boundary` (272–289) | Exit 1 + `api-leak` for an owner model returned from `Features\Orders\Api`. | Behavior is still right, but `06-A3` names the category `public-api-model-leak`; coverage is same-owner only and misses cross-owner and `Builder`/paginator leaks. | Keep and rename; add cross-owner model, `Illuminate\Database\Eloquent\Builder`, and PHPDoc-only FQCN cases. |
| `rejects an exception after its private dependency has been removed` (290–306) | A `feature-private` exception whose `target` is `Orders\Models\Order` is expected to become stale. | Model references must not be `feature-private` at all; a model-target `feature-private` row is itself obsolete. The stale-exception mechanics remain valid. | Change the target to `App\Features\Orders\Services\OrderSynchronizer` so it exercises a real private import; add a separate assertion that no `feature-private`/`platform-direction` exception targets `\Models\`. |
| **Missing** positive fixtures | — | `06-A3` explicitly requires positive proof for `belongsTo(Institution::class)`, `BelongsTo<Institution, Event>`, morph alias map, and owner `Api` scalar IDs. | Add to the same file’s dataset (see §4.1). |
| **Missing** negative fixtures | — | `06-A3` requires proof for `Order::query()`, `->save()`, private Services imports, Platform model imports, and Api model leaks. HEAD has none (the checker did not implement the first two). | Add (see §4.1). |

### 1.2 `tests/Unit/Platform/FeatureCatalogTest.php`

**No v2 contradiction.** It locks manifest schema-2 loading, writer-directory rules, duplicate resource owners, morph aliases, and root-store-writer metadata. It does not assert model imports. Keep as-is. It is, however, the wrong home for `(surface,key)` duplicate registration; that belongs with the new registry (§4.2).

### 1.3 `tests/Unit/Platform/OwnedWriteRuleTest.php`

**No v2 contradiction.** It enforces `06-A3` #6 `foreign-store-write` (`$order->save()` from Billing → `architecture.foreignWrite`) and unregistered-location writes. Keep. Two gaps:

- No fixture proving a **type-only** foreign model reference produces **zero** write errors (import vs write distinction is the whole point of R1).
- No static-write fixture (`Order::create()`/`Order::where()->update()`) in this rule; that is now expected under `foreign-model-domain-operation` in the boundary test, not here.

### 1.4 `tools/architecture.json` at HEAD

366 exceptions: `architecture.writeLocation` 58, `api-leak` 5, `feature-private` 161, `api-shape` 3, `platform-direction` 139.
There are **zero exceptions whose target contains `\Models\`**, so the model-seam problem is the *checker rule*, not waiver rows. Do not “remove 161 model waivers”; there are none. The 161 `feature-private` rows are non-model private imports and must be burned down by the B1–B5 splits (R2–R5), not by R1.

### 1.5 Worktree state (partial R1 + Platform pruning, not shippable yet)

Observed at 13:27–13:47 UTC; re-check before acting.

`git diff` already:

- renames the model test to `allows foreign feature model type and relation references` (47–91) and adds
  `rejects cross-owner Eloquent model queries and construction` (93–129) and `rejects platform imports of feature models` (131–147);
- narrows the `feature-private` model exemption to `$sourceFeature && \Models\` and removes the Platform model seam in `architecture-check.php`;
- adds `StaticCall`/`New_` detection as `foreign-model-domain-operation`;
- removes two `api-leak` exceptions.

Still missing before this can be committed as R1:

1. Instance domain operations (`$order->cancel()`, `$order->refresh()`, relation `attach()`/`sync()`) are not classified; only `save/update/delete` are caught later by the write rule.
2. Container/dynamic resolution (`app(Order::class)`, `resolve(Order::class)`, `Order::class` in a binding array) is not detected. `06-A3` accepts manual review for this, but the test suite should say so explicitly rather than imply full coverage.
3. No `foreign-model-reference` diagnostic inventory, so R1 cannot report “permitted seams” separately from exceptions as required by `07` §6/`04` §8A.
4. Rule names remain legacy (`feature-private`, `platform-direction`, `api-leak`); `06-A3` prescribes `foreign-feature-private`, `platform-feature-private`, `public-api-model-leak`. Pick one mapping and document it in the ledger; otherwise the final category delta cannot be produced mechanically.
5. **Blocking for B1:** `$public = $sourceFeature && str_contains($relative, '/Api/')` also matches `Features/<Owner>/EntryPoints/Api/{Admin,Member}/…`. A B1 adapter at the prescribed location that imports its own `Models`, `Queries`, `Actions` or Filament `Resource` will be reported as `api-leak`/`api-shape`. Narrow the public-Api detector to `app/Features/*/Api/` (the contract namespace), not `EntryPoints/Api/`.
6. Removing the `api-leak` waivers before retiring the two leaking APIs leaves `EventDiscoveryAdapter` (still imports `Features\Events\Models\Event`) and
   `ShareTrackingContract` exposed; once the current stale-exception error is cleared this becomes an `api-leak` finding. Do not commit a red or
   partially-clean architecture gate: either retire those APIs in the same slice, or keep the two waivers until the replacement exists. At 13:49 UTC the
   worktree checker exited 2 on `Remove stale exception: app/Platform/Api/Admin/AdminResourceMutationService.php App\Features\DonationChannels\Api\DonationChannelWorkflow`,
   i.e. the exception file is mid-burn-down and is not usable as evidence.
7. No negative test for a non-model private import (`use App\Features\Institutions\Services\InstitutionService` from an Events action) and no positive test for `belongsTo`/`BelongsTo<>`/morph map. These are the fixtures that make the semantic rule real.

---

## 2. Existing coverage map

### 2.1 Admin/Member resource manifest and CRUD

| File | Tests | Behavior actually locked |
|---|---:|---|
| `tests/Feature/Api/Admin/AdminApiTest.php` | 87 | Manifest access by admin/viewer and role drift, bearer-ability isolation; manifest key **subset** (`people, events, inspirations, institutions, references, reports, series, spaces, venues, donation-channels, address-countries, address-areas`) and fixed top-level docs/surface-sync/workflow keys; person/event search and filters; per-resource metadata/write schemas for people, events, institutions, references, venues, series, spaces, donation-channels, reports, inspirations, address-areas; validate-only previews and remediation payloads; relations listing; batch create/update with per-row errors, missing record keys, `validate_only`, no rollback of successes; media replacement/clear semantics for report/inspiration/series/donation-channel/person/institution/venue/event. |
| `tests/Feature/Api/Admin/AdminApiMcpParityTest.php` | 8 | HTTP vs MCP equality for: person search route keys, event filter route keys, related record listing, validate-only update preview, event moderation, report triage, contribution review, membership review. |
| `tests/Feature/Mcp/AdminServerTest.php` | 87 | `admin-list-resources` accessible keys (intersection count, not exclusivity), compact vs `verbose` metadata, no `resource_class` unless verbose; tool inventory via `toContain`; per-tool JSON schemas, required fields, annotations and OAuth scopes; record list/detail/relations/media; event search/date filters/prayer metadata; write schemas and create/update for most writable resources; batch tools; validate-only and remediation; file descriptors; viewer/member/admin authorization; HTTP MCP initialize/tools-list/auth/scope. |
| `tests/Feature/Mcp/MemberServerTest.php` | 39 | Exactly 2 accessible member resources and their order for institution members (`institutions, events`) and person members (`people, events`); institution metadata/list/detail; person/reference/event write schemas; institution update preview; relations; contribution and membership workflow tools; write-tool hiding; admin/member isolation; HTTP MCP auth/scope. |
| `tests/Feature/Api/Member/MemberApiMcpParityTest.php` | 4 | Public contribution API vs member MCP parity for request listings/actions; membership application list/submit/cancel. Not resource-manifest parity. |
| `tests/Unit/McpGuideDocsTest.php` | 3 | Sorted **writable** admin/member key sets compared to docs markdown; uses `AdminResourceMutationService`/`MemberResourceMutationService` (classes B1 deletes). |
| `tests/Feature/AdminResourcesCoverageTest.php` | 5 | Super-admin access to 15 Filament resource index pages; `FilamentResourceRegistry` dedupe; commerce event template registration in both panels; expected relation managers. This is Filament-panel coverage, not API manifest coverage. |
| `tests/Feature/AppFeedback/AppFeedbackAdminTest.php`, `Community/CommunityReleaseTest.php`, `ManagedWorkspacesTest.php`, `OrganizationFrontendTest.php` | spot | Feature resource classes are registered/resolvable (`AdminResourceRegistry::resources()`, `MemberResourceRegistry::resolve()/resources()`). These will need rewriting when the registries change from Filament classes to `ResourceDefinition`s. |

### 2.2 Frontend catalog

| File | Behavior locked |
|---|---|
| `tests/Feature/Api/Frontend/CatalogApiTest.php` | 11 of 18 methods: states require country and are country-scoped; cities require state; references only published verified/pending; administrative subdivisions require district/state; venues only active/verified/pending; membership subjects; taxonomy terms; districts; institution-role options require auth; prayer institutions only verified; submit-institutions only `allow_public_event_submission`. |
| `tests/Feature/Api/Frontend/CatalogLocationCascadeTest.php` | Provider-driven area options; Singapore stateless cascade; Malaysian cascade unchanged. |
| `tests/Feature/Api/Frontend/FrontendApiParityTest.php` | Countries and spaces catalogs (institution scoping, no truncation); form-contract links to languages/submit-persons catalogs. |
| `tests/Feature/ReferenceAuthorsTest.php` | `reference-authors` catalog search. |
| `tests/Feature/Api/Frontend/FormContractApiTest.php` | Advanced event form contract, which calls `FrontendCatalogService::advancedBuilderContext()`. |
| `tests/Feature/FeatureOwnershipSeamsTest.php` | Directly binds/calls `FrontendCatalogService` for `submitInstitutions`, `references`, `submitPersons`. Must be rewritten to owner catalog APIs at B2. |

Missing: exact 18-method output golden (keys, order, null/empty semantics, limit default 50, `q` trimming) and the `advancedBuilderContext` output shape itself.

### 2.3 Contributions

| File | Behavior locked |
|---|---|
| `tests/Feature/ContributionWorkflowServiceTest.php` | Pending create requests for institution/person; structured relation data; approval creates a record without attaching proposer membership and notifies; staged create does not duplicate; rejection notifies; original-data capture and approved reference/institution/event updates; event associations/languages and key people; `updateRulesFor()` validation for an event. |
| `tests/Feature/ContributionWorkflowActionsTest.php` | Action-layer approve/reject/cancel/delete; proposer deletion; slug/UUID context resolution; metadata shape; pending lookups; duplicate guards; ~22 workflows. |
| `tests/Feature/ContributionPagesTest.php` | Dedicated institution/person/reference pages; locale copy; validation; media staging and apply/reject preservation for person avatar, reference cover, event media; event schedule/prayer preservation; direct-edit authorization; visibility of reviewer fields; 85 scenarios. |
| `tests/Feature/ContributionApprovalDuplicateRecheckTest.php` | Maps identity/name/locality duplicate recheck at approval; pending-vs-pending conflicts; lock serialization; stale approval/rejection; API 409/422/503. |
| `tests/Feature/PendingContributionDuplicateGuardTest.php` | Submission-time duplicate guards for place/cid/name/locality, UUID casing, area assignments. |
| `tests/Feature/ContributionRequestAdminResourceTest.php` | Filament approve/reject, conflict notifications, view page, bulk delete through lifecycle. |
| `tests/Feature/Contributions/ContributionBoundarySeamsTest.php` | Literal table, morph aliases, enum backing values, reader binding, feature manifest, staged-media copy. |
| `tests/Feature/ContributionLocationCascadeTest.php`, `ContributionEventPrayerTest.php`, `ContributionReactiveFieldTest.php`, `ContributionAddressPersistenceTest.php`, `InstitutionContributionLocationPickerTest.php` | Location/prayer/reactive/address behavior. |

Missing: exact `contractFor`/`stateFor`/`updateRulesFor` wire arrays per entity, per-owner `create*`/`apply` parity, explicit transaction/rollback side-effect counts, after-commit notification ordering.

### 2.4 Event search

| File | Behavior locked |
|---|---|
| `tests/Feature/EventSearchTest.php` | 120 scenarios: title/fuzzy/transposition search; institution/person/reference expansion toggles; reference-author search; language/format/muslim-only/gender/link/timing/absolute-time filters; district/country/subdistrict/federal-territory; category/bidang; prayer-time; near/nearby; pagination; cached-result behavior; relation hydration for both discovery adapters; public-status visibility per adapter; card eager loads; query listener for cache priming. |
| `tests/Feature/EventSearchTypesenseFilterTest.php` | Deterministic criteria normalization; Typesense `filter_by` strings for status, area, country, tags, references, PIC; DB-fallback triggers (reference author, etc.); past/overlap `ends_at` semantics. |
| `tests/Feature/EventSearchRelationProviderIntegrationTest.php` | Injected package `EventSearchRelationProvider` is consumed by Postgres and “Typesense” paths. |
| `tests/Feature/EventLocationFilterParityTest.php` | API selected-address contract vs DB discovery; venue/institution precedence; secondary address exclusion; nearby distance; `venue_id` winning place; eager-loaded place reuse. |
| `tests/Feature/EventListBatchLoadingTest.php` | Occurrence media query counts (1 or 2) for serialized search results and person roles. |
| `tests/Feature/PublicScheduleDiscoveryTest.php` | Occurrence/session nesting, scoped pages, private child exclusion/cross-parent traversal, institution/reference/speaker leaves, inherited prayer timing. |
| `tests/Feature/Discovery/DiscoveryTimezoneBoundaryCharacterizationTest.php` | Viewer-local date → UTC midnight; past/upcoming by end instant. |
| `tests/Feature/Discovery/EventVisibilityMatrixCharacterizationTest.php` | Home/list/REST detail/Livewire/sitemap visibility matrices. |
| `tests/Feature/Discovery/GroupedOccurrenceEndInstantTest.php` | Grouped-occurrence end instant across SQL/page/API. |
| `tests/Feature/Discovery/SavedSearchMatchingCharacterizationTest.php` | One full search per saved-search candidate (query-count semantics). |
| `tests/Feature/PrayerTimeFilterTarawihTest.php`, `SearchFilterCatalogTest.php`, `SearchFilterRulesTest.php`, `WeekendDateFilterTest.php` | Prayer labels, canonical filter vocabulary/validation, weekend date filter. |

Important limitation: the “Typesense” tests run with `scout.driver=database`, so they exercise Scout’s database engine, not a real Typesense result set. `EventSearchTypesenseFilterTest` only asserts filter strings. There is **no** DB↔Typesense result/ordering/count parity test and **no** Typesense-error fallback test for events.

### 2.5 Route and schedule parity

| File | Behavior locked |
|---|---|
| `tests/Feature/ScheduleIntegrityTest.php` | 9 tests / 11 descriptions: notification reminders, escalation, prune orphaned, sync locks, media clean/regenerate, horizon snapshot, communications dispatch, share-pending-effects, affiliate maturity/payouts, and absence of affiliate ranks/bonuses/aggregate. |
| `tests/Feature/RouteCachingGuardTest.php` | Only `GET api/v1/user`, `GET docs`, `GET docs.json` are controller-backed (not closures). |
| `tests/Feature/ScrambleDocsTest.php` | Every live `api/v1` route has a generated Scramble operation and vice versa; selected schemas/examples/security. Not a baseline inventory: a route dropped from both code and docs passes. |
| `tests/Feature/Api/ApiAuthorizationParityGuardTest.php` | No Sanctum ability middleware on API routes outside the allowlist; no `tokenCan` outside allowed paths. |
| `tests/Feature/OrganizationSeamsTest.php` | Named organization routes resolve to expected actions. |

**Missing:** a checked-in route inventory baseline (method/domain/URI/name/middleware/order/duplicates) and a schedule inventory asserting every named schedule is registered **once** with exact cron/timezone/`withoutOverlapping`; 7 of the 15 named schedules in `routes/console.php` have no test at all.

---

## 3. CI gates and gaps

Workflows present: `quality.yml`, `fix-code-style.yml`, `verify-final-regressions.yml`, `verify-media-regressions.yml`.

| Gate | Where / command | What actually runs | Gap |
|---|---|---|---|
| Change detection | `quality.yml` `changes` (`dorny/paths-filter`) | `php`: `**/*.php`, composer files, pint/rector/phpstan, `tools/**`, `app/Features/**/feature.json`, workflow. `tests`: `**/*.php`, composer, `phpunit.xml`, `tests/**`, `tools/**`, feature.json, workflow. | `resources/js/**`, `resources/css/**`, `package.json`, `vite.config.*`, `public/**` match **neither** filter. A JS/CSS/asset-only change runs no build, no browser, no tests; `quality` passes because skipped jobs are neither `failure` nor `cancelled`. |
| Rector | `vendor/bin/rector process --dry-run --ansi` | All PHP. | Fine, but only when `php` filter true. |
| Pint | `vendor/bin/pint --test` | All PHP. | Fine. |
| PHPStan + architecture | `composer arch:check` = `php tools/architecture-check.php` + `phpstan analyse -c tools/architecture-phpstan.neon` (level 6 + `OwnedWriteRule`) | `app`, `bootstrap`, `config`, `database`, `routes`, `tools`, affiliate vendor source; tests excluded. | No assertion on exception **categories/budget**; hundreds of exceptions (366 at HEAD; worktree mid-burn-down) are green. No `tests/Architecture` path exists in `phpunit.xml` or the shard finder, so the `04` §2 suggestion `tests/Architecture/NoRootDomainCodeTest.php` would never run. |
| Pest shards | `quality.yml` `tests`, 20 shards | `find tests/Feature tests/Unit -name '*Test.php'`, one file per `vendor/bin/pest --compact` (serial per shard), PostgreSQL 18. | `tests/Browser` is excluded; a new browser test is never run. New golden tests under `tests/Fixtures` or `tests/Architecture` never run. Real-driver tests are included (intentional per `tests/Pest.php` comment) but no `composer test:drivers` evidence is produced. |
| Browser | `quality.yml` `browser` | `npm ci && npm run build && npx playwright install --with-deps chromium && vendor/bin/pest --ci --trace tests/Browser/PlaywrightSmokeTest.php` | Only the single home-page smoke test (`visit('/')->assertSee('ilmu360')`). Gated on `tests` filter, so JS/CSS-only changes skip it. No admin/member/resource UI smoke. |
| Final regressions | `verify-final-regressions.yml` | Push to `refactor` only; triggers on itself, `app/Platform/Ui/Components/EventJsonLd.php`, `tests/Feature/FilamentEventResourceTest.php`, two Registration actions. Runs 3 Pest files. | `app/Platform/Ui/Components/EventJsonLd.php` no longer exists (now `app/Features/Events/EntryPoints/Ui/EventJsonLd.php`), so the UI trigger is dead. Push-only, no PR/manual dispatch. |
| Media regressions | `verify-media-regressions.yml` | Push to `refactor` only; triggers include `app/Mcp/Tools/Concerns/DefinesMcpFileDescriptorSchema.php`, `app/Mcp/Tools/Concerns/UploadsEventImage.php`, `app/Models/Reference.php`. | All three paths are stale: they now live under `app/Platform/Mcp/…`, `app/Features/Events/EntryPoints/Mcp/…`, `app/Features/References/Models/…`. The workflow therefore never runs from the code it is meant to verify. |
| Style autofix | `fix-code-style.yml` | Rector fix + Pint on push to any branch, commits and pushes as bot. | Can rewrite an in-flight refactor branch; not a verification gate. No `arch:check` after autofix. |

Additional gaps:

- No CI job runs `php artisan route:cache`/`config:cache`/`optimize:clear`; `04` §5.B requires them.
- No CI job compares `route:list` or `schedule:list` against a saved baseline.
- No CI job runs `npm run build` when only frontend assets change.
- No CI job asserts MCP tool count/uniqueness; only Pest tests with `toContain` assertions.
- No CI job runs the full `tests/Browser` suite; there is only one browser test today.

---

## 4. Required new fixtures and golden tests (per 06-B)

### 4.1 R1 / `06-A` — model seam (P0)

Target: `tests/Unit/Platform/ArchitectureBoundaryTest.php` (rewrite the tests in §1.1; keep `FeatureCatalogTest`/`OwnedWriteRuleTest`).

**Positive fixtures** (exit 0; no `foreign-model-reference`/`foreign-model-domain-operation`/`foreign-feature-private` finding):

| Fixture | Proves |
|---|---|
| aliased `use …\Models\Order as Purchase; handle(Purchase $order)` | import aliasing is a reference, not an operation |
| grouped `use …\Models\{Order};` | group-use handling |
| fully-qualified `handle(\App\Features\Orders\Models\Order $order)` | FQCN handling |
| `public const MODEL = 'App\\Features\\Orders\\Models\\Order';` | literal string handling |
| `public function institution(): BelongsTo { return $this->belongsTo(Institution::class); }` | Eloquent relation identity |
| `/** @return BelongsTo<Institution, $this> */` | generic relation type |
| morph map `Relation::morphMap(['institution' => Institution::class])` | morph identity |
| owner `Api` `find(string $id): ?OrderSnapshot` and `institutionId: string` | public boundary uses scalars/values, not models |

**Negative fixtures** (exit 1; assert the **specific** rule and target, never merely the import):

| Fixture | Expected rule |
|---|---|
| `Order::query()->first()` / `Order::where(...)->update(...)` / `Order::create([...])` / `Order::destroy(...)` | `foreign-model-domain-operation` |
| `new Order` | `foreign-model-domain-operation` |
| `app(Order::class)` / `resolve(Order::class)` | `foreign-model-domain-operation` (implement container-call detection) |
| `$order->save()/update()/delete()` on a foreign-typed parameter | `foreign-store-write` (write rule) **or** `foreign-model-domain-operation`; assert exactly one, not both |
| `use App\Features\Institutions\Services\InstitutionService;` in an Events action | `foreign-feature-private` |
| `App\Platform\Admin\Widget` imports `App\Features\Orders\Models\Order` | `platform-feature-private` |
| `App\Platform\Admin\Widget` imports `App\Features\Orders\Services\OrderService` | `platform-feature-private` |
| `bootstrap/providers.php` references `Orders\FeatureServiceProvider` | allowed (composition root) |
| `Billing\Api\FindOrder::find(): ?Order` (owner model) | `public-api-model-leak` |
| `Billing\Api\FindOrder::find(): ?\App\Features\Orders\Models\Order` (PHPDoc-only FQCN) | `public-api-model-leak` (requires PHPDoc parsing; currently missed) |
| `Billing\Api\FindOrder::find(): Builder<Order>` / `LengthAwarePaginator<int, Order>` | `public-api-model-leak` |
| `Billing\Api\FindOrder::find(string $orderId): ?OrderSnapshot` | allowed |

Also add in `OwnedWriteRuleTest.php`: a type-only foreign model reference returns `errors=0,file_errors=0`; and in `ArchitectureBoundaryTest.php`: a fixture against the **real** `tools/architecture.json` asserting no exception target contains `\Models\` and no exception file/target is a stale path.

### 4.2 `06-B1` — resource engine (P0/P1)

| Test | Target file | Assertion |
|---|---|---|
| Registry contract | **new** `tests/Unit/Platform/ResourceDefinitionRegistryTest.php` | `register`/`registerWriter` throw on duplicate `(surface,key)`; same key on Admin and Member coexists; `resolve` returns the same instance; `reader` returns null for a definition-only key; `all`/`accessible`/`writable` preserve registration order; interfaces expose no `Model`, `Builder` or Filament type (reflection over signatures). |
| Full admin manifest golden | `tests/Feature/Api/Admin/AdminApiTest.php` (extend; 87 existing tests) | Exact ordered `data.resources` snapshot for a super admin: every key, metadata, relations, abilities, filters, `write_support`, `api_routes`, `panel_routes`, `mcp_tools`; exact `version`, `docs`, `surface_sync`, `write_workflow`, `rules`. Assert no duplicate key and no `resource_class` in JSON. |
| Full member manifest golden | `tests/Feature/Mcp/MemberServerTest.php` | Exact 2-key list and order for institution-member and person-member contexts, full metadata, and exact `member-get-resource-meta` payloads for all 4 member keys. |
| Per-key read parity | `tests/Feature/Api/Admin/AdminApiTest.php` + `tests/Feature/Mcp/AdminServerTest.php` | Dataset over every registered admin key: list (pagination/filters/sort), detail, relation, media; exact keys/status/errors. Missing-record and forbidden-record negative cases. |
| Per-key write parity | `tests/Feature/Api/Admin/AdminApiTest.php` | Dataset over every writable key: create/update schema, `validate_only` (no persistence), store/update, invalid payload; exact error bodies and side-effect counts. |
| Batch semantics | `tests/Feature/Api/Admin/AdminApiTest.php` (extend 3700–3950) | Mixed valid/invalid batch: per-row statuses/errors, successful rows committed, `validate_only` zero writes, per-item transaction boundaries, update missing `record_key`, non-writable rejection. |
| MCP parity by key | `tests/Feature/Api/Admin/AdminApiMcpParityTest.php` + `tests/Feature/Mcp/AdminServerTest.php` | For every writable key, HTTP schema == `admin-get-write-schema` and HTTP payload == MCP list/detail; exact tool set (equality, not `toContain`) and no duplicate tool names. |
| Adapter placement | `tests/Feature/AdminResourcesCoverageTest.php` or architecture test | Every registered definition resolves to an owner adapter whose file is under `Features/<Owner>/EntryPoints/Api/{Admin,Member}/`; no `Platform` adapter for a feature key. |
| Docs/keys coupling | `tests/Unit/McpGuideDocsTest.php` | Rewrite `liveWritable*ResourceKeys()` to use `ResourceDefinitionRegistry`; keep the docs equality assertion. This test **will fail** when B1 deletes the mutation services unless rewritten first. |

### 4.3 `06-B2` — FrontendCatalogService (P0/P1)

| Test | Target file | Assertion |
|---|---|---|
| 18-method catalog golden | `tests/Feature/Api/Frontend/CatalogApiTest.php` (extend; currently 12 tests / 11 methods) | One test per method: status, `data` envelope, key order, ID/label/slug fields, null/empty semantics, `limit` default 50, `q` trim, missing-parent empty array; auth-required methods 401; role-scoped methods. Include countries, languages, address-areas, submit-persons, reference-authors, spaces, advanced-builder context (via `FormContractApiTest`), and institution-role options. |
| Cascade parity | `tests/Feature/Api/Frontend/CatalogLocationCascadeTest.php` | Keep exact provider-driven/Singapore/Malaysia assertions; run after dispatcher cutover. |
| Binding cutover | `tests/Feature/FeatureOwnershipSeamsTest.php` | Replace `FrontendCatalogService` assertions with owner catalog API bindings (`AddressCatalog`, `LanguageOptions`, `TaxonomyCatalog`, `ReferenceCatalog`, `InstitutionCatalog`, `PersonCatalog`, `PlaceCatalog`, `MembershipSubjectCatalog`, `PrayerInstitutionCatalog`, `AdvancedBuilderContext`); assert the old class is absent/unbound. |
| Form contract context | `tests/Feature/Api/Frontend/FormContractApiTest.php` | Advanced event field contract exact keys/options after `advancedBuilderContext` moves. |

### 4.4 `06-B3` — event discovery (P0/P1)

| Test | Target file | Assertion |
|---|---|---|
| DB ↔ Typesense result parity | `tests/Feature/EventSearchTest.php` (extend the existing adapter dataset at 2712–2744) | For a matrix of filters/search/sort/pagination: same IDs, same order, same total, same page count from `PostgresEventDiscovery` and a real/faked Typesense engine. Do not run both against `scout.driver=database`; that only tests one engine. |
| Typesense fallback | `tests/Feature/EventSearchTest.php` | Simulate Typesense failure/timeout and assert DB fallback returns the same result set and does not leak the exception. |
| Reader API golden | `tests/Feature/EventSearchTest.php` or **new** `tests/Feature/Discovery/EventDiscoveryReaderSeamTest.php` | `Features/Events/Api/EventDiscoveryReader` returns immutable summaries/scalars (no `Event` model, no `Builder`, no `LengthAwarePaginator<int, Event>`); assert exact public payload keys. Add a PHPDoc-only FQCN leak fixture in the architecture test. |
| Query budget | `tests/Feature/EventListBatchLoadingTest.php` (extend) | Full search/list/detail path stays under a documented query ceiling; pagination does not add per-item queries; relation provider eager-loads remain. |
| Cross-domain reads | `tests/Feature/EventLocationFilterParityTest.php`, `PrayerTimeFilterTarawihTest.php`, `Discovery/*CharacterizationTest.php` | After `PostgresEventDiscovery` moves to `Events/Queries`, update imports and keep timezone/prayer/visibility/location parity; assert no `Discovery` imports of Events private queries remain (architecture test). |

### 4.5 `06-B4` — contributions (P0/P1)

| Test | Target file | Assertion |
|---|---|---|
| Projection golden | `tests/Feature/Contributions/ContributionBoundarySeamsTest.php` (extend; currently 6 tests) | For event, institution, person, reference: exact `contractFor`/`stateFor`/`updateRulesFor` arrays — keys, field order, types, defaults, allowed values, conditional rules, media fields, labels. Snapshot once **before** extraction and compare after. |
| Per-owner create/apply parity | `tests/Feature/ContributionWorkflowServiceTest.php` | `createInstitution`/`createPerson`/`createReference`/`apply` via each owner API produce the same proposer, duplicate check, lifecycle status, typed normalization, audit, verification and after-commit notification as today. |
| Transaction/media/order | `tests/Feature/ContributionApprovalDuplicateRecheckTest.php` + `ContributionPagesTest.php` | Rollback leaves no partial entity/media; staged media copied in the same order; approval lock order unchanged; rejected request preserves originals; notification after commit. |
| Cross-surface parity | `tests/Feature/Api/Admin/AdminApiMcpParityTest.php` + `tests/Feature/Api/Member/MemberApiMcpParityTest.php` | Contribution review schema/workflow JSON equals between HTTP and MCP for the same fixture; member cannot review a foreign institution/person/reference. |
| Old class removal | `tests/Feature/Contributions/ContributionBoundarySeamsTest.php` | Assert `ContributionEntityStateService` is gone/unbound and the per-owner `ContributionProjection` implementations are bound; no `Platform` wrapper remains. |

### 4.6 Cross-cutting (P0/P1)

| Test | Target file | Assertion |
|---|---|---|
| Route inventory | **new** `tests/Feature/RouteInventoryParityTest.php` (no existing equivalent; must live under `tests/Feature` so CI runs it) | Checked-in normalized baseline (method, domain, URI, name, middleware, `where` constraints, duplicate URI+method) compared to `Route::getRoutes()`; normalize only handler FQCNs. Include the named admin/member/MCP/public route families. |
| Schedule inventory | `tests/Feature/ScheduleIntegrityTest.php` (extend) | All 15 named `routes/console.php` schedules plus the feature provider schedules (`notification-reminders`, `escalate-pending-events`) and package `communications-dispatch-due`: registered exactly once, exact expression/timezone/`withoutOverlapping`/command signature. Add the 7 missing: `committed-share-signals`, `reward-gratitude-digests`, `referrals-expire-invites`, `reward-source-corrections`, `reward-point-lot-expiry`, `prune-share-cards`, `prune-guest-share-profiles`. |
| Architecture ledger policy | `tests/Unit/Platform/ArchitectureBoundaryTest.php` (final-state test, enabled at R6) | Real `tools/architecture.json` has zero `feature-private`/`platform-direction` exceptions targeting `\Models\`, zero Platform god-class waivers, zero auto-generated private-import waivers, expires/stale path checks, and an explicit allowlist of package writes/root stores. |
| CI trigger freshness | `.github/workflows/verify-*.yml` (workflow change; needs approval, not a test) | Replace stale paths with current ones or delete the workflows; add `pull_request`/`workflow_dispatch` if they are meant to gate. |

---

## 5. Tests that must be rewritten first because they reference classes B1–B4 delete

These are not “optional cleanups”; they will fail or become meaningless during the split. Update them in the same slice as the move.

| Class | Tests |
|---|---|
`AdminResourceRegistry` / `AdminResourceMutationService` | `tests/Feature/AppFeedback/AppFeedbackAdminTest.php`, `tests/Feature/Community/CommunityReleaseTest.php`, `tests/Feature/GeographyFormCountryCodeTest.php`, `tests/Feature/Rewards/Host/RewardSurfaceCharacterizationTest.php`, `tests/Unit/McpGuideDocsTest.php` |
`MemberResourceRegistry` / `MemberResourceMutationService` | `tests/Feature/ManagedWorkspacesTest.php`, `tests/Feature/OrganizationFrontendTest.php`, `tests/Unit/McpGuideDocsTest.php` |
`FrontendCatalogService` | `tests/Feature/FeatureOwnershipSeamsTest.php`, `tests/Feature/ReferenceFamilyTest.php` |
`ContributionEntityStateService` | `tests/Feature/ContributionAddressPersistenceTest.php`, `ContributionPagesTest.php`, `ContributionWorkflowServiceTest.php`, `InstitutionContributionLocationPickerTest.php`, `InstitutionSlugGenerationTest.php`, `PersonSlugGenerationTest.php`, `SlugRedirectFeatureTest.php` |
`PostgresEventDiscovery` / `TypesenseEventDiscovery` | `tests/Feature/ContributionEventPrayerTest.php`, `EventLocationFilterParityTest.php`, `EventSearchRelationProviderIntegrationTest.php`, `EventSearchTest.php`, `PrayerTimeFilterTarawihTest.php`, `EventSearchTypesenseFilterTest.php` |

`tests/Unit/Platform/FeatureCatalogTest.php` and `OwnedWriteRuleTest.php` do **not** need renaming for B1–B4; only the additions in §4.1.

---

## 6. Prioritized verification order

1. **Freeze the baseline (now, before more code moves).** Capture into `tasks/feature-refactor-evidence/`: `route:list --json`, `schedule:list`, admin/member manifest JSON, per-key schemas, 18 catalog method outputs, contribution projections, event search result sets (DB + real Typesense if available). Do not rely on the implementing agent’s reported 738/170 counts.
2. **R1 test rewrite (P0).** Rewrite `ArchitectureBoundaryTest` per §4.1; add the non-model private import, Platform model/private, and Api leak fixtures; add the type-only write positive in `OwnedWriteRuleTest`. Run only those files first. Do not commit until `php tools/architecture-check.php --json` exits 0 with no model-target exceptions and the allowed fixtures produce zero findings.
3. **R1 checker completion.** Add `foreign-model-reference` diagnostics, domain-operation detection for instance/dynamic calls, PHPDoc parsing for public API leaks, and narrow `$public` to `app/Features/*/Api/`. Re-run R1 tests + `composer arch:check`.
4. **B1 registry + manifest golden before extraction (P0).** Add the registry unit test and full admin/member manifest + per-key CRUD/read/mixed-batch goldens. Run `AdminApiTest`, `AdminApiMcpParityTest`, `AdminServerTest`, `MemberServerTest`, `McpGuideDocsTest`. These are the tests that make the descriptor extraction provable.
5. **B1 extraction per owner subset (R2).** For each owner/resource subset: extract writer/reader, keep the golden tests green, delete the corresponding Platform god-class branch, then re-run. Stop if any key disappears, order changes, or a tenant/record authorization case regresses.
6. **B2 catalog golden then cutover (R3).** Add the 18-method golden, rewrite `FeatureOwnershipSeamsTest`, then replace `FrontendCatalogService` with owner catalog APIs; run `CatalogApiTest`, `CatalogLocationCascadeTest`, `FrontendApiParityTest`, `FormContractApiTest`.
7. **B4 projection golden then cutover (R3).** Add projection golden + per-owner create/apply/transaction tests, then delete `ContributionEntityStateService`; run the contribution suite and both API/MCP parity tests.
8. **B3 parity + query budget then move (R4).** Add DB↔Typesense parity/fallback, reader API golden, and query ceiling; move `PostgresEventDiscovery`/`TypesenseEventDiscovery` to Events; update the 6 coupled test files; run event search/discovery/public schedule suites.
9. **R6 gates and CI (P0).** Add route inventory + complete schedule inventory; fix/delete stale `verify-*` workflows; add a JS/CSS-triggered build/browser path; add an architecture exception-budget test; then run the full `04` §5 gate and capture exit codes.

**Stop conditions:** any golden test that changes only because an internal class moved is a bug in the test (normalize FQCNs only for routes); any golden output that changes for a resource key, catalog method, search filter, or contribution projection is a contract change and must be reviewed as such.

---

## 7. Residual risks the tests cannot fully close

- Dynamic Eloquent access (`app(Order::class)`, `resolve()`, reflection, raw `DB::table('orders')`) is only partially detectable by the AST checker; `06-A3` accepts manual review, but the final ledger must list the manual review evidence.
- A real Typesense driver is not available in the default test environment; parity tests need either a configured Typesense service or a deterministic fake that exercises the same filter/order contract. SQLite-only runs do not prove Postgres/Typesense behavior.
- Browser coverage is a single home-page smoke; resource admin/member UI flows have no browser regression coverage.
- `fix-code-style.yml` can push formatting commits to `refactor` while a refactor slice is in flight; run it intentionally, not as a background gate.
