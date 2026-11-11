<?php

declare(strict_types=1);

namespace App\Features\Membership;

use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MembershipInvitations;
use App\Features\Membership\Services\MembershipApplicationsService;
use App\Features\Membership\Services\MembershipAuthorizationService;
use App\Features\Membership\Services\MembershipCommandsService;
use App\Features\Membership\Services\MembershipDirectoryService;
use App\Features\Membership\Services\MembershipInvitationsService;
use App\Platform\FeatureModule;
use App\Shared\Membership\MembershipAuthorization;
use App\Shared\Membership\MembershipDirectory;

/**
 * Membership owns membership behaviour. The feature.json manifest remains
 * empty until M1 claims the member pivots, applications and invitations; the
 * package lifecycle actions stay canonical external writers.
 */
final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(MembershipDirectory::class, MembershipDirectoryService::class);
        $this->app->bind(MembershipAuthorization::class, MembershipAuthorizationService::class);
        $this->app->bind(MembershipCommands::class, MembershipCommandsService::class);
        $this->app->bind(MembershipApplications::class, MembershipApplicationsService::class);
        $this->app->bind(MembershipInvitations::class, MembershipInvitationsService::class);
    }
}
