<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Rewards\Jobs\RecheckRewardAwardsJob;
use App\Jobs\Referrals\ReverseReferralRewardsForShareOutcomeJob;
use App\Models\ShareOutcomeCorrection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShareOutcomeCorrectionObserver
{
    public function created(ShareOutcomeCorrection $correction): void
    {
        $businessKey = (string) $correction->getAttribute('business_key');

        try {
            ReverseReferralRewardsForShareOutcomeJob::dispatch(
                $businessKey,
                'source-correction:'.(string) $correction->getAttribute('reason'),
            )->afterCommit();
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
