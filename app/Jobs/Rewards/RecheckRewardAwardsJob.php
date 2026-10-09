<?php

declare(strict_types=1);

namespace App\Jobs\Rewards;

use App\Actions\Rewards\InvalidateRewardMilestoneContributionAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Enums\Rewards\RewardAwardStatus;
use App\Models\RewardAward;
use App\Models\RewardMilestoneContribution;
use App\Models\ShareOutcomeCorrection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * Rechecks awards after a source correction lands.
 *
 * Correction wins races with queued evaluation: unfulfilled awards on the
 * corrected source move to rejected (held/pending_review) or reversed
 * (approved) with the correction as evidence. Fulfilled awards are NEVER
 * auto-reversed here — post-fulfillment value needs the authorized
 * recovery/settlement workflow, not a silent reversal. Milestone
 * contributions on the source are invalidated so cycles re-evaluate.
 */
final class RecheckRewardAwardsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $businessKey,
    ) {}

    public function uniqueId(): string
    {
        return 'reward-recheck:'.$this->businessKey;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        TransitionRewardAwardAction $transition,
        InvalidateRewardMilestoneContributionAction $invalidate,
    ): void {
        $correction = ShareOutcomeCorrection::query()
            ->where('business_key', $this->businessKey)
            ->first();

        if (! $correction instanceof ShareOutcomeCorrection) {
            return;
        }

        $evidence = [
            'correction_id' => (string) $correction->getKey(),
            'correction_reason' => (string) $correction->getAttribute('reason'),
            'business_key' => $this->businessKey,
        ];

        $awards = RewardAward::query()
            ->where('source_business_key', $this->businessKey)
            ->whereIn('status', [
                RewardAwardStatus::Held->value,
                RewardAwardStatus::PendingReview->value,
                RewardAwardStatus::Approved->value,
            ])
            ->orderBy('id')
            ->get();

        foreach ($awards as $award) {
            /** @var RewardAward $award */
            $to = $award->status === RewardAwardStatus::Approved
                ? RewardAwardStatus::Reversed
                : RewardAwardStatus::Rejected;

            try {
                $transition->handle(
                    $award,
                    $to,
                    'system',
                    null,
                    'system',
                    'source_withdrawn',
                    $evidence,
                );
            } catch (InvalidArgumentException $exception) {
                // A concurrent operator/system transition already moved the
                // award out of the expected state; anything else is a real
                // failure and must retry.
                if (str_starts_with($exception->getMessage(), 'Invalid award transition')) {
                    continue;
                }

                throw $exception;
            }
        }

        $contributions = RewardMilestoneContribution::query()
            ->where('source_business_key', $this->businessKey)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();

        foreach ($contributions as $contribution) {
            // The action is idempotent on revoked_at under the cycle lock.
            $invalidate->handle(
                $contribution,
                'system',
                null,
                'system',
                'source_withdrawn',
            );
        }
    }
}
