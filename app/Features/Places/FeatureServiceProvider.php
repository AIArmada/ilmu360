<?php

declare(strict_types=1);

namespace App\Features\Places;

use App\Features\Places\Api\InstitutionSpaceLinks;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\Places\Api\PlaceLookup;
use App\Features\Places\Api\SpaceEligibility;
use App\Features\Places\Api\SpaceWorkflow;
use App\Features\Places\Api\VenueWorkflow;
use App\Features\Places\EntryPoints\Admin\Resources\PlaceMutationHooks;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource;
use App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource;
use App\Features\Places\EntryPoints\Filament\Stats\PendingVenuesStat;
use App\Features\Places\Models\Venue;
use App\Features\Places\Queries\EloquentPlaceLookup;
use App\Features\Places\Services\EloquentPlaceCatalog;
use App\Features\Places\Services\InstitutionSpaceLinkService;
use App\Features\Places\Services\SpaceEligibilityService;
use App\Features\Places\Services\SpaceWorkflowService;
use App\Features\Places\Services\VenueWorkflowService;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\FeatureModule;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Shared\Submission\SpaceSelectionEligibility;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(PlaceCatalog::class, EloquentPlaceCatalog::class);
        $this->app->tag([PendingVenuesStat::class], 'admin.stats');

        $this->app->bind(InstitutionSpaceLinks::class, InstitutionSpaceLinkService::class);
        $this->app->bind(SpaceEligibility::class, SpaceEligibilityService::class);
        $this->app->bind(SpaceSelectionEligibility::class, SpaceEligibilityService::class);
        $this->app->bind(VenueWorkflow::class, VenueWorkflowService::class);
        $this->app->bind(SpaceWorkflow::class, SpaceWorkflowService::class);
        $this->app->bind(PlaceLookup::class, EloquentPlaceLookup::class);

        $resources = $this->app->make(FilamentResourceRegistry::class);
        $resources->register(SpaceResource::class);
        $resources->register(VenueResource::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(AdminMutationHookRegistry::class)->register($this->app->make(PlaceMutationHooks::class));
        $this->app->make(PublicSlugRegistry::class)->register(new PublicSlugResource(
            parameter: 'venue',
            routeName: 'venues.show',
            modelClass: Venue::class,
            searchColumns: ['name', 'slug'],
            labelAttribute: 'name',
        ));
    }
}
