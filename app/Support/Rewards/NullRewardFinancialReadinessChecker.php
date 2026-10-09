<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Contracts\Rewards\RewardFinancialReadinessChecker;
use App\Models\RewardAward;

/**
 * Truthful null financial readiness: no funding, payee, or fulfillment
 * adapters exist in this slice, so every financial approval/fulfillment
 * check is rejected with explicit missing-capability blockers. Cash/benefit
 * evaluations are therefore recorded as held-prerequisite candidates only;
 * actual money/benefit movement arrives with the WP6 finance scope.
 */
final class NullRewardFinancialReadinessChecker implements RewardFinancialReadinessChecker
{
    public function canApproveFinancial(RewardAward $award): RewardFinancialReadinessAssessment
    {
        return new RewardFinancialReadinessAssessment(
            ready: false,
            blockers: [
                'funding-adapter-missing',
                'payee-verification-missing',
                'fulfillment-adapter-missing',
            ],
            notes: ['Null checker: no budget, payee, or fulfillment backing exists in this slice; the award stays a held candidate.'],
        );
    }

    public function canFulfillFinancial(RewardAward $award): RewardFinancialReadinessAssessment
    {
        return new RewardFinancialReadinessAssessment(
            ready: false,
            blockers: [
                'funding-adapter-missing',
                'payee-verification-missing',
                'fulfillment-adapter-missing',
                'financial-fulfillment-is-wp6-scope',
            ],
            notes: ['Null checker: cash/benefit fulfillment is never marked in this slice.'],
        );
    }
}
