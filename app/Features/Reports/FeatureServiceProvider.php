<?php

declare(strict_types=1);

namespace App\Features\Reports;

use App\Features\Reports\Api\Frontend\ReportFormContractContributor;
use App\Features\Reports\Api\ReportAdminContract;
use App\Features\Reports\Api\ReportCommands as ReportCommandsContract;
use App\Features\Reports\Api\ReportReader;
use App\Features\Reports\Api\ReportSubjectResolver;
use App\Features\Reports\Api\ReportTriageContract;
use App\Features\Reports\Contracts\ReportLifecycle;
use App\Features\Reports\EntryPoints\Admin\RecordActions\ReportRecordActionContributor;
use App\Features\Reports\EntryPoints\Api\Admin\ReportResourceDefinition;
use App\Features\Reports\EntryPoints\ApiDocumentation\ReportsRequestExampleContributor;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Features\Reports\EntryPoints\Mcp\Tools\AdminGetReportTriageSchemaTool;
use App\Features\Reports\EntryPoints\Mcp\Tools\AdminTriageReportTool;
use App\Features\Reports\Models\Report;
use App\Features\Reports\Persistence\ReportLifecycle as ReportLifecycleImplementation;
use App\Features\Reports\Policies\ReportPolicy;
use App\Features\Reports\Queries\DatabaseReportReader;
use App\Features\Reports\Services\CompositeReportSubjectResolver;
use App\Features\Reports\Services\ReportAdmin;
use App\Features\Reports\Services\ReportCommands;
use App\Features\Reports\Services\ReportTriage;
use App\Features\Reports\Support\Telemetry\ReportsSignalSchemaContributor;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\RecordActionContributorRegistry;
use App\Platform\Api\Frontend\FrontendFormContractRegistry;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\ApiDocumentation\RequestExampleRegistry;
use App\Platform\FeatureModule;
use App\Platform\Mcp\McpSurfaceRegistry;
use App\Platform\Telemetry\SignalSchemaRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(ReportSubjectResolver::class, CompositeReportSubjectResolver::class);
        $this->app->bind(ReportLifecycle::class, ReportLifecycleImplementation::class);
        $this->app->bind(ReportCommandsContract::class, ReportCommands::class);
        $this->app->bind(ReportReader::class, DatabaseReportReader::class);
        $this->app->bind(ReportAdminContract::class, ReportAdmin::class);
        $this->app->bind(ReportTriageContract::class, ReportTriage::class);

        $this->app->make(FilamentResourceRegistry::class)->register(ReportResource::class);
    }

    public function boot(): void
    {
        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(ReportResourceDefinition::class));
        parent::boot();

        $this->app->make(SignalSchemaRegistry::class)->register(new ReportsSignalSchemaContributor);
        $this->app->make(RequestExampleRegistry::class)->register(new ReportsRequestExampleContributor);

        Gate::policy(Report::class, ReportPolicy::class);
        Relation::morphMap(['report' => Report::class]);

        $this->app->make(RecordActionContributorRegistry::class)->register($this->app->make(ReportRecordActionContributor::class));

        $this->app->make(FrontendFormContractRegistry::class)->register(ReportFormContractContributor::class);

        $surfaces = $this->app->make(McpSurfaceRegistry::class);

        $surfaces->registerAdminTool(AdminGetReportTriageSchemaTool::class);
        $surfaces->registerAdminTool(AdminTriageReportTool::class);
    }
}
