<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Observers;

use App\Features\ShareTracking\Actions\Effects\ScheduleShareReceiptEffectsAction;
use App\Features\ShareTracking\Models\ShareSourceAttributionReceipt;

/** Notify ShareTracking's owned after-write effects action. */
final readonly class ShareSourceAttributionReceiptObserver
{
    public function __construct(private ScheduleShareReceiptEffectsAction $effects) {}

    public function created(ShareSourceAttributionReceipt $record): void
    {
        $this->effects->created($record);
    }
}
