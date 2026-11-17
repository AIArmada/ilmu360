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
- [ ] Trace every caller including Filament forms, Livewire, API, configuration/class strings, and tests.
- [ ] Consolidate the Maps/type/form actions under Institutions, remove old paths, verify package-derived addressing semantics and related contribution workflows.

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
- [ ] Keep only justified framework/package entrypoints at root, reposition domain logic and routing helpers as needed.
- [ ] Search *all* production/test paths for retired symbols, stale path strings, duplicate registrations and unowned writes.
- [ ] Run the final architecture/type checks, regular static analysis, Pint/Rector, full relevant Pest suite, route parity and CI.

## Verified inspection limits

The initial 51-file source and manifest inventory was read through the GitHub connector on `refactor`. Call-site discovery from a working checked-out repository and execution of Laravel/Pest/static analysis **were not available from this connector session**. Source-review classification is not a substitute for these gates. Do not mark a later phase complete without checking all runtime references, registration surfaces and behavior.

Repository guidance in `tasks/lessons.md`: use Pest and the quick architecture checker for per-slice verification; reserve PHPStan, Rector and Pint for the final pass. Do not weaken existing behavior or install backwards-compatibility shims to get a green check.
