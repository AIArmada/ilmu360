<?php

declare(strict_types=1);

namespace App\Features\Persons;

use App\Features\Persons\Actions\PersonSubmissionAccessAction;
use App\Features\Persons\Api\PersonHomepageStats;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Api\PersonSearchCache;
use App\Features\Persons\Api\PersonSubmissionAccess;
use App\Features\Persons\Api\PersonSubmissionOptionsCache;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Listeners\PersonMediaCacheListener;
use App\Features\Persons\Persistence\PersonHomepageStatsCacheStore;
use App\Features\Persons\Persistence\PersonListingCacheVersionStore;
use App\Features\Persons\Persistence\PersonSearchCacheStore;
use App\Features\Persons\Persistence\PersonSubmissionOptionsCacheStore;
use App\Features\Persons\Queries\EloquentPersonLookup;
use App\Features\Persons\Services\PersonWorkflowService;
use App\Platform\FeatureModule;
use App\Platform\Media\MediaChangeListener;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->commands([
            EntryPoints\Console\Commands\IndexPersonsToTypesense::class,
        ]);

        $this->app->bind(PersonWorkflow::class, PersonWorkflowService::class);
        $this->app->bind(PersonSearchCache::class, PersonSearchCacheStore::class);
        $this->app->bind(PersonSubmissionOptionsCache::class, PersonSubmissionOptionsCacheStore::class);
        $this->app->bind(PersonSubmissionAccess::class, PersonSubmissionAccessAction::class);
        $this->app->bind(PersonLookup::class, EloquentPersonLookup::class);
        $this->app->bind(PersonHomepageStats::class, PersonHomepageStatsCacheStore::class);
        $this->app->bind(PersonListingCacheVersion::class, PersonListingCacheVersionStore::class);
        $this->app->tag([PersonMediaCacheListener::class], MediaChangeListener::TAG);
    }
}
