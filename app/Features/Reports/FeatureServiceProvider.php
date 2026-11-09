<?php

declare(strict_types=1);

namespace App\Features\Reports;

use App\Features\Reports\Persistence\ReportLifecycle as ReportLifecycleImplementation;
use App\Platform\FeatureModule;
use App\Platform\Reporting\ReportLifecycle;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(ReportLifecycle::class, ReportLifecycleImplementation::class);
    }
}
