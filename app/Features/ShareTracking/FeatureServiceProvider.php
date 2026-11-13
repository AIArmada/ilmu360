<?php

declare(strict_types=1);

namespace App\Features\ShareTracking;

use App\Features\ShareTracking\Actions\BrowserSelection\LockBrowserSelectionAction;
use App\Features\ShareTracking\Actions\CommittedSignals\DrainCommittedShareSignalsAction;
use App\Features\ShareTracking\Actions\GuestClaims\ClaimGuestProfileAction;
use App\Features\ShareTracking\Api\BrowserSelectionWriter;
use App\Features\ShareTracking\Api\CommittedShareSignals;
use App\Features\ShareTracking\Api\EffectReceipts;
use App\Features\ShareTracking\Api\GuestShareClaims;
use App\Features\ShareTracking\Api\OutcomeCorrections;
use App\Features\ShareTracking\Api\SourceAttributionReceipts;
use App\Features\ShareTracking\Api\SourceReceiptReader;
use App\Features\ShareTracking\Persistence\DatabaseEffectReceipts;
use App\Features\ShareTracking\Persistence\DatabaseOutcomeCorrections;
use App\Features\ShareTracking\Persistence\DatabaseSourceAttributionReceipts;
use App\Features\ShareTracking\Persistence\DatabaseSourceReceiptReader;
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
 * business keys in an inclusive receipt window for the Rewards preclaim
 * backfill, so that feature no longer imports the receipt model or table.
 *
 * Must stay root (documented here so later slices do not absorb them): HTTP,
 * Livewire and route entrypoints; DTOs; analytics readers; observers;
 * `ProduceCommittedShareSignalJob`; commands and schedules; account
 * restoration; the checkout provenance step; `SignupAttributionSourceResolver`;
 * package affiliate models, actions and guest credential/claim primitives;
 * `ShareTrackingUrlService`/destination policy; and the narrow package gateway
 * left behind when `AffiliatesShareTrackingService` is peeled apart.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(BrowserSelectionWriter::class, LockBrowserSelectionAction::class);
        $this->app->bind(CommittedShareSignals::class, DrainCommittedShareSignalsAction::class);
        $this->app->bind(EffectReceipts::class, DatabaseEffectReceipts::class);
        $this->app->bind(GuestShareClaims::class, ClaimGuestProfileAction::class);
        $this->app->bind(OutcomeCorrections::class, DatabaseOutcomeCorrections::class);
        $this->app->bind(SourceAttributionReceipts::class, DatabaseSourceAttributionReceipts::class);
        $this->app->bind(SourceReceiptReader::class, DatabaseSourceReceiptReader::class);
    }
}
