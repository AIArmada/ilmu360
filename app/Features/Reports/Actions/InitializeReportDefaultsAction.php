<?php

declare(strict_types=1);

namespace App\Features\Reports\Actions;

use App\Features\Reports\Contracts\ReportLifecycle;
use App\Features\Reports\Models\Report;
use App\Shared\Auth\UserMorphType;

/** Initialize report ownership identity and valid lifecycle state. */
final readonly class InitializeReportDefaultsAction
{
    public function __construct(
        private ReportLifecycle $lifecycle,
        private UserMorphType $userMorphType,
    ) {}

    public function handle(Report $report): void
    {
        if ($report->getAttribute('reporter_type') === null) {
            $report->setAttribute('reporter_type', $this->userMorphType->morphClass());
        }

        if ($report->getAttribute('status') === null) {
            $this->lifecycle->initialize($report, Report::STATUS_OPEN);

            return;
        }

        $this->lifecycle->preparePersistedStatus($report, (string) $report->getAttribute('status'));
    }
}
