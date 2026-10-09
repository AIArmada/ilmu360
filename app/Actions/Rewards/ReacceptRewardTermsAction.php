<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Models\RewardEnrollment;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardProgramVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Prospective terms reacceptance. Always appends a new acceptance row (next
 * seq, even for identical terms) and advances the current pointer. Prior
 * rows are never rewritten, so frozen references stay stable.
 */
final readonly class ReacceptRewardTermsAction
{
    use AsAction;

    public function handle(
        RewardEnrollment|string $enrollment,
        RewardProgramVersion|string $policyVersion,
        ?string $acceptedVia = null,
        ?CarbonInterface $decisionTime = null,
    ): RewardEnrollmentAcceptance {
        if ($acceptedVia !== null && ! in_array($acceptedVia, EnrollRewardMemberAction::ACCEPTED_VIA, true)) {
            throw new InvalidArgumentException('Accepted-via must be one of ['.implode(',', EnrollRewardMemberAction::ACCEPTED_VIA).'].');
        }

        $decision = $decisionTime === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decisionTime)->setTimezone('UTC');

        $enrollmentId = $enrollment instanceof RewardEnrollment ? (string) $enrollment->getKey() : $enrollment;
        $versionId = $policyVersion instanceof RewardProgramVersion ? (string) $policyVersion->getKey() : $policyVersion;

        return DB::transaction(function () use ($enrollmentId, $versionId, $acceptedVia, $decision): RewardEnrollmentAcceptance {
            $locked = RewardEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->first();

            if (! $locked instanceof RewardEnrollment) {
                throw new InvalidArgumentException('Unknown reward enrollment.');
            }

            $version = RewardProgramVersion::query()->whereKey($versionId)->first();

            if (! $version instanceof RewardProgramVersion || (string) $version->program_id !== (string) $locked->program_id) {
                throw new InvalidArgumentException('Policy version does not belong to the enrollment program.');
            }

            $versionPublishedAt = CarbonImmutable::parse($version->published_at)->setTimezone('UTC');

            if ($decision->lessThan($versionPublishedAt)) {
                throw new InvalidArgumentException('Acceptance cannot predate the policy publication.');
            }

            $enrolledAt = CarbonImmutable::parse($locked->enrolled_at)->setTimezone('UTC');

            if ($decision->lessThan($enrolledAt)) {
                throw new InvalidArgumentException('Acceptance cannot predate the enrollment.');
            }

            $latest = RewardEnrollmentAcceptance::query()
                ->where('enrollment_id', $locked->getKey())
                ->orderByDesc('accepted_at')
                ->orderByDesc('seq')
                ->lockForUpdate()
                ->first();

            if ($latest instanceof RewardEnrollmentAcceptance
                && $decision->lessThan(CarbonImmutable::parse($latest->accepted_at)->setTimezone('UTC'))
            ) {
                throw new InvalidArgumentException('Acceptance time cannot move backwards; consent history is append-only.');
            }

            $maxSeq = (int) (RewardEnrollmentAcceptance::query()
                ->where('enrollment_id', $locked->getKey())
                ->orderByDesc('seq')
                ->lockForUpdate()
                ->value('seq') ?? 0);

            $terms = $version->terms;

            $acceptance = RewardEnrollmentAcceptance::query()->create([
                'enrollment_id' => $locked->getKey(),
                'seq' => $maxSeq + 1,
                'policy_version_id' => $version->getKey(),
                'material_terms_hash' => $version->material_terms_hash,
                'terms_label' => (string) ($terms['version_label'] ?? 'v'.$version->version),
                'accepted_snapshot' => [
                    'material' => $terms['material'] ?? [],
                    'rules' => $version->rules,
                    'policy_version' => $version->version,
                    'policy_effective_from' => CarbonImmutable::parse($version->effective_from)->setTimezone('UTC')->toIso8601String(),
                ],
                'accepted_at' => $decision,
                'accepted_via' => $acceptedVia,
            ]);

            $locked->forceFill(['current_acceptance_id' => $acceptance->getKey()])->save();

            return $acceptance;
        });
    }
}
