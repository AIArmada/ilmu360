<?php

declare(strict_types=1);

namespace App\Features\Organizations;

use AIArmada\Organizations\Contracts\CurrentOrganizationResolver as CurrentOrganizationResolverContract;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationCommands;
use App\Features\Organizations\Api\OrganizationWorkspace;
use App\Features\Organizations\Services\CurrentOrganizationResolver;
use App\Features\Organizations\Services\OrganizationAccessService;
use App\Features\Organizations\Services\OrganizationCommandsService;
use App\Features\Organizations\Services\OrganizationWorkspaceService;
use App\Platform\FeatureModule;

/**
 * Organizations owns organization behaviour while package migrations and
 * models remain canonical.
 *
 * O1 moved current-organization resolution and access here; O2 moved workspace
 * composition, command adapters, API transport and the `/organisasi` route
 * registrations. The package organization tables stay canonical: the feature
 * manifest deliberately claims no tables, and vendor Filament organization
 * resources remain vendor-owned and read-only to this feature.
 *
 * Deliberate product hold: organization-only membership does not grant Ahli
 * panel or member MCP access. Panel/MCP access still requires an Institution,
 * Person, Reference or Event subject membership; do not widen
 * `User::hasAhliPanelAccess()` without a product decision.
 */
final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(OrganizationAccess::class, OrganizationAccessService::class);
        $this->app->bind(OrganizationWorkspace::class, OrganizationWorkspaceService::class);
        $this->app->bind(OrganizationCommands::class, OrganizationCommandsService::class);
        $this->app->singleton(CurrentOrganizationResolverContract::class, CurrentOrganizationResolver::class);
        config(['organizations.resolver' => CurrentOrganizationResolver::class]);
    }
}
