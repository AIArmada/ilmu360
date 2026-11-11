<?php

declare(strict_types=1);

namespace App\Features\Organizations;

use AIArmada\Organizations\Contracts\CurrentOrganizationResolver as CurrentOrganizationResolverContract;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Services\CurrentOrganizationResolver;
use App\Features\Organizations\Services\OrganizationAccessService;
use App\Platform\FeatureModule;

/**
 * Organizations owns organization behaviour while package migrations and
 * models remain canonical.
 *
 * O1 moved current-organization resolution and access into Services and binds
 * them here. The workspace and command adapters remain in App\Support\Organizations
 * until O2 moves the remaining runtime composition; AppServiceProvider keeps
 * those root-facing bindings.
 */
final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(OrganizationAccess::class, OrganizationAccessService::class);
        $this->app->singleton(CurrentOrganizationResolverContract::class, CurrentOrganizationResolver::class);
        config(['organizations.resolver' => CurrentOrganizationResolver::class]);
    }
}
