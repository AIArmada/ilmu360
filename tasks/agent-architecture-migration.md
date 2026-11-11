# Agent-friendly architecture conversion

> **Authoritative active plan.** Read this file first to resume. History lives in [agent-architecture-migration-history.md](agent-architecture-migration-history.md); consult it only for a specific completed stage, decision, limitation or log. Never load the history or the full `tasks/todo.md` routinely just to resume.

## Resume here

- Current checkpoint: the whole **ilmu360°** application conversion remains incomplete. Branch `refactor`.
- Latest accepted implementation checkpoint `1e218e9d` — Events E2a submission persistence (Ember AGREE). Previous: `84b7c63b` — E1b; E1 complete.
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
- Compare uncached route snapshots in the same environment; verify route/config cache behavior separately. Run affected parallel Pest once per meaningful revision and save output; pass the architecture boundary scanner (`php tools/architecture-check.php`) with no new baseline or blanket exceptions.
- **Verification cadence (user rule):** during development the only behavioural gate is **Pest**. Do NOT run PHPStan, Rector or Pint per slice, and do not require them from implementers or reviewers — they are slow. Reserve all three for the final pass after the codebase is finalized, where they fix typing, style, comments and static analysis.
- No live data, deployment, sibling `commerce` source, dependency, or broad baseline/exception changes. Use isolated test settings.

## Remaining application parts

- [x] Saved Searches: persistence, core, query/execution boundaries, attribution orchestration, identity/generic helpers, atomic transport/page cutover (done `69244285`; evidence in history).
- [x] Feedback/reports: Slice 0 seams, Slice 1 Feedback feature, Slice 2a Reports move + composition, Slice 3 cleanup verified (arch 0/5, PHPStan 0, routes 738/736, AppFeedback 88/429 + report/lifecycle/admin suites green). `ModerationReview` stays with Events/package. Interim seams: `GlobalReportEscalation` only (Event-only); `GlobalReportSubjectResolver` replaced by Composite/Event/Directory resolvers in Directory S0.
- [x] Inspirations: app-owned table, Save action, Filament via named actions, reader/card + admin contract, public endpoint, feature routes + provider (done `8ac9d8b8`, mutually agreed; dormant `last_state_change_at` preserved).
- [x] Identity/accounts: I0–I6 done (seams, lifecycle, foundation, Directory/Membership cutover, Events/Moderation/Contributions cutover + fixes, reverse-relation migration, transport cutover); `User` conventional with auth scaffolding only.
- [x] Platform/shared seams: D1-A/C/M/R/T/S + P1/P2/P3 landed (addressing canonicalization, cache primitives, media fold, routing registry, telemetry recorder, search dispatcher, API responses, auth placement, timezone). Remaining: Directory D1 move.
- [x] Directory: S0-S5 landed (`ba653602`, `49b02796`, `e13fe91b`, `afd9ba04`, `6dc86a78`, `0af69631`, `f3d5f666`); donation channels folded into S1. **S6b languages deferred by ruling**: cross-feature catalog (Event/Series/Person/Institution all use `languages`/`languageables`); keep root/unclaimed until a shared Catalog capability exists, rather than creating foreign writes from Events.
- [x] Organizations: O1+O2 done (`e7cb8f23`, `b03e86eb`). **O-map accepted**. Rulings: `organizations`/`organization_members` stay package-canonical (no table claim); feature owns behavior via `OrganizationAccess`/`OrganizationWorkspace`/`OrganizationCommands` (`owns: {}`); vendor Filament stays vendor; organization event ownership stays Events. Next: S1 seams, then O1 context/access (fix `CurrentOrganizationResolver` singleton holding Request for Octane), O2 workspace/API composition.
- [x] Membership: M1b–M5 done (`4a4ece6e`, `afea071e`, `e4abd9df`, `44482a42`, `f445082d`, `bc38aefb`). **M-map accepted**. Rulings: app claims `institution_members`/`person_members`/`reference_members`/`event_members`, `membership_applications`, `membership_invitations` with named app writers (package lifecycle actions stay canonical and are documented as external writers); Authz tables never claimed (global+scoped rows share tables; scoped adapter stays in Support/Authz); models stay root (package table config); `MembershipSubjectCatalog` in Shared with root implementation; `MemberSubjectType` stays UI mapping; enums split as-is; M4 fixes the missing pivot `role` in lifecycle snapshots with regression tests; preserve current one-owner tolerance, invitation-email asymmetry, org-only panel exclusion, durable organization membership and subject-delete orphan behaviour (recorded product decisions, not silent changes).
- [ ] Events authoring/submission/scheduling: **E-map accepted** (21 hazards catalogued); E0 `f05c3fef`, E1a `3cedc170`, E1b `84b7c63b`, E2a `1e218e9d` landed; E2 orchestration (Submit/Complete) finalizes with E6. Slice order E0 seams → E1 authoring core (relations/classifications/series/schedule/slugs) → E2 submission intake → E3 lifecycle/moderation (7 transitions as named actions; vendor lifecycle actions not exposed) → E4 schedule/occurrence/session → E5 delete/cleanup through owner APIs → E6 transport/model move. **Sequencing correction:** spatie transition classes are typed to the root Event model, so the full E3 move cannot precede E6. E2 persistence landed in E2a with Submit/Complete kept as documented root orchestrators; E3 and the remaining E2 orchestration finalize after E6 (model move). Next: E4a schedule workspaces, then E4b Filament cutover, then E5a delete/cleanup (contributions stay in the Contributions migration). Rulings: keep `event_languages` vs `languageables` split; Filament must route through Events actions; Series gets DeleteSeriesAction; change-announcement venue/space keys fixed; engagement cleanup via EngagementManager; Organizations authorizes and Events writes for org events.
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
