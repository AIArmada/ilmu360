# Agent-friendly architecture conversion

## Resume here

- Scope: the **whole ilmu360° application**, converted in verified, committable parts.
- Authorization: user invoked `laravel-agent-convert`, requested commits per part, and a checklist another agent can continue.
- Branch: `refactor`. Initial working tree was clean on 2026-11-07.
- Current part: **4b — provider-backed Prayer engine** (committed as `741a9755`); Part 4a is committed as `6216b088`.
- Next action: **Part 5 — Saved Searches**. Refresh the graph, trace the account lifecycle/reward/notification consumers listed below, capture fresh registrations and convert a complete owning slice. Prayer provider conversion is complete; event/location adapters remain for their own later migration.
- Before continuing, read `AGENTS.md`, `.ai/rules/index.md`, `tasks/lessons.md`, and `/Users/Saiffil/.codex/skills/laravel-agent-convert/SKILL.md` with its architecture/migration/tooling references.
- Update this checklist and `tasks/todo.md` in each part. Record commit subject, checks, limitations, and the next concrete action. A completed part does not mean full-app adoption.

## Baseline

- PHP `^8.5`; Laravel locked `v13.40.0`; Livewire `v4.4.8`; Filament `v5.11.1`.
- Preserve normal PHPStan level 6, Larastan, Pest extension, existing baseline and test flow. Never baseline architecture violations.
- Locked tooling already available: PHP-Parser `v5.9.0`, PHPStan `2.3.5`, Larastan `v3.14.0`; no new dependency needed to run the foundation.
- Initial inventory: 52 model files, 69 controllers, 13 jobs, 34 listeners, 30 policies, 27 console files, 44 migrations, 498 test files, no feature manifests.
- Existing graph is indexed but materially stale: nested action paths changed, graph-only ShareGuest classes disappeared, AppFeedback/SignupAttribution are missing. Refresh before domain implementation; verify current files/configuration strings before moves.
- Refreshed index for Part 2 uses project ID `Users-Saiffil-Herd-ilmu360`; the older `ilmu360` graph is stale. Index excluded generated/tool folders and skipped one generated PHPStan cache file.
- Part 1 corrects CI from PHP 8.4 to PHP 8.5 and wires both architecture checks into the required PHPStan job.
- Do not modify live data, deploy, or edit sibling `commerce` package source. Use isolated test settings. Persisted class strings/morph identities require a deliberate data-preservation strategy before model moves.

## Acceptance checklist for each feature

- [ ] Trace callers, routes, Eloquent/query/raw SQL writes, model hooks, UI persistence, jobs, external stores and package writers.
- [ ] One feature owns each resource in `feature.json`; list the actual named Actions/Persistence classes containing mutations.
- [ ] Move the entire slice and all current callers; remove superseded implementations, aliases and delegating wrappers.
- [ ] Expose public `Api` interfaces/immutable values for real cross-feature boundaries; never export mutable models/builders.
- [ ] Preserve URLs/names, middleware order, payloads/status, actor/tenant rules, transactions and queue/retry/after-commit semantics.
- [ ] Move routes and remove old registration atomically; use native providers and no example status endpoint.
- [ ] Verify policy/factory/binding/observer/listener/command/schedule/Livewire/Filament registrations and duplicate invocation counts.
- [ ] Preserve existing product signals; evaluate tracking if UI behavior changes.
- [ ] Compare uncached route snapshots in the same environment; verify relevant config/route cache behavior.
- [ ] Run affected parallel Pest tests once per meaningful revision; save expensive output. Run Pint and normal level-6 PHPStan.
- [ ] Pass both architecture checks with no blanket exceptions or new baseline; review the diff and commit the part.

## Parts

### 1. Foundation and enforcement

- [x] Inspect instructions, versions, clean tree and existing verification flow; read all conversion references.
- [x] Preview bundled foundation with native provider registration.
- [x] Apply foundation; integrate Composer commands, CI gates and regression checks.
- [x] Capture registrations/routes and verify checks; record evidence below.
- [x] Checkpoint commit: `feat(architecture): add feature ownership foundation and migration checklist`.

### 2. GitHub issue reporting

- [x] Move owning action/reporter/request/controller/data/exception and reporter boundary together.
- [x] Migrate feedback escalation/admin view, frontend form catalog and two MCP tool consumers to its public boundary.
- [x] Move provider binding and `/api/v1/github-issues` route; preserve auth/client middleware and `throttle:github-issues` order.
- [x] Verify HTTP success/failure, null reporter, API/feedback/MCP/catalog regressions and caches; checkpoint subject: `refactor(github): migrate issue reporting into its owning feature`.

Current owner: `app/Features/GitHubIssues/` (`Api`, `Actions`, `Persistence`, `Services`, `EntryPoints/Http`, `Routes`, native provider and manifest). No owned DB table; external `store:github-issues` belongs to `Persistence/GitHubIssueReporter`. Old classes and AppServiceProvider binding are removed. Tests: `GitHubIssueReportApiTest`, `AppFeedbackGitHubTest`, `LifecycleHardCutTest`, `AdminServerTest`, `MemberServerTest`.

### 3. Baseline MCP corrections, then Prayer

- [x] Reproduce and diagnose `AdminServerTest` viewer-role read failure (`tests/Feature/Mcp/AdminServerTest.php:123`): tool returns `Unexpected server error.` Read recent server logs to find the original exception.
- [x] Diagnose prayer metadata contract failure (`tests/Feature/Mcp/AdminServerTest.php:1026`): expected `prayer_offset = 'immediately'`, actual `5`. Reconcile the current canonical event timing contract and all relevant consumers; do not merely change an expectation to silence the failure.
- [x] Resolve both baseline failures: safe appeal-policy discovery in `9296fed0`, canonical prayer payload assertion in `24072eae`; all 87 admin MCP tests pass. These failures predated Part 2.
- [x] Convert Prayer in `741a9755` with four declared persistence owners and unchanged Events resolver identity. Graph refreshed after conversion (`Users-Saiffil-Herd-ilmu360`); ownership/caller/config audit and 584 passing checks recorded in Part 4b. Graph call traces remain sparse, so verified source references supplement them.

### Remaining application parts

Ordering remains provisional until each ownership trace is complete. Split large parts into coherent commits and keep every remaining item visible.

- [x] Provider-backed Prayer: providers, preview, cache/store writes, jobs and commands converted in Part 4b. Event-specific expression/location adapters remain Events-owned; their stored resolver identity is preserved for later Events migration.
- [ ] Saved searches: page/API/model/policy/actions/filter normalization; immutable reads, account cleanup/restoration and reward source facts.
- [ ] Feedback/reports: conversations, report lifecycle, review and notification adapters.
- [ ] Inspirations: app-owned table, Save action, Filament persistence and frontend/API readers.
- [ ] Identity/accounts: User/social accounts, auth/OAuth/Fortify, account lifecycle/restoration and public identity contracts.
- [ ] Platform/shared seams: media, geography/addressing, timezone, generic API responses, search and auditing integration; no concrete feature imports in Platform/Shared.
- [ ] Directory: institutions/names/import exclusions, persons/names/titles/affiliations, references/contributors, venues/spaces, institution bridges, donation channels and languages.
- [ ] Organizations: current-owner context, workspace, readiness/access contracts; package remains canonical persistence owner.
- [ ] Membership: applications/invitations and institution/person/reference/event membership pivots, roles and atomic ownership transfer.
- [ ] Events authoring/submission/scheduling: events, occurrences/sessions, series/items, classifications, involvements, references, lifecycle and slugs.
- [ ] Event registration/commerce/admission: payments/refunds, receipts/passes, participants, check-in and package adapters.
- [ ] Contributions: staged requests/media, approval/review orchestration through Directory/Events APIs, not foreign writes.
- [ ] Discovery/search/engagement: public listings, catalogs, follow/save/going and frontend query contracts.
- [ ] Share tracking/attribution: browser selections, source receipts, effect receipts, outcome corrections, cohort projections, signal cursors/skips, package guest identity and analytics.
- [ ] Rewards/digests: enrollment/source facts/awards/payees/payouts/reconciliations, `reward_digest_slots` and gratitude scheduling.
- [ ] Communications: preferences/channels/reminders/digest matching and after-commit delivery.
- [ ] AI: model pricing, usage ledger, budget policies, media extraction and tools.
- [ ] Community releases: publishing lifecycle and eligibility; distinguish from shared projection/analytics stores.
- [ ] Documentation/MCP/admin composition: schemas/docs/resource registry and remaining adapters, each using the actual owner's public API.
- [ ] Final app-wide audit: every runtime resource has an owner and named write paths; old code removed, unique registration, CI active, behavior/type/cache tests pass.

## Ownership decisions and hazards

- Directory is a defensible combined capability for tightly related institution/person/reference/venue records. Series belongs with Events (`event_series` and `event_series_items`). `ModerationReview` wraps package `ModerationAction`; do not invent a `moderation_reviews` table.
- `ContributionEntityMutationService` (~2,100 lines) writes nearly all directory/event resources, names, affiliations, addresses and contacts. `SaveInstitutionAction`/`SavePersonAction`/`SaveReferenceAction`/`SaveVenueAction` call it. Extract actual owned mutations; do not move the generic writer into Contributions and grant it foreign resources.
- Contribution approval directly creates/verifies entities, assigns ownership, applies media and updates request state in a transaction. Keep atomic behavior and post-transaction notifications while delegating owned mutations.
- `User` exposes mutable reverse relations across most capabilities and invokes attribution/submission-lock logic. Migrate consumers and account lifecycle workflows before moving related models; ordinary auth scaffolding can remain conventional where appropriate.
- `Event` hooks save URL/audience projections and delete membership/engagement/registration/submission/moderation/announcement/media data before package cascade cleanup. Move these side effects into explicit lifecycle orchestration without losing behavior.
- `AppMembershipHook` and `User::updated()` call `PublicSubmissionLockService`, which writes directory flags/cache. Directory owns these writes; Membership/Identity call a public eligibility-sync operation.
- `TransferPersonOwnershipAction` locks records, updates two pivots and scoped roles, then invokes hooks. Preserve one-owner invariants and atomic role synchronization.
- `config/events.php`, `config/persons.php`, `AppServiceProvider` register models, policies, observers, slugs and morph identities. Stored fully qualified class strings exist; import replacement alone is insufficient.
- SavedSearch inherits configurable `getTable()`; literal `$table` alone does not override it. Writes occur in three existing actions, API destroy, Livewire delete and `HasUserRestoration`. API delete reverses share outcomes while Livewire delete currently does not: preserve the distinction during conversion.
- Saved-search readers include dashboard/admin user views, notification matching and reward fact readers; replace mutable `User::savedSearches()` exposure with read values and account lifecycle operations. Execute depends on EventSearchService and currently returns event models; Events needs a real query boundary when migrated.
- `AffiliatesShareTrackingService` (~2,900 lines) contains many package writes and guest/receipt orchestration. Extract named use cases; a renamed facade is insufficient. Current guest state lives in the affiliates package, despite stale graph-only app classes.

## Completed parts and evidence

### Part 1 — foundation and enforcement (2026-11-08)

Checkpoint subject: `feat(architecture): add feature ownership foundation and migration checklist`.

Changes: native `FeatureModule` loader and `FeatureCatalog`; AST boundary checker; PHPStan owned-write rule; Composer `architecture:boundaries`, `architecture:types`, `architecture:test`; PHP 8.5 CI and filters for manifests/tooling. `tools/architecture.neon` **includes the existing root `phpstan.neon`**, preserving level 6, application stubs, Larastan, Pest extension, paths and baseline; it adds the ownership rule without suppressing unrelated analysis. Root analysis also covers `tools/`.

Verification on the final code:

- `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync composer architecture:test` — pass: zero structural violations; normal level-6 analysis plus ownership rule, zero errors.
- `vendor/bin/pest --parallel --processes=4 --compact --filter='FeatureCatalogTest|ArchitectureBoundaryTest|OwnedWriteRuleTest|GitHubIssueReportApiTest'` — **18 passed, 97 assertions**. Real temporary fixtures cover duplicate ownership, multiple allowed actions, missing/foreign writers, private dependency forms, public API/provider wiring, API leaks, stale exceptions, Eloquent/query/cache writes and harmless collections.
- `vendor/bin/rector process --dry-run --debug app/Platform tools tests/Unit/Platform tests/Fixtures/Architecture` — zero changes/errors. Scoped proposed transformations were reviewed/applied before this final run.
- `vendor/bin/pint --dirty --format agent`, `composer validate --no-check-publish`, workflow YAML parsing and `git diff --check` — pass (Composer retains its pre-existing Octane exact-version advisory).
- Before/after uncached route snapshots: **749 routes, identical** method/domain/URI/name/middleware/order/multiplicity. Route/config caching succeeded using isolated `/tmp` cache paths; cached GitHub route retained POST, `api.client.github-issues.store`, `api`, `auth:sanctum`, and `throttle:github-issues`. Temporary caches were cleared.
- Captured command and event inventories before the change. No domain registration or application behavior changed in Part 1.

Temporary logs/snapshots are `/tmp/ilmu360-architecture-*`; they are supplementary and may expire. Regenerate fresh snapshots at the start of each part. Test runner/analyzer worker ports require normal local process access; where restricted, PHPStan/Rector `--debug` disables their worker socket. The fixture PHPStan subprocess sets `PAO_DISABLE=1` to get stable native JSON output rather than agent-dependent formatting.

Limits: no domain feature has been converted yet (production manifests count is zero); foundation tests use isolated sample features. Unmigrated resources are intentionally outside feature ownership enforcement until claimed. The whole-app suite, remote GitHub CI and deployment were not run. Keep the full capability checklist open.

Next concrete work: Part 2 GitHub issue reporting; use its caller list and tests above. No new dependency or live-data change was introduced in Part 1.

### Part 2 — GitHub issue reporting

Checkpoint subject: `refactor(github): migrate issue reporting into its owning feature`.

Changes and boundary decisions:

- Native feature provider owns its binding and route; one external issue store, one actual HTTP mutation implementation. Removed the old action, services, contracts, data, request/controller and schema helper paths with no aliases/wrappers.
- Public `Api/SubmitGitHubIssueReport` and reporter interface are used by MCP, feedback escalation, admin availability and frontend catalog consumers. MCP transport classes remain composition adapters and reference only the feature's public API.
- Submission/catalog values are final readonly and contain no Laravel runtime objects. Validation uses the equivalent string `in:` rule instead of exporting a framework Rule object through the catalog.
- `Shared/Auth/IntegrationFeedbackUser` and its readonly identity value form the real authenticated-user boundary. `User` implements that contract; GitHub code receives IDs/display/role facts without importing the mutable app model.
- `Shared/Integrations/GitHubIssueReportingException` is the failure contract shared by HTTP/MCP/reporting consumers; keeping it outside feature internals preserves existing status/code/details handling. It has no feature dependency.
- Preserved request authorization, payload normalization, issue body identity/context, Copilot model fallback, null reporter behavior, request IDs and existing product tracking. No UI workflow change, package source edit, dependency addition or real GitHub issue creation.

Evidence:

- Structural architecture check: **zero violations, one feature**. Integrated level-6 PHPStan plus owned-write rule: **zero errors**.
- Before/after uncached route snapshots: **749 routes, identical**. Event registration inventory also identical.
- API/feedback/lifecycle/admin/member MCP run: **152 tests, 150 passed, 2 failed, 2,283 assertions**. All changed GitHub flows passed. The two failures above were independently reproduced with Part 2 stashed: baseline admin MCP file **87 tests, 85 passed, the same 2 failures**. The pre-Part-2 branch includes Part 1 `9a9eadee` and concurrent merge `3a87682a`; their tree difference is seven view files (checkout/pass/refund/home navigation), with no PHP event/MCP logic changes. These concurrent changes were preserved. The stash was restored successfully and no stash remains from this check.
- Cached route + config API run: **8 passed, 56 assertions**, including guest 401, invalid category 422, plain/admin submissions, Copilot fallbacks and GitHub rejection status/details. Used isolated `/tmp/ilmu360-architecture-part2-*` caches and cleared them afterward.
- Scoped Rector final dry run: zero changes/errors; Pint and `git diff --check` pass. Obsolete class references audited in source/config/routes/bootstrap/tests (including escaped class strings): none.

Logs: `/tmp/ilmu360-architecture-part2-{pest,mcp-baseline,phpstan,cached-api-pest,final-rector}.log`; these may expire, so the results and failure identities are recorded here. Do not claim the whole application or full suite is converted/passing. Continue with Part 3 above.

### Part 3a — safe appeal policy discovery

Checkpoint subject: `fix(auth): deny appeal creation without award context`.

Root cause: AdminResourceRegistry asks Gate for the class-level create capability while listing resources. RewardAppealPolicy required an award argument, causing ArgumentCountError and breaking the entire viewer catalog. The policy now accepts absent context and denies it; actual award-recipient authorization remains unchanged. Added a Gate-level regression and retained the existing reward policy tests.

Validation: parallel Pest filter `RewardAppealPolicyTest|RewardPolicyTest|AdminServerTest`: **93 tests, 92 passed, 1 existing prayer serialization failure**, 1,589 assertions. Viewer catalog and all reward policy checks now pass. Integrated PHPStan level 6 plus owned-write analysis passes; Pint passes. Logs: `/tmp/ilmu360-part3a-{pest,phpstan}.log`. No routes or UI workflow changed.

Part 3b evidence: PrayerOffset::Immediately->minutes() intentionally returns **5** for prayer completion. The Event accessor maps stored signed minutes back to the string enum value, while generic admin attributes use `$record->toArray()`. Trace this serialization/projection path before editing; do not change the offset duration or simply weaken the existing MCP assertion.

### Part 3b — canonical MCP prayer payload assertion

Checkpoint subject: `test(mcp): align prayer offset with canonical event payload`.

The runtime serializer is correct. AdminResourceRegistry::serializeRecord and serializeRecordDetail use EventPayloadData, whose signedPrayerOffset returns signed integer minutes. Existing PublicSubmissionOccurrenceGroupingTest assertions already require 5 and -15. PrayerOffset::Immediately maps to five minutes intentionally. The MCP assertion still expected the input/form enum string; it now expects the canonical numeric output. No production code, durations, routes, or payloads changed. Ordinary events do not expose the grouped-submission display_occurrence section; no assertion for that section was retained.

Final admin MCP suite: **87 passed, 1,561 assertions**, including both originally recorded failures. Integrated level-6 PHPStan/owned-write analysis and structural checks pass; Pint and diff whitespace checks pass. Logs: `/tmp/ilmu360-part3b-admin-pest.log`, `/tmp/ilmu360-part3b-phpstan.log`.

The broader run also exercised PublicSubmissionOccurrenceGroupingTest and found an unrelated fixture issue in `filters public occurrence speakers, references and prayer slots while excluding draft occurrence data`: missing role `moderator` for guard `web`, at its reference-page response assertion around line 644. Production behavior was untouched in this checkpoint. Investigate role fixture setup before proceeding; do not claim the whole suite passes. Broader diagnostic output is `/tmp/ilmu360-part3b-pest.log` (large HTML failure body).

### Part 3c — self-contained occurrence regression

Checkpoint subject: `test(events): complete occurrence visibility fixtures`. The reference-page scenario seeded event participation roles but not application authorization roles. It now explicitly seeds canonical RoleSeeder within that scenario. Once the page rendered, its old parent-title assertion was also exposed: reference listings use PublicScheduleLeaf::title(), which prefers the occurrence title. The test now requires Public Prayer Slot Submission and rejects Draft Prayer Slot Submission. Production code and assertions protecting draft filtering remain unchanged.

Final occurrence file: **15 passed, 177 assertions** (`/tmp/ilmu360-part3c-occurrence-pest.log`). Prior combined run: **102/103 passed**, with all admin MCP and appeal policy checks passing; its sole failure was the parent-title assertion corrected afterward. Integrated level-6 PHPStan plus owned-write rule, structural check, Pint and whitespace checks pass. Static log: `/tmp/ilmu360-part3c-phpstan.log`. The recorded baseline blockers are resolved for these affected tests; full-app adoption remains open.

### Part 4 implementation map — provider-backed Prayer

Own provider-backed prayer resolution, not event/location persistence. No implementation moved yet.

1. Capture routes, command signatures, schedules and relevant tests. Move the existing three `app/Actions/Prayer` actions, provider/zone services under `app/Services/Prayer`, provider contracts under `app/Contracts`, readonly `app/Data/Prayer` values, and parsing helper `app/Support/Prayer/PrayerClock.php` into `Features/Prayer`.
2. Public API: preview/start-clock/anchor interfaces implemented by those actions; existing readonly PrayerQuery/PrayerTimesDTO values; provider and monthly/zone integration contracts. Add a narrow registry interface for `zoneResolverFor`, `methodsFor`, `timezoneFor`, and a Ramadan calendar interface. External callers must not import private concrete registries/providers. Expose HardcodedPrayerFallback as a canonical final readonly public estimates value with unchanged constants/map.
3. Move genuinely shared scheduling values EventPrayerTime, PrayerReference and PrayerOffset to `Shared/Prayer`, updating every current import; ordinary App\Enums dependencies fail feature boundaries. Public provider failure ProviderUnavailable belongs in Shared as an integration failure contract. Remove superseded paths, not aliases.
4. Four actual store writers: PrayerTimesCache -> Persistence (monthly/daily/fresh/stale/negative/locks); PrayerCircuitBreaker -> Persistence (failure/deadline/locks); extract registry `countMissingMapping` to Persistence/PrayerMappingMetrics (`prayer:v3:metric:missing-mapping:*`); extract JakimZoneResolver `rememberZone`, `forgetMemo` and invalidation to Persistence/PrayerZoneMemo (`prayer:v3:gps-memo:%.7F:%.7F`). Manifest resources: store:prayer-times, store:prayer-breaker, store:prayer-zone-memo, store:prayer-mapping-metrics. Preserve key formats, configured prefix/store, locks, stale degradation and error handling.
5. Move config/prayer.php to feature configuration and merge under unchanged `prayer` key; private class strings cannot stay in root config. Keep immutable config/prayer_zones.php key. Preserve provider mappings and native provider registration.
6. Adapt external consumers: Services/PrayerTimeExpressionResolver; Actions/Events/Submission/SubmitFrontendEventAction; Support/Events/{AdminEventScheduleResolver,EventContributionUpdateStateMapper,AdminEventTimeMapper}; Support/Submission/SubmissionTimingPolicy; Forms/EventContributionFormSchema; Livewire/Pages/Dashboard/Events/CreateAdvanced; Actions/Events/Authoring/PrepareAdvancedParentProgramSubmissionAction. Also audit all fallback constant consumers and scheduling writers.
7. **Keep Services/PrayerTimeExpressionResolver with Events and its current canonical class identity during this slice.** It queries Event/EventSession/EventLocation/Address; it is not provider-core. Use Prayer APIs internally. Its persisted resolver_class writers are PersistOccurrenceSubmissionAction, PersistSessionSubmissionAction, SyncEventScheduleAction and AdvancedEventSeeder. Later Events conversion must deliberately migrate that identity. PrayerLocation and PrayerTargetSelector likewise remain Event/Locations adapters; do not pull mutable Institution/Venue/Person models into Prayer.
8. Move entrypoints: PrefetchPrayerTimes, PrayerStatsCommand, RefreshPrayerTimes, ResolveGpsZone, preview controller/request. Register commands once in feature provider. Preserve job retry/backoff and after-response work; ResolveGpsZone deliberately self-redispatches instead of release().
9. Move GET `/api/v1/catalogs/prayer-preview`, route name `api.client.catalogs.prayer-preview`, middleware `api` then `throttle:60,1`; remove old registration. Institution prayer catalog stays with Institutions. Preserve prefetch daily 03:30 Asia/Kuala_Lumpur and stats hourly UTC schedules, names and overlap semantics.
10. Verify all Unit/Prayer tests, preview/stats/expression tests, submit zero-HTTP and preview behaviors, admin/contribution prayer tests, cache modes, snapshots, both architecture gates and normal level-6 analysis. No sibling package or live data changes. Commit only a coherent verified slice; update remaining scope if splitting core and adapters across checkpoints.

### Part 4a — shared scheduling values

Checkpoint subject: `refactor(prayer): move shared scheduling enums to canonical boundary`. Moved EventPrayerTime, PrayerReference and PrayerOffset from App\Enums to App\Shared\Prayer, updating 107 caller files across application code, factories/seeders, tests (including Pest.php) and three Blade views. The three enum bodies are byte-for-byte identical after normalizing the namespace. No cases, backing values, labels, offsets, payload shape or timing behavior changed. No old classes or aliases remain; source audit found no old direct/escaped references or grouped imports needing repair.

Persistence preflight: event data uses backing strings/signed minutes, not enum FQCNs; PrayerTimeExpressionResolver identity was untouched. No data migration or live-data changes. Compiled views were cleared so freshly loaded forms/views use the canonical classes. At eventual deployment, normal fresh-page/worker reload requirements still apply to enum-bearing Livewire state; no compatibility aliases were introduced.

Verification: **577 selected parallel Pest checks passed, 3,536 assertions**, excluding real-driver tests. Filter: `Prayer|AdminEventTimeMapperTest|AdminServerTest|PublicSubmissionOccurrenceGroupingTest|EventApiContractTest|HomePageTest|SubmissionTimingPolicyTest|ResolvedStartClockTest|HardcodedPrayerFallbackTest`. Integrated level-6 PHPStan/owned-write analysis passes with no baseline change; structural boundary check passes; uncached routes are identical. Pint and whitespace checks pass. Logs/snapshots: `/tmp/ilmu360-part4a-{pest,phpstan}.log` and `/tmp/ilmu360-part4a-{before,after}-routes.json`.

Only the shared-values prerequisite is complete: Prayer providers/stores/entrypoints have not yet moved, so the manifest count remains one (GitHubIssues). Continue the core provider conversion rather than treating Prayer as adopted.

### Part 4b — provider-backed Prayer feature

Checkpoint subject: `refactor(prayer): consolidate provider engine and owned cache writes`.

Converted `app/Features/Prayer`: public provider/query/preview/start-clock/anchor contracts and readonly values; internal providers, registry, Ramadan rules and district matcher; native provider; HTTP preview; queue jobs; prefetch/stats commands; private configuration under unchanged `prayer` key. The root preview route and schedule registrations were removed in the same slice. Commands register once through the feature provider. Old Prayer action/service/contract/data/job/command/controller/request paths were removed without aliases or old delegating classes.

Storage ownership: `PrayerTimesCache`, `PrayerCircuitBreaker`, extracted `PrayerMappingMetrics` and `PrayerZoneMemo` each live in Persistence and are the actual mutation classes declared for their four stores. Mapping metrics and zone memo mutations no longer hide in registry/resolver code. Prefixes, key precision, TTLs, locks, stale/negative behavior, upstream fallback and swallowed store failures are preserved. Cache rows serialize plain arrays, not DTO class identities.

Outside callers now use public interfaces. The registry API exposes only zone resolver, calculation methods and country timezone; provider enumeration/cache currency operations stay internal. Ramadan API exposes only the calendar decision. Existing fallback estimates and clock parsing are public immutable support values. `PrayerAreaTypes` owns the district-type catalog used by the location adapter, replacing its import of the private matcher. ProviderUnavailable is a Shared integration failure contract.

Events-owned `Services/PrayerTimeExpressionResolver`, `Support/Prayer/PrayerLocation` and `PrayerTargetSelector` remain canonical adapters, with public Prayer dependencies. No persisted resolver_class identity changed. No package source, database schema, live records, dependencies or product tracking behavior changed.

Configuration uses Laravel mergeConfigFrom and skips loading defaults when cached. Larastan now recognizes the exact feature Config directory alongside root config via configDirectories; no ignore/baseline was added. Reviewed scoped Rector transformations, preserved provider exception code 0 explicitly, and adopted installed Laravel 13 Signature/Description command attributes with identical parsed definitions.

Verification on final code:

- **584 parallel Pest checks passed, 3,612 assertions**, excluding real-driver checks; Prayer providers/cache/breakers/GPS/refresh jobs, preview, submission timing and zero-inline-HTTP, persisted expression resolution, event payloads, admin MCP, public occurrence grouping and homepage rendering. Log `/tmp/ilmu360-part4b-final-pest.log`.
- Isolated cached config/route preview + stats run: **11 passed, 49 assertions**. Focused zero-inline-HTTP run after updating the old namespace trace assertion: **9 passed, 85 assertions**. Logs `/tmp/ilmu360-part4b-{cached,zero-http}-pest.log`. Temporary caches cleared.
- Integrated level-6 PHPStan plus owned-write rule: **zero errors**, no baseline changes. Structural gate: **zero findings, two features**. Scoped Rector over the whole Prayer feature: zero changes/errors; Pint and whitespace checks pass. Logs `/tmp/ilmu360-part4b-final-{phpstan,rector,pint}.log`.
- **749 routes unchanged**; complete prayer command definitions identical; all schedule definitions (expression/command/name/timezone/options) identical after ignoring next-due presentation and registration order. Original 03:30 Malaysia prefetch and hourly UTC alert preserved. `/tmp/ilmu360-part4b-{before,after}-*.json`.
- Direct and escaped obsolete source class references audited: none. Existing event resolver class references are intentional and unchanged.

Deployment is separate: queued prayer job class identities changed. Before eventual cutover, finish/drain existing prayer jobs using the old release and reload workers under the new release; do not add compatibility aliases or silently rewrite live queues. No deployment or queue mutation was performed here.

Next: Saved Searches (Part 5). Whole-app migration and final full-suite acceptance remain open. Refresh graph before discovery; the graph predates these moved Prayer paths.
