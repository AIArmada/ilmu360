# 11 — Next-Agent Brief

## What was completed

A full evidence-based audit of `aiarmada/*` adoption in ilmu360, verified against `composer.json`, the path repo (`/Users/Saiffil/Herd/commerce/packages/*`), all `app/Models`, `app/Actions`, `app/Services`, `app/Filament`, 62 migrations, published configs, and the **live PostgreSQL DB table inventory**. 12 assessment files written to `docs/commerce-aiarmada-adoption-assessment/`. Stable gap IDs AIA-MIG/MODEL/ACTION/FILAMENT/CONFIG/TEST/DELETE assigned.

## Most important findings (trust these, they're code-verified)

1. **The original hypothesis is REJECTED.** ilmu360 has **zero local commerce code** — no cart/order/voucher/checkout/payment/chip/invoice/transaction/subscription/refund anywhere. The commerce packages (cart, checkout, orders, vouchers, chip, etc.) are correctly NOT required. Do not pursue their adoption.
2. **22 packages are required; only ~6 drive runtime** (addressing, contacting, signals, affiliates, authz, commerce-support foundation). These are the gold-standard, fully-adopted examples.
3. **The real gap is shallow adoption of the "events cluster":** events, engagement, communications, membership, moderation, references have **schema + (some) models + (some) Filament plugins installed, but ZERO package Actions/Services are called.** Verified: `rg "AIArmada\\\(Events|Engagement|Inventory|Communications|Moderation|Ticketing|Seating)\\\(Actions|Services)" app/` → no matches.
4. **Parallel-storage duplicates** are the concrete debt: `registrations` (orphan), `series`/`event_series`, `followings`/`engagement_follows`, `membership_claims`/`membership_applications`, app `notification_*`/pkg `communication_*`, `event_checkins`/`event_attendances`.
5. **inventory, ticketing, seating are installed (tables exist) but completely unused** — schema overhead only.
6. **`filament-events` is required in composer.json but its plugin is never registered** — dead dependency.
7. **No `config/events.php` is published** despite 60 event tables running on package defaults → fragile table ownership for the shared `events`/`venues` tables.
8. The existing `docs/aiarmada-adoption/status.md` & `readiness-reassessment.md` **overstate** engagement/communications/membership adoption ("uses package actions") — that is false per code. Fix the docs.

## Next safest task

**AIA-TEST-008 then AIA-MIG-002** (see `10-remediation-backlog.md` #1, #2, #4): add a model-inheritance unit test, prove `registrations` is orphaned, then add a forward `drop` migration. Low risk, no behavior change, builds the test-first habit before the riskier event-flow cutover.

## Exact files to open first

1. `app/Models/Registration.php` — confirm it extends `AIArmada\Events\Models\EventRegistration` and has no `$table` override (inherits `event_registrations`).
2. `app/Providers/AppServiceProvider.php:118-127` — the blanket migration loader.
3. `app/Actions/Events/RegisterForEventAction.php` — the pilot cutover target.
4. `app/Models/Event.php` — the central adapter model to thin later.
5. `/Users/Saiffil/Herd/commerce/packages/events/src/Actions/` — the package actions to wire in.

## Exact commands to run first

```bash
rg -n "AIArmada\\\\Events\\\\(Actions|Services)" app/        # confirm zero (baseline)
rg -n "'registrations'" app/ routes/                          # confirm orphan
php artisan tinker --execute 'echo (new App\Models\Registration)->getTable();'   # expect event_registrations
vendor/bin/pest --parallel --filter=RegisterForEvent          # see current coverage (likely none)
```

## Files that appear removable (after their test passes)

- `app/Models/MembershipClaim.php` (→ `MembershipApplication`)
- `app/Models/Notification*.php` cluster (→ communications, staged)
- `app/Actions/Events/{RegisterForEventAction,SaveAdminEventAction,SubmitFrontendEventAction}.php` (after pilot)
- `app/Models/Concerns/HasFollowers.php` + `followings` writers (after engagement cutover)
- `app/Services/Notifications/*` (staged)
- `app/Filament/Resources/{States,Districts,Subdistricts}/` empty stub dirs (safe now)
- Tables: `registrations`, then `followings`, `membership_claims`

## Files that MUST NOT be removed yet

- `app/Models/Event.php`, `Venue.php`, `Reference.php` — they carry media collections + Scout config + builders not yet relocated. Thin first (AIA-MODEL-001/002/003).
- `app/Models/Registration.php`, `MemberInvitation.php` — justified `EXTEND_PACKAGE_WITH_REASON`.
- `app/Models/Institution.php`, `Speaker.php` — genuinely app-specific (no package parent).
- `app/Models/Series.php`, `EventCheckin.php`, `ModerationReview.php` — pending human decisions.
- Old migration files — keep as history; only add forward drop migrations.

## Warnings / decisions NOT to make without human approval

1. Do NOT register or drop `filament-events` without a decision (AIA-FILAMENT-002).
2. Do NOT touch the `events`/`venues`/`saved_searches`/`reports` shared tables without publishing `config/events.php` first (AIA-MIG-007).
3. Do NOT drop the `notification_*` cluster without a one-time data migration into `communication_*` (AIA-ACTION-004) — data must move, but no compatibility layer stays.
4. Do NOT remove inventory/ticketing/seating packages without confirming the paid-ticketing roadmap (AIA-MIG-008).
5. Do NOT delete any local class until its AIA-TEST-* test exists and passes against the package implementation.
6. Do NOT trust `docs/aiarmada-adoption/status.md` or `readiness-reassessment.md` adoption claims at face value — they're ahead of the code.

## ⚠️ OWNER DIRECTIVE — NO BACKWARD COMPATIBILITY (applies to every task)

Hard cutovers only. When you replace local code with a package: **delete the local class in the same change** (no deprecated aliases, shims, facades, or wrappers left behind), update every consumer (controllers, Livewire, Filament, API, MCP, tests) to call the package directly, migrate existing data once, then drop the source table — all in one step behind a passing test. No dual-write/dual-read compatibility periods. Any `TEMPORARY_ADAPTER_FOR_CUTOVER` / `staged` wording in these docs means "full cutover + removal in a single change," not a phased keep-both-alive transition. One-time data migration is permitted and expected; lingering compatibility code is not.

## How to resume

Open `00-progress-ledger.md` → "Next exact action" → follow `10-remediation-backlog.md` in order. Every gap has evidence, a cutover decision, risk, and verification.
