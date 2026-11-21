<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\Effects;

use AIArmada\Rewards\Jobs\RecheckRewardAwardsJob;
use App\Features\Referrals\Api\ReferralOutcomeWorkflow;
use App\Features\ShareTracking\Models\ShareOutcomeCorrection;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ScheduleShareOutcomeCorrectionEffectsAction
{
    public function created(ShareOutcomeCorrection $correction): void
    {
        $businessKey = (string) $correction->getAttribute('business_key');

        try {
            app(ReferralOutcomeWorkflow::class)->scheduleRewardReversal(
                $businessKey,
                'source-correction:'.$correction->getAttribute('reason'),
            );
        } catch (Throwable $exception) {
            Log::warning('Referral reversal dispatch failed after recording a share correction.', [
                'correction_id' => (string) $correction->getKey(),
                'business_key' => $businessKey,
                'exception' => $exception,
            ]);
        }

        try {
            RecheckRewardAwardsJob::dispatch($businessKey)->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('Reward award recheck dispatch failed after recording a share correction.', [
                'correction_id' => (string) $correction->getKey(),
                'business_key' => $businessKey,
                'exception' => $exception,
            ]);
        }
    }
}
