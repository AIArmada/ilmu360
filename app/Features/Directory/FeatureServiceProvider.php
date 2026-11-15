<?php

declare(strict_types=1);

namespace App\Features\Directory;

use App\Features\Directory\Actions\Institutions\InstitutionSubmissionAccessAction;
use App\Features\Directory\Actions\Persons\PersonSubmissionAccessAction;
use App\Features\Directory\Api\DirectoryContributionMedia;
use App\Features\Directory\Api\DirectoryReportSubjectProvider;
use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Features\Directory\Api\InstitutionSearchCache;
use App\Features\Directory\Api\InstitutionSpaceLinks;
use App\Features\Directory\Api\InstitutionSubmissionAccess;
use App\Features\Directory\Api\InstitutionSubmissionOptionsCache;
use App\Features\Directory\Api\InstitutionVenueLinks;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonSearchCache;
use App\Features\Directory\Api\PersonSubmissionAccess;
use App\Features\Directory\Api\PersonSubmissionOptionsCache;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Api\PublicDirectoryCacheVersions;
use App\Features\Directory\Api\PublicDirectoryStats;
use App\Features\Directory\Api\ReferenceQueries;
use App\Features\Directory\Api\ReferenceSearchCache;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Features\Directory\Api\SpaceWorkflow;
use App\Features\Directory\Api\SubmissionEligibilitySync;
use App\Features\Directory\Api\VenueWorkflow;
use App\Features\Directory\Contributors\DirectoryAccountLifecycleContributor;
use App\Features\Directory\EntryPoints\Console\FlagStaleInactiveInstitutions;
use App\Features\Directory\Listeners\MediaDirectoryCacheListener;
use App\Features\Directory\Persistence\InstitutionSearchCacheStore;
use App\Features\Directory\Persistence\InstitutionSubmissionOptionsCacheStore;
use App\Features\Directory\Persistence\PersonSearchCacheStore;
use App\Features\Directory\Persistence\PersonSubmissionOptionsCacheStore;
use App\Features\Directory\Persistence\PublicDirectoryCacheVersionStore;
use App\Features\Directory\Persistence\PublicDirectoryStatsCacheStore;
use App\Features\Directory\Persistence\ReferenceSearchCacheStore;
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
use App\Features\Directory\Services\SpaceEligibilityService;
use App\Features\Directory\Services\SpaceWorkflowService;
use App\Features\Directory\Services\SubmissionEligibilitySyncService;
use App\Features\Directory\Services\VenueWorkflowService;
use App\Features\Directory\Support\DirectoryModel;
use App\Platform\FeatureModule;
use App\Platform\Media\MediaChangeListener;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Platform\Search\ResourceSearchAdapter;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Directory\SpaceEligibility;

/**
 * Directory owns institutions, speakers, references, venues, spaces and their
 * shared contacts, addresses, names and relations. Persistent mutations are
 * declared in feature.json; account lifecycle and eligibility updates use
 * Directory implementations. The app provider composes the reader and command
 * adapters over these public workflows.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(SubmissionEligibilitySync::class, SubmissionEligibilitySyncService::class);
        $this->app->bind(InstitutionWorkflow::class, InstitutionWorkflowService::class);
        $this->app->bind(InstitutionVenueLinks::class, InstitutionVenueLinkService::class);
        $this->app->bind(InstitutionSpaceLinks::class, InstitutionSpaceLinkService::class);
        $this->app->bind(InstitutionSubmissionAccess::class, InstitutionSubmissionAccessAction::class);
        $this->app->bind(InstitutionSubmissionOptionsCache::class, InstitutionSubmissionOptionsCacheStore::class);
        $this->app->bind(InstitutionSearchCache::class, InstitutionSearchCacheStore::class);
        $this->app->bind(PersonWorkflow::class, PersonWorkflowService::class);
        $this->app->bind(PersonSearchCache::class, PersonSearchCacheStore::class);
        $this->app->bind(PersonSubmissionOptionsCache::class, PersonSubmissionOptionsCacheStore::class);
        $this->app->bind(PersonSubmissionAccess::class, PersonSubmissionAccessAction::class);
        $this->app->bind(PublicDirectoryCacheVersions::class, PublicDirectoryCacheVersionStore::class);
        $this->app->bind(PublicDirectoryStats::class, PublicDirectoryStatsCacheStore::class);
        $this->app->bind(ReferenceSearchCache::class, ReferenceSearchCacheStore::class);
        $this->app->bind(ReferenceWorkflow::class, ReferenceWorkflowService::class);
        $this->app->bind(ReferenceQueries::class, ReferenceQueryService::class);
        $this->app->bind(SpaceEligibility::class, SpaceEligibilityService::class);
        $this->app->bind(VenueWorkflow::class, VenueWorkflowService::class);
        $this->app->bind(SpaceWorkflow::class, SpaceWorkflowService::class);
        $this->app->bind(DirectoryReportSubjectProvider::class, DirectoryReportSubjectResolver::class);
        $this->app->bind(DirectoryContributionMedia::class, DirectoryContributionMediaService::class);
        $this->app->bind(DonationChannelWorkflow::class, DonationChannelWorkflowService::class);

        $this->app->tag([DirectoryAccountLifecycleContributor::class], AccountLifecycleContributor::TAG);
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
