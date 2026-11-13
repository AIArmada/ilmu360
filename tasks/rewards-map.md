# Rewards / digests — ownership and migration map

**Status:** accepted ownership and hazard map. Rewards Rw0–Rw3 are implemented; the Communications migration is completing C4 and still needs C5.
**Inventory base:** `refactor` @ `45c5c119` (the source inventory predates the current implementation).
**Prepared:** 2026-11-13.
**Precedence:** `AGENTS.md`, `.ai/rules`, `tasks/lessons.md`, `tasks/agent-architecture-migration.md` (next action), and the S-map entries in `tasks/agent-architecture-migration-history.md`.

## Evidence and method

- `composer show aiarmada/rewards` → `dev-main`, path `../commerce/packages/rewards` @ `8dbd63e3`. `aiarmada/filament-rewards` → `dev-main` @ `f4bdec3e`.
- Source, migrations, routes, config, policies, Filament plugin, package contracts/actions read directly; `rg` used for all writer/reader sweeps.
- `php tools/architecture-check.php` → **0 violations / 16 features** (read-only baseline).
- No Pest run, no DB-writing commands, no Rector/Pint/PHPStan (per task and `tasks/lessons.md` verification-cadence rule).

---

## 1. Inventory

### 1.1 Package `aiarmada/rewards`

| Part | Count | Notes |
|---|---:|---|
| Migrations / tables | 30 / 30 | Every table name resolves from `config('rewards.database.tables.*')`; models use `getTable()` (e.g. `src/Models/RewardProgram.php:64-67`), so the ownership scanner cannot see them. |
| Models | 30 | One model per package table; all UUID PKs; no app subclasses. |
| Actions | 51 (48 write) | The canonical lifecycle/ledger writers. |
| Enums | 27 | Statuses, components, roles, reasons. |
| Support | 42 (6 write helpers) | `RewardLedgerEntries`, `RewardApprovalLocks`, `RewardBenefitCatalogService`, `RewardBenefitStockLedger`, `RewardBudgetLedger`, `RewardPointOperationAuthority`. |
| Contracts | 13 | Host extension points; the app binds 8 of them. |
| Jobs | 2 | `EvaluateRewardEntitlementJob`, `RecheckRewardAwardsJob`. |
| Commands | 2 | `rewards:apply-source-corrections`, `rewards:expire-point-lots`. |
| Tests | 0 in repo | Package is source-only here. |

Package topology: `RewardProgram` is the tenant/owner boundary (`HasOwner` + `HasOwnerScopeConfig`). The app override sets `owner.enabled` **false** (`config/rewards.php:48-51`); the package default is **true** (`REWARDS_OWNER_ENABLED`). Ledger tables carry no owner columns and resolve tenancy through the program.

### 1.2 App surface

| Area | Files |
|---|---|
| Feature actions | `app/Features/Rewards/Actions/{Enrollment,GratitudeDigests}/*Action.php` |
| Support | `app/Support/Rewards/*` (10): composite/activity/share-attribution/referral fact readers, planner adapter, version resolver, correction-key source, member presenter, payout-method catalog, voucher coupon fulfillment |
| Model | `app/Models/RewardDigestSlot.php` (only app-owned reward model) |
| Rewards notification | `app/Features/Rewards/Notifications/ShareGratitudeDigestNotification.php` |
| Communications delivery adapters | `app/Features/Communications/EntryPoints/Channels/*Channel.php` |
| Delivery fence | `app/Features/Communications/Support/Delivery/GuardedDeliverySend.php` |
| Policies | 9 `app/Policies/Reward*Policy.php` |
| API | 9 controllers, 25 routes (`routes/api.php:270-295`) |
| Web/Livewire | `app/Livewire/Pages/Dashboard/RewardMemberIndex.php`, enrollment prompt in `UserDashboard.php:94-149`; route `routes/web.php:131` |
| Console | 3 commands, schedules `routes/console.php:28-53` |
| Observers | `ShareSourceAttributionReceiptObserver`, `ShareOutcomeCorrectionObserver`; registered `AppServiceProvider.php:817-818` |
| Jobs | `app/Jobs/Referrals/ReverseReferralRewardsForShareOutcomeJob.php` |
| Wiring | contracts/policies `AppServiceProvider.php:483-490, 684-692`; fraud gate `:700-702`; config `config/rewards.php`, `config/filament-rewards.php`, `config/notification-center.php:7-8` |
| Admin | `FilamentRewardsPlugin::make()` at `app/Providers/Filament/AdminPanelProvider.php:110`; **no app Filament reward resource/page/widget** — the entire UI is vendor `aiarmada/filament-rewards` (10 resources) |
| MCP | No reward-specific MCP tool. Generic admin API is read-only for rewards: `AdminResourceMutationService::supports()` allowlist `:100-115` excludes all reward resources; no `AIArmada\Rewards` import under `app/Mcp` or `app/Ai` |
| Tests | `tests/Feature/Rewards/Host/*Test.php` 32 files, **234 cases**, ~10.7k lines; plus `tests/Feature/RewardAppealPolicyTest.php`; race workers and transports under `Host/` |

`config/rewards.php` mirrors the package table keys (formatting-only diff, prefix `reward_`), then adds host vocabulary: `outcomes` (including `referral_qualified`), `moderated_outcomes`, `recipient_types: ['user']`, `cap_scopes`, `payouts.methods: ['bank_transfer']`. `config/filament-rewards.php` is app-only (navigation, staff roles, owner types).

### 1.3 App-owned reward tables

Search `database/migrations` for `reward_` returns exactly one app-created table:

- `reward_digest_slots` — `database/migrations/2026_10_08_040001_create_share_delivery_tables.php:23-42` (created beside `share_cohort_projections` and `community_releases`; migration stays where it is).
  Columns: `id`, `recipient_type`, `recipient_id`, `local_day`, `plan_key` **unique**, `status`, `categories` jsonb, `payload` jsonb, `timezone`, `due_at`, `sent_at`, `cancelled_at`, `communication_id`, timestamps. **Unique** `(recipient_type, recipient_id, local_day)`; index `(status, due_at)`.
- No other app-owned `reward_*` table exists.

---

## 2. Table ownership matrix

### 2.1 App-owned table — proposed claim (Rw1)

`app/Features/Rewards/feature.json` proposal:

```json
{
  "schema": 2,
  "name": "Rewards",
  "owns": {
    "table:reward_digest_slots": [
      "App\\Features\\Rewards\\Actions\\GratitudeDigests\\PlanRewardGratitudeDigestAction",
      "App\\Features\\Rewards\\Actions\\GratitudeDigests\\DispatchDueRewardDigestsAction",
      "App\\Features\\Rewards\\Actions\\GratitudeDigests\\CancelRewardDigestAction"
    ]
  }
}
```

| Table | Current writers (production) | Proposed writer classes | Conditions |
|---|---|---|---|
| `reward_digest_slots` | Plan `:188-198` create, `:233-236` update; Dispatch `:163`, `:173-176`, `:315`, `:357-361`, `:383-386`, `:391-394`, `:410-413`, `:418-421`, `:432-436`; Cancel `:121-124`, `:210-214` | `PlanRewardGratitudeDigestAction`, `DispatchDueRewardDigestsAction`, and `CancelRewardDigestAction` in `Features\Rewards\Actions\GratitudeDigests` | Keep the model in `app/Models` with a literal `$table = 'reward_digest_slots'` and resolve it from the feature through a `reward_digest_slot` morph alias + `Support\RewardsModel` helper; feature code never imports `App\Models\*`. `ResolveDigestMailAmbiguityAction` does **not** write slots; it now lives in Rewards and uses the Communications delivery-fencing API. |

`RewardDigestSlot` invariants to preserve: frozen `recipient_type/recipient_id/local_day/plan_key/timezone/due_at`; immutable `sent_at/cancelled_at`; transitions `planned→sending|cancelled`, `sending→sent|cancelled|planned`; delete refused (`app/Models/RewardDigestSlot.php:74-106`).

### 2.2 Package-canonical tables — explicitly `owns: {}` (30)

No app code writes these tables directly. `rg` over `app` for `Reward*::query()->create|update|delete`, `DB::table('reward_*')`, and direct `save/forceFill` outside the digest actions returns **zero**. Canonical writers below are package classes and must stay external; the app only proxies through them. Table→writer mapping is in this matrix; Appendix A gives each writer class's exact `file:line` write sites.

| Domain | Tables | Canonical package writers |
|---|---|---|
| Programs | `reward_programs`, `reward_program_versions`, `reward_version_publications` | `CreateRewardProgramAction`, `PublishRewardProgramVersionAction`, `TransitionRewardProgramStatusAction` |
| Enrollment | `reward_enrollments`, `reward_enrollment_acceptances`, `reward_enrollment_periods` | `EnrollRewardMemberAction`, `LeaveRewardProgramAction`, `ReacceptRewardTermsAction` |
| Evaluation / claims / caps | `reward_source_coordinators`, `reward_source_component_claims`, `reward_cap_counters` | `EvaluateRewardEntitlementAction`, `TransitionRewardAwardAction`, `RewardApprovalLocks` |
| Awards / ledger | `reward_awards`, `reward_award_entries`, `reward_adjustments` | `EvaluateRewardEntitlementAction`, `RecordRewardMilestoneContributionAction`, `TransitionRewardAwardAction`, `AppendRewardAdjustmentAction`, `RewardLedgerEntries` |
| Badges / milestones | `reward_badge_grants`, `reward_milestone_cycles`, `reward_milestone_contributions` | `GrantRewardBadgeAction`, `RevokeRewardBadgeAction`, `RecordRewardMilestoneContributionAction`, `InvalidateRewardMilestoneContributionAction` |
| Points | `reward_point_lots`, `reward_point_movements`, `reward_point_operations`, `reward_point_reservation_submissions` | `CreditRewardPointsAction`, `DebitRewardPointsAction`, `ReserveRewardPointsAction`, `ReleaseRewardPointsAction`, `RefundRewardPointsAction`, `ExpireRewardPointsAction`, `ClawBackAwardPointsAction`, `MarkRewardReservationSubmittedAction`, `RewardPointOperationAuthority` |
| Appeals | `reward_appeals`, `reward_appeal_decisions` | `OpenRewardAppealAction`, `AdvanceRewardAppealAction`, `RewardLedgerEntries` |
| Funding / budget | `reward_funding_entries`, `reward_budget_movements`, `reward_funding_sources` | `RecordRewardFundingAction`, `RewardBudgetLedger`, `RecordRewardFundingSourceAction` |
| Payees / payouts | `reward_payees`, `reward_payout_requests` | `RegisterRewardPayeeAction`, `VerifyRewardPayeeAction`, `RejectRewardPayeeAction`; `RequestRewardPayoutAction`, `ApproveRewardPayoutAction`, `SubmitRewardPayoutAction`, `ConfirmRewardPayoutAction`, `ReconcileRewardPayoutAction`, `CancelRewardPayoutAction` |
| Benefits | `reward_benefit_items`, `reward_benefit_stock_movements`, `reward_benefit_grants` | `CreateRewardBenefitItemAction`, `RetireRewardBenefitItemAction`, `RewardBenefitStockLedger`, `RedeemRewardBenefitAction`, `FulfillRewardBenefitAction`, `ConsumeRewardBenefitAction`, `CancelRewardBenefitGrantAction`, `ExpireRewardBenefitGrantAction`, `RevokeRewardBenefitGrantAction`, `RecordRewardBenefitRecallAction`, `RewardBenefitCatalogService` |
| Recoveries | `reward_recoveries` | `OpenRewardRecoveryAction`, `SettleRewardRecoveryAction`, `WriteOffRewardRecoveryAction` |

Important package-side unique keys (race contract): awards canonical unique `(recipient, source_business_key, family_key, component)`; source claims `(source_business_key, component, role, family)`; coordinators `(source_business_key, component, role)`; benefit grants `grant_key`; payout requests `award_id` and `operation_key`; point movements `operation_key`; award entries `(award_id, seq)` and `(award_id, source_operation_id)`.

### 2.3 Writer counts

- Package writer classes: **48 actions + 6 support helpers = 54**.
- App direct writers to package tables: **0**.
- App direct writers to `reward_digest_slots`: **3** action classes (to become feature-local).
- App proxy entrypoints that invoke package actions: 6 reward actions, 9 API controllers, 2 Livewire surfaces, 3 app console commands (digest dispatch, mail-ambiguity resolution, communications digest stub), 2 package commands, the demo/seed command, and vendor Filament resource actions.
- Reward tests: 234 cases / 32 files; digest-specific 34 cases (`RewardDigestTest` 18, `RewardDigestRaceTest` 5, `RewardDigestMailAmbiguityTest` 5, `RewardDigestMailResolveCommandTest` 5, plus one digest case in `RewardDeliveryTest`).

---

## 3. Flow and entrypoint map

### A. Gratitude digest planning (package achievements → app slot)

1. The package calls the bound `RewardGratitudePlanner` **inside the same outer transaction as the achievement write** (before commit):
   - `TransitionRewardAwardAction.php:346-356` (approved/fulfilled → plan),
   - `:357-364` (reversed → `cancelPendingFor`, **no try/catch**),
   - `EvaluateRewardEntitlementAction.php:1042-1050`,
   - `GrantRewardBadgeAction.php:156-162`,
   - `RecordRewardMilestoneContributionAction.php:560-570, 625-637`.
2. `App\Support\Rewards\DigestRewardGratitudePlanner:19-26` delegates to `PlanRewardGratitudeDigestAction` / `CancelRewardDigestAction`.
3. Plan validates user recipient, 1..3 known categories, policy enabled + daily/weekly cadence (`NotificationSettingsManager:281-320`). Timezone is per-user; **delivery time and weekly day are global config** (`config/notification-center.php:7-8`, read at `NotificationSettingsManager:317-318`).
4. Due time: next local day (daily) or `next(weeklyDay)` (weekly) at delivery time; “calmness floor” pushes to `decided + 20h`; `local_day` is derived from the **due** instant in the recipient timezone; `plan_key = gratitude:{recipientId}:{local_day}` (191-char guard).
5. Concurrency: `DB::transaction` + `lockForUpdate` read; insert on miss (`:188-198`); unique violation caught **outside** the transaction, deadlock retried 3× (`:124-160`); concurrent insert merges into the winner under lock (`:154-158`). Merge only mutates `planned` slots; extends `payload.window.to`, unions categories, preserves `payload.delivery`, saves (`:210-239`). Sending/sent/cancelled return untouched.

### B. Dispatch and claim (hourly)

1. `routes/console.php:28-32`: `rewards:dispatch-gratitude-digests` hourly UTC, `withoutOverlapping()` (default 1440-minute lock), `timezone('UTC')`.
2. `DispatchRewardDigestsCommand` → `DispatchDueRewardDigestsAction::handle(batch=50)`: select due planned ids (`:71-77`), then sending ids (`:84-89`).
3. `dispatchOne` (`:102-180`) opens **one transaction per slot**, locks the slot, rechecks status/due, recipient, policy, quiet hours. Quiet-hours and opt-out are rechecked at send time, not only plan time.
4. Payload is recomputed over the frozen window/categories; zero material cancels (`nothing-to-thank`); delivery bookkeeping is preserved from the old payload (`:136-163`).
5. `adoptOrCreateCommunication` (`:192-261`) locks the latest `Communication` by `idempotency_key = plan_key`; adopts it only for sendable statuses; redrives a job only when every delivery is pre-send and `queued_at + 120 min < now` (`:214-225`, `:267-292`, `->afterCommit()`); otherwise creates a new managed communication via `DispatchManagedNotificationAction` (communications package), which writes its own rows and dispatches the real job `->afterCommit()`.
6. Slot becomes `sending` with `communication_id` (`:173-176`). No eligible channel → cancelled (`:167-171`).

### C. Send, observe, reconcile

1. `Features\Rewards\Notifications\ShareGratitudeDigestNotification` sends through the Communications digest-mail and inbox drivers.
2. `Features\Communications\EntryPoints\Channels\DigestMailChannel` claims delivery + communication, commits a durable attempt boundary **before** SMTP, sends outside any transaction, and records Sent fenced against the attempt; any send/record exception leaves the delivery `Sending` with an unresolved attempt (UNKNOWN) and rethrows. `Features\Communications\Support\Delivery\GuardedDeliverySend` owns the claim and fenced recording.
3. `ShareGratitudeDigestNotification::failed()` calls the scalar `DeliveryFencing` API. The API locks each delivery, verifies it belongs to the supplied communication, and preserves accepted, delivered, terminal, and ambiguous states.
4. `reconcileOne` (`:297-425`) locks slot → communication → deliveries. Any delivered channel → `sent` + per-channel outcomes + `sent_at`. In-flight → wait; pre-send stalled past 120 min escapes to `planned` (max 2 escapes), ambiguous in-flight never escapes; all-terminal failure requeues up to 3 attempts then cancels `delivery-failed`.
5. `DispatchDueRewardDigestsAction` has **no deadlock/retry wrapper around `reconcileOne` or `dispatchOne`**.

### D. Cancel / recalculate

`CancelRewardDigestAction::handle` (`:40-128`): actor must be staff/admin user or system (`RewardLedgerEntries::assertActor`); reason 1..191; one transaction, slot lock; sent/cancelled refuse; `sending` calls `cancelSendingTransport` first; `force=true` cancels planned outright (still refusing delivered/ambiguous); otherwise recompute over frozen window and either cancel (`total <= 0`) or return to `planned` with fresh payload, preserving delivery bookkeeping.
`cancelPendingForRecipient` (`:136-163`) is best-effort: reads all planned/sending ids outside a transaction, calls `run()` per slot and swallows `InvalidArgumentException` (delivered/ambiguous); every other exception propagates.

### E. Mail ambiguity resolution (operator)

`Features\Rewards\Actions\GratitudeDigests\ResolveDigestMailAmbiguityAction`: staff/admin/system only; `sent` requires observed provider message id, `failed` requires reason; one transaction, delivery lock, latest started-unresolved attempt must match `delivery.attempt_count`; delegates to the scalar Communications `DeliveryFencing` API; stamps `metadata.digest_mail_resolution`; never sends mail. The root command verifies the real operator and calls the Rewards API.
`ResolveDigestMailAmbiguityCommand:25-124` verifies the real operator exists and holds `super_admin|admin` (never a self-claimed role), validates evidence, delegates. The slot is not touched directly; the next reconcile run settles it.

### F. Enrollment / acceptance / preclaim

- API: `RewardEnrollmentController::store:24-64` (requires client `version_id`; policy `enroll`; package `EnrollRewardMemberAction`; then `EvaluatePreclaimShareOutcomesAction`), `destroy:67-98` (`leave`), `reaccept:101-145`.
- Web: `RewardMemberIndex::enroll:84-110` routes through `EnrollRewardProgramMemberAction` (records the `rewards.program_enrolled` signal on first active enrollment, `:32-62`); `leave`, `reaccept` call package actions directly; dashboard prompt `UserDashboard:94-149` routes through the same wrapper.
- `EnrollRewardMemberAction` itself: enrollment identity unique `(program, member_type, member_id)`, acceptance/period `seq` unique, `current_acceptance_id` updates (`package EnrollRewardMemberAction.php:106-205`).
- Preclaim: `EvaluatePreclaimShareOutcomesAction:32-122` reads `windows.preclaim_lookback_days` from the published version; cursor over `ShareSourceAttributionReceipt` in the lookback; roles by `sharer_id` and `actor_id`; dispatches `EvaluateRewardEntitlementJob` per role per receipt `->afterCommit()`; also resolves the mirrored `activity:` key. No chunk/limit; runs synchronously after enroll/reaccept.

### G. Source facts / evaluation / claims

1. ShareTracking writes a receipt (`DatabaseSourceAttributionReceipts`); `ShareSourceAttributionReceiptObserver:14-57` dispatches `EvaluateRewardEntitlementJob(businessKey)` **after commit**.
2. `EvaluateRewardEntitlementJob` implements `ShouldBeUniqueUntilProcessing`, unique id `reward-eval:{businessKey}{scopeSuffix}`, 3 tries, backoff 30/120/300 (`package Jobs/EvaluateRewardEntitlementJob.php:50-78`). It reads the fact through the bound `RewardSourceFactReader` (composite), enumerates candidates under owner context, and calls `EvaluateRewardEntitlementAction` per component.
3. `App\Support\Rewards\CompositeRewardSourceFactReader:19-33` routes namespaces: `activity:*` → activity reader, `share-outcome:*` → share-attribution reader, `referrals:*` → referral reader; unknown → null (fail closed).
4. Evaluation locks a global coordinator then the program (`EvaluateRewardEntitlementAction.php:211-238`), resolves the version/acceptance/cap, inserts the award under the canonical unique, appends ledger entries, and records the source claim (`:1006`, `:604`, `:814`); approved awards plan the digest.
5. Corrections: `ReverseShareOutcomeAction` writes a `ShareOutcomeCorrection` (ShareTracking `Api\OutcomeCorrections`); `ShareOutcomeCorrectionObserver:15-41` dispatches `RecheckRewardAwardsJob` + `ReverseReferralRewardsForShareOutcomeJob` after commit. `RecheckRewardAwardsJob` never auto-reverses fulfilled awards; it rejects/reverses only held/pending/approved and invalidates milestone contributions (`package Jobs/RecheckRewardAwardsJob.php:101-163`).
6. Guest claim: `ClaimGuestProfileAction:203-212` dispatches evaluation for up to 500 claimed receipt keys after commit.
7. Unattributed activity path: `AffiliatesShareTrackingService::dispatchActivityRewardIfUnattributed:636-651` dispatches the `activity:` key after commit when the share receipt is unattributed.

### H. Referral points rail

`QualifyReferralFromShareOutcomeAction:152-233` reads the activity fact, then qualifies accepted invites whose program is active and whose `qualifier_outcome` matches, and fulfills each reward. The referrals package `RewardsPointsFulfillment` dispatches `EvaluateRewardEntitlementJob::dispatchSync(...)` **inside a DB transaction**, then requires exactly one live points award for the frozen side/family and compares the points amount (`packages/referrals/src/Fulfillment/RewardsPointsFulfillment.php:60-112`). Referral reversal: `ReverseReferralRewardsForShareOutcomeAction:25-63` re-reads the fact and reverses only `Fulfilled` rewards that are no longer valid; the job isolates failures with logging.

### I. Awards / benefits / funding / payouts (package canonical)

- Member reads: `RewardAwardController` (recipient-scoped awards + entries), `RewardBalanceController` (point lots + `RewardPointLedger::balancesFor`; badges), `RewardAppealController`, `RewardPayoutController` (read-only), `RewardBenefitController` (catalog/redeem/grants/consume/cancel), `RewardPayeeController` (methods/list/register).
- Staff writes: vendor Filament actions call package actions only (`Publish`, `TransitionAward`, `DismissFraud`, `RequestPayout`, `Fulfill`, `Verify/RejectPayee`, `Settle/WriteOffRecovery`, benefit grant actions). `CreateRewardProgram` resolves the configured owner server-side and calls `CreateRewardProgramAction`.
- Funding readiness: app binds package `FundedRewardReadinessChecker`, `FundedRewardFinancialReadinessChecker`, `ManualRewardPayoutExecutor`, `ManualRewardPayoutMethodCatalog`, `PointLedgerRewardReservationResolver` (`AppServiceProvider:483-490`). Money/points are package-canonical; no app reconciliation writer exists.
- Benefit coupon fulfillment: `VouchersRewardBenefitCouponFulfillment` issues recipient-bound single-use vouchers and proves usage against canonical `VoucherUsage` rows (`:27-153`). It writes Vouchers package tables through Vouchers actions, not reward tables.

### J. Admin / MCP / console

- Admin: `AdminPanelProvider:110` registers the vendor plugin; app `config/filament-rewards.php` supplies navigation, `staff_roles` (`:38`), owners (`:46-52`). Policies are app-owned (`AppServiceProvider:684-692`), plus fraud gate `:700-702`.
- MCP: generic admin tools can list/read reward resources through Filament, but writes are denied by the `AdminResourceMutationService::supports()` allowlist (`:100-115`). No reward-specific MCP tool exists.
- Console: `rewards:dispatch-gratitude-digests` (app), `rewards:apply-source-corrections` and `rewards:expire-point-lots` (package). Communications C1 removed the app `communications:send-digests` batch stub and schedules package `communications:dispatch-due` from the Communications feature provider. Demo/seed command `ActivateLocalShareExamples` writes `reward_programs` / `reward_benefit_items` through package `CreateRewardProgramAction` / `CreateRewardBenefitItemAction` / `PublishRewardProgramVersionAction` / `TransitionRewardProgramStatusAction`; `PublishCommunityReleaseCommand` targets the ShareTracking `community_releases` table and only reuses the reward unique-violation helper.

---

## 4. Hazards

Severity reflects migration risk, not a request to change behavior.

| # | Severity | Hazard | Evidence |
|---|---|---|---|
| H1 | High | Digest bookkeeping runs **inside** award/badge/milestone transactions. A digest-side failure (notification-preference creation/read failure, DB error, lock timeout) can abort an approval/reversal; the reverse path calls `cancelPendingFor` with no `try/catch`. | `TransitionRewardAwardAction:346-364`; `EvaluateRewardEntitlementAction:1042-1050`; `GrantRewardBadgeAction:156-162`; `RecordRewardMilestoneContributionAction:560-570,625-637`; Plan throws refusal-style `InvalidArgumentException` at `:60-94`; package callers catch only that class. |
| H2 | High | **Lock-order inversion**: the send job locks delivery → communication (`Features\Communications\Support\Delivery\GuardedDeliverySend`), while cancel locks communication → deliveries (`CancelRewardDigestAction`) and reconcile locks slot → communication → deliveries (`DispatchDueRewardDigestsAction`). A send vs cancel/reconcile race can deadlock on (communication, delivery). Dispatch/reconcile have no deadlock retry or per-slot catch, so one abort can end the hourly run mid-batch. | Current code paths; `DispatchDueRewardDigestsAction::handle()` loops without `try`. |
| H3 | Resolved in Rw2b | The three `reward_digest_slots` writers were root `App\Actions\Rewards` classes. They now live in `Features\Rewards\Actions\GratitudeDigests`, use `Support\RewardsModel`, and expose scalar feature APIs; the architecture scan passes. | `tools/architecture-check.php`; `tests/Unit/Platform/FeatureCatalogTest.php`. |
| H4 | High | `EvaluatePreclaimShareOutcomesAction` scans an unbounded receipt lookback and dispatches one job per role per receipt **synchronously on the enrollment HTTP/page path**, with no chunk/limit. Large windows can enqueue thousands of jobs inside a request. | `:50-121`. |
| H5 | Medium-High | `withoutOverlapping()` uses the default 1440-minute lock. A crashed/killed digest run can suppress the next 24 hourly runs. | `routes/console.php:28-32`. |
| H6 | Medium-High | Referral points fulfillment runs reward evaluation **synchronously inside a transaction** and requires exactly one live award; any digest/bookkeeping failure inside that unit can fail referral fulfillment (reward marked Failed). | `RewardsPointsFulfillment.php:84-112`. |
| H7 | Medium | Digest delivery time and weekly day are **global config**, not user preferences, while the plan docblock says “the user’s digest delivery time”. Per-user notification settings cannot change the send time. | `NotificationSettingsManager:317-318`; `config/notification-center.php:7-8`; `Plan...:97-106`. |
| H8 | Medium | Timezone/`local_day`/`due_at` are frozen at plan time; a later timezone change never re-days the slot. The 20-hour calmness floor can push a daily digest from the configured morning to late afternoon/evening of the same target local day (it cannot cross into a later day for daily cadence; weekly can only shift within its target day). | `Plan...:96-114`; model guard `:74-87`. |
| H9 | Medium | One digest slot merges awards/badges/milestones from **all programs/owners** for the recipient; the table has no owner column and no per-owner branding/sender. Owner mode is disabled by default, but enabling it will need a decision. | migration `:23-42`; `Plan...:210-239`. |
| H10 | Medium | Communications `idempotency_key` is only indexed, not unique. `adoptOrCreateCommunication` adopts the latest sendable row, otherwise inserts a new one with the same key; duplicate communication rows are possible and reconcile keys off `slot.communication_id`. | communications migration `:30`; `Dispatch:201-254`. |
| H11 | Medium | Source verification is duplicated between the activity and share-attribution readers. The purchaser test differs: activity uses `(float) total_amount > 0`, share-attribution uses `(int) total_amount > 0`; fractional/string amounts can yield different `Purchaser` roles. | `ActivityRewardSourceFactReader:454-459`; `ShareAttributionRewardSourceFactReader:305-311`. |
| H12 | Medium | Reward-source readers import root models/services directly: `Registration`, `EventCheckin`, `EventSubmission`, `ShareOutcomeCorrection`, `ShareSourceAttributionReceipt`, `SavedSearchReader`, `EngagementReader`, affiliates/referral package models. They are root read adapters today; moving them into a feature is blocked until those owners expose read APIs. | `Activity...:14-21,282-304`; `ShareAttribution...:22-33`; `Referral...:7-24`. |
| H13 | Medium | `ShareCorrectionKeySource::taintedKeys` scans all live awards, hashes each business key, and joins affiliates fraud signals; `causeFor` repeats queries per key. Sweep cost grows with award history; it also reads another feature’s correction/receipt tables directly. | `:28-224`. |
| H14 | Medium | `reward_digest_slots` has **no retention policy** and model deletes are forbidden; one row per recipient per day accumulates forever. There is no prune command. | model `:103-105`; no retention command found. |
| H15 | Medium | Ambiguous digest mail is operator-only with no alert/metric; a stuck `Sending` slot blocks that day's digest indefinitely. The resolution command is not digest-scoped — any `Sending` delivery with an unresolved attempt can be resolved. | `ResolveDigestMailAmbiguityAction:78-101`; command `:25-124`; no alert found. |
| H16 | Medium | Livewire redemption passes a fresh `Str::uuid()` as `redemptionKey` on every click, so double-click/retry creates a new grant key (bounded only by stock/points). The API requires a caller-supplied idempotency key. | `RewardMemberIndex:225-239`; `RedeemRewardBenefitAction:84-88`; API `RewardBenefitController:76-90`. |
| H17 | Medium | API enrollment calls the package action directly and records no `rewards.program_enrolled` signal; Livewire/dashboard enrollment records it only on first active enrollment. Telemetry differs by surface. | `RewardEnrollmentController:44-55`; `EnrollRewardProgramMemberAction:32-62`; `ProductSignalSchemaRegistry:19`. |
| H18 | Low-Medium | Staff role asymmetry: `filament-rewards.staff_roles` is `super_admin,admin`, while the `reward-fraud-steward` gate also allows `moderator`. Moderators can be authorized by the gate but the plugin's `staffActor()` rejects them. | `config/filament-rewards.php:38`; `AppServiceProvider:700-702`; `RewardAdminPresenter:204-230`. |
| H19 | Low-Medium | Admin/MCP composition is vendor-only and currently read-only for rewards. Any future addition to the admin write allowlist must route through package actions; a generic model save would bypass ledger/cap/claim semantics. | `AdminResourceMutationService:100-115`; `FilamentRewardsPlugin:38-58`. |
| H20 | Low | `CreateRewardProgramAction::isUniqueViolation` is reused as a generic SQLSTATE helper by digest planning and ShareTracking community releases; it also treats any message containing “unique/duplicate” as a unique violation. | `CreateRewardProgramAction:112-122`; `Plan...:140`; `PublishCommunityReleaseAction:153`. |
| H21 | Low | **Resolved in Communications C1:** the misleading `communications:send-digests` stub and schedule were removed. The package `communications:dispatch-due` command is now scheduled every minute and processes due Communication aggregates; batch lifecycle remains untouched. | `app/Features/Communications/FeatureServiceProvider.php`; `tasks/communications-map.md` C1. |
| H22 | Low | `App\Support\Rewards\RewardPayoutMethods` re-derives the configured method set and labels independently from the package `ManualRewardPayoutMethodCatalog` (which reports registered/manual/supported flags, not labels); member methods and package readiness can drift if either side adds filtering. | app helper `:19-34`; package catalog `:24-59`; binding `AppServiceProvider:487`. |
| H23 | Low | `RewardMemberPresenter::outcomeLabel` omits `referral_qualified`, so member award detail falls back to the raw outcome code for referral awards. | `:328-344`. |
| H24 | Low | Planner naming/payload drift: the package contract calls the argument `$channels` while the app treats it as digest categories; `digestPayload` hardcodes `recipient_type='user'` although `recipient_types` is configurable. | `RewardGratitudePlanner:19-29`; `Plan...:251-260`. |
| H25 | Low | Reward read surfaces are uncached and duplicated: API controllers, Livewire page, dashboard prompt, and MCP each re-query package tables with their own recipient/status filters. No reward cache or projection exists today; a future cache would have no package-write invalidation hook (no reward model observers are registered). | `RewardAwardController`, `RewardBalanceController`, `RewardMemberIndex`, `UserDashboard:94-149`; `AppServiceProvider` has no reward model observers. |

---

## 5. Proposed bounded slices

Order: characteristics first; no table claim until all writers are feature-local.

### Rw0 — claim-free seams + characterization (no production behavior change)

**Scope**
- Create `app/Features/Rewards` with `FeatureServiceProvider`, `feature.json` `{"schema":2,"name":"Rewards","owns":{}}`, register once in `bootstrap/providers.php` (or the established provider discovery path).
- Add literal `protected $table = 'reward_digest_slots';` to `app/Models/RewardDigestSlot.php` (root stays root; no move). Document the model as a root model resolved through the future morph alias.
- Add characterization tests (new files only; do not delete existing):
  - model guards: frozen columns, transition matrix, delete refusal;
  - weekly cadence + global delivery time/day; calmness floor; timezone `local_day` bucketing; replan of sent/cancelled;
  - cross-category merge across programs (current behavior, H9);
  - plan/cancel failure isolation from award transitions (H1 characterization, expected current behavior);
  - API vs Livewire enrollment signal parity (H17, expected current difference);
  - admin/MCP: `AdminResourceMutationService::supports()` is false for every reward resource (H19).
- Document in the feature provider docblock which root adapters are intentional and why: `DigestRewardGratitudePlanner` and `ResolveDigestMailAmbiguityCommand`, `RewardSourceFactReader` composite + three readers, `RewardMemberPresenter`, `RewardPayoutMethods`, Vouchers fulfillment, policies, and transport.

**Stays root/package:** everything; no claims; package actions unchanged.
**Evidence:** existing 234 reward cases + new focused cases; `php tools/architecture-check.php` 0/17; routes identical (738); no schema/migration change.
**Holds:** no claim, no model move, no behavior change; keep `reward_digest_slots` in the historical share migration.

### Rw1 — digest-slot ownership (`reward_digest_slots`)

**Scope**
- Introduce the minimum host seam needed to keep writers feature-local (narrower than a transport port; package communications classes remain directly usable from feature code):
  - `Shared\Communications\DigestPolicy` is implemented by Communications' `NotificationCenterDigestPolicy` over the `AccountNotificationPreferences` API: enabled, scalar cadence, timezone, delivery time, weekly day, quiet hours, and the trigger key. No Communications enum crosses into Rewards.
  - At Rw1, `Shared\Communications\DigestMessages` built the notification through a root factory. C4 removed that single-consumer Shared contract; Rewards now injects its feature-local `ShareGratitudeDigestMessages` service directly.
  - Recipient resolution uses `Shared\Auth\AccountDirectory::resolveNotifiable()` / `AccountActor`, not `App\Models\User`.
- Keep `RewardDigestSlot` in `app/Models` with literal `$table`, register `reward_digest_slot` morph alias + feature `Support\RewardsModel` resolver, and move only the three actions → `Features\Rewards\Actions\GratitudeDigests`. Preserve exact statuses, transactions, locks, retry bounds, payload shape, plan key, calmness floor, merge semantics. The feature calls package `DispatchManagedNotificationAction`, `CancelCommunicationAction`, `DispatchManagedNotificationJob`, `Communication`, and `CommunicationDelivery` directly (package imports are allowed).
- Add `Features\Rewards\Api\GratitudeDigest` (interface) with plan/cancel/dispatch entrypoints returning scalars/void only (no model crosses); feature provider binds it; root `DigestRewardGratitudePlanner` becomes a thin package-contract adapter calling the Api (root→feature Api is allowed; root→feature private is not). `DispatchRewardDigestsCommand` calls the Api.
- Claim `table:reward_digest_slots` with exactly the three feature writer classes. Keep `ResolveDigestMailAmbiguityCommand` at root as a CLI adapter; the action stays in Rewards and delegates Communications mutations to `DeliveryFencing`.
- Do not change the lock order in this slice. Record H2 as a preserved hazard; if the deadlock is observed in the race suite, fix it in a separate, characterized change (delivery-first ordering everywhere) rather than silently reordering.

**Stays package-canonical:** Communications tables/models/actions. App channels and delivery fencing now live in Communications; digest notification intent and ambiguity action live in Rewards; only the ambiguity command remains root as a CLI adapter.
**Evidence:** `RewardDigestTest` 18, `RewardDigestRaceTest` 5 (real drivers), `RewardDigestMailAmbiguityTest` 5 (mail path unchanged), `RewardDigestMailResolveCommandTest` 5, digest case in `RewardDeliveryTest`; plan/merge/cancel/status assertions; architecture 0/17; feature manifest validation; routes identical; final-pass ownership PHPStan 0.
**Holds:** no schema change, no timing/retention change, no communications behavior change, no deadlock fix without separate characterization.

### Rw2 — enrollment, acceptance, preclaim, source-fact normalization

**Scope**
- Add `ShareTracking\Api\SourceReceiptReader` (window + keys + immutable snapshots) because `EvaluatePreclaimShareOutcomesAction` currently reads the ShareTracking table/model directly; add it only with this consumer.
- Use `Shared\Auth\AccountActor`/`AccountDirectory` (already implemented by `User`) instead of root `User` in feature code.
- Move `EnrollRewardProgramMemberAction` and `EvaluatePreclaimShareOutcomesAction` into `Features\Rewards\Actions`; expose `Api\MemberEnrollment` (`enroll`, `leave`, `reaccept`, `preclaim`) returning scalar ids/status/flags only. Route API controller and Livewire page through the Api; keep transport (`app/Http`, `app/Livewire`) root until Rw4. Preserve `rewards.program_enrolled` behavior and add the API path to it (or record the decision in §6).
- Extract one shared source-fact mapping value/service so the activity and share-attribution readers use one verification/purchaser/role mapping; fix H11 deliberately (align to the package’s integer minor-unit contract) only with a failing-first characterization test.
- Keep the three fact readers root as documented read adapters: they depend on root Events/Registration/SavedSearches/Engagement/ShareTracking models and package affiliate/referral models; moving them would require those owners’ read APIs first.
- `owns: {}` unchanged.

**Stays root/package:** source readers, package evaluation/claims/awards, ShareTracking receipt writer/observer, referral package fulfillment, notification policy.
**Evidence:** `RewardEnrollmentPromptTest` 4, `RewardMemberApiTest` enrollment journey (48 total), `RewardMemberWebTest` 5, `RewardActivityFactsTest` 12, `RewardHostAdapterTest` 11, `RewardEventWiringTest` 6, `RewardSourceCorrectionTest` 5, `RewardModeJourneyTest` 6; Signal assertions; architecture 0/17; routes identical.
**Holds:** no change to referral qualification/points, no change to award/claim semantics, no reader move, no new job fan-out beyond existing behavior (H4 recorded as a performance ticket).

### Rw3 — member awards, benefits, payouts, reconciliation read surfaces

**Scope**
- Add `Features\Rewards\Api\MemberRewards` returning immutable arrays/pagination for catalog, awards, appeal detail, balances, badges, payees, payouts, benefit grants, and the payout funds state.
- Move `RewardMemberPresenter` and `RewardPayoutMethods` into the feature (they depend only on package models/enums/config + Carbon). Root controllers/Livewire call the Api; keep controllers/pages root until Rw4.
- Keep every money/points write with package actions. Document `Request/Approve/Submit/Confirm/Reconcile/Cancel` payout, recovery, funding, budget, points, and benefit actions as canonical external writers; add explicit root-adapter docblocks.
- Add an explicit test that no app code writes a package reward table and that generic admin MCP write support remains false for all reward resources.
- `owns: {}` unchanged.

**Stays root/package:** all package ledger/money/points tables and actions; policies stay root (they type-hint `User`); Filament UI stays vendor; funding readiness bindings.
**Evidence:** `RewardMemberApiTest` 48, `RewardMemberPayeeTest` 7, `RewardBenefitTest` 7, `RewardBenefitApiTest` 1, `RewardBenefitIdentityTest` 11, `RewardFinanceTest` 3, `RewardFinanceRaceTest` 9, `RewardFundingAuthorityTest` 10, `RewardFundingRaceTest` 3, `RewardPayoutLifecycleTest` 2, `RewardPayoutRaceTest` 5, `RewardFinanceAdminTest` 3, `RewardAdminTest` 4, `RewardAdminOperabilityTest` 6, `RewardProgramAdminLifecycleTest` 1, `RewardSurfaceRenderTest` 1; read parity (redaction, thirty-day impact bounds); architecture 0/17; routes identical.
**Holds:** no package-table claim, no payout/points/funding/recovery behavior change, no policy move.

### Rw4 — transport, admin/MCP/docs composition, final audit

**Scope**
- Move reward HTTP controllers, Livewire page, route registrations, and route names/middleware into `Features\Rewards` `EntryPoints`/`Routes`; preserve aliases, middleware order, payloads/status, Scramble groups/endpoints. Remove root registrations in the same change.
- Keep policies root unless refactored to `AccountActor` (feature policies importing `User` would violate `feature-external`). Vendor Filament stays vendor; document it as an external writer of package tables. No new app Filament resources.
- MCP: no reward-specific tools; add documentation/contract test that reward resources are read-only through the generic admin API and that any future write must call package actions.
- Docs: correct digest-time docblocks (global config), planner `$channels` naming, H23 label gap, H10 idempotency note; update the active migration ledger and AGENTS notes.
- Final pass (only after all slices): PHPStan level 6 + owned-write rule, Pint, scoped Rector, full Pest and real-driver groups, route snapshots cached/uncached, architecture scanner.

**Stays root/package:** package actions/tables, communications/notifications, vendor Filament, and the ambiguity command (until its Communications slice).
**Evidence:** route inventory byte-identical or intentionally aliased; docs/Scramble inventory; full reward suite 234+; architecture 0/17; ownership PHPStan 0; Pint/Rector final.
**Holds:** no behavior change in transport cutover; no new claims beyond `reward_digest_slots`; no Communications/AI/community-release work folded in.

---

## 6. Genuine product decisions (only these)

1. **Per-user digest time/day**: keep the global `notification-center.defaults.digest_delivery_time` / `digest_weekly_day`, or expose them as user preferences? Current docs imply per-user; the code is global (H7).
2. **Cross-owner digest aggregation**: when owner mode is enabled, should one daily digest merge awards from multiple programs/owners (H9), or should each owner get a separate sender/template (requires an owner column and a different unique key)?
3. **Calmness floor semantics**: accept that the 20-hour floor can move a daily digest from 08:00 to late afternoon/evening on the target day (H8), or send at the configured delivery time even when it is <20h away?
4. **Digest-slot retention**: keep permanent history with delete forbidden (H14), or add a bounded retention/prune policy (e.g. keep N days of sent/cancelled slots)?
5. **API enrollment telemetry**: record `rewards.program_enrolled` on API enrollment to match Livewire/dashboard (H17), or document API as intentionally untracked?

## 7. Holds and unverified items

- Do not claim any of the 30 package tables; do not add app reward tables.
- Do not move reward-source readers until Events/Registration/SavedSearches/Engagement/ShareTracking/Referrals expose the read contracts they need.
- Do not change digest merge/dedup, status truthfulness, retry/escape bounds, quiet-hours, mail-ambiguity holds, or money/points semantics in a migration slice.
- Do not touch the historical `2026_10_08_040001_create_share_delivery_tables.php` grouping.
- Not verified here: runtime UI behavior (read-only static analysis), real-driver deadlock reproduction (existing race tests should be treated as the baseline, not proof of H2 absence), production queue/Horizon behavior, and the exact performance cost of preclaim fan-out.

---

## Appendix A — package writer index (file:line)

Primary Eloquent write sites (`::query()->create`, `forceFill`, `save`) for the 54 canonical writer classes. Paths are relative to `../commerce/packages/rewards/`. The package has **no delete writers**; app code has no package-table deletes. `ApplyRewardSourceCorrectionsAction`, `PreviewRewardQualificationAction` and `ReverseRewardFulfillmentAction` have no direct writes (delegation/read) and are intentionally absent.

| File | Write lines |
|---|---|
| `src/Actions/AcknowledgeRewardRecognitionAction.php` | 70 |
| `src/Actions/AdvanceRewardAppealAction.php` | 152, 155 |
| `src/Actions/AppendRewardAdjustmentAction.php` | 169, 253, 259 |
| `src/Actions/ApproveRewardPayoutAction.php` | 99, 103, 110, 114 |
| `src/Actions/CancelRewardBenefitGrantAction.php` | 102, 106 |
| `src/Actions/CancelRewardPayoutAction.php` | 221, 224 |
| `src/Actions/ClawBackAwardPointsAction.php` | 179, 199, 202, 339 |
| `src/Actions/ConfirmRewardPayoutAction.php` | 178, 184 |
| `src/Actions/ConsumeRewardBenefitAction.php` | 97, 101 |
| `src/Actions/CreateRewardBenefitItemAction.php` | 119 |
| `src/Actions/CreateRewardProgramAction.php` | 63 |
| `src/Actions/CreditRewardPointsAction.php` | 213, 231 |
| `src/Actions/DebitRewardPointsAction.php` | 241, 369, 466, 469 |
| `src/Actions/DismissRewardFraudAction.php` | 91 |
| `src/Actions/EnrollRewardMemberAction.php` | 106, 132, 139, 159, 175, 182, 185, 205 |
| `src/Actions/EvaluateRewardEntitlementAction.php` | 219, 604, 736, 754, 814, 879, 1006 |
| `src/Actions/ExpireRewardBenefitGrantAction.php` | 100, 103 |
| `src/Actions/ExpireRewardPointsAction.php` | 147, 152, 170, 175 |
| `src/Actions/FulfillRewardBenefitAction.php` | 117, 122 |
| `src/Actions/GrantRewardBadgeAction.php` | 134 |
| `src/Actions/InvalidateRewardMilestoneContributionAction.php` | 87, 90, 101, 143, 146 |
| `src/Actions/LeaveRewardProgramAction.php` | 74, 77, 93, 96 |
| `src/Actions/MarkRewardReservationSubmittedAction.php` | 83, 108, 112, 117, 120 |
| `src/Actions/OpenRewardAppealAction.php` | 110 |
| `src/Actions/OpenRewardRecoveryAction.php` | 158 |
| `src/Actions/PublishRewardProgramVersionAction.php` | 122, 135, 147 |
| `src/Actions/ReacceptRewardTermsAction.php` | 93, 109 |
| `src/Actions/ReconcileRewardPayoutAction.php` | 166, 171, 225, 233 |
| `src/Actions/RecordRewardBenefitRecallAction.php` | 83 |
| `src/Actions/RecordRewardFundingAction.php` | 137 |
| `src/Actions/RecordRewardFundingSourceAction.php` | 88 |
| `src/Actions/RecordRewardMilestoneContributionAction.php` | 486, 525, 544, 562, 566, 577, 629, 633 |
| `src/Actions/RedeemRewardBenefitAction.php` | 250 |
| `src/Actions/RefundRewardPointsAction.php` | 388, 410 |
| `src/Actions/RegisterRewardPayeeAction.php` | 103, 106, 109 |
| `src/Actions/RejectRewardPayeeAction.php` | 64, 68 |
| `src/Actions/ReleaseRewardPointsAction.php` | 158, 174 |
| `src/Actions/RequestRewardPayoutAction.php` | 234, 395, 400 |
| `src/Actions/ReserveRewardPointsAction.php` | 236 |
| `src/Actions/RetireRewardBenefitItemAction.php` | 58, 61 |
| `src/Actions/RevokeRewardBadgeAction.php` | 78, 84 |
| `src/Actions/RevokeRewardBenefitGrantAction.php` | 102, 106 |
| `src/Actions/SettleRewardRecoveryAction.php` | 137, 141 |
| `src/Actions/SubmitRewardPayoutAction.php` | 164, 169, 172, 179, 206, 214, 218, 246, 282 |
| `src/Actions/TransitionRewardAwardAction.php` | 324, 327, 1053, 1181, 1199, 1202 |
| `src/Actions/TransitionRewardProgramStatusAction.php` | 80, 83 |
| `src/Actions/VerifyRewardPayeeAction.php` | 118, 121, 124 |
| `src/Actions/WriteOffRewardRecoveryAction.php` | 65, 69 |
| `src/Support/RewardApprovalLocks.php` | 34 |
| `src/Support/RewardBenefitCatalogService.php` | 147 |
| `src/Support/RewardBenefitStockLedger.php` | 120 |
| `src/Support/RewardBudgetLedger.php` | 251 |
| `src/Support/RewardLedgerEntries.php` | 103, 138 |
| `src/Support/RewardPointOperationAuthority.php` | 51 |
