<?php

declare(strict_types=1);

namespace App\Features\Membership;

use App\Features\Membership\Api\Frontend\MembershipApplicationFormContractContributor;
use App\Features\Membership\Api\MemberPermissions;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipAuthorization;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MembershipDirectory;
use App\Features\Membership\Api\MembershipInvitations;
use App\Features\Membership\Api\MembershipSubjectCatalog;
use App\Features\Membership\Api\MembershipSubjectOptionsCatalog;
use App\Features\Membership\Contributors\MembershipAccountLifecycleContributor;
use App\Features\Membership\EntryPoints\Admin\Member\MembershipApplicationMemberWorkflowToolContributor;
use App\Features\Membership\EntryPoints\Admin\RecordActions\MembershipApplicationRecordActionContributor;
use App\Features\Membership\EntryPoints\ApiDocumentation\MembershipRequestExampleContributor;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Features\Membership\EntryPoints\Livewire\Pages\MembershipApplications\Create;
use App\Features\Membership\EntryPoints\Livewire\Pages\MembershipApplications\Index;
use App\Features\Membership\EntryPoints\Mcp\Tools\AdminGetMembershipApplicationReviewSchemaTool;
use App\Features\Membership\EntryPoints\Mcp\Tools\AdminReviewMembershipApplicationTool;
use App\Features\Membership\EntryPoints\Mcp\Tools\MemberCancelMembershipApplicationTool;
use App\Features\Membership\EntryPoints\Mcp\Tools\MemberListMembershipApplicationsTool;
use App\Features\Membership\EntryPoints\Mcp\Tools\MemberSubmitMembershipApplicationTool;
use App\Features\Membership\Services\EloquentMembershipSubjectCatalog;
use App\Features\Membership\Services\EloquentMembershipSubjectOptionsCatalog;
use App\Features\Membership\Services\MembershipApplicationsService;
use App\Features\Membership\Services\MembershipAuthorizationService;
use App\Features\Membership\Services\MembershipCommandsService;
use App\Features\Membership\Services\MembershipDirectoryService;
use App\Features\Membership\Services\MembershipInvitationsService;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\RecordActionContributorRegistry;
use App\Platform\Api\Frontend\FrontendFormContractRegistry;
use App\Platform\Api\Member\MemberWorkflowToolRegistry;
use App\Platform\ApiDocumentation\RequestExampleRegistry;
use App\Platform\FeatureModule;
use App\Platform\Mcp\McpSurfaceRegistry;
use App\Shared\Auth\AccountLifecycleContributor;
use Livewire\Livewire;

/**
 * Membership owns member behaviour, member pivots, applications and
 * invitations. The manifest lists every application-side class that writes a
 * claimed table directly.
 *
 * The package lifecycle actions remain canonical external writers for the
 * member pivots and are intentionally statically invisible:
 * AddMemberAction, ChangeMemberRoleAction, RemoveMemberAction,
 * AcceptInvitationAction and ApproveMembershipApplicationAction (the latter
 * two delegate to AddMemberAction).
 *
 * For membership_applications the package ApplyForMembershipAction,
 * CancelMembershipApplicationAction, ApproveMembershipApplicationAction and
 * RejectMembershipApplicationAction remain canonical external lifecycle
 * writers. The feature owns only the app-side submit guards, media discard,
 * reviewer review orchestration and owner-context handling listed in
 * feature.json.
 *
 * Invitations are claimed by this feature for the app-side writer
 * InviteSubjectMember (raw token plus the app-only invitation email) and the
 * acceptance guards. The package InviteMemberAction, AcceptInvitationAction and
 * RevokeInvitationAction remain canonical external writers for package-driven
 * flows and intentionally do not send the app invitation email (recorded
 * invitation-email asymmetry).
 *
 * Membership HTTP and Livewire entrypoints register from this feature and use
 * its public application API. MCP tools remain app-level transport adapters;
 * their application reads, writes and role catalogs cross through Membership APIs.
 */
final class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(MembershipSubjectCatalog::class, EloquentMembershipSubjectCatalog::class);
        $this->app->bind(MemberPermissions::class, Services\MemberPermissionGate::class);
        $this->app->bind(MembershipSubjectOptionsCatalog::class, EloquentMembershipSubjectOptionsCatalog::class);
        $this->app->bind(MembershipDirectory::class, MembershipDirectoryService::class);
        $this->app->bind(MembershipAuthorization::class, MembershipAuthorizationService::class);
        $this->app->bind(MembershipCommands::class, MembershipCommandsService::class);
        $this->app->bind(MembershipApplications::class, MembershipApplicationsService::class);
        $this->app->bind(MembershipInvitations::class, MembershipInvitationsService::class);

        $this->app->tag([
            MembershipAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);

        $this->app->make(FilamentResourceRegistry::class)->register(MembershipApplicationResource::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(RequestExampleRegistry::class)->register(new MembershipRequestExampleContributor);

        Livewire::component('pages.membership-applications.create', Create::class);
        Livewire::component('pages.membership-applications.index', Index::class);

        $this->app->make(RecordActionContributorRegistry::class)->register($this->app->make(MembershipApplicationRecordActionContributor::class));

        $this->app->make(MemberWorkflowToolRegistry::class)->register($this->app->make(MembershipApplicationMemberWorkflowToolContributor::class));

        $this->app->make(FrontendFormContractRegistry::class)->register(MembershipApplicationFormContractContributor::class);

        $surfaces = $this->app->make(McpSurfaceRegistry::class);

        $surfaces->registerAdminTool(AdminGetMembershipApplicationReviewSchemaTool::class);
        $surfaces->registerAdminTool(AdminReviewMembershipApplicationTool::class);

        $surfaces->registerMemberTool(MemberCancelMembershipApplicationTool::class);
        $surfaces->registerMemberTool(MemberListMembershipApplicationsTool::class);
        $surfaces->registerMemberTool(MemberSubmitMembershipApplicationTool::class);
    }
}
