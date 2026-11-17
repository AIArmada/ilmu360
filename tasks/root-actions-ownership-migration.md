# Root Actions ownership consolidation — refactor branch

Started 2026-11-17. Baseline commit: `2e574bdbbb48b3a07770edafd79883df3f0ff423`.

## Non-negotiable rules

- Feature ownership is by business capability, not shared folder name or package table ownership.
- Cut over a whole vertical slice atomically: update *all* class references, dependency injection, route/CLI/queue/listener registrations, tests and manifest declarations; delete the superseded path in the same commit.
- **No** backwards-compatible aliases, forwarding classes, dual implementations, old/new payload synonyms, legacy branches, temporary migrations, or leftover paths.
- Package-owned tables stay canonical. The application feature may still own its orchestration, and uses package APIs/models according to the verified package contract.
- An action inside a feature may use its own private implementation. Cross-feature calls must use the owning feature's `Api` boundary. A `Platform`/ `Shared` class must not import a concrete feature.
- Do not create one interface per use case or needless DTO/repository layers.
- Preserve existing authorization/tenancy, transactions, lock ordering, idempotency, events, after-commit and notification semantics.

## Scope inventory (51 root PHP files)

| Folder | Count | Primary owner/candidate | Audit decision |
| --- | ---: | --- | --- |
| Contributions | 13 | Features/Contributions | Move query/submission behavior; inspect contribution entity mutation and form validation; use subject feature APIs for writes |
| Engagement | 10 | Features/Engagement | Move follow/save/going writers and result types; package remains canonical storage |
| Events | 12 | Features/Events | Move authoring, submission, change announcements, event-specific affiliate coordination; use Registration and ShareTracking public APIs |
| Fortify | 3 | Laravel Fortify / Features/Identity | Keep genuine Fortify contract adapters only; extract domain behavior into Identity where appropriate |
| Institutions | 3 | Features/Institutions | Move Maps resolution/type inference/form projection to the feature; no duplicate geography rules |
| Organizations | 1 | Features/Organizations + Events API | Organization authorization stays in Organizations, event creation through Events public boundary |
| Referrals | 3 | Referrals package + app-level integration | Decide package integration owner before moving; no gratuitous wrapper feature |
| ShareTracking | 4 | Features/ShareTracking | Move effect dispatch/reversal; preserve transactional lease/lock order and package canonical writes |
| Signals | 1 | Signals integration / Platform Telemetry | Decide based on mobile transport vs business ownership; preserve package ingestion behavior |
| Slugs | 1 | Features/SlugRedirects | Public slug redirect lookup uses this feature's Api and Platform registry; move to SlugRedirects, not Platform (Platform cannot import feature) |
| **Total** | **51** | | |

## Phase plan and verification

### Phase 1 — Public slug resolution (1 file)
- [x] Inspect the resolver, its public slug registry, SlugRedirects public lookup, middleware, AppServiceProvider route bindings, and existing redirect/route tests.
- [x] Move the resolver into `Features/SlugRedirects/Actions`, update both directly observed consumers, and delete the root action.
- [ ] Run `php tools/architecture-check.php`, focused Pest slug redirect and route cache tests, and route parity in the same uncached environment. CI verification must be recorded separately; no claim of passing tests without execution.

### Phase 2 — Institutions (3 files)
- [x] Inspect the three actions and their direct API/Livewire consumers and classification unit test.
- [x] Move three actions into Institutions and expose one institution Maps API for lookup, classification and form merging. Direct cutover; delete root paths.
- [ ] Confirm architecture, tests, and no-old-namespace checks on CI before closing the phase.

### Phase 3 — Contributions (13 files)
- [ ] Trace all thirteen actions, outstanding root services, request reads, reviewer guards, staged create and direct-update paths.
- [ ] Keep contribution-request lifecycle owner in Contributions. Delegate entity writes through Institutions, Persons, References and Events APIs; preserve locking and duplicate checks.
- [ ] Cut all current callers to new classes/API, remove root paths, verify review concurrency, retries and authorization.

### Phase 4 — Engagement (10 files)
- [ ] Move follow/save/going orchestration and its result values into the owner. Do not claim vendor package tables or duplicate package writers.
- [ ] Verify going-triggered free admission, share outcome freeze, idempotency and account lifecycle.

### Phase 5 — Events and Organizations (13 files)
- [ ] Rehome Events authoring, submissions, moderation, announcement and commerce operations; migrate Organization event creation through public Events API.
- [ ] Verify timestamps, schedule/registration semantics, moderator permissions, after-commit events and affiliate reversal.

### Phase 6 — ShareTracking and Referrals (7 files)
- [ ] Rehome ShareTracking orchestration and identify proper Referrals package integration owner; remove unnecessary forwarding layers.
- [ ] Preserve idempotency, effect lease and lock ordering, payout safety, and reward reversal semantics.

### Phase 7 — Fortify, Signals, final sweep (4 files)
- [x] Move mobile telemetry's Signals-package ingestion adapter to Platform/Telemetry alongside ProductSignalRecorder and ProductSignalsClientContext, updating the frontend API action injection and deleting the root path.
- [ ] Keep only justified framework/package entrypoints at root, reposition remaining domain logic and routing helpers as needed.
- [ ] Search *all* production/test paths for retired symbols, stale path strings, duplicate registrations and unowned writes.
- [ ] Run the final architecture/type checks, regular static analysis, Pint/Rector, full relevant Pest suite, route parity and CI.

## Verified inspection limits

The initial 51-file source and manifest inventory was read through the GitHub connector on `refactor`. Call-site discovery from a working checked-out repository and execution of Laravel/Pest/static analysis **were not available from this connector session**. Source-review classification is not a substitute for these gates. Do not mark a later phase complete without checking all runtime references, registration surfaces and behavior.

Repository guidance in `tasks/lessons.md`: use Pest and the quick architecture checker for per-slice verification; reserve PHPStan, Rector and Pint for the final pass. Do not weaken existing behavior or install backwards-compatibility shims to get a green check.


## Phase 1 architecture CI correction

The first GitHub Quality run (44001740277) flagged four `feature-private` violations: the root middleware and AppServiceProvider imported the moved feature action directly. Corrected by making the action implement `SlugRedirects/Api/PublicSlugLookup`, returning a readonly scalar `ResolvedPublicSlug` instead of an Eloquent model. Root route adapters hydrate the registered model by identifier and retain the original request-attribute shape, redirect status, and mark-used semantics. The feature binds its own public contract. No private feature action import, alias, or old root path remains. CI must confirm this correction before marking the phase validated.


### Contributions preparation cutover
- [x] Move three submission-preparation actions (normalize, change diff and unique creation claim) into Contributions.
- [x] Expose one public preparation contract to API, Livewire, staged-create and Institution form consumers. Bind InstitutionClaimGuard inside the owner.
- [ ] Confirm CI boundary, type and behavior checks before marking this slice fully verified. Root query and staged entity orchestration remain for later slices.


### Contributions pending-request guard cutover
- [x] Remove duplicate root pending-entity query in favor of scalar `ContributionRequestReader::pendingRequestIdForEntity` with the same pending status and ordering.
- [x] Move `EnsureNoPendingContributionRequestAction` into Contributions, bind the existing `ContributionRequestGuard` from the owning feature and update HTTP/Livewire/tests.
- [ ] Verify affected CI and direct import audit.


### Contributions user-owned request lookup consolidation
- [x] Eliminate root FindUserPendingContributionRequestAction and ResolveOwnContributionRequestAction. Their one query each now belongs to Contributions/Queries/EloquentContributionRequestReader (scalar IDs).
- [x] Update frontend/member/Livewire adapters to hydrate authorized request identifiers locally; keep 404 on unauthorized request access and original query ordering.
- [ ] Confirm behavior and static analysis on Quality CI.


### Contributions reviewer access cutover
- [x] Move pending-approval and reviewable-request policy queries into Contributions/Queries.
- [x] Expose scalar ID-only ContributionReviewAccess contract. Rehydrate response models at HTTP/member/admin adapters; retain 403 for unauthorized reviews, request status filtering and sorting.
- [ ] Confirm source audit and affected CI results.


### Contributions subject/update form context cutover
- [x] Rehome slug-or-UUID contribution subject resolution and update-form state projection to Contributions/Queries.
- [x] Expose ContributorUpdateContextReader with scalar entity identity and array-form contract; root HTTP/Livewire hydrate the entity through MorphModelResolver.
- [ ] Verify route-binding/authorization parity, storage reads and Quality CI.


### Direct-update cutover
- [x] Move ApplyDirectContributionUpdateAction to Contributions and expose scalar ContributionDirectUpdates API; delete old path.
- [x] Replace API/Livewire/test callers, refresh the caller's entity after mutation; preserve transaction and moderation logic.
- [ ] Run Quality CI and verify scope/behavior.


### ShareTracking guest claim simplification
- [x] Remove the root ClaimGuestShareProfileAction forwarding adapter. Callers now consume the existing scalar GuestShareClaims feature API directly, preserving result decisions and explicit consent.
- [ ] Verify claim/replay/identity tests and boundary CI.


### Engagement going cutover
- [x] Move event-going and remove-going orchestration and package writes under Engagement; expose scalar EventGoingCommands.
- [x] Update HTTP/Livewire to contract; keep private action tests for explicit Request attribution scenarios. Delete root actions.
- [ ] Verify original eligibility, counts, idempotency, free admission and share attribution on CI.


### Engagement save cutover
- [x] Move event bookmark save/remove actions and immutable result objects under Engagement. Public EventSaveCommands returns only scalar bookmark identity and created/removed flags.
- [x] Replace API, event page and Livewire trait callers; delete old root paths.
- [ ] Verify existing action tests plus Save API response parity and CI.


### Engagement follow cutover
- [x] Move follow/unfollow orchestration and results into Engagement, retain package-canonical rows and sharing attribution.
- [x] Expose scalar SubjectFollowCommands; update API, directory cards and page callers; delete four root classes.
- [ ] Verify eligibility, idempotency, share attribution, and package counters in CI.
