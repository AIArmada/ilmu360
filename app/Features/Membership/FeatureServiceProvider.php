<?php

declare(strict_types=1);

namespace App\Features\Membership;

use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MembershipInvitations;
use App\Features\Membership\Contributors\MembershipAccountLifecycleContributor;
use App\Features\Membership\Services\MembershipApplicationsService;
use App\Features\Membership\Services\MembershipAuthorizationService;
use App\Features\Membership\Services\MembershipCommandsService;
use App\Features\Membership\Services\MembershipDirectoryService;
use App\Features\Membership\Services\MembershipInvitationsService;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Membership\MembershipAuthorization;
use App\Shared\Membership\MembershipDirectory;

/**
 * Membership owns member behaviour, member pivots, applications and
 * invitations. The manifest lists every application-side class that writes a
 * claimed table directly.
 *
 * The package lifecycle actions remain canonical external writers for the
 * member pivots and are intentionally statically invisible:
 * AddMemberAction, ChangeMemberRoleAction, RemoveMemberAction,
 * AcceptInvitationAction and ApproveMembershipApplicationAction (the latter
 * two delegate to AddMemberAction). Application/invitation package writers
 * remain external until their own M slices claim those tables.
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

        $this->app->tag([
            MembershipAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);
    }
}
