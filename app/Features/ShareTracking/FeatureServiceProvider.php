<?php

declare(strict_types=1);

namespace App\Features\ShareTracking;

use App\Platform\FeatureModule;

/**
 * Share tracking is claim-free in S0 because FeatureCatalog requires every
 * claimed table to name feature-local writers. The seven app tables are still
 * written by root services and actions, so this manifest declares `owns: {}`.
 *
 * Slice order:
 * - S1 selections/source receipts: extract the selection capture, active
 *   attribution resolution, outcome recording and source-receipt freeze use
 *   cases, rewire middleware/controller/signup/checkout/recorder callers, then
 *   claim `share_browser_selections` and `share_source_attribution_receipts`.
 * - S2 effects/corrections/claims: move reversal, effect dispatch, participation
 *   reversal and guest claim orchestration while preserving monetary guards,
 *   lease fencing and duplicate-dispatch tolerance; claim `share_effect_receipts`
 *   and `share_outcome_corrections`.
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
class FeatureServiceProvider extends FeatureModule {}
