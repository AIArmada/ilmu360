<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use Carbon\CarbonImmutable;

/**
 * Automation candidate resolution for single-fact evaluation.
 *
 * For one committed source fact, resolves every (active program, enabled
 * component, bound role, recipient) tuple the evaluate job must attempt.
 * Binding follows the kernel exactly: sharer-side roles bind the fact's
 * sharer id, actor-side roles bind the fact's actor id. Unattributed,
 * invalid, and cancelled facts yield no candidates — automation stays
 * silent instead of freezing rejection rows for facts that could never
 * qualify; policy-merit rejections still freeze through explicit calls.
 */
final class RewardEvaluationCandidates
{
    /**
     * @return list<array{
     *     program: RewardProgram,
     *     version: RewardProgramVersion,
     *     component: RewardComponent,
     *     role: RewardBeneficiaryRole,
     *     recipient_id: string,
     * }>
     */
    public static function for(RewardSourceFact $fact, CarbonImmutable $at): array
    {
        if (! $fact->isAttributed || ! $fact->isValid || $fact->isCancelled) {
            return [];
        }

        $sides = [];

        if ($fact->sharerId !== null) {
            $sides[] = ['role' => RewardBeneficiaryRole::Sharer, 'recipient_id' => $fact->sharerId];
        }

        if ($fact->actorId !== null) {
            $role = in_array($fact->actorRole, [RewardBeneficiaryRole::Participant, RewardBeneficiaryRole::Purchaser], true)
                ? $fact->actorRole
                : RewardBeneficiaryRole::Participant;

            $sides[] = ['role' => $role, 'recipient_id' => $fact->actorId];
        }

        if ($sides === []) {
            return [];
        }

        $at = $at->setTimezone('UTC');
        $candidates = [];

        $programs = RewardProgram::query()
            ->where('status', RewardProgramStatus::Active->value)
            ->orderBy('family_key')
            ->get();

        foreach ($programs as $program) {
            /** @var RewardProgram $program */
            $versions = RewardProgramVersion::query()
                ->where('program_id', $program->getKey())
                ->orderBy('version')
                ->get();

            $timeline = [];

            foreach ($versions as $version) {
                /** @var RewardProgramVersion $version */
                $timeline[] = [
                    'id' => (string) $version->getKey(),
                    'version' => (int) $version->version,
                    'effective_from' => CarbonImmutable::parse($version->effective_from)->setTimezone('UTC'),
                ];
            }

            $applicable = RewardVersionResolver::resolveAt($timeline, $at);

            if ($applicable === null) {
                continue;
            }

            $resolved = null;

            foreach ($versions as $version) {
                if ((string) $version->getKey() === $applicable['id']) {
                    $resolved = $version;
                    break;
                }
            }

            if (! $resolved instanceof RewardProgramVersion) {
                continue;
            }

            $rules = $resolved->rules;
            $entries = is_array($rules['components'] ?? null) ? $rules['components'] : [];

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $component = RewardComponent::tryFrom((string) ($entry['type'] ?? ''));

                if (! $component instanceof RewardComponent) {
                    continue;
                }

                foreach ($sides as $side) {
                    $candidates[] = [
                        'program' => $program,
                        'version' => $resolved,
                        'component' => $component,
                        'role' => $side['role'],
                        'recipient_id' => $side['recipient_id'],
                    ];
                }
            }
        }

        return $candidates;
    }
}
