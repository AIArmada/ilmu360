<?php

declare(strict_types=1);

namespace App\Features\Directory;

use App\Features\Directory\Actions\Institutions\InstitutionSubmissionAccessAction;
use App\Features\Directory\Actions\Persons\PersonSubmissionAccessAction;
use App\Features\Directory\Api\DirectoryContributionMedia;
use App\Features\Directory\Api\DirectoryReportSubjectProvider;
use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Features\Directory\Api\InstitutionSpaceLinks;
use App\Features\Directory\Api\InstitutionSubmissionAccess;
use App\Features\Directory\Api\InstitutionVenueLinks;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonSearchCacheVersion;
use App\Features\Directory\Api\PersonSubmissionAccess;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Api\ReferenceQueries;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Features\Directory\Api\SpaceWorkflow;
use App\Features\Directory\Api\VenueWorkflow;
use App\Features\Directory\EntryPoints\Console\FlagStaleInactiveInstitutions;
use App\Features\Directory\Listeners\MediaDirectoryCacheListener;
use App\Features\Directory\Persistence\PersonSearchCache;
use App\Features\Directory\Queries\ReferenceResourceSearchAdapter;
use App\Features\Directory\Services\DirectoryContributionMediaService;
use App\Features\Directory\Services\DirectoryReportSubjectResolver;
use App\Features\Directory\Services\DonationChannelWorkflowService;
use App\Features\Directory\Services\InstitutionSpaceLinkService;
use App\Features\Directory\Services\InstitutionVenueLinkService;
use App\Features\Directory\Services\InstitutionWorkflowService;
use App\Features\Directory\Services\PersonWorkflowService;
use App\Features\Directory\Services\ReferenceQueryService;
use App\Features\Directory\Services\ReferenceWorkflowService;
use App\Features\Directory\Services\SpaceWorkflowService;
use App\Features\Directory\Services\VenueWorkflowService;
use App\Features\Directory\Support\DirectoryModel;
use App\Platform\FeatureModule;
use App\Platform\Media\MediaChangeListener;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Platform\Search\ResourceSearchAdapter;

/**
 * Directory owns institutions, speakers, references, venues, spaces and their
 * shared contacts, addresses, names and relations.
 *
 * Most writes go through the Actions listed in feature.json. Root writers
 * still remain, so write enforcement is not complete:
 *
 * - `EloquentDirectoryCommands::delete()` falls through to `$record->delete()`
 *   for institution and donation-channel records; the other record types
 *   delegate to their feature actions.
 * - `DirectoryAccountLifecycleContributor` updates `verified_by` directly on
 *   references and venues when detaching or restoring an account.
 *
 * Legacy root models/readers remain in place until their consuming slices
 * (Events, Contributions, Discovery) move behind the Directory Api.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(InstitutionWorkflow::class, InstitutionWorkflowService::class);
        $this->app->bind(InstitutionVenueLinks::class, InstitutionVenueLinkService::class);
        $this->app->bind(InstitutionSpaceLinks::class, InstitutionSpaceLinkService::class);
        $this->app->bind(InstitutionSubmissionAccess::class, InstitutionSubmissionAccessAction::class);
        $this->app->bind(PersonWorkflow::class, PersonWorkflowService::class);
        $this->app->bind(PersonSearchCacheVersion::class, PersonSearchCache::class);
        $this->app->bind(PersonSubmissionAccess::class, PersonSubmissionAccessAction::class);
        $this->app->bind(ReferenceWorkflow::class, ReferenceWorkflowService::class);
        $this->app->bind(ReferenceQueries::class, ReferenceQueryService::class);
        $this->app->bind(VenueWorkflow::class, VenueWorkflowService::class);
        $this->app->bind(SpaceWorkflow::class, SpaceWorkflowService::class);
        $this->app->bind(DirectoryReportSubjectProvider::class, DirectoryReportSubjectResolver::class);
        $this->app->bind(DirectoryContributionMedia::class, DirectoryContributionMediaService::class);
        $this->app->bind(DonationChannelWorkflow::class, DonationChannelWorkflowService::class);

        $this->app->tag([MediaDirectoryCacheListener::class], MediaChangeListener::TAG);
        $this->app->tag([ReferenceResourceSearchAdapter::class], ResourceSearchAdapter::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([
            FlagStaleInactiveInstitutions::class,
        ]);

        $this->app->make(PublicSlugRegistry::class)->register(new PublicSlugResource(
            parameter: 'venue',
            routeName: 'venues.show',
            modelClass: DirectoryModel::venue(),
            searchColumns: ['name', 'slug'],
            labelAttribute: 'name',
        ));

        $this->app->make(PublicSlugRegistry::class)->register(new PublicSlugResource(
            parameter: 'reference',
            routeName: 'references.show',
            modelClass: DirectoryModel::reference(),
            searchColumns: ['title', 'slug'],
            labelAttribute: 'title',
        ));
    }
}
