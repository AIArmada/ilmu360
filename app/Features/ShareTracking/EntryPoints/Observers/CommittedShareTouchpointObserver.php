<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\EntryPoints\Observers;

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Features\ShareTracking\EntryPoints\Jobs\ProduceCommittedShareSignalJob;
use App\Features\ShareTracking\Services\CommittedShareSignalProducer;

/**
 * Fans committed outbound shares out to the Signals pipeline. Only
 * outbound_share touchpoints produce; visits and every other
 * touchpoint type are ignored. The job runs after commit, so Signals
 * work can never roll back the share it describes.
 */
class CommittedShareTouchpointObserver
{
    public function created(AffiliateTouchpoint $touchpoint): void
    {
        if ((string) $touchpoint->getAttribute('touchpoint_type') !== CommittedShareSignalProducer::TOUCHPOINT_TYPE) {
            return;
        }

        ProduceCommittedShareSignalJob::dispatch((string) $touchpoint->getKey())->afterCommit();
    }
}
