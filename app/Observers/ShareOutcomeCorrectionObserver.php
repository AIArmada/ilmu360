<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Rewards\RecheckRewardAwardsJob;
use App\Models\ShareOutcomeCorrection;

class ShareOutcomeCorrectionObserver
{
    public function created(ShareOutcomeCorrection $correction): void
    {
        RecheckRewardAwardsJob::dispatch((string) $correction->getAttribute('business_key'))->afterCommit();
    }
}
