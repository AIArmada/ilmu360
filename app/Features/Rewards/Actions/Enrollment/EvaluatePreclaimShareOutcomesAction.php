<?php

declare(strict_types=1);

namespace App\Features\Rewards\Actions\Enrollment;

use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Rewards\Support\RewardSourceFact;
use App\Features\ShareTracking\Api\SourceReceiptReader;
use App\Shared\ShareTracking\ActivityRewardBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Preclaim backfill after enrollment or reacceptance.
 *
 * Reads the published version's `windows.preclaim_lookback_days` guard, walks
 * the committed share-outcome receipts inside the inclusive lookback in
 * `occurred_at,id` order, and dispatches one entitlement evaluation per
 * applicable role after commit. Attributed receipts also mirror the
 * actor-only `activity:` key. The receipt window comes from
 * `ShareTracking\Api\SourceReceiptReader` and every source fact comes from the
 * package reader boundary, so no root model, service or support type is used.
 *
 * This collaborator keeps only the backfill entrypoint; the public
 * `Api\MemberEnrollment` boundary is implemented by
 * `EnrollRewardProgramMemberAction`.
 */
final readonly class EvaluatePreclaimShareOutcomesAction
{
    public function __construct(
        private RewardSourceFactReader $sourceFactReader,
        private SourceReceiptReader $receiptReader,
    ) {}

    public function preclaim(
        string $programId,
        string $memberId,
        string $recipientType,
        string $versionId,
    ): void {
        $program = RewardProgram::query()->findOrFail($programId);
        $version = RewardProgramVersion::query()->findOrFail($versionId);

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

        foreach ($this->receiptReader->keysBetween($lookbackStart, $decisionTime) as $businessKey) {
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

            $activityFact = $this->sourceFactReader->read($activityBusinessKey);

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
        foreach (ShareOutcomeType::cases() as $outcome) {
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
