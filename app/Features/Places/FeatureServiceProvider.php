<?php

declare(strict_types=1);

namespace App\Features\Places;

use App\Features\Places\Api\Filament\VenueQuickAdd;
use App\Features\Places\Api\InstitutionSpaceLinks;
use App\Features\Places\Api\Orm\PlaceLookup;
use App\Features\Places\Api\Orm\VenueAuthoringCandidates;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\Places\Api\SpaceEligibility;
use App\Features\Places\Api\SpaceWorkflow;
use App\Features\Places\Api\VenueWorkflow;
use App\Features\Places\EntryPoints\Admin\Resources\PlaceMutationHooks;
use App\Features\Places\EntryPoints\Api\Admin\SpaceResourceDefinition;
use App\Features\Places\EntryPoints\Api\Admin\VenueResourceDefinition;
use App\Features\Places\EntryPoints\Filament\Forms\VenueFormSchema;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource;
use App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource;
use App\Features\Places\EntryPoints\Filament\Stats\PendingVenuesStat;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Features\Places\Observers\SpaceInstitutionLinkCleanupObserver;
use App\Features\Places\Observers\VenueInstitutionLinkCleanupObserver;
use App\Features\Places\Queries\EloquentPlaceLookup;
use App\Features\Places\Queries\EloquentVenueAuthoringCandidates;
use App\Features\Places\Services\EloquentPlaceCatalog;
use App\Features\Places\Services\InstitutionSpaceLinkService;
use App\Features\Places\Services\SpaceEligibilityService;
use App\Features\Places\Services\SpaceWorkflowService;
use App\Features\Places\Services\VenueWorkflowService;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\FeatureModule;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Shared\Submission\SpaceSelectionEligibility;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(VenueQuickAdd::class, VenueFormSchema::class);
        $this->app->bind(PlaceCatalog::class, EloquentPlaceCatalog::class);
        $this->app->tag([PendingVenuesStat::class], 'admin.stats');

        $this->app->bind(InstitutionSpaceLinks::class, InstitutionSpaceLinkService::class);
        $this->app->bind(SpaceEligibility::class, SpaceEligibilityService::class);
        $this->app->bind(SpaceSelectionEligibility::class, SpaceEligibilityService::class);
        $this->app->bind(VenueWorkflow::class, VenueWorkflowService::class);
        $this->app->bind(SpaceWorkflow::class, SpaceWorkflowService::class);
        $this->app->bind(PlaceLookup::class, EloquentPlaceLookup::class);
        $this->app->bind(VenueAuthoringCandidates::class, EloquentVenueAuthoringCandidates::class);

        $resources = $this->app->make(FilamentResourceRegistry::class);
        $resources->register(SpaceResource::class);
        $resources->register(VenueResource::class);
    }

    public function boot(): void
    {
        Space::observe(SpaceInstitutionLinkCleanupObserver::class);
        Venue::observe(VenueInstitutionLinkCleanupObserver::class);

        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(SpaceResourceDefinition::class));
        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(VenueResourceDefinition::class));
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
