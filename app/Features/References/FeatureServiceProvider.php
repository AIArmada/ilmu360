<?php

declare(strict_types=1);

namespace App\Features\References;

use App\Features\References\Api\ReferenceHomepageStats;
use App\Features\References\Api\ReferenceLookup;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Api\ReferenceSearchCache;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\References\Persistence\ReferenceHomepageStatsCacheStore;
use App\Features\References\Persistence\ReferenceSearchCacheStore;
use App\Features\References\Queries\EloquentReferenceLookup;
use App\Features\References\Queries\ReferenceResourceSearchAdapter;
use App\Features\References\Services\ReferenceQueryService;
use App\Features\References\Services\ReferenceWorkflowService;
use App\Models\Reference;
use App\Platform\FeatureModule;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Platform\Search\ResourceSearchAdapter;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(ReferenceSearchCache::class, ReferenceSearchCacheStore::class);
        $this->app->bind(ReferenceWorkflow::class, ReferenceWorkflowService::class);
        $this->app->bind(ReferenceQueries::class, ReferenceQueryService::class);
        $this->app->bind(ReferenceLookup::class, EloquentReferenceLookup::class);
        $this->app->bind(ReferenceHomepageStats::class, ReferenceHomepageStatsCacheStore::class);
        $this->app->tag([ReferenceResourceSearchAdapter::class], ResourceSearchAdapter::TAG);
    }

    public function boot(): void
    {
        parent::boot();
        $this->app->make(PublicSlugRegistry::class)->register(new PublicSlugResource(
            parameter: 'reference',
            routeName: 'references.show',
            modelClass: Reference::class,
            searchColumns: ['title', 'slug'],
            labelAttribute: 'title',
        ));
    }
}
