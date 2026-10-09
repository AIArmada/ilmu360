<?php

declare(strict_types=1);

namespace App\Contracts\Rewards;

use App\Models\RewardAward;
use App\Support\Rewards\RewardFinancialReadinessAssessment;

/**
 * Per-award financial readiness for cash/benefit components.
 *
 * Distinct from program-lifecycle readiness (RewardReadinessChecker): this
 * contract answers whether a single evaluated award may proceed toward
 * financial approval/fulfillment — funded budget, verified payee, working
 * fulfillment adapter. Policy approval of a cash/benefit award is recorded
 * separately from actual component fulfillment, which stays a WP6 scope.
 */
interface RewardFinancialReadinessChecker
{
    public function canApproveFinancial(RewardAward $award): RewardFinancialReadinessAssessment;

    public function canFulfillFinancial(RewardAward $award): RewardFinancialReadinessAssessment;
}
