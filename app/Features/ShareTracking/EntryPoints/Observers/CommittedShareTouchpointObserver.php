<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\EntryPoints\Observers;

use AIArmada\Affiliates\Features\Attribution\Models\AffiliateTouchpoint;
use App\Features\ShareTracking\Actions\CommittedSignals\EnqueueCommittedShareTouchpointSignalAction;

/** Eloquent notification adapter; the ShareTracking Action owns the signal dispatch. */
final readonly class CommittedShareTouchpointObserver
{
    public function __construct(private EnqueueCommittedShareTouchpointSignalAction $effects) {}

    public function created(AffiliateTouchpoint $touchpoint): void
    {
        $this->effects->handle($touchpoint);
    }
}
