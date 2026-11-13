<?php

declare(strict_types=1);

namespace App\Features\Rewards;

use App\Features\Rewards\Actions\GratitudeDigests\GratitudeDigestAction;
use App\Features\Rewards\Api\GratitudeDigest;
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
 * Shared seams keep the feature free of root notification services and users:
 * - `Shared\Communications\DigestPolicy` with the root
 *   `NotificationCenterDigestPolicy` adapter over
 *   `NotificationSettingsManager`/`ResolvedNotificationPolicy` and the global
 *   digest config; only scalar cadence, trigger, timezone, delivery time,
 *   weekly day and quiet hours cross.
 * - `Shared\Communications\DigestMessages` with the root
 *   `ShareGratitudeDigestMessages` adapter resolving recipients through
 *   `Shared\Auth\AccountDirectory` and building the existing
 *   `ShareGratitudeDigestNotification`.
 * - `Api\GratitudeDigest` exposes scalar plan, cancel, cancel-pending and
 *   dispatch entrypoints returning `Api\GratitudeDigestSnapshot`; the root
 *   `DigestRewardGratitudePlanner` is the package-contract adapter and
 *   `DispatchRewardDigestsCommand` calls the Api.
 *
 * The 30 package-canonical `reward_*` tables stay explicit `owns: {}` holds:
 * `AIArmada\Rewards` actions/support helpers are their canonical external
 * writers, and app code performs zero direct writes to them.
 *
 * Intentional root adapters/holds documented for later slices:
 * - Mail ambiguity: `ResolveDigestMailAmbiguityAction` and
 *   `ResolveDigestMailAmbiguityCommand` stay root; they write communications,
 *   never digest slots.
 * - Notifications/channels/communications: `ShareGratitudeDigestNotification`,
 *   `DigestMailChannel`, `InboxChannel` and `GuardedDeliverySend` stay root;
 *   the `DigestMessages` adapter only builds the existing notification.
 * - Source facts: `CompositeRewardSourceFactReader` plus
 *   `ActivityRewardSourceFactReader`, `ShareAttributionRewardSourceFactReader`
 *   and `ReferralRewardSourceFactReader` stay root read adapters until the
 *   Events/Registration/SavedSearches/Engagement/ShareTracking/referrals
 *   owners expose read contracts.
 * - Member reads: `RewardMemberPresenter` and `RewardPayoutMethods` stay root
 *   catalog/presentation adapters over package models and config.
 * - Voucher fulfillment: `VouchersRewardBenefitCouponFulfillment` stays root
 *   and writes Vouchers package tables through Vouchers actions.
 * - Policies: the nine `App\Policies\Reward*Policy` classes stay root because
 *   they type-hint `App\Models\User`.
 * - Commands/schedules/transport: `SendDigestNotificationsCommand` (stub), the
 *   hourly schedule, API controllers, Livewire pages, observers/jobs and route
 *   registrations stay root until Rw4.
 *
 * Lock order, H1 coupling (plan/cancel inside award transactions, including
 * reversal with no try/catch), H2 delivery/communication inversion, status
 * transitions, 20-hour calmness, merge/dedup, retries/escapes and payload shape
 * are preserved. No schema, retention, timing or communications behavior
 * changes happen in this slice.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(GratitudeDigest::class, GratitudeDigestAction::class);
    }
}
