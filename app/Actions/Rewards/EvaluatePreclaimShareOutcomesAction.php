<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Rewards\Support\RewardSourceFact;
use App\Enums\DawahShareOutcomeType;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Support\Rewards\ActivityRewardSourceFactReader;
use App\Support\Rewards\ShareAttributionRewardSourceFactReader;
use App\Support\ShareTracking\ActivityRewardBusinessKey;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class EvaluatePreclaimShareOutcomesAction
{
    use AsAction;

    public function __construct(
        private readonly ShareAttributionRewardSourceFactReader $sourceFactReader,
        private readonly ActivityRewardSourceFactReader $activityFactReader,
    ) {}

    public function handle(RewardProgram $program, User $member, RewardProgramVersion $version): void
    {
        $rules = $version->rules;
        $windows = is_array($rules) && is_array($rules['windows'] ?? null)
            ? $rules['windows']
            : [];
        $lookbackDays = $windows['preclaim_lookback_days'] ?? null;
        $familyKey = $program->getAttribute('family_key');

        if (! is_int($lookbackDays) || $lookbackDays < 1 || ! is_string($familyKey) || $familyKey === '') {
            return;
        }

        $decisionTime = CarbonImmutable::now('UTC');
        $lookbackStart = $decisionTime->subDays($lookbackDays);
        $memberId = (string) $member->getKey();
        $recipientType = $member->getMorphClass();

        $receipts = ShareSourceAttributionReceipt::query()
            ->where('namespace', ShareOutcomeBusinessKey::NAMESPACE)
            ->whereBetween('occurred_at', [$lookbackStart, $decisionTime])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->cursor();

        foreach ($receipts as $receipt) {
            $businessKey = $receipt->business_key;

            if (! is_string($businessKey) || $businessKey === '') {
                continue;
            }

            $fact = $this->sourceFactReader->read($businessKey);

            if (! $fact instanceof RewardSourceFact
                || ! $fact->isValid
                || $fact->occurredAt->lessThan($lookbackStart)
                || $fact->occurredAt->greaterThan($decisionTime)
            ) {
                $fact = null;
            }

            $roles = [];

            if ($fact instanceof RewardSourceFact && $fact->isAttributed && $fact->sharerId === $memberId) {
                $roles[RewardBeneficiaryRole::Sharer->value] = RewardBeneficiaryRole::Sharer;
            }

            if ($fact instanceof RewardSourceFact && $fact->actorId === $memberId) {
                $beneficiaryRole = RewardBeneficiaryRole::actorSide($fact->actorRole);
                $roles[$beneficiaryRole->value] = $beneficiaryRole;
            }

            foreach ($roles as $role) {
                EvaluateRewardEntitlementJob::dispatch(
                    $businessKey,
                    $memberId,
                    $role,
                    $recipientType,
                    $familyKey,
                )->afterCommit();
            }

            $activityBusinessKey = $this->activityBusinessKeyFor($businessKey);

            if ($activityBusinessKey === null) {
                continue;
            }

            $activityFact = $this->activityFactReader->read($activityBusinessKey);

            if (! $activityFact instanceof RewardSourceFact
                || ! $activityFact->isValid
                || $activityFact->actorId !== $memberId
                || $activityFact->occurredAt->lessThan($lookbackStart)
                || $activityFact->occurredAt->greaterThan($decisionTime)
            ) {
                continue;
            }

            $activityRole = RewardBeneficiaryRole::actorSide($activityFact->actorRole);

            EvaluateRewardEntitlementJob::dispatch(
                $activityBusinessKey,
                $memberId,
                $activityRole,
                $recipientType,
                $familyKey,
            )->afterCommit();
        }
    }

    private function activityBusinessKeyFor(string $businessKey): ?string
    {
        foreach (DawahShareOutcomeType::cases() as $outcome) {
            try {
                $parsed = ShareOutcomeBusinessKey::parse($businessKey, $outcome);
            } catch (InvalidArgumentException) {
                continue;
            }

            return ActivityRewardBusinessKey::for($outcome, $parsed['source_transition_id']);
        }

        return null;
    }
}
