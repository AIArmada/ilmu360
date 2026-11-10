<?php

declare(strict_types=1);

namespace App\Features\Directory;

use App\Features\Directory\Actions\Institutions\InstitutionSubmissionAccessAction;
use App\Features\Directory\Api\DirectoryReportSubjectProvider;
use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Features\Directory\Api\InstitutionSubmissionAccess;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\EntryPoints\Console\FlagStaleInactiveInstitutions;
use App\Features\Directory\Listeners\MediaDirectoryCacheListener;
use App\Features\Directory\Services\DirectoryReportSubjectResolver;
use App\Features\Directory\Services\DonationChannelWorkflowService;
use App\Features\Directory\Services\InstitutionWorkflowService;
use App\Platform\FeatureModule;
use App\Platform\Media\MediaChangeListener;

/**
 * Directory owns institutions, speakers, references, venues, spaces and their
 * shared contacts, addresses, names and relations.
 *
 * Write ownership is enforced through the Actions listed in feature.json.
 * Legacy root models/readers remain in place until their consuming slices
 * (Events, Contributions, Discovery) move behind the Directory Api.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(InstitutionWorkflow::class, InstitutionWorkflowService::class);
        $this->app->bind(InstitutionSubmissionAccess::class, InstitutionSubmissionAccessAction::class);
        $this->app->bind(DirectoryReportSubjectProvider::class, DirectoryReportSubjectResolver::class);
        $this->app->bind(DonationChannelWorkflow::class, DonationChannelWorkflowService::class);

        $this->app->tag([MediaDirectoryCacheListener::class], MediaChangeListener::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([
            FlagStaleInactiveInstitutions::class,
        ]);
    }
}
