<?php

declare(strict_types=1);

namespace App\Features\Places;

use App\Features\Places\Api\InstitutionSpaceLinks;
use App\Features\Places\Api\PlaceLookup;
use App\Features\Places\Api\SpaceEligibility;
use App\Features\Places\Api\SpaceWorkflow;
use App\Features\Places\Api\VenueWorkflow;
use App\Features\Places\Queries\EloquentPlaceLookup;
use App\Features\Places\Services\InstitutionSpaceLinkService;
use App\Features\Places\Services\SpaceEligibilityService;
use App\Features\Places\Services\SpaceWorkflowService;
use App\Features\Places\Services\VenueWorkflowService;
use App\Models\Venue;
use App\Platform\FeatureModule;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(InstitutionSpaceLinks::class, InstitutionSpaceLinkService::class);
        $this->app->bind(SpaceEligibility::class, SpaceEligibilityService::class);
        $this->app->bind(VenueWorkflow::class, VenueWorkflowService::class);
        $this->app->bind(SpaceWorkflow::class, SpaceWorkflowService::class);
        $this->app->bind(PlaceLookup::class, EloquentPlaceLookup::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->app->make(PublicSlugRegistry::class)->register(new PublicSlugResource(
            parameter: 'venue',
            routeName: 'venues.show',
            modelClass: Venue::class,
            searchColumns: ['name', 'slug'],
            labelAttribute: 'name',
        ));
    }
}
