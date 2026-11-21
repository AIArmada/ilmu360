<?php

declare(strict_types=1);

namespace App\Features\Discovery;

use App\Features\Discovery\Actions\InvalidateAddressCatalogsAction;
use App\Features\Discovery\Api\EventSearchReader;
use App\Features\Discovery\Api\Orm\PublicScheduleOrmPort;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Discovery\Api\SearchFilterCatalog;
use App\Features\Discovery\Api\SearchFilterLabels;
use App\Features\Discovery\Api\SearchFilterNormalizer;
use App\Features\Discovery\Api\SearchFilterRules;
use App\Features\Discovery\Contributors\FrontendManifestContributor;
use App\Features\Discovery\Queries\DatabaseSearchFilterLabels;
use App\Features\Discovery\Services\EventSearchFilterCatalog;
use App\Features\Discovery\Services\EventSearchFilterNormalizer;
use App\Features\Discovery\Services\EventSearchFilterRules;
use App\Features\Discovery\Services\EventSearchService;
use App\Features\Discovery\Services\PublicListingsCache;
use App\Features\Discovery\Services\PublicScheduleDiscoveryService;
use App\Features\Discovery\Support\Telemetry\DiscoverySignalSchemaContributor;
use App\Platform\Api\Frontend\FrontendFormContractRegistry;
use App\Platform\FeatureModule;
use App\Platform\Telemetry\SignalSchemaRegistry;
use App\Shared\Contracts\AddressCatalogEffects;

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
        $this->app->bind(PublicScheduleOrmPort::class, PublicScheduleDiscoveryService::class);
        $this->app->bind(EventSearchReader::class, EventSearchService::class);
        $this->app->scoped(SearchFilterLabels::class, DatabaseSearchFilterLabels::class);
        $this->app->bind(AddressCatalogEffects::class, InvalidateAddressCatalogsAction::class);
        $this->app->bind(PublicListingsCacheInvalidator::class, PublicListingsCache::class);
        $this->app->bind(SearchFilterCatalog::class, EventSearchFilterCatalog::class);
        $this->app->bind(SearchFilterNormalizer::class, EventSearchFilterNormalizer::class);
        $this->app->bind(SearchFilterRules::class, EventSearchFilterRules::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(FrontendFormContractRegistry::class)->register(FrontendManifestContributor::class);

        $this->app->make(SignalSchemaRegistry::class)->register(new DiscoverySignalSchemaContributor);
    }
}
