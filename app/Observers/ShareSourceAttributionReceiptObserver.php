<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Rewards\EvaluateRewardEntitlementJob;
use App\Models\ShareSourceAttributionReceipt;

class ShareSourceAttributionReceiptObserver
{
    public function created(ShareSourceAttributionReceipt $receipt): void
    {
        EvaluateRewardEntitlementJob::dispatch((string) $receipt->getAttribute('business_key'))->afterCommit();

        // Reward evaluation keeps its own dedicated dispatch. The
        // committed-share signal is produced from committed OUTBOUND
        // shares (CommittedShareTouchpointObserver), never from
        // downstream receipts.
    }
}
