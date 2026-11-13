<?php

declare(strict_types=1);

namespace App\Features\Rewards;

use App\Platform\FeatureModule;

/**
 * Rewards/digests claim-free scaffold (Rw0).
 *
 * This feature currently owns no table or store (`owns: {}`) and has no Api.
 * The single app-owned reward table, `reward_digest_slots`, remains
 * unclaimed until Rw1 moves its three writers (`PlanRewardGratitudeDigestAction`,
 * `DispatchDueRewardDigestsAction`, `CancelRewardDigestAction`) and adds the
 * Shared digest seams. The root `App\Models\RewardDigestSlot` keeps a literal
 * `$table` so ownership survives a later move; no morph alias exists yet.
 *
 * The 30 package-canonical `reward_*` tables are explicitly held as
 * `owns: {}`: `AIArmada\Rewards` actions/support helpers are their canonical
 * external writers, and app code performs zero direct writes to them.
 *
 * Intentional root adapters/holds documented for later slices:
 * - Digest planner adapter: `DigestRewardGratitudePlanner` binds the package
 *   `RewardGratitudePlanner` contract to the three root digest actions above;
 *   it runs inside award/badge/milestone transactions exactly as today.
 * - Mail ambiguity: `ResolveDigestMailAmbiguityAction` and
 *   `ResolveDigestMailAmbiguityCommand` stay root; they write communications,
 *   never digest slots.
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
 * - Notifications/channels/communications: `ShareGratitudeDigestNotification`,
 *   `DigestMailChannel`, `InboxChannel` and `GuardedDeliverySend` stay root;
 *   the notification factory and mail-ambiguity boundary move only with the
 *   Communications slice.
 * - Commands/schedules/transport: `DispatchRewardDigestsCommand`,
 *   `SendDigestNotificationsCommand` (stub), the hourly schedule, API
 *   controllers, Livewire pages, observers/jobs and route registrations stay
 *   root until Rw4.
 *
 * No behavior changes, retention/timing/merge/communications/transport
 * changes, package-table exceptions or H1–H25 fixes happen in Rw0.
 */
class FeatureServiceProvider extends FeatureModule {}
