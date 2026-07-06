# 10 — Remediation Backlog

> ## ⚠️ OWNER DIRECTIVE — NO BACKWARD COMPATIBILITY
> Every item below is a **hard cutover**. When you replace local code with a package:
> delete the local class in the same change (no deprecated aliases/shims/wrappers),
> update all consumers to call the package directly, migrate any existing data once,
> then drop the source table — all in one step behind a passing test. No dual-write
> or dual-read compatibility periods. Any `TEMPORARY_*` / `staged` wording in this
> table is to be read as "full cutover + removal in a single change," not a phased
> keep-both-alive transition. Only one-time data migration is permitted.

Ordered for safe implementation: safety/tests first → low-risk config → direct replacements → deletions → model/flow cutover → migration → filament → cleanup → docs → verify.

| # | Gap ID | Title | Domain | Status | Decision | Remove local? | Package source of truth | Evidence | Problem | Fix | Files removed | Files changed | Risk | Deps | Verification |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | AIA-TEST-008 | Model-inheritance sanity test | model | `READY_FOR_IMPLEMENTATION` | (safety) | no | — | 5 models extend pkg | unverified inheritance | add unit test asserting table resolution | — | `tests/Unit/Models/PackageModelInheritanceTest.php` | low | — | `pest --filter=PackageModelInheritance` |
| 2 | AIA-TEST-003 | Confirm `registrations` has no writer | migration | `READY_FOR_IMPLEMENTATION` | (safety) | no | — | `Registration` inherits `event_registrations`; `registrations` orphan | prove orphan | grep + test | — | test file | low | — | grep + `pest --filter=LegacyRegistrations` |
| 3 | AIA-CONFIG-001 | Publish `config/events.php` | config | `READY_FOR_IMPLEMENTATION` | pin ownership | no | events pkg | no config; 60 tables on defaults | fragile table ownership | publish + pin table names | — | `config/events.php` | low | — | `migrate:fresh` scratch DB |
| 4 | AIA-MIG-002 | Drop orphaned `registrations` table | migration | `READY_FOR_IMPLEMENTATION` | `LOCAL_MIGRATION_LEGACY_HISTORY_ONLY` | table only | events pkg | orphan table | forward `drop` migration | new migration | new drop migration | low | #2 | `migrate:status` |
| 5 | AIA-FILAMENT-001 | Delete empty geography stub dirs | filament | `READY_FOR_IMPLEMENTATION` | `READY_TO_REMOVE_AFTER_REFERENCES_UPDATED` | yes | `FilamentAddressingPlugin` | empty stubs | remove stubs | rm dirs | `app/Filament/Resources/{States,Districts,Subdistricts}` | — | low | — | admin geo CRUD renders |
| 6 | AIA-ACTION-001c | Pilot: event register via package action | action | `READY_FOR_IMPLEMENTATION` | `REPLACE_WITH_PACKAGE_CALL` | after pilot | `AIArmada\Events\Actions\*` | pkg actions unused | route register through pkg | wire + test | (later) `RegisterForEventAction.php` | action + test | high | #1 | `pest --filter=RegisterForEvent` |
| 7 | AIA-ACTION-001/001b | Roll pilot to save/submit | action | `PARTIALLY_IMPLEMENTED` | `REPLACE_WITH_PACKAGE_CALL` | after tests | events pkg | pkg actions unused | same pattern | wire + test | local event actions | actions + tests | high | #6 | `pest --filter=SaveEvent,SubmitEvent` |
| 8 | AIA-ACTION-002 | Engagement follow → package | action | `IMPLEMENTED_AND_VERIFIED` | `REPLACE_WITH_PACKAGE_CALL` | yes | engagement pkg | parallel `followings` | switch + backfill | action + migration | `HasFollowers`, `followings` | action + backfill migration | med | AIA-TEST-004 | `pest --filter=Follow` |
| 9 | AIA-MIG-003 | Backfill `followings`→`engagement_follows`, drop | migration | `IMPLEMENTED` | `NEEDS_SCHEMA_TRANSITION_PLAN` | table | engagement pkg | parallel storage | data migration | migration | drop migration | med | #8 | row counts |
| 10 | AIA-MODEL-010 / AIA-MIG-005 | Membership claim→application | model+migr | `IMPLEMENTED_AND_VERIFIED` | `REPLACE_WITH_PACKAGE_CALL` | model updated | membership pkg | claim dup | map+cutover+delete | action + migration + model update | — | action+migr+model | med | AIA-TEST-005 | `pest --filter=Claim` |
| 11 | AIA-FILAMENT-002 | Decide `filament-events` fate | filament | `NEEDS_HUMAN_DECISION` | — | n/a | filament-events | dead dependency | register OR drop requirement | — | `composer.json` / `AdminPanelProvider` | med | human | route:list |
| 12 | AIA-ACTION-004 / AIA-MODEL-012 / AIA-MIG-004 | Communications cutover (staged) | action+model+migr | `IMPLEMENTED_AND_VERIFIED` | `REPLACE_WITH_PACKAGE_CALL` (staged) | yes | communications pkg | heaviest parallel | stage delivery→backfill→drop | services + migration | `Notification*` cluster | many | **high** | AIA-TEST-006 | `pest --filter=Delivery` |
| 13 | AIA-MODEL-001/002/003 | Thin Event/Venue/Reference adapters | model | `NOT_STARTED` | `LOCAL_MODEL_TEMPORARY_ADAPTER`→slim | partial | events/references pkg | media+builder in app | move media to pkg extension | model edits | — | models | high | #7 | event/venue/ref tests |
| 14 | AIA-MODEL-006/008/011 | Resolve 3 human-decision models | model | `NEEDS_HUMAN_DECISION` | — | unclear | events/moderation pkg | Series/EventCheckin/ModerationReview | decide ownership | — | — | med | human | per-test |
| 15 | AIA-MIG-008 | Activate or uninstall inventory/ticketing/seating | migration | `NEEDS_HUMAN_DECISION` | — | n/a | those pkgs | unused schema | roadmap decision | gate migrations or remove pkgs | — | `AppServiceProvider`/`composer.json` | low | human | grep imports |
| 16 | AIA-CONFIG-003 | Per-package migration opt-in | config | `DEFERRED` | — | maybe | — | blanket load | narrow after cutover | edit SP | — | `AppServiceProvider.php` | med | #4,#9,#10 | `migrate:status` |
| 17 | AIA-DOCS | Correct adoption docs | docs | `NOT_STARTED` | — | no | — | docs overstate adoption | fix status/inventory/reassessment | — | 3-4 docs | low | after cutovers | review |

## Per-item goal type

- **Direct package usage:** #6, #7, #8, #10, #12
- **Local code deletion:** #4 (table), #5 (dirs), #9 (table), #10 (model), #12 (cluster)
- **Temporary adapter:** #13 (thin models during transition)
- **Extension with reason:** none new (AIA-MODEL-004/005 already correct)
- **Upstream package improvement:** candidates — event media-collection extension points (from #13); consider contributing if reused.
- **Human decision before action:** #11, #14, #15
