# 09 — Deletion Candidates

> ## ⚠️ OWNER DIRECTIVE — NO BACKWARD COMPATIBILITY
> All removals are **hard**. Any candidate marked `TEMPORARY_KEEP_FOR_CUTOVER` below
> means: keep only until the cutover change is prepared, then delete in that same change
> (migrate data once → switch consumers → drop). No lingering aliases, wrappers, or
> dual-write/dual-read compatibility. See `00-progress-ledger.md`.

Each candidate lists the package replacement, why it may be removed, readiness, risk, and what must be done first. **No deletion is recommended until the listed test exists and references are updated.**

## 1. Likely removable models

| Gap ID | File | Package replacement | Why removable | Readiness | Risk | Required before deletion | Verification |
| --- | --- | --- | --- | --- | --- | --- | --- |
| AIA-DELETE-001 | `app/Models/MembershipClaim.php` | `AIArmada\Membership\Models\MembershipApplication` | duplicates package application (AIA-MIG-005) | `READY_TO_REMOVE_AFTER_TESTS` | med | AIA-TEST-005 passing; data migrated; `SubmitMembershipClaimAction` cut over | claim admin + submit works |
| AIA-DELETE-002 | `app/Models/Notification*` (Message, Delivery, Destination, Rule, Setting, PendingNotification) | `AIArmada\Communications\Models\*` | heavy parallel storage (AIA-MIG-004) | `TEMPORARY_KEEP_FOR_CUTOVER` | **high** | AIA-TEST-006; staged delivery cutover + history backfill | notification delivery works via package |

## 2. Likely removable actions/services

| Gap ID | File | Package replacement | Why removable | Readiness | Risk | Required before deletion | Verification |
| --- | --- | --- | --- | --- | --- | --- | --- |
| AIA-DELETE-003 | `app/Actions/Events/RegisterForEventAction.php` | `AIArmada\Events\Actions\*` | pkg action unused (AIA-ACTION-001c) | `READY_TO_REMOVE_AFTER_TESTS` | high | AIA-TEST-001; pilot wired | registration works |
| AIA-DELETE-004 | `app/Actions/Events/SaveAdminEventAction.php` | pkg create/update action | (AIA-ACTION-001) | `READY_TO_REMOVE_AFTER_TESTS` | high | AIA-TEST-002 | admin event save works |
| AIA-DELETE-005 | `app/Actions/Events/SubmitFrontendEventAction.php` | pkg submit action | (AIA-ACTION-001b) | `READY_TO_REMOVE_AFTER_TESTS` | high | AIA-TEST-002 | public submit works |
| AIA-DELETE-006 | `app/Models/Concerns/HasFollowers.php` (+ `followings` writers) | `AIArmada\Engagement\Actions\*` | parallel follow storage (AIA-MIG-003) | `READY_TO_REMOVE_AFTER_TESTS` | med | AIA-TEST-004; backfill `followings`→`engagement_follows` | follow/unfollow works |
| AIA-DELETE-007 | `app/Services/Notifications/*` (notification service layer) | `AIArmada\Communications\Services/Jobs` | (AIA-ACTION-004) | `TEMPORARY_KEEP_FOR_CUTOVER` | high | AIA-TEST-006; staged | notifications deliver |

## 3. Likely removable Filament/admin files

| Gap ID | File | Package replacement | Why removable | Readiness | Risk | Required before deletion | Verification |
| --- | --- | --- | --- | --- | --- | --- | --- |
| AIA-DELETE-008 | `app/Filament/Resources/States/`, `Districts/`, `Subdistricts/` (empty stub dirs) | `FilamentAddressingPlugin` | empty stubs; geography via plugin | `READY_TO_REMOVE_AFTER_REFERENCES_UPDATED` | low | confirm no references | admin geography CRUD renders |
| AIA-DELETE-009 | (conditional) local `Events`/`Venues` Filament Resources | `filament-events` Resources | only if AIA-FILAMENT-002 decided to register plugin | `NEEDS_HUMAN_DECISION` | med | decision + tests | event/venue admin CRUD |

## 4. Likely removable config/service-provider code

| Gap ID | File | Replacement | Why | Readiness | Risk | Required before deletion | Verification |
| --- | --- | --- | --- | --- | --- | --- | --- |
| AIA-DELETE-010 | `app/Providers/AppServiceProvider.php:118-127` blanket `loadMigrationsFrom(vendor/aiarmada/*)` | per-package migration opt-in | pulls unused inventory/ticketing/seating schema | `TEMPORARY_KEEP_FOR_CUTOVER` | med | AIA-CONFIG-003 decision; ensure needed packages still load | `php artisan migrate:status` |

## 5. Migration files → legacy history only (DO NOT delete; stop being source of direction)

| Gap ID | Migration | Table | Action | Readiness | Risk |
| --- | --- | --- | --- | --- | --- |
| AIA-DELETE-011 | `…000024_create_registrations_table` | `registrations` | keep as history; add forward `drop` migration (AIA-MIG-002) | `READY_TO_REMOVE_AFTER_TESTS` | low |
| AIA-DELETE-012 | `2026_02_16_…_create_followings_table` | `followings` | keep as history; drop after backfill (AIA-MIG-003) | `TEMPORARY_KEEP_FOR_CUTOVER` | med |
| AIA-DELETE-013 | `2026_03_19_…_create_membership_claims_table` | `membership_claims` | keep as history; drop after cutover (AIA-MIG-005) | `TEMPORARY_KEEP_FOR_CUTOVER` | med |

## 6. Tests to rewrite around package behavior

| Gap ID | Test area | Action |
| --- | --- | --- |
| AIA-DELETE-014 | Any test asserting local `registrations`/`followings`/`membership_claims`/`notification_*` writes | rewrite to assert package tables (`event_registrations`, `engagement_follows`, `membership_applications`, `communication_*`) — see AIA-TEST-* |

## 7. Docs to update after cutover

- `docs/aiarmada-adoption/status.md` — currently overstates engagement/communications/membership as "In Progress" with package actions; correct to "schema/plugin only".
- `docs/aiarmada-adoption/package-inventory.md` — stale (says 5 required; actual 22).
- `docs/commerce-package-readiness-reassessment.md` — claims "engagement uses package actions" — **false per code evidence**; correct.
- The 3 original source docs (`deep-functional-comparison`, `replacement-analysis`, `refactor-perspective`) are superseded by `readiness-reassessment` — mark as historical only.
