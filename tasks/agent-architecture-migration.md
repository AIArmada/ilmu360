# Agent-friendly architecture conversion

> **Authoritative active plan.** Read this file first to resume. History lives in [agent-architecture-migration-history.md](agent-architecture-migration-history.md); consult it only for a specific completed stage, decision, limitation or log. Never load the history or the full `tasks/todo.md` routinely just to resume.

## Resume here

- Current checkpoint: the whole **ilmu360°** application conversion remains incomplete. Branch `refactor`.
- Latest accepted implementation checkpoint `1818ac78` — occurrence-page gap closed (scoped JSON-LD address, bounded query budget, map dedupe; Muse AGREE). Previous: `dd5f0b39` — S1 follow-ups.
- Done: GitHubIssues; Prayer; Saved Searches (persistence, core, query/execution, attribution, identity/helpers, atomic transport/page cutover); Feedback/Reports (seams, both features, admin/MCP composition); Inspirations; Identity I0–I6 (seams, lifecycle, foundation, Directory/Membership + Events/Moderation/Contributions cutovers, reverse-relation migration, transport cutover); Platform D1 seams (A/C/M/R/T/S/P1/P2/P3); Directory S0 boundary.
- Do not reintroduce `User::savedSearches()`, a mutable Event paginator across the execution boundary, duplicate key lists, or obsolete taxonomy/addressing aliases.
- Latest passing evidence (Directory S0): arch 0/**9 features**; DirectorySeams 10/10 + affected batch 41/41 green; Pint clean; PHPStan 6 + ownership 0 errors; routes identical. Per-slice D1 logs in history.

## Next action — Directory D1 packages (S0/S0b landed)

- Identity I0–I6, Saved Searches, Feedback/Reports, Inspirations all committed per plan. Platform D1 batch landed: A, C, M, R, T, S, P1, P2, P3 (evidence in history).
- D1 reconcile plan accepted (research-only, no edits): all Platform/Shared prerequisites satisfied; two temporary adapters remain necessary (morph-alias cross-feature model resolver; composite report-subject resolver). Slice order: S0 boundary/strangler + S0b relation adapter → packages S1→S6. Language ruling corrected: shared catalog, not Directory-owned. Donation channels pulled forward into S1 (or short-lived writer port).
- Full whole-app conversion remains open; Identity I0–I6 is complete.

## Execution ownership and required reading

- Codex plans, reviews and commits accepted checkpoints. OpenCode implements, verifies and repairs using exact `opencode-go/deepseek-v4.1-flash#max` (agent ID `build`). Start a fresh bounded session for the next stage; never substitute models. Leave no active worker at handoff.
- **Reviewer pairing (binding):** reviewers must be the opposite model to the orchestrator, at top effort. DeepSeek V4.1 Flash orchestrator → **Muse Ember 1.0 `max`**, invoked through the **Muse CLI** (`muse exec --model muse-ember-1.0 --reasoning-effort max --workspace <repo> "<brief>"`), because the orchestrator's `muse` provider lists Ember but rejects the call (`model does not exist or you lack access`); Muse Spark 1.3 `#xhigh` via the provider is the stated fallback only. Muse orchestrator → DeepSeek V4.1 Flash `#max`. Never delegate a review to the model the orchestrator is currently running as.
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

- [x] Saved Searches: persistence, core, query/execution boundaries, attribution orchestration, identity/generic helpers, atomic transport/page cutover (done `69244285`; evidence in history).
- [x] Feedback/reports: Slice 0 seams, Slice 1 Feedback feature, Slice 2a Reports move + composition, Slice 3 cleanup verified (arch 0/5, PHPStan 0, routes 738/736, AppFeedback 88/429 + report/lifecycle/admin suites green). `ModerationReview` stays with Events/package. Interim seams: `GlobalReportEscalation` only (Event-only); `GlobalReportSubjectResolver` replaced by Composite/Event/Directory resolvers in Directory S0.
- [x] Inspirations: app-owned table, Save action, Filament via named actions, reader/card + admin contract, public endpoint, feature routes + provider (done `8ac9d8b8`, mutually agreed; dormant `last_state_change_at` preserved).
- [x] Identity/accounts: I0–I6 done (seams, lifecycle, foundation, Directory/Membership cutover, Events/Moderation/Contributions cutover + fixes, reverse-relation migration, transport cutover); `User` conventional with auth scaffolding only.
- [x] Platform/shared seams: D1-A/C/M/R/T/S + P1/P2/P3 landed (addressing canonicalization, cache primitives, media fold, routing registry, telemetry recorder, search dispatcher, API responses, auth placement, timezone). Remaining: Directory D1 move.
- [ ] Directory: S0 + S0b + S1 landed (`ba653602`, `49b02796`, `e13fe91b` + `d236c3dd`); models stay root with literal `$table` until Events/Contributions convert (reads only; writes owned+enforced). Next S2 persons → S3 references → S4 venues/spaces → S5 bridges → S6a donation channels (if not folded) / S6b languages as shared catalog. Rulings: literal `$table` + explicit `getTable()` for config-overridden package models; `MediaLink` → Events; `person_search_terms` → Directory projection; `ContributionEntityMutationService` stays out; Series stays Events; languages are cross-feature (Platform/Shared), not Directory-owned. Temporary adapters: morph-alias resolver + composite report-subject resolver.
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
- `User` keeps only auth scaffolding (`Notifiable`, tokens, panel gates, `globalRoles()`); I5 removed all reverse relations and the `booted()` hook (now `UserObserver`). Remaining lifecycle writes are Engagement/registration leftovers queued for later slices.
- `Event` lifecycle hooks save URL/audience projections and delete membership/engagement/registration/submission/moderation/announcement/media data before package cascade cleanup. Move these to explicit lifecycle orchestration without behavior loss.
- `AppMembershipHook` and `User::updated()` call `PublicSubmissionLockService`, which writes directory flags/cache. Directory owns those writes; Membership/Identity call a public eligibility-sync operation.
- `TransferPersonOwnershipAction` locks records, updates two pivots and scoped roles, then invokes hooks. Preserve one-owner invariants and atomic role synchronization.
- `config/events.php`, `config/persons.php` and `AppServiceProvider` register models, policies, observers, slugs and morph identities. Stored fully qualified class strings exist, including Prayer `resolver_class`; import replacement alone is insufficient.
- SavedSearch uses configurable `getTable()` (a literal `$table` alone is insufficient); runtime mutations are centralized, and API deletion reverses share outcomes while page/account deletion does not. Preserve that distinction, the established immutable/public-result boundary, and the public read paths used by dashboard/admin user views, notification matching and reward fact readers. Do not reintroduce the removed mutable `User` relation.
- `AffiliatesShareTrackingService` (~2,900 lines) contains many package writes and guest/receipt orchestration; current guest state lives in the affiliates package. Extract named use cases; a renamed facade is insufficient.
- Canonical geography is done (D1-A verified all 7 consumers resolved via package resolvers; follow-up `exists:countries` fixed with regression test).

## Maintenance

- After each accepted stage, replace the current checkpoint and next action, and check off or remove completed active work.
- Append detailed evidence, commit IDs, limitations and logs only to the [history archive](agent-architecture-migration-history.md).
- `tasks/todo.md` is an entry pointer, not another migration ledger. Do not let the active file grow into a history again.
