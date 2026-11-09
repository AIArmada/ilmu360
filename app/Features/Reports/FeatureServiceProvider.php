<?php

declare(strict_types=1);

namespace App\Features\Reports;

use App\Features\Reports\Api\ReportAdminContract;
use App\Features\Reports\Api\ReportCommands as ReportCommandsContract;
use App\Features\Reports\Api\ReportReader;
use App\Features\Reports\Api\ReportTriageContract;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Features\Reports\EntryPoints\Livewire\Pages\Reports\Create;
use App\Features\Reports\Models\Report;
use App\Features\Reports\Persistence\ReportLifecycle as ReportLifecycleImplementation;
use App\Features\Reports\Policies\ReportPolicy;
use App\Features\Reports\Queries\DatabaseReportReader;
use App\Features\Reports\Services\ReportAdmin;
use App\Features\Reports\Services\ReportCommands;
use App\Features\Reports\Services\ReportTriage;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\FeatureModule;
use App\Platform\Reporting\ReportLifecycle;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(ReportLifecycle::class, ReportLifecycleImplementation::class);
        $this->app->bind(ReportCommandsContract::class, ReportCommands::class);
        $this->app->bind(ReportReader::class, DatabaseReportReader::class);
        $this->app->bind(ReportAdminContract::class, ReportAdmin::class);
        $this->app->bind(ReportTriageContract::class, ReportTriage::class);

        $this->app->make(FilamentResourceRegistry::class)->register(ReportResource::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Report::class, ReportPolicy::class);
        Relation::morphMap(['report' => Report::class]);

        Livewire::component('pages.reports.create', Create::class);
    }
}
