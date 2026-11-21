<?php

declare(strict_types=1);

namespace App\Features\Persons;

use App\Features\Persons\Actions\PersonSubmissionAccessAction;
use App\Features\Persons\Api\ContributionProjection;
use App\Features\Persons\Api\Filament\PersonQuickAdd;
use App\Features\Persons\Api\Orm\PersonLookup;
use App\Features\Persons\Api\PersonAuthoringCandidates;
use App\Features\Persons\Api\PersonCatalog;
use App\Features\Persons\Api\PersonHomepageStats;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Persons\Api\PersonSearchCache;
use App\Features\Persons\Api\PersonSubmissionAccess;
use App\Features\Persons\Api\PersonSubmissionOptionsCache;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\EntryPoints\Admin\Member\PersonMemberResourceHooks;
use App\Features\Persons\EntryPoints\Admin\Resources\PersonAdminHooks;
use App\Features\Persons\EntryPoints\Admin\Resources\PersonMutationHooks;
use App\Features\Persons\EntryPoints\Api\Admin\PersonResourceDefinition;
use App\Features\Persons\EntryPoints\Api\Member\PersonResourceDefinition as PersonMemberResourceDefinition;
use App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\PersonResource as AhliPersonResource;
use App\Features\Persons\EntryPoints\Filament\Forms\PersonFormSchema;
use App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource;
use App\Features\Persons\EntryPoints\Filament\Stats\PendingPersonsStat;
use App\Features\Persons\EntryPoints\Sitemap\PersonSitemapUrls;
use App\Features\Persons\Listeners\PersonMediaCacheListener;
use App\Features\Persons\Persistence\PersonHomepageStatsCacheStore;
use App\Features\Persons\Persistence\PersonListingCacheVersionStore;
use App\Features\Persons\Persistence\PersonSearchCacheStore;
use App\Features\Persons\Persistence\PersonSubmissionOptionsCacheStore;
use App\Features\Persons\Queries\EloquentPersonAuthoringCandidates;
use App\Features\Persons\Queries\EloquentPersonLookup;
use App\Features\Persons\Queries\PersonContributionProjection;
use App\Features\Persons\Services\EloquentPersonCatalog;
use App\Features\Persons\Services\PersonWorkflowService;
use App\Features\Persons\Support\Telemetry\PersonsSignalSchemaContributor;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Member\MemberResourceHookRegistry;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\FeatureModule;
use App\Platform\Media\MediaChangeListener;
use App\Platform\Telemetry\SignalSchemaRegistry;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(ContributionProjection::class, PersonContributionProjection::class);
        $this->app->bind(PersonQuickAdd::class, PersonFormSchema::class);
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
        $this->app->bind(PersonAuthoringCandidates::class, EloquentPersonAuthoringCandidates::class);
        $this->app->bind(PersonHomepageStats::class, PersonHomepageStatsCacheStore::class);
        $this->app->bind(PersonListingCacheVersion::class, PersonListingCacheVersionStore::class);
        $this->app->tag([PersonMediaCacheListener::class], MediaChangeListener::TAG);

        $resources = $this->app->make(FilamentResourceRegistry::class);
        $resources->register(PersonResource::class, FilamentResourceRegistry::PANEL_ADMIN);
        $resources->register(AhliPersonResource::class, FilamentResourceRegistry::PANEL_AHLI);
    }

    public function boot(): void
    {
        $memberRegistry = $this->app->make(ResourceDefinitionRegistry::class);
        $memberDefinition = $this->app->make(PersonMemberResourceDefinition::class);
        $memberRegistry->register($memberDefinition);
        $memberRegistry->registerWriter($memberDefinition);

        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(PersonResourceDefinition::class));
        $this->app->make(SignalSchemaRegistry::class)->register(new PersonsSignalSchemaContributor);

        parent::boot();

        $this->app->make(MemberResourceHookRegistry::class)->register($this->app->make(PersonMemberResourceHooks::class));

        $this->app->make(AdminMutationHookRegistry::class)->register($this->app->make(PersonMutationHooks::class));
    }
}
