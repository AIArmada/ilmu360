# Agent-friendly architecture conversion

## Resume here

- Scope: the **whole ilmu360° application**, converted in verified, committable parts.
- Authorization: user invoked `laravel-agent-convert`, requested commits per part, and a checklist another agent can continue.
- Branch: `refactor`. Initial working tree was clean on 2026-11-07.
- Current part: **1 — foundation and enforcement** (verified; checkpoint commit subject below).
- Next action: start **2 — GitHub issue reporting**, refresh the graph, trace current callers, capture fresh uncached routes, then implement its complete boundary using the ownership notes below.
- Before continuing, read `AGENTS.md`, `.ai/rules/index.md`, `tasks/lessons.md`, and `/Users/Saiffil/.codex/skills/laravel-agent-convert/SKILL.md` with its architecture/migration/tooling references.
- Update this checklist and `tasks/todo.md` in each part. Record commit subject, checks, limitations, and the next concrete action. A completed part does not mean full-app adoption.

## Baseline

- PHP `^8.5`; Laravel locked `v13.40.0`; Livewire `v4.4.8`; Filament `v5.11.1`.
- Preserve normal PHPStan level 6, Larastan, Pest extension, existing baseline and test flow. Never baseline architecture violations.
- Locked tooling already available: PHP-Parser `v5.9.0`, PHPStan `2.3.5`, Larastan `v3.14.0`; no new dependency needed to run the foundation.
- Initial inventory: 52 model files, 69 controllers, 13 jobs, 34 listeners, 30 policies, 27 console files, 44 migrations, 498 test files, no feature manifests.
- Existing graph is indexed but materially stale: nested action paths changed, graph-only ShareGuest classes disappeared, AppFeedback/SignupAttribution are missing. Refresh before domain implementation; verify current files/configuration strings before moves.
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

- [ ] Move owning action/reporter/request/controller/data/exception and reporter boundary together.
- [ ] Migrate feedback escalation/admin view, frontend form catalog and two MCP tool consumers to its public boundary.
- [ ] Move provider binding and `/api/v1/github-issues` route; preserve auth/client middleware and `throttle:github-issues` order.
- [ ] Verify HTTP success/failure, null reporter, API/feedback/MCP/catalog regressions and caches; commit.

Existing files: `app/Actions/GitHub/SubmitGitHubIssueReportAction.php`, `app/Services/GitHub/GitHubIssueReporter.php`, `app/Contracts/GitHubIssueReporterContract.php`, `app/Data/GitHub/GitHubIssueSubmissionData.php`, `app/Support/GitHub/GitHubIssueReportContract.php`, `app/Exceptions/GitHubIssueReportingException.php`, frontend HTTP controller/request and admin/member MCP tools. No owned DB table. Binding currently in `AppServiceProvider`. Public API must avoid mutable `User` and Spatie Data types; use actor ID and readonly submission values/arrays. Tests: `GitHubIssueReportApiTest`, `AppFeedbackGitHubTest`, `LifecycleHardCutTest`, `AdminServerTest`, `MemberServerTest`, frontend contract parity.

### Remaining application parts

Ordering remains provisional until each ownership trace is complete. Split large parts into coherent commits and keep every remaining item visible.

- [ ] Prayer/time expressions: providers, preview, cache/store writes, resolver registration and persisted `resolver_class`.
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
