<?php

declare(strict_types=1);

namespace App\Features\Institutions;

use App\Features\Institutions\Actions\InstitutionSubmissionAccessAction;
use App\Features\Institutions\Actions\ResolveInstitutionMapsPlaceSelectionAction;
use App\Features\Institutions\Api\InstitutionHomepageStats;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Institutions\Api\InstitutionMapsSelection;
use App\Features\Institutions\Api\InstitutionSearchCache;
use App\Features\Institutions\Api\InstitutionSubmissionAccess;
use App\Features\Institutions\Api\InstitutionSubmissionOptionsCache;
use App\Features\Institutions\Api\InstitutionVenueLinks;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\EntryPoints\Console\FlagStaleInactiveInstitutions;
use App\Features\Institutions\Listeners\InstitutionMediaCacheListener;
use App\Features\Institutions\Persistence\InstitutionHomepageStatsCacheStore;
use App\Features\Institutions\Persistence\InstitutionListingCacheVersionStore;
use App\Features\Institutions\Persistence\InstitutionSearchCacheStore;
use App\Features\Institutions\Persistence\InstitutionSubmissionOptionsCacheStore;
use App\Features\Institutions\Queries\EloquentInstitutionLookup;
use App\Features\Institutions\Services\InstitutionVenueLinkService;
use App\Features\Institutions\Services\InstitutionWorkflowService;
use App\Platform\FeatureModule;
use App\Platform\Media\MediaChangeListener;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(InstitutionWorkflow::class, InstitutionWorkflowService::class);
        $this->app->bind(InstitutionMapsSelection::class, ResolveInstitutionMapsPlaceSelectionAction::class);
        $this->app->bind(InstitutionVenueLinks::class, InstitutionVenueLinkService::class);
        $this->app->bind(InstitutionSubmissionAccess::class, InstitutionSubmissionAccessAction::class);
        $this->app->bind(InstitutionSubmissionOptionsCache::class, InstitutionSubmissionOptionsCacheStore::class);
        $this->app->bind(InstitutionSearchCache::class, InstitutionSearchCacheStore::class);
        $this->app->bind(InstitutionLookup::class, EloquentInstitutionLookup::class);
        $this->app->bind(InstitutionHomepageStats::class, InstitutionHomepageStatsCacheStore::class);
        $this->app->bind(InstitutionListingCacheVersion::class, InstitutionListingCacheVersionStore::class);
        $this->app->tag([InstitutionMediaCacheListener::class], MediaChangeListener::TAG);
    }

    public function boot(): void
    {
        parent::boot();
        $this->commands([FlagStaleInactiveInstitutions::class]);
    }
}
