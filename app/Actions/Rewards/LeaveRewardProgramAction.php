<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardEnrollmentStatus;
use App\Models\RewardEnrollment;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardEnrollmentPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Member leave. Closes the open period (the single allowed period mutation)
 * and marks the enrollment left. Nothing is deleted: acceptance history,
 * periods, and frozen references stay intact so accrued obligations remain
 * payable under their frozen terms.
 */
final readonly class LeaveRewardProgramAction
{
    use AsAction;

    public function handle(
        RewardEnrollment|string $enrollment,
        ?string $reason = null,
        ?CarbonInterface $decisionTime = null,
    ): RewardEnrollment {
        $reason = trim($reason ?? 'member-left');

        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new InvalidArgumentException('Leave reason must be 1..191 characters.');
        }

        $decision = $decisionTime === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decisionTime)->setTimezone('UTC');

        $enrollmentId = $enrollment instanceof RewardEnrollment ? (string) $enrollment->getKey() : $enrollment;

        return DB::transaction(function () use ($enrollmentId, $reason, $decision): RewardEnrollment {
            $locked = RewardEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->first();

            if (! $locked instanceof RewardEnrollment) {
                throw new InvalidArgumentException('Unknown reward enrollment.');
            }

            if ($locked->status === RewardEnrollmentStatus::Left) {
                return $locked;
            }

            $open = RewardEnrollmentPeriod::query()
                ->where('enrollment_id', $locked->getKey())
                ->whereNull('left_at')
                ->orderBy('seq')
                ->lockForUpdate()
                ->first();

            if ($open instanceof RewardEnrollmentPeriod) {
                $joinedAt = CarbonImmutable::parse($open->joined_at)->setTimezone('UTC');

                if ($decision->lessThan($joinedAt)) {
                    throw new InvalidArgumentException('Leave time cannot predate the period join; history cannot be fabricated.');
                }

                $open->forceFill([
                    'left_at' => $decision,
                    'leave_reason' => $reason,
                ])->save();
            }

            $latestAcceptance = RewardEnrollmentAcceptance::query()
                ->where('enrollment_id', $locked->getKey())
                ->orderByDesc('accepted_at')
                ->orderByDesc('seq')
                ->lockForUpdate()
                ->first();

            if ($latestAcceptance instanceof RewardEnrollmentAcceptance
                && $decision->lessThan(CarbonImmutable::parse($latestAcceptance->accepted_at)->setTimezone('UTC'))
            ) {
                throw new InvalidArgumentException('Leave time cannot predate the latest acceptance.');
            }

            $locked->forceFill([
                'status' => RewardEnrollmentStatus::Left,
                ...($locked->left_at === null ? ['left_at' => $decision] : []),
            ])->save();

            return $locked->refresh();
        });
    }
}
