<?php

declare(strict_types=1);

namespace App\Features\Rewards;

use AIArmada\Rewards\Contracts\RewardBenefitCatalog;
use AIArmada\Rewards\Contracts\RewardBenefitCouponFulfillment;
use AIArmada\Rewards\Contracts\RewardFinancialReadinessChecker;
use AIArmada\Rewards\Contracts\RewardGratitudePlanner;
use AIArmada\Rewards\Contracts\RewardReadinessChecker;
use AIArmada\Rewards\Contracts\RewardReservationResolver;
use AIArmada\Rewards\Models\RewardAppeal;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBenefitGrant;
use AIArmada\Rewards\Models\RewardBenefitItem;
use AIArmada\Rewards\Models\RewardFundingEntry;
use AIArmada\Rewards\Models\RewardPayee;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardRecovery;
use AIArmada\Rewards\Support\FundedRewardFinancialReadinessChecker;
use AIArmada\Rewards\Support\FundedRewardReadinessChecker;
use AIArmada\Rewards\Support\PointLedgerRewardReservationResolver;
use AIArmada\Rewards\Support\RewardBenefitCatalogService;
use App\Features\Rewards\Actions\Enrollment\EnrollRewardProgramMemberAction;
use App\Features\Rewards\Actions\Enrollment\EvaluatePreclaimShareOutcomesAction;
use App\Features\Rewards\Actions\GratitudeDigests\GratitudeDigestAction;
use App\Features\Rewards\Api\GratitudeDigest;
use App\Features\Rewards\Api\MemberEnrollment;
use App\Features\Rewards\Policies\RewardAppealPolicy;
use App\Features\Rewards\Policies\RewardAwardPolicy;
use App\Features\Rewards\Policies\RewardBenefitGrantPolicy;
use App\Features\Rewards\Policies\RewardBenefitItemPolicy;
use App\Features\Rewards\Policies\RewardFundingEntryPolicy;
use App\Features\Rewards\Policies\RewardPayeePolicy;
use App\Features\Rewards\Policies\RewardPayoutRequestPolicy;
use App\Features\Rewards\Policies\RewardProgramPolicy;
use App\Features\Rewards\Policies\RewardRecoveryPolicy;
use App\Features\Rewards\Support\DigestRewardGratitudePlanner;
use App\Features\Rewards\Support\PublishedProgramVersionsAdapter;
use App\Features\Rewards\Support\VouchersRewardBenefitCouponFulfillment;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountActor;
use App\Shared\Rewards\PublishedProgramVersions;
use Illuminate\Support\Facades\Gate;

/**
 * Rewards owns `reward_digest_slots` (Rw1) through exactly three named writers:
 * `PlanRewardGratitudeDigestAction`, `DispatchDueRewardDigestsAction` and
 * `CancelRewardDigestAction` under `Actions\GratitudeDigests`.
 *
 * The slot model stays root (`App\Features\Rewards\Models\RewardDigestSlot`) with a literal
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
 *   dispatch entrypoints returning `Api\GratitudeDigestSnapshot`; the
 *   feature-local `DigestRewardGratitudePlanner` adapts the package contract,
 *   and `DispatchRewardDigestsCommand` calls the Api.
 * - Published-version resolution and Vouchers-backed coupon fulfillment are
 *   feature-local integrations registered here.
 *
 * Rw2a adds preclaim evaluation of eligible prior outcomes behind
 * `Api\MemberEnrollment`; Rw2b moves enrollment, leave and reacceptance into
 * `Actions\Enrollment`. The Api is
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
 * Active integration points:
 * - `ResolveDigestMailAmbiguityCommand` stays a root transport adapter and
 *   delegates settlement to Communications after verifying the operator.
 * - `CompositeRewardSourceFactReader`, `ActivityRewardSourceFactReader`,
 *   `ShareAttributionRewardSourceFactReader`, `ReferralRewardSourceFactReader`
 *   and `ShareCorrectionKeySource` remain active root integration adapters. They
 *   read canonical records still owned by other extracted and unextracted
 *   domains. Root composition binds the package contracts; Rewards imports no
 *   root implementation classes.
 * - Member presentation, HTTP endpoints, Livewire page, view, and API/web
 *   routes are feature-owned. They read package models without caching and
 *   route all package writes through package Actions. The broad member reads
 *   need no separate feature Api while every caller is inside Rewards.
 * - The general dashboard's enrollment prompt remains root composition: it
 *   combines reward eligibility with cross-feature banked activity facts and
 *   performs enrollment through `Api\MemberEnrollment`.
 * - `VouchersRewardBenefitCouponFulfillment` is feature-local and writes
 *   Vouchers package tables through Vouchers actions.
 * - The hourly Rewards schedule, ShareTracking observers, reversal job, and
 *   ambiguity command remain active external integrations. Vendor Filament remains package-owned, while
 *   generic admin API/MCP exposes reward resources read-only; any future
 *   reward mutation must go through an AIArmada\Rewards Action.
 * - Digest communications idempotency keys are indexed but not unique.
 *   Duplicate Communication rows remain possible; reconciliation uses the
 *   slot's `communication_id`, preserving H10 without implying uniqueness.
 *
 * The package planner names its achievement categories `$channels`; Rewards
 * maps those values directly to the fixed digest categories, not delivery
 * channels.
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
        $this->commands([
            EntryPoints\Console\Commands\DispatchRewardDigestsCommand::class,
        ]);

        $this->app->bind(GratitudeDigest::class, GratitudeDigestAction::class);
        $this->app->bind(MemberEnrollment::class, EnrollRewardProgramMemberAction::class);
        $this->app->bind(PublishedProgramVersions::class, PublishedProgramVersionsAdapter::class);

        $this->app->singleton(RewardReservationResolver::class, PointLedgerRewardReservationResolver::class);
        $this->app->singleton(RewardReadinessChecker::class, FundedRewardReadinessChecker::class);
        $this->app->singleton(RewardFinancialReadinessChecker::class, FundedRewardFinancialReadinessChecker::class);
        $this->app->singleton(RewardBenefitCatalog::class, RewardBenefitCatalogService::class);
        $this->app->singleton(RewardBenefitCouponFulfillment::class, VouchersRewardBenefitCouponFulfillment::class);
        $this->app->singleton(RewardGratitudePlanner::class, DigestRewardGratitudePlanner::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(RewardAppeal::class, RewardAppealPolicy::class);
        Gate::policy(RewardAward::class, RewardAwardPolicy::class);
        Gate::policy(RewardBenefitGrant::class, RewardBenefitGrantPolicy::class);
        Gate::policy(RewardBenefitItem::class, RewardBenefitItemPolicy::class);
        Gate::policy(RewardFundingEntry::class, RewardFundingEntryPolicy::class);
        Gate::policy(RewardPayee::class, RewardPayeePolicy::class);
        Gate::policy(RewardPayoutRequest::class, RewardPayoutRequestPolicy::class);
        Gate::policy(RewardProgram::class, RewardProgramPolicy::class);
        Gate::policy(RewardRecovery::class, RewardRecoveryPolicy::class);

        // Fraud stewardship is authorized separately from award approval.
        Gate::define('reward-fraud-steward', static fn (mixed $actor): bool => $actor instanceof AccountActor
            && $actor->hasAnyRole(['super_admin', 'moderator']));
    }
}
