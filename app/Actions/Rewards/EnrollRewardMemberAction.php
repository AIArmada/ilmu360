<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardEnrollmentStatus;
use App\Models\RewardEnrollment;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardEnrollmentPeriod;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Canonical member enrollment.
 *
 * New member: creates the enrollment row plus acceptance seq 1 and period
 * seq 1 atomically. Active member with the same frozen policy: idempotent
 * no-op. Active member under changed material terms: appends a new
 * acceptance (prospective; frozen references on accrued obligations are
 * untouched). Left member: rejoin appends a fresh acceptance row (always,
 * even for identical terms) plus a new period.
 *
 * Lock order: programs, then enrollments, then acceptances, then periods.
 * Bulk callers must process (program_id, member_type, member_id) in sorted
 * order. The unique identity constraint arbitrates creation races.
 */
final readonly class EnrollRewardMemberAction
{
    use AsAction;

    /**
     * @var list<string>
     */
    public const array MEMBER_TYPES = ['user'];

    /**
     * @var list<string>
     */
    public const array ACCEPTED_VIA = ['member-portal', 'staff-assisted', 'api'];

    public function handle(
        RewardProgram|string $program,
        string $memberType,
        string $memberId,
        RewardProgramVersion|string $policyVersion,
        ?string $acceptedVia = null,
        ?CarbonInterface $decisionTime = null,
    ): RewardEnrollment {
        if (! in_array($memberType, self::MEMBER_TYPES, true)) {
            throw new InvalidArgumentException('Member type must be one of ['.implode(',', self::MEMBER_TYPES).']; no unclaimed-guest rewards.');
        }

        if (! Str::isUuid($memberId)) {
            throw new InvalidArgumentException('Member id must be a UUID.');
        }

        if ($acceptedVia !== null && ! in_array($acceptedVia, self::ACCEPTED_VIA, true)) {
            throw new InvalidArgumentException('Accepted-via must be one of ['.implode(',', self::ACCEPTED_VIA).'].');
        }

        $decision = $decisionTime === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decisionTime)->setTimezone('UTC');

        $programId = $program instanceof RewardProgram ? (string) $program->getKey() : $program;
        $versionId = $policyVersion instanceof RewardProgramVersion ? (string) $policyVersion->getKey() : $policyVersion;

        return DB::transaction(function () use ($programId, $memberType, $memberId, $versionId, $acceptedVia, $decision): RewardEnrollment {
            $lockedProgram = RewardProgram::query()->whereKey($programId)->lockForUpdate()->first();

            if (! $lockedProgram instanceof RewardProgram) {
                throw new InvalidArgumentException('Unknown reward program.');
            }

            $version = RewardProgramVersion::query()->whereKey($versionId)->first();

            if (! $version instanceof RewardProgramVersion || (string) $version->program_id !== (string) $lockedProgram->getKey()) {
                throw new InvalidArgumentException('Policy version does not belong to the program.');
            }

            $versionPublishedAt = CarbonImmutable::parse($version->published_at)->setTimezone('UTC');

            if ($decision->lessThan($versionPublishedAt)) {
                throw new InvalidArgumentException('Acceptance cannot predate the policy publication.');
            }

            $enrollment = RewardEnrollment::query()
                ->where('program_id', $lockedProgram->getKey())
                ->where('member_type', $memberType)
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->first();

            if (! $enrollment instanceof RewardEnrollment) {
                try {
                    $enrollment = RewardEnrollment::query()->create([
                        'program_id' => $lockedProgram->getKey(),
                        'member_type' => $memberType,
                        'member_id' => $memberId,
                        'status' => RewardEnrollmentStatus::Active,
                        'enrolled_at' => $decision,
                    ]);
                } catch (QueryException $exception) {
                    if (! CreateRewardProgramAction::isUniqueViolation($exception)) {
                        throw $exception;
                    }

                    $enrollment = RewardEnrollment::query()
                        ->where('program_id', $lockedProgram->getKey())
                        ->where('member_type', $memberType)
                        ->where('member_id', $memberId)
                        ->lockForUpdate()
                        ->first();

                    if (! $enrollment instanceof RewardEnrollment) {
                        throw new LogicException('Enrollment creation raced but the row is missing.');
                    }
                }

                $acceptance = $this->appendAcceptance($enrollment, $version, $acceptedVia, $decision);

                RewardEnrollmentPeriod::query()->create([
                    'enrollment_id' => $enrollment->getKey(),
                    'seq' => 1,
                    'joined_at' => $decision,
                    'join_acceptance_id' => $acceptance->getKey(),
                ]);

                $enrollment->forceFill(['current_acceptance_id' => $acceptance->getKey()])->save();

                return $enrollment->refresh();
            }

            if ($enrollment->status === RewardEnrollmentStatus::Active) {
                $current = $enrollment->current_acceptance_id !== null
                    ? RewardEnrollmentAcceptance::query()->whereKey($enrollment->current_acceptance_id)->first()
                    : null;

                if ($current instanceof RewardEnrollmentAcceptance
                    && (string) $current->policy_version_id === (string) $version->getKey()
                    && $current->material_terms_hash === $version->material_terms_hash
                ) {
                    return $enrollment;
                }

                $this->assertMonotonicAcceptance($enrollment, $decision);

                $acceptance = $this->appendAcceptance($enrollment, $version, $acceptedVia, $decision);
                $enrollment->forceFill(['current_acceptance_id' => $acceptance->getKey()])->save();

                return $enrollment->refresh();
            }

            $this->assertMonotonicAcceptance($enrollment, $decision);
            $this->assertRejoinAfterLeave($enrollment, $decision);

            $acceptance = $this->appendAcceptance($enrollment, $version, $acceptedVia, $decision);

            $maxPeriod = (int) (RewardEnrollmentPeriod::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->orderByDesc('seq')
                ->lockForUpdate()
                ->value('seq') ?? 0);

            RewardEnrollmentPeriod::query()->create([
                'enrollment_id' => $enrollment->getKey(),
                'seq' => $maxPeriod + 1,
                'joined_at' => $decision,
                'join_acceptance_id' => $acceptance->getKey(),
            ]);

            $enrollment->forceFill([
                'status' => RewardEnrollmentStatus::Active,
                'current_acceptance_id' => $acceptance->getKey(),
            ])->save();

            return $enrollment->refresh();
        });
    }

    private function appendAcceptance(
        RewardEnrollment $enrollment,
        RewardProgramVersion $version,
        ?string $acceptedVia,
        CarbonImmutable $decision,
    ): RewardEnrollmentAcceptance {
        $maxSeq = (int) (RewardEnrollmentAcceptance::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->value('seq') ?? 0);

        $terms = $version->terms;

        return RewardEnrollmentAcceptance::query()->create([
            'enrollment_id' => $enrollment->getKey(),
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
    }

    private function assertMonotonicAcceptance(RewardEnrollment $enrollment, CarbonImmutable $decision): void
    {
        $latest = RewardEnrollmentAcceptance::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->orderByDesc('accepted_at')
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->first();

        if ($latest instanceof RewardEnrollmentAcceptance
            && $decision->lessThan(CarbonImmutable::parse($latest->accepted_at)->setTimezone('UTC'))
        ) {
            throw new InvalidArgumentException('Acceptance time cannot move backwards; consent history is append-only.');
        }
    }

    private function assertRejoinAfterLeave(RewardEnrollment $enrollment, CarbonImmutable $decision): void
    {
        $last = RewardEnrollmentPeriod::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->orderByDesc('seq')
            ->lockForUpdate()
            ->first();

        if ($last instanceof RewardEnrollmentPeriod && $last->left_at !== null
            && $decision->lessThanOrEqualTo(CarbonImmutable::parse($last->left_at)->setTimezone('UTC'))
        ) {
            throw new InvalidArgumentException('Rejoin must be strictly after the last leave; overlapping periods are rejected.');
        }

        $open = RewardEnrollmentPeriod::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereNull('left_at')
            ->lockForUpdate()
            ->exists();

        if ($open) {
            throw new InvalidArgumentException('Rejoin requires the previous period to be closed; overlapping periods are rejected.');
        }
    }
}
