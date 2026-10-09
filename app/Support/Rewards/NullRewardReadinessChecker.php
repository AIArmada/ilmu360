<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Contracts\Rewards\RewardReadinessChecker;
use App\Enums\Rewards\RewardComponent;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;

/**
 * Truthful null readiness implementation for the core slice.
 *
 * Structural checks only. Money/benefit activation is always rejected: no
 * funding, payee, retention, or reversal adapters exist yet, and this checker
 * never reports funded or payee readiness. Real adapters arrive with the
 * later finance scope and replace this checker for those components.
 */
final class NullRewardReadinessChecker implements RewardReadinessChecker
{
    public function canMarkReady(RewardProgram $program, ?RewardProgramVersion $version): RewardReadinessAssessment
    {
        if (! $version instanceof RewardProgramVersion) {
            return new RewardReadinessAssessment(
                ready: false,
                blockers: ['no-published-version'],
                notes: ['A program needs at least one published version before it can be readied.'],
            );
        }

        $notes = ['Structural readiness only: rules/terms snapshots exist and validate.'];

        if ($this->involvesMoneyOrBenefit($version)) {
            $notes[] = 'Money/benefit activation will additionally require funding, payee, retention, and reversal adapters (later finance scope).';
        }

        return new RewardReadinessAssessment(ready: true, notes: $notes);
    }

    public function canActivate(RewardProgram $program, ?RewardProgramVersion $version): RewardReadinessAssessment
    {
        if (! $version instanceof RewardProgramVersion) {
            return new RewardReadinessAssessment(
                ready: false,
                blockers: ['no-published-version'],
                notes: ['A program needs at least one published version before it can be activated.'],
            );
        }

        if ($this->involvesMoneyOrBenefit($version)) {
            return new RewardReadinessAssessment(
                ready: false,
                blockers: [
                    'funding-adapter-missing',
                    'payee-verification-missing',
                    'retention-policy-missing',
                    'reversal-settlement-missing',
                ],
                notes: ['Null checker: money/benefit activation is unsupported in the core slice; no real program is activated.'],
            );
        }

        return new RewardReadinessAssessment(
            ready: true,
            notes: ['Structural activation only; explicit staff transition required, never automatic.'],
        );
    }

    private function involvesMoneyOrBenefit(RewardProgramVersion $version): bool
    {
        foreach ($version->componentTypes() as $type) {
            $component = RewardComponent::tryFrom($type);

            if ($component instanceof RewardComponent && $component->requiresMoneyOrBenefitFulfillment()) {
                return true;
            }
        }

        return false;
    }
}
