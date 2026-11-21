<?php

declare(strict_types=1);

namespace App\Providers;

use AIArmada\FilamentRewards\Resources\RewardAwardResource\RewardAwardResource;
use AIArmada\FilamentRewards\Resources\RewardFraudResource\RewardFraudResource;
use App\Features\Identity\EntryPoints\Api\Admin\UserAdminResourceHooks;
use App\Platform\Admin\ExcludedFromAdminApi;
use App\Platform\Admin\FilamentPageRegistry;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Admin\FilamentWidgetRegistry;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Admin\AdminResourceHookRegistry;
use App\Platform\Api\Admin\RecordActionContributorRegistry;
use App\Platform\Api\Frontend\Contributors\MobileTelemetryFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractRegistry;
use App\Platform\Api\Member\MemberResourceHookRegistry;
use App\Platform\Api\Member\MemberWorkflowToolRegistry;
use App\Platform\Api\Resources\AddressAreaResourceDefinition;
use App\Platform\Api\Resources\AdminResourceMetadataBuilder;
use App\Platform\Api\Resources\EloquentResourceQuery;
use App\Platform\Api\Resources\EloquentResourceSerializer;
use App\Platform\Api\Resources\EloquentResourceWriterSupport;
use App\Platform\Api\Resources\FilamentBackedResourceDefinition;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceActorResolver;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use App\Platform\Api\Resources\ResourceDefinitionDirectory;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\Api\Resources\ResourceIntrospection;
use App\Platform\Api\Resources\ResourceSurface;
use App\Platform\Auditing\EntryPoints\Api\Admin\AuditResourceDefinition;
use App\Platform\Auditing\EntryPoints\Filament\Resources\Audits\AuditResource;
use Filament\Facades\Filament;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the platform admin registry before feature providers register, so
 * features can contribute Filament resources ahead of panel construction.
 */
class AdminRegistryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FilamentResourceRegistry::class);
        $this->app->singleton(FilamentPageRegistry::class);
        $this->app->singleton(FilamentWidgetRegistry::class);
        $this->app->singleton(AdminMutationHookRegistry::class);
        $this->app->singleton(MemberResourceHookRegistry::class);
        $this->app->singleton(MemberWorkflowToolRegistry::class);
        $this->app->singleton(AdminResourceHookRegistry::class);
        $this->app->singleton(RecordActionContributorRegistry::class);
        $this->app->singleton(FrontendFormContractRegistry::class);

        $this->app->singleton(ResourceDefinitionRegistry::class);
        $this->app->make(ResourceDefinitionRegistry::class)->allowCollision(
            ResourceSurface::Admin,
            'reward-awards',
            RewardAwardResource::class,
            RewardFraudResource::class,
        );
        $this->app->singleton(ResourceIntrospection::class);
        $this->app->singleton(ResourceDefinitionDirectory::class);
        $this->app->singleton(EloquentResourceQuery::class);
        $this->app->singleton(EloquentResourceSerializer::class);
        $this->app->singleton(AdminResourceMetadataBuilder::class);
        $this->app->singleton(ResourceActorResolver::class);
        $this->app->singleton(EloquentResourceWriterSupport::class);
        $this->app->singleton(ResourceDefinitionContext::class);

        $this->app->make(FilamentResourceRegistry::class)->register(AuditResource::class);
    }

    public function boot(): void
    {
        $contracts = $this->app->make(FrontendFormContractRegistry::class);

        $contracts->register(MobileTelemetryFormContractContributor::class);

        $this->app->booted(function (): void {
            $this->registerAdminResourceDefinitions();
        });
    }

    private function registerAdminResourceDefinitions(): void
    {
        $registry = $this->app->make(ResourceDefinitionRegistry::class);
        $context = $this->app->make(ResourceDefinitionContext::class);

        $this->app->make(AdminResourceHookRegistry::class)->register(
            $this->app->make(UserAdminResourceHooks::class),
        );

        // Platform/package-owned definitions that are not feature resources.
        $this->registerDefinition($registry, $this->app->make(AuditResourceDefinition::class));
        $this->registerDefinition($registry, $this->app->make(AddressAreaResourceDefinition::class));

        /** @var list<class-string<\Filament\Resources\Resource>> $resources */
        $resources = array_values(array_filter(
            array_values(Filament::getPanel('admin')->getResources()),
            static fn (string $resource): bool => ! is_subclass_of($resource, ExcludedFromAdminApi::class),
        ));

        // Feature providers have already registered their owner adapters; this
        // discovery list is the single public ordering and the only source of
        // generic package definitions. No static resource list is maintained.
        $registry->orderBy($resources);

        foreach ($resources as $resourceClass) {
            if ($registry->hasForClass(ResourceSurface::Admin, $resourceClass)) {
                continue;
            }

            $this->registerDefinition($registry, new FilamentResourceDefinition($context, $resourceClass));
        }
    }

    private function registerDefinition(ResourceDefinitionRegistry $registry, FilamentBackedResourceDefinition $definition): void
    {
        $registry->register($definition);
    }
}
