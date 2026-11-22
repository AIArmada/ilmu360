<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\CommittedSignals;

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Features\ShareTracking\EntryPoints\Jobs\ProduceCommittedShareSignalJob;
use App\Features\ShareTracking\Services\CommittedShareSignalProducer;

final readonly class EnqueueCommittedShareTouchpointSignalAction
{
    public function handle(AffiliateTouchpoint $touchpoint): void
    {
        if ((string) $touchpoint->getAttribute('touchpoint_type') !== CommittedShareSignalProducer::TOUCHPOINT_TYPE) {
            return;
        }

        ProduceCommittedShareSignalJob::dispatch((string) $touchpoint->getKey())->afterCommit();
    }
}
