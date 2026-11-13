<?php

declare(strict_types=1);

namespace App\Features\Rewards;

use App\Features\Rewards\Actions\Enrollment\EnrollRewardProgramMemberAction;
use App\Features\Rewards\Actions\Enrollment\EvaluatePreclaimShareOutcomesAction;
use App\Features\Rewards\Actions\GratitudeDigests\GratitudeDigestAction;
use App\Features\Rewards\Api\GratitudeDigest;
use App\Features\Rewards\Api\MemberEnrollment;
use App\Platform\FeatureModule;

/**
 * Rewards owns `reward_digest_slots` (Rw1) through exactly three named writers:
 * `PlanRewardGratitudeDigestAction`, `DispatchDueRewardDigestsAction` and
 * `CancelRewardDigestAction` under `Actions\GratitudeDigests`.
 *
 * The slot model stays root (`App\Models\RewardDigestSlot`) with a literal
 * `$table`; feature code resolves it through the `reward_digest_slot` morph
 * alias and `Support\RewardsModel`, never by importing the root model.
 *
 * Communications integration crosses only its app API:
 * - Rewards resolves policy through AccountNotificationPreferences and builds
 *   immutable message values through `ShareGratitudeDigestMessages`.
 * - Managed communication creation/adoption, delivery snapshots and
 *   cancellation, plus ambiguous external-delivery settlement, use
 *   `ManagedNotificationDelivery`; Rewards never reads package models.
 * - `Api\GratitudeDigest` exposes scalar plan, cancel, cancel-pending and
 *   dispatch entrypoints returning `Api\GratitudeDigestSnapshot`; the root
 *   `DigestRewardGratitudePlanner` is the package-contract adapter and
 *   `DispatchRewardDigestsCommand` calls the Api.
 *
 * Rw2a adds the preclaim backfill behind `Api\MemberEnrollment`; Rw2b moves
 * enrollment, leave and reacceptance into `Actions\Enrollment`. The Api is
 * bound to `EnrollRewardProgramMemberAction`, which resolves the notifiable
 * through `Shared\Auth\AccountDirectory`, authorizes with the existing
 * policies, resolves the published version through
 * `Shared\Rewards\PublishedProgramVersions`, and delegates the post-write
 * preclaim to `EvaluatePreclaimShareOutcomesAction`. Preclaim reads receipts
 * through `ShareTracking\Api\SourceReceiptReader`, resolves facts through the
 * package `RewardSourceFactReader` composite, and still dispatches
 * `EvaluateRewardEntitlementJob` per role `->afterCommit()`.
 *
 * The 30 package-canonical `reward_*` tables stay explicit `owns: {}` holds:
 * `AIArmada\Rewards` actions/support helpers are their canonical external
 * writers, and app code performs zero direct writes to them.
 *
 * Intentional root adapters/holds documented for later slices:
 * - `ResolveDigestMailAmbiguityCommand` stays a root transport adapter and
 *   delegates settlement to Communications after verifying the operator.
 * - Source facts: `CompositeRewardSourceFactReader` plus
 *   `ActivityRewardSourceFactReader`, `ShareAttributionRewardSourceFactReader`
 *   and `ReferralRewardSourceFactReader` stay root read adapters until the
 *   Events/Registration/SavedSearches/Engagement/ShareTracking/referrals
 *   owners expose read contracts.
 * - Member reads: `RewardMemberPresenter` and `RewardPayoutMethods` stay root
 *   read-only presentation adapters over package models and config. API,
 *   Livewire, dashboard-prompt and MCP readers remain independent and uncached;
 *   there is no member reward read projection or cache until a real
 *   cross-feature consumer and immutable snapshot/query-count evidence exist.
 * - Voucher fulfillment: `VouchersRewardBenefitCouponFulfillment` stays root
 *   and writes Vouchers package tables through Vouchers actions.
 * - Policies: the nine `App\Policies\Reward*Policy` classes stay root because
 *   they type-hint `App\Models\User`.
 * - Commands/schedules/transport: the hourly Rewards schedule, API
 *   controllers, Livewire pages, observers/jobs, route registrations, vendor
 *   Filament admin and read-only MCP stay root as transport/composition
 *   adapters until their documented later slices. Communications owns its
 *   package dispatcher schedule.
 *
 * Lock order is Rewards slot → sorted deliveries → communication → attempts;
 * channel sends keep delivery → communication → attempt. H1 coupling
 * (plan/cancel inside award transactions, including reversal with no
 * try/catch), status transitions, 20-hour calmness, merge/dedup,
 * retries/escapes and payload shape are preserved. Communications package
 * tables and schemas stay package-owned.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(GratitudeDigest::class, GratitudeDigestAction::class);
        $this->app->bind(MemberEnrollment::class, EnrollRewardProgramMemberAction::class);
    }
}
