# 08 — Test Verification Plan

ilmu360 uses **Pest** (`pestphp/pest` v4). Run with parallel where safe. Engine is PostgreSQL in prod — keep a SQLite test config only if tests are engine-agnostic; otherwise test against PG.

## Existing test inventory (directory level)

`tests/Feature/` and `tests/Unit/`. Known commerce-relevant fixtures referenced: `SubmitEventMediaTest`, `MediaConversionsTest`, `UserRestoreTest`, `Signals*Test`. **No package-action-backed tests exist for events/engagement/communications/membership** — adoption is currently unverified by tests.

## Test gap table

| Gap ID | Area | Existing tests | Missing tests | Refactor protected? | Risk | Suggested test file | Verification command |
| --- | --- | --- | --- | --- | --- | --- | --- |
| AIA-TEST-001 | Event register flow | none package-backed | test that registration goes through `AIArmada\Events\Actions\*` and writes `event_registrations` | NO | high | `tests/Feature/Events/RegisterForEventTest.php` | `vendor/bin/pest --parallel --filter=RegisterForEvent` |
| AIA-TEST-002 | Event create/submit (admin+public) | `SubmitEventMediaTest` (media only) | tests covering package action delegation for save/submit | NO | high | `tests/Feature/Events/SaveEventTest.php`, `SubmitEventTest.php` | `vendor/bin/pest --parallel --filter=SaveEvent` / `SubmitEvent` |
| AIA-TEST-003 | Orphaned `registrations` table drop | none | test asserting nothing writes to `registrations`; migration drop is safe | NO | low | `tests/Feature/Migrations/LegacyRegistrationsTableTest.php` | `vendor/bin/pest --parallel --filter=LegacyRegistrations` |
| AIA-TEST-004 | Engagement follow cutover | none | test that follow writes `engagement_follows` via package action | NO | med | `tests/Feature/Engagement/FollowTest.php` | `vendor/bin/pest --parallel --filter=Follow` |
| AIA-TEST-005 | Membership claim→application | none | test claim workflow against package `MembershipApplication` | partial | med | `tests/Feature/Membership/ClaimTest.php` | `vendor/bin/pest --parallel --filter=Claim` |
| AIA-TEST-006 | Communications delivery cutover | none | test notification delivery routed through package `Communications` | NO | high | `tests/Feature/Communications/DeliveryTest.php` | `vendor/bin/pest --parallel --filter=Delivery` |
| AIA-TEST-007 | Migration collision (events/venues/reports/saved_searches) | none | fresh-migrate smoke test on PG throwaway DB | partial | high | `tests/Feature/Migrations/FreshMigrateSmokeTest.php` | `php artisan migrate:fresh` on scratch DB |
| AIA-TEST-008 | Model inheritance sanity | none | test that `Event`/`Venue`/`Reference`/`Registration`/`MemberInvitation` resolve to package tables | NO | med | `tests/Unit/Models/PackageModelInheritanceTest.php` | `vendor/bin/pest --parallel --filter=PackageModelInheritance` |

## Rule: no local-code removal without a passing test first

Per the audit decision rules, before deleting any local class in `09-deletion-candidates.md`, the corresponding test above must exist and pass against the **package** implementation.

## Standard verification commands

```bash
vendor/bin/pest --parallel                       # full suite, parallel
vendor/bin/pest --parallel --filter=RegisterForEvent
php artisan test --compact --filter=Cart         # n/a — no cart tests exist (no cart code)
php artisan migrate:fresh --seed                 # throwaway PG DB only
php artisan migrate:status
vendor/bin/phpstan analyse --ansi                # level-6 static analysis
vendor/bin/pint --dirty --format agent           # formatting
```
