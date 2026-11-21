<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Observers;

use App\Features\ShareTracking\Actions\Effects\ScheduleShareOutcomeCorrectionEffectsAction;
use App\Features\ShareTracking\Models\ShareOutcomeCorrection;

/** Notify ShareTracking's owned after-write effects action. */
final readonly class ShareOutcomeCorrectionObserver
{
    public function __construct(private ScheduleShareOutcomeCorrectionEffectsAction $effects) {}

    public function created(ShareOutcomeCorrection $record): void
    {
        $this->effects->created($record);
    }
}
