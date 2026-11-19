<?php

declare(strict_types=1);

namespace App\Features\Discovery;

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Discovery\Services\EventSearchFilterCatalog;
use App\Features\Discovery\Services\EventSearchFilterNormalizer;
use App\Features\Discovery\Services\EventSearchFilterRules;
use App\Features\Discovery\Services\PublicListingsCache;
use App\Platform\FeatureModule;
use App\Shared\Discovery\SearchFilterCatalog;
use App\Shared\Discovery\SearchFilterNormalizer;
use App\Shared\Discovery\SearchFilterRules;

/**
 * Discovery owns the public search-filter normalization, validation and
 * catalog rules, plus cache invalidation orchestration through its public Api.
 * Cross-feature model hydration remains a root integration concern until it
 * can be decoupled behind explicit read-side public boundaries.
 * No database tables or cache stores are newly claimed by this module.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(PublicListingsCacheInvalidator::class, PublicListingsCache::class);
        $this->app->bind(SearchFilterCatalog::class, EventSearchFilterCatalog::class);
        $this->app->bind(SearchFilterNormalizer::class, EventSearchFilterNormalizer::class);
        $this->app->bind(SearchFilterRules::class, EventSearchFilterRules::class);
    }
}
