<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Contracts\Rewards\RewardReadinessChecker;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\NullRewardReadinessChecker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Staff-driven lifecycle transitions. Every entry to ready/active is gated by
 * the readiness checker; the null checker truthfully rejects money/benefit
 * activation. Transition timestamps record first entry immutably.
 */
final readonly class TransitionRewardProgramStatusAction
{
    use AsAction;

    public function __construct(
        private ?RewardReadinessChecker $checker = null,
    ) {}

    public function handle(
        RewardProgram|string $program,
        RewardProgramStatus|string $to,
    ): RewardProgram {
        $toEnum = $to instanceof RewardProgramStatus ? $to : RewardProgramStatus::tryFrom($to);

        if (! $toEnum instanceof RewardProgramStatus) {
            throw new InvalidArgumentException('Unknown program status.');
        }

        $programId = $program instanceof RewardProgram ? (string) $program->getKey() : $program;
        $checker = $this->checker ?? new NullRewardReadinessChecker;

        return DB::transaction(function () use ($programId, $toEnum, $checker): RewardProgram {
            $locked = RewardProgram::query()->whereKey($programId)->lockForUpdate()->first();

            if (! $locked instanceof RewardProgram) {
                throw new InvalidArgumentException('Unknown reward program.');
            }

            if ($locked->status === $toEnum) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo($toEnum)) {
                throw new InvalidArgumentException('Invalid reward program status transition.');
            }

            if ($toEnum === RewardProgramStatus::Ready || $toEnum === RewardProgramStatus::Active) {
                $latest = RewardProgramVersion::query()
                    ->where('program_id', $locked->getKey())
                    ->orderByDesc('version')
                    ->first();

                $assessment = $toEnum === RewardProgramStatus::Ready
                    ? $checker->canMarkReady($locked, $latest)
                    : $checker->canActivate($locked, $latest);

                if (! $assessment->ready) {
                    throw new InvalidArgumentException(
                        'Program cannot transition to ['.$toEnum->value.']: '.implode('; ', $assessment->blockers)
                    );
                }
            }

            $column = $toEnum->transitionColumn();

            $locked->forceFill([
                'status' => $toEnum,
                ...($column !== null && $locked->getAttribute($column) === null ? [$column => CarbonImmutable::now('UTC')] : []),
            ])->save();

            return $locked->refresh();
        });
    }
}
