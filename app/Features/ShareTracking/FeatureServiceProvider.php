<?php

declare(strict_types=1);

namespace App\Features\ShareTracking;

use App\Features\ShareTracking\Actions\BrowserSelection\LockBrowserSelectionAction;
use App\Features\ShareTracking\Actions\CommittedSignals\DrainCommittedShareSignalsAction;
use App\Features\ShareTracking\Actions\GuestClaims\ClaimGuestProfileAction;
use App\Features\ShareTracking\Actions\GuestProfiles\DetachTrackedShareLinkAction;
use App\Features\ShareTracking\Actions\GuestProfiles\PruneAbandonedGuestProfilesAction;
use App\Features\ShareTracking\Actions\LandingAttribution\CreateLandingAttributionAction;
use App\Features\ShareTracking\Actions\LandingAttribution\TouchPassiveNavigationAttributionAction;
use App\Features\ShareTracking\Actions\MemberProfiles\EnsureMemberAffiliateProfileAction;
use App\Features\ShareTracking\Actions\OutboundAttribution\CreateOutboundShareAttributionAction;
use App\Features\ShareTracking\Actions\Touchpoints\RecordOutboundShareTouchpointAction;
use App\Features\ShareTracking\Actions\Touchpoints\RecordVisitTouchpointAction;
use App\Features\ShareTracking\Api\BrowserSelectionWriter;
use App\Features\ShareTracking\Api\CommittedShareSignals;
use App\Features\ShareTracking\Api\EffectReceipts;
use App\Features\ShareTracking\Api\FinancialObligationReader;
use App\Features\ShareTracking\Api\GuestProfilePruner;
use App\Features\ShareTracking\Api\GuestShareClaims;
use App\Features\ShareTracking\Api\LandingAttributionWriter;
use App\Features\ShareTracking\Api\MarkedShareAffiliateIds;
use App\Features\ShareTracking\Api\MemberAffiliateProfileWriter;
use App\Features\ShareTracking\Api\OutboundShareAttributionWriter;
use App\Features\ShareTracking\Api\OutboundShareTouchpointWriter;
use App\Features\ShareTracking\Api\OutcomeCorrections;
use App\Features\ShareTracking\Api\PassiveNavigationAttributionWriter;
use App\Features\ShareTracking\Api\ShareOutcomeEvidenceReader;
use App\Features\ShareTracking\Api\SourceAttributionReceipts;
use App\Features\ShareTracking\Api\SourceReceiptReader;
use App\Features\ShareTracking\Api\TrackedShareLinkDetacher;
use App\Features\ShareTracking\Api\VisitTouchpointWriter;
use App\Features\ShareTracking\Persistence\DatabaseEffectReceipts;
use App\Features\ShareTracking\Persistence\DatabaseOutcomeCorrections;
use App\Features\ShareTracking\Persistence\DatabaseSourceAttributionReceipts;
use App\Features\ShareTracking\Persistence\DatabaseSourceReceiptReader;
use App\Features\ShareTracking\Queries\AffiliateFinancialObligationReader as DatabaseFinancialObligationReader;
use App\Features\ShareTracking\Queries\DatabaseMarkedShareAffiliateIds;
use App\Features\ShareTracking\Queries\DatabaseShareOutcomeEvidenceReader;
use App\Platform\FeatureModule;

/**
 * Share tracking claims all seven app tables named in feature.json: browser
 * selections, source attribution receipts, effect receipts, outcome
 * corrections and the cohort/signal reader tables.
 *
 * Slice order:
 * - S1a source receipts: `DatabaseSourceAttributionReceipts` owns the
 *   freeze-once receipt write; `AffiliatesShareTrackingService` keeps outcome
 *   recording orchestration and reads direct receipt rows for replay.
 * - S1b browser selections/capture: `LockBrowserSelectionAction` owns the
 *   selection lock/create/accept/clear writes while the service keeps
 *   `captureRequest`/`resolveActiveAttribution` orchestration and the unlocked
 *   read used for active-attribution resolution.
 * - S2a1 effect receipts: `DatabaseEffectReceipts` owns freeze-once projection
 *   and ticket-reversal creation, the five-minute worker lease with fencing and
 *   terminal settlement, and cancellation by source. `AffiliatesShareTrackingService`,
 *   `DispatchShareEffectReceiptsAction`, `ReverseShareOutcomeAction` and
 *   `ReverseTicketAffiliateCommissionAction` keep their root orchestration and
 *   package writes.
 * - S2a2 outcome corrections: `DatabaseOutcomeCorrections` owns the idempotent
 *   correction create/replay. `ReverseShareOutcomeAction` keeps the outer
 *   reversal transaction, effect-lock and conversion-reversal order, monetary
 *   guard, and the correction observer dispatch.
 * - S2b guest claims (landed): `ClaimGuestProfileAction` owns the guest claim
 *   orchestration behind `GuestShareClaims`, preserving the users → capability
 *   → guest affiliate → existing claim → recipient member affiliate → claim
 *   insert lock order. Shared `GuestClaims`/`ClaimRecipients` seams keep the
 *   root support and package adapters.
 * - S3 cohorts/signals (landed): `DrainCommittedShareSignalsAction` owns the
 *   committed-share Signals drain and the cohort/signal-reader tables behind
 *   `CommittedShareSignals`, preserving the fenced cursor transaction,
 *   occurrence-ordered backlog sweep, per-event poison memory, idempotent
 *   projection and forward-only cursor. `DrainCommittedShareSignalsCommand`
 *   stays root.
 * - S4a analytics/cleanup (landed): the pass-through `ShareTrackingService`
 *   wrapper and unused null object are deleted; every concrete caller now
 *   depends on `AffiliatesShareTrackingService` directly, while
 *   `ShareTrackingContract` stays the public seam and resolves to that service
 *   as a singleton.
 * - S4b analytics/retention holds (landed): analytics readers stay root
 *   adapters across `Services`, `Services/ShareTracking`, `Support/Share`
 *   and `Support/ShareTracking`; the metric units, 90-day guest-profile
 *   retention and frozen cohort-projection identity/stage rules are guarded
 *   as holds, not migration candidates.
 *
 * ShareTracking migration is complete: all seven app tables are owned and the
 * remaining root surfaces above are intentional adapters, not unclaimed work.
 *
 * Rw2a adds one read seam: `Api\SourceReceiptReader` bound to
 * `Persistence\DatabaseSourceReceiptReader` exposes the ordered share-outcome
 * business keys in an inclusive receipt window for Rewards preclaim evaluation,
 * so that feature no longer imports the receipt model or table. `Api\ShareAnalytics`
 * exposes scalar member and cohort summaries; root composition binds it to the
 * analytics readers that remain root adapters.
 * Community uses `Api\ShareOutcomeEvidenceReader` for aggregate qualified
 * evidence; the immutable result contains only counts and cells. Registration
 * purchaser facts are resolved through Registration's public value API. The
 * Signals cohort projection remains separate and is not release evidence.
 *
 * Must stay root (documented here so later slices do not absorb them): HTTP,
 * Livewire and route entrypoints; DTOs; analytics readers; observers;
 * `ProduceCommittedShareSignalJob`; commands and schedules; account
 * restoration; the checkout provenance step; `SignupAttributionSourceResolver`;
 * other package affiliate models/actions and guest credential/claim primitives;
 * the narrow landing-attribution, passive-navigation, and touchpoint Actions
 * are table-resolver adapters;
 * `ShareTrackingUrlService`/destination policy; and the root package gateway
 * that coordinates ShareTracking operations with Affiliates.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(BrowserSelectionWriter::class, LockBrowserSelectionAction::class);
        $this->app->bind(CommittedShareSignals::class, DrainCommittedShareSignalsAction::class);
        $this->app->bind(EffectReceipts::class, DatabaseEffectReceipts::class);
        $this->app->bind(FinancialObligationReader::class, DatabaseFinancialObligationReader::class);
        $this->app->bind(GuestProfilePruner::class, PruneAbandonedGuestProfilesAction::class);
        $this->app->bind(GuestShareClaims::class, ClaimGuestProfileAction::class);
        $this->app->bind(LandingAttributionWriter::class, CreateLandingAttributionAction::class);
        $this->app->bind(MarkedShareAffiliateIds::class, DatabaseMarkedShareAffiliateIds::class);
        $this->app->bind(MemberAffiliateProfileWriter::class, EnsureMemberAffiliateProfileAction::class);
        $this->app->bind(OutboundShareAttributionWriter::class, CreateOutboundShareAttributionAction::class);
        $this->app->bind(OutcomeCorrections::class, DatabaseOutcomeCorrections::class);
        $this->app->bind(ShareOutcomeEvidenceReader::class, DatabaseShareOutcomeEvidenceReader::class);
        $this->app->bind(SourceAttributionReceipts::class, DatabaseSourceAttributionReceipts::class);
        $this->app->bind(SourceReceiptReader::class, DatabaseSourceReceiptReader::class);
        $this->app->bind(TrackedShareLinkDetacher::class, DetachTrackedShareLinkAction::class);
        $this->app->bind(OutboundShareTouchpointWriter::class, RecordOutboundShareTouchpointAction::class);
        $this->app->bind(PassiveNavigationAttributionWriter::class, TouchPassiveNavigationAttributionAction::class);
        $this->app->bind(VisitTouchpointWriter::class, RecordVisitTouchpointAction::class);
    }
}
