<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;

final readonly class AiBudgetPolicy
{
    public function __construct(private AiUsageLedger $usageLedger) {}

    public function decide(
        string $operation,
        ?string $provider,
        ?string $model,
        ?float $estimatedCostUsd,
        ?User $actor = null,
    ): AiBudgetDecision {
        if (! (bool) config('ai.usage_tracking.enabled', true)) {
            return new AiBudgetDecision('deny', 'usage_tracking_disabled');
        }

        if ($estimatedCostUsd === null || $estimatedCostUsd < 0) {
            return new AiBudgetDecision('defer', 'cost_estimate_unavailable');
        }

        $periodLimit = (float) config('ai.budget.monthly_limit_usd', 100.0);
        $periodUsage = $this->usageLedger->currentPeriodCostUsd();

        if ($periodUsage + $estimatedCostUsd > $periodLimit) {
            return new AiBudgetDecision('deny', 'monthly_budget_exceeded');
        }

        $approvalThreshold = (float) config('ai.budget.privileged_approval_threshold_usd', 10.0);

        if ($estimatedCostUsd > $approvalThreshold && ! $actor?->hasRole('super_admin')) {
            return new AiBudgetDecision('privileged_approval', 'cost_requires_privileged_approval');
        }

        return new AiBudgetDecision('allow', 'within_budget');
    }
}
