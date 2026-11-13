<?php

declare(strict_types=1);

namespace App\Features\ShareTracking;

use App\Features\ShareTracking\Actions\BrowserSelection\LockBrowserSelectionAction;
use App\Features\ShareTracking\Actions\GuestClaims\ClaimGuestProfileAction;
use App\Features\ShareTracking\Api\BrowserSelectionWriter;
use App\Features\ShareTracking\Api\EffectReceipts;
use App\Features\ShareTracking\Api\GuestShareClaims;
use App\Features\ShareTracking\Api\OutcomeCorrections;
use App\Features\ShareTracking\Api\SourceAttributionReceipts;
use App\Features\ShareTracking\Persistence\DatabaseEffectReceipts;
use App\Features\ShareTracking\Persistence\DatabaseOutcomeCorrections;
use App\Features\ShareTracking\Persistence\DatabaseSourceAttributionReceipts;
use App\Platform\FeatureModule;

/**
 * Share tracking claims the browser selection, source attribution receipt,
 * effect receipt and outcome correction writes named in feature.json. The
 * remaining app tables are still written by root services and actions, so they
 * stay unclaimed until their slice lands.
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
 *   root support and package adapters. S3 cohorts/signals next.
 * - S3 cohorts/signals: move the committed-share reader or keep a root adapter
 *   and claim `share_cohort_projections`, `share_signal_reader_cursors` and
 *   `share_signal_reader_skips` after characterising backlog/poison SQL.
 * - S4 analytics/cleanup: rewire analytics readers or keep root adapters and
 *   delete the `ShareTrackingContract`/`ShareTrackingService` wrapper once
 *   callers migrate.
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
        $this->app->bind(EffectReceipts::class, DatabaseEffectReceipts::class);
        $this->app->bind(GuestShareClaims::class, ClaimGuestProfileAction::class);
        $this->app->bind(OutcomeCorrections::class, DatabaseOutcomeCorrections::class);
        $this->app->bind(SourceAttributionReceipts::class, DatabaseSourceAttributionReceipts::class);
    }
}
