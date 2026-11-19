<?php

declare(strict_types=1);

namespace App\Features\References;

use App\Features\References\Api\ReferenceCatalog;
use App\Features\References\Api\ReferenceHomepageStats;
use App\Features\References\Api\ReferenceLookup;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Api\ReferenceSearchCache;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\References\EntryPoints\Admin\Member\ReferenceMemberResourceHooks;
use App\Features\References\EntryPoints\Admin\Resources\ReferenceAdminHooks;
use App\Features\References\EntryPoints\Admin\Resources\ReferenceMutationHooks;
use App\Features\References\EntryPoints\Filament\Ahli\Resources\References\ReferenceResource as AhliReferenceResource;
use App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource;
use App\Features\References\EntryPoints\Filament\Stats\PendingReferencesStat;
use App\Features\References\Models\Reference;
use App\Features\References\Persistence\ReferenceHomepageStatsCacheStore;
use App\Features\References\Persistence\ReferenceSearchCacheStore;
use App\Features\References\Queries\EloquentReferenceLookup;
use App\Features\References\Queries\ReferenceResourceSearchAdapter;
use App\Features\References\Services\EloquentReferenceCatalog;
use App\Features\References\Services\ReferenceQueryService;
use App\Features\References\Services\ReferenceWorkflowService;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Member\MemberResourceHookRegistry;
use App\Platform\FeatureModule;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Platform\Search\ResourceSearchAdapter;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->make(\App\Platform\Api\Admin\AdminResourceHookRegistry::class)->register(new ReferenceAdminHooks);

        $this->app->bind(ReferenceCatalog::class, EloquentReferenceCatalog::class);
        $this->app->tag([PendingReferencesStat::class], 'admin.stats');

        $this->commands([
            EntryPoints\Console\Commands\IndexReferencesToTypesense::class,
        ]);

        $this->app->bind(ReferenceSearchCache::class, ReferenceSearchCacheStore::class);
        $this->app->bind(ReferenceWorkflow::class, ReferenceWorkflowService::class);
        $this->app->bind(ReferenceQueries::class, ReferenceQueryService::class);
        $this->app->bind(ReferenceLookup::class, EloquentReferenceLookup::class);
        $this->app->bind(ReferenceHomepageStats::class, ReferenceHomepageStatsCacheStore::class);
        $this->app->tag([ReferenceResourceSearchAdapter::class], ResourceSearchAdapter::TAG);

        $resources = $this->app->make(FilamentResourceRegistry::class);
        $resources->register(ReferenceResource::class, FilamentResourceRegistry::PANEL_ADMIN);
        $resources->register(AhliReferenceResource::class, FilamentResourceRegistry::PANEL_AHLI);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(MemberResourceHookRegistry::class)->register($this->app->make(ReferenceMemberResourceHooks::class));

        $this->app->make(AdminMutationHookRegistry::class)->register($this->app->make(ReferenceMutationHooks::class));
        $this->app->make(PublicSlugRegistry::class)->register(new PublicSlugResource(
            parameter: 'reference',
            routeName: 'references.show',
            modelClass: Reference::class,
            searchColumns: ['title', 'slug'],
            labelAttribute: 'title',
        ));
    }
}
