<?php

declare(strict_types=1);

namespace App\Features\Institutions;

use App\Features\Institutions\Actions\InstitutionSubmissionAccessAction;
use App\Features\Institutions\Actions\ResolveInstitutionMapsPlaceSelectionAction;
use App\Features\Institutions\Actions\SyncAddressChangeEffectsAction;
use App\Features\Institutions\Api\ContributionProjection;
use App\Features\Institutions\Api\Filament\InstitutionQuickAdd;
use App\Features\Institutions\Api\Frontend\InstitutionWorkspaceFormContractContributor;
use App\Features\Institutions\Api\InstitutionAuthoringCandidates;
use App\Features\Institutions\Api\InstitutionCards;
use App\Features\Institutions\Api\InstitutionCatalog;
use App\Features\Institutions\Api\InstitutionHomepageStats;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Institutions\Api\InstitutionMapsSelection;
use App\Features\Institutions\Api\InstitutionSearch;
use App\Features\Institutions\Api\InstitutionSearchCache;
use App\Features\Institutions\Api\InstitutionSubmissionAccess;
use App\Features\Institutions\Api\InstitutionSubmissionOptionsCache;
use App\Features\Institutions\Api\InstitutionVenueLinks;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\EntryPoints\Admin\Member\InstitutionMemberResourceHooks;
use App\Features\Institutions\EntryPoints\Admin\Resources\InstitutionMutationHooks;
use App\Features\Institutions\EntryPoints\Api\Admin\InstitutionResourceDefinition;
use App\Features\Institutions\EntryPoints\Api\Member\InstitutionResourceDefinition as InstitutionMemberResourceDefinition;
use App\Features\Institutions\EntryPoints\ApiDocumentation\InstitutionWorkspaceRequestExampleContributor;
use App\Features\Institutions\EntryPoints\Console\Commands\PruneOrphanedEntities;
use App\Features\Institutions\EntryPoints\Console\FlagStaleInactiveInstitutions;
use App\Features\Institutions\EntryPoints\Filament\Ahli\Resources\Institutions\InstitutionResource as AhliInstitutionResource;
use App\Features\Institutions\EntryPoints\Filament\Forms\InstitutionFormSchema;
use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\InstitutionResource;
use App\Features\Institutions\EntryPoints\Filament\Stats\PendingInstitutionsStat;
use App\Features\Institutions\EntryPoints\Sitemap\InstitutionSitemapUrls;
use App\Features\Institutions\Listeners\InstitutionMediaCacheListener;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Models\InstitutionImportExclusion;
use App\Features\Institutions\Models\InstitutionName;
use App\Features\Institutions\Models\InstitutionVenue;
use App\Features\Institutions\Observers\InstitutionImportExclusionObserver;
use App\Features\Institutions\Observers\InstitutionNameObserver;
use App\Features\Institutions\Observers\InstitutionObserver;
use App\Features\Institutions\Persistence\InstitutionHomepageStatsCacheStore;
use App\Features\Institutions\Persistence\InstitutionListingCacheVersionStore;
use App\Features\Institutions\Persistence\InstitutionSearchCacheStore;
use App\Features\Institutions\Persistence\InstitutionSubmissionOptionsCacheStore;
use App\Features\Institutions\Queries\EloquentInstitutionAuthoringCandidates;
use App\Features\Institutions\Queries\EloquentInstitutionLookup;
use App\Features\Institutions\Queries\InstitutionCardsQuery;
use App\Features\Institutions\Queries\InstitutionContributionProjection;
use App\Features\Institutions\Queries\InstitutionResourceSearchAdapter;
use App\Features\Institutions\Services\EloquentInstitutionCatalog;
use App\Features\Institutions\Services\InstitutionSearchService;
use App\Features\Institutions\Services\InstitutionVenueLinkService;
use App\Features\Institutions\Services\InstitutionWorkflowService;
use App\Features\Institutions\Support\Telemetry\InstitutionsSignalSchemaContributor;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Frontend\FrontendFormContractRegistry;
use App\Platform\Api\Member\MemberResourceHookRegistry;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\ApiDocumentation\RequestExampleRegistry;
use App\Platform\FeatureModule;
use App\Platform\Media\MediaChangeListener;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use App\Platform\Search\ResourceSearchAdapter;
use App\Platform\Telemetry\SignalSchemaRegistry;
use App\Shared\Contracts\AddressableChangeEffects;
use App\Shared\Contracts\AddressChangeEffects;
use Illuminate\Database\Eloquent\Relations\Relation;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->tag([InstitutionResourceSearchAdapter::class], ResourceSearchAdapter::TAG);
        $this->app->bind(InstitutionSearch::class, InstitutionSearchService::class);
        $this->app->bind(InstitutionCards::class, InstitutionCardsQuery::class);
        $this->app->bind(AddressChangeEffects::class, SyncAddressChangeEffectsAction::class);
        $this->app->bind(AddressableChangeEffects::class, SyncAddressChangeEffectsAction::class);
        $this->app->bind(ContributionProjection::class, InstitutionContributionProjection::class);
        $this->app->bind(InstitutionQuickAdd::class, InstitutionFormSchema::class);
        $this->app->bind(InstitutionCatalog::class, EloquentInstitutionCatalog::class);
        $this->app->tag([PendingInstitutionsStat::class], 'admin.stats');

        $this->app->tag([InstitutionSitemapUrls::class], 'sitemap.urls');

        $this->commands([
            EntryPoints\Console\Commands\IndexInstitutionsToTypesense::class,
        ]);

        $this->app->bind(InstitutionWorkflow::class, InstitutionWorkflowService::class);
        $this->app->bind(InstitutionMapsSelection::class, ResolveInstitutionMapsPlaceSelectionAction::class);
        $this->app->bind(InstitutionVenueLinks::class, InstitutionVenueLinkService::class);
        $this->app->bind(InstitutionSubmissionAccess::class, InstitutionSubmissionAccessAction::class);
        $this->app->bind(InstitutionSubmissionOptionsCache::class, InstitutionSubmissionOptionsCacheStore::class);
        $this->app->bind(InstitutionSearchCache::class, InstitutionSearchCacheStore::class);
        $this->app->bind(InstitutionLookup::class, EloquentInstitutionLookup::class);
        $this->app->bind(InstitutionAuthoringCandidates::class, EloquentInstitutionAuthoringCandidates::class);
        $this->app->bind(InstitutionHomepageStats::class, InstitutionHomepageStatsCacheStore::class);
        $this->app->bind(InstitutionListingCacheVersion::class, InstitutionListingCacheVersionStore::class);
        $this->app->tag([InstitutionMediaCacheListener::class], MediaChangeListener::TAG);

        $resources = $this->app->make(FilamentResourceRegistry::class);
        $resources->register(InstitutionResource::class, FilamentResourceRegistry::PANEL_ADMIN);
        $resources->register(AhliInstitutionResource::class, FilamentResourceRegistry::PANEL_AHLI);
    }

    public function boot(): void
    {
        Relation::morphMap([
            'institution' => Institution::class,
            'institution_venue' => InstitutionVenue::class,
            'institution_name' => InstitutionName::class,
            'institution_import_exclusion' => InstitutionImportExclusion::class,
        ]);

        Institution::observe(InstitutionObserver::class);
        Institution::observe(InstitutionImportExclusionObserver::class);
        InstitutionName::observe(InstitutionNameObserver::class);

        $memberRegistry = $this->app->make(ResourceDefinitionRegistry::class);
        $memberDefinition = $this->app->make(InstitutionMemberResourceDefinition::class);
        $memberRegistry->register($memberDefinition);
        $memberRegistry->registerWriter($memberDefinition);

        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(InstitutionResourceDefinition::class));
        $this->app->make(SignalSchemaRegistry::class)->register(new InstitutionsSignalSchemaContributor);
        $this->app->make(RequestExampleRegistry::class)->register(new InstitutionWorkspaceRequestExampleContributor);

        parent::boot();

        $this->app->make(PublicSlugRegistry::class)->register(new PublicSlugResource(
            parameter: 'institution',
            routeName: 'institutions.show',
            modelClass: Institution::class,
            searchColumns: ['name', 'slug'],
            labelAttribute: 'name',
        ));

        $this->app->make(FrontendFormContractRegistry::class)->register(InstitutionWorkspaceFormContractContributor::class);

        $this->app->make(MemberResourceHookRegistry::class)->register($this->app->make(InstitutionMemberResourceHooks::class));

        $this->app->make(AdminMutationHookRegistry::class)->register($this->app->make(InstitutionMutationHooks::class));
        $this->commands([FlagStaleInactiveInstitutions::class, PruneOrphanedEntities::class]);
    }
}
