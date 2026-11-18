<?php

declare(strict_types=1);

namespace App\Features\Persons;

use App\Features\Persons\Actions\PersonSubmissionAccessAction;
use App\Features\Persons\Api\PersonCatalog;
use App\Features\Persons\Api\PersonHomepageStats;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Api\PersonSearchCache;
use App\Features\Persons\Api\PersonSubmissionAccess;
use App\Features\Persons\Api\PersonSubmissionOptionsCache;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\EntryPoints\Admin\Resources\PersonAdminHooks;
use App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\PersonResource as AhliPersonResource;
use App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource;
use App\Features\Persons\EntryPoints\Filament\Stats\PendingPersonsStat;
use App\Features\Persons\EntryPoints\Sitemap\PersonSitemapUrls;
use App\Features\Persons\Listeners\PersonMediaCacheListener;
use App\Features\Persons\Persistence\PersonHomepageStatsCacheStore;
use App\Features\Persons\Persistence\PersonListingCacheVersionStore;
use App\Features\Persons\Persistence\PersonSearchCacheStore;
use App\Features\Persons\Persistence\PersonSubmissionOptionsCacheStore;
use App\Features\Persons\Queries\EloquentPersonLookup;
use App\Features\Persons\Services\EloquentPersonCatalog;
use App\Features\Persons\Services\PersonWorkflowService;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\FeatureModule;
use App\Platform\Media\MediaChangeListener;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->make(\App\Platform\Api\Admin\AdminResourceHookRegistry::class)->register(new PersonAdminHooks);

        $this->app->bind(PersonCatalog::class, EloquentPersonCatalog::class);
        $this->app->tag([PendingPersonsStat::class], 'admin.stats');

        $this->app->tag([PersonSitemapUrls::class], 'sitemap.urls');

        $this->commands([
            EntryPoints\Console\Commands\IndexPersonsToTypesense::class,
            EntryPoints\Console\Commands\ReindexPersonSearch::class,
        ]);

        $this->app->bind(PersonWorkflow::class, PersonWorkflowService::class);
        $this->app->bind(PersonSearchCache::class, PersonSearchCacheStore::class);
        $this->app->bind(PersonSubmissionOptionsCache::class, PersonSubmissionOptionsCacheStore::class);
        $this->app->bind(PersonSubmissionAccess::class, PersonSubmissionAccessAction::class);
        $this->app->bind(PersonLookup::class, EloquentPersonLookup::class);
        $this->app->bind(PersonHomepageStats::class, PersonHomepageStatsCacheStore::class);
        $this->app->bind(PersonListingCacheVersion::class, PersonListingCacheVersionStore::class);
        $this->app->tag([PersonMediaCacheListener::class], MediaChangeListener::TAG);

        $resources = $this->app->make(FilamentResourceRegistry::class);
        $resources->register(PersonResource::class, FilamentResourceRegistry::PANEL_ADMIN);
        $resources->register(AhliPersonResource::class, FilamentResourceRegistry::PANEL_AHLI);
    }
}
