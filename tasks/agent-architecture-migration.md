# Agent-friendly architecture conversion

> **Authoritative active plan.** Read this file first to resume. History lives in [agent-architecture-migration-history.md](agent-architecture-migration-history.md); consult it only for a specific completed stage, decision, limitation or log. Never load the history or the full `tasks/todo.md` routinely just to resume.

## Resume here

- Current checkpoint: the whole **ilmu360°** application conversion remains incomplete. Branch `refactor`.
- Latest accepted implementation checkpoint `80f1d408` — saved-search attribution orchestration behind public Api (attribution actions + Shared port, roots deleted, ResourceOwner preserved). Previous accepted `0d82b947` — Phase-1 identity. Before that `782c8747` — execution result boundary.
- Done: GitHubIssues; Prayer; Saved Searches persistence, core and query/execution boundaries. Remaining Saved Searches work is transport and page composition.
- Do not reintroduce `User::savedSearches()`, a mutable Event paginator across the execution boundary, duplicate key lists, or obsolete taxonomy/addressing aliases.
- Latest passing evidence (tests/assertions): 5d.2c.3a affected SavedSearch|SearchFilter **165/606**, Signals **17/95**, docs alignment **1/9**, execute schema **1/20**; PHPStan level 6/ownership, boundaries, scoped Rector and Pint pass. Detailed logs are in history.

## Next action — 5d.2c.3b (Phase 1 done, Phases 2–5 open)

- Phase 1 done (`0d82b947`) + attribution move done (`80f1d408`, DeepSeek-implemented + orchestrator-verified, mutual agreement): public Api orchestration with Shared port, roots deleted, `ResourceOwner` preserved.
- Next: rebase held Slice 2 (additive transport prep) onto `80f1d408`, then Phase 2 feature-local values, Phase 3 Platform/Shared helpers, Phase 5 atomic route cutover.

- Map the actual existing root `CreateSavedSearchAction` and `DeleteSavedSearchWithAttributionReversalAction` callers, then move their orchestration to the correct owning boundary. Preserve owner/actor/request provenance, outer transactions and rollback, and the API-versus-page/account-deletion distinction. Move real implementation; no pass-through wrappers.
- Then resolve identity and generic HTTP/UI helpers through public boundaries. Only after those dependencies are complete, cut over HTTP/page entrypoints and routes atomically across the six API actions.
- Preserve `/carian-tersimpan`, the explicit `pages.saved-searches.index` alias (used by ShareTrackingTest), snapshot binding/policy/auth, payloads/Signals, and cached/uncached behavior. Prove unique registration with same-environment uncached route snapshots.
- Full transport and whole-app conversion remain open; this stage is not completion.

## Execution ownership and required reading

- Codex plans, reviews and commits accepted checkpoints. OpenCode implements, verifies and repairs using exact `opencode-go/deepseek-v4.1-flash#max` (agent ID `build`). Start a fresh bounded session for the next stage; never substitute another model. Leave no active worker at handoff.
- Before continuing, read `AGENTS.md`, `.ai/rules/index.md` and relevant sections of `tasks/lessons.md`. Discover `laravel-agent-convert` by name through your harness's skill catalog/discovery tools or configured project/user/system skill locations. Read its `SKILL.md` and architecture/migration/tooling references using that environment's access mechanism; resolve relative references from the skill's own location. If unavailable, report the missing skill rather than assuming a machine-specific path.
- Current graph project: `Users-Saiffil-Herd-ilmu360`. Graph call edges remain sparse: verify current source/configuration strings before moves; do not rely on graph traces alone.
- Do not load archived inventories, counts, versions or commit lists to resume; verify installed versions when needed under `AGENTS.md`. Use this checkpoint and load only the evidence needed for the task. Old per-attempt/provider histories belong only in the archive.

## Stage gates

- Trace callers, routes, Eloquent/query/raw-SQL writes, model hooks, UI persistence, jobs, external stores and package writers before moving a slice.
- One feature owns each resource and lists its named mutation classes. Move the entire slice and all current callers; remove superseded implementations, aliases and delegating wrappers.
- Cross-feature boundaries expose public `Api` interfaces and immutable values; never export mutable models/builders.
- Preserve URLs/names, middleware order, payloads/status, actor/tenant rules, transactions, queue/retry/after-commit semantics and stored class/morph identities.
- Move routes and remove old registration atomically. Verify policy/factory/binding/observer/listener/command/schedule/Livewire/Filament registrations and duplicate invocation counts.
- Preserve existing Signals; evaluate tracking for real UI behavior changes.
- Compare uncached route snapshots in the same environment; verify route/config cache behavior separately. Run affected parallel Pest once per meaningful revision and save output; run Pint, normal level-6 PHPStan (including the ownership rule) and scoped Rector for touched scopes; pass both architecture checks with no new baseline or blanket exceptions.
- No live data, deployment, sibling `commerce` source, dependency, or broad baseline/exception changes. Use isolated test settings.

## Remaining application parts

- [ ] Saved Searches: transport/page composition, attribution orchestration, identity/generic helpers, atomic route cutover (current next stage).
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

## Ownership decisions and enduring hazards

- Directory is one combined capability for tightly related institution/person/reference/venue records; **Series belongs to Events** (`event_series`, `event_series_items`). `ModerationReview` wraps package `ModerationAction`; do not invent a moderation table.
- `ContributionEntityMutationService` (~2,100 lines) is a foreign-resource writer, not a Contributions-owned seam. Extract actual owned mutations; do not move the generic writer into Contributions or grant it foreign resources. Keep contribution approval's atomic create/verify/ownership/media/request-state behavior and post-transaction notifications.
- `User` still carries mutable reverse relations and attribution/submission-lock hooks. Migrate consumers and account lifecycle workflows before moving related models; ordinary auth scaffolding may stay conventional.
- `Event` lifecycle hooks save URL/audience projections and delete membership/engagement/registration/submission/moderation/announcement/media data before package cascade cleanup. Move these to explicit lifecycle orchestration without behavior loss.
- `AppMembershipHook` and `User::updated()` call `PublicSubmissionLockService`, which writes directory flags/cache. Directory owns those writes; Membership/Identity call a public eligibility-sync operation.
- `TransferPersonOwnershipAction` locks records, updates two pivots and scoped roles, then invokes hooks. Preserve one-owner invariants and atomic role synchronization.
- `config/events.php`, `config/persons.php` and `AppServiceProvider` register models, policies, observers, slugs and morph identities. Stored fully qualified class strings exist, including Prayer `resolver_class`; import replacement alone is insufficient.
- SavedSearch uses configurable `getTable()` (a literal `$table` alone is insufficient); runtime mutations are centralized, and API deletion reverses share outcomes while page/account deletion does not. Preserve that distinction, the established immutable/public-result boundary, and the public read paths used by dashboard/admin user views, notification matching and reward fact readers. Do not reintroduce the removed mutable `User` relation.
- `AffiliatesShareTrackingService` (~2,900 lines) contains many package writes and guest/receipt orchestration; current guest state lives in the affiliates package. Extract named use cases; a renamed facade is insufficient.
- Canonical geography follow-ups still include seven unrelated obsolete `addressing.tables.*` consumers: `app/Livewire/Concerns/JoinsHierarchyAreaName.php`, `app/Support/Api/Admin/AdminResourceMutationService.php`, `app/Http/Controllers/Api/Frontend/SearchController.php`, `app/Http/Controllers/Api/Frontend/ContributionController.php`, `app/Services/ContributionEntityMutationService.php`, `tests/Feature/EventLocationPrecedenceTest.php`, `tests/Pest.php`.

## Maintenance

- After each accepted stage, replace the current checkpoint and next action, and check off or remove completed active work.
- Append detailed evidence, commit IDs, limitations and logs only to the [history archive](agent-architecture-migration-history.md).
- `tasks/todo.md` is an entry pointer, not another migration ledger. Do not let the active file grow into a history again.
