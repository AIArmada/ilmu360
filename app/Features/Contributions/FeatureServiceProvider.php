<?php

declare(strict_types=1);

namespace App\Features\Contributions;

use App\Features\Contributions\Actions\ApplyDirectContributionUpdateAction;
use App\Features\Contributions\Actions\EnsureNoPendingContributionRequestAction;
use App\Features\Contributions\Actions\EnsureUniqueContributionCreateAction;
use App\Features\Contributions\Actions\SubmitStagedContributionCreateAction;
use App\Features\Contributions\Api\ContributionDirectUpdates;
use App\Features\Contributions\Api\ContributionEntityState;
use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Api\ContributionReviewAccess;
use App\Features\Contributions\Api\ContributionSubmissionPreparation;
use App\Features\Contributions\Api\ContributionUpdateContextReader;
use App\Features\Contributions\Api\Filament\PersonContributionSchema;
use App\Features\Contributions\Api\Frontend\SubmitInstitutionFormContractContributor;
use App\Features\Contributions\Api\Frontend\SubmitPersonFormContractContributor;
use App\Features\Contributions\Api\Orm\SubmissionContext;
use App\Features\Contributions\Api\StagedContributionCreates;
use App\Features\Contributions\Api\SubmissionCatalog;
use App\Features\Contributions\Api\SubmissionEntityAccess;
use App\Features\Contributions\Api\VerifiedInstitutionMapsSelections;
use App\Features\Contributions\Contracts\ContributionRequestGuard;
use App\Features\Contributions\Contracts\InstitutionClaimGuard;
use App\Features\Contributions\Contributors\ContributionsAccountLifecycleContributor;
use App\Features\Contributions\EntryPoints\Admin\Member\ContributionRequestMemberWorkflowToolContributor;
use App\Features\Contributions\EntryPoints\Admin\RecordActions\ContributionRequestRecordActionContributor;
use App\Features\Contributions\EntryPoints\Api\Admin\ContributionRequestResourceDefinition;
use App\Features\Contributions\EntryPoints\Filament\Forms\PersonContributionFormSchema;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Features\Contributions\EntryPoints\Mcp\Tools\AdminGetContributionRequestReviewSchemaTool;
use App\Features\Contributions\EntryPoints\Mcp\Tools\AdminReviewContributionRequestTool;
use App\Features\Contributions\EntryPoints\Mcp\Tools\MemberApproveContributionRequestTool;
use App\Features\Contributions\EntryPoints\Mcp\Tools\MemberCancelContributionRequestTool;
use App\Features\Contributions\EntryPoints\Mcp\Tools\MemberListContributionRequestsTool;
use App\Features\Contributions\EntryPoints\Mcp\Tools\MemberRejectContributionRequestTool;
use App\Features\Contributions\Notifications\ContributionRequestNotificationService;
use App\Features\Contributions\Persistence\CacheVerifiedInstitutionMapsSelections;
use App\Features\Contributions\Queries\EloquentContributionRequestReader;
use App\Features\Contributions\Queries\ResolveContributionUpdateContextAction;
use App\Features\Contributions\Services\ContributionEntityStateService;
use App\Features\Contributions\Services\ContributionRequestLifecycleService;
use App\Features\Contributions\Services\ContributionReviewAccessService;
use App\Features\Contributions\Services\ContributionSubmissionPreparationService;
use App\Features\Contributions\Services\EloquentSubmissionCatalog;
use App\Features\Contributions\Services\EntitySubmissionAccess;
use App\Features\Contributions\Services\SubmissionContextResolver;
use App\Features\Contributions\Support\ContributionRequestNotifier;
use App\Features\Contributions\Support\Telemetry\ContributionsSignalSchemaContributor;
use App\Platform\Admin\FilamentResourceRegistry;
use App\Platform\Api\Admin\RecordActionContributorRegistry;
use App\Platform\Api\Frontend\FrontendFormContractRegistry;
use App\Platform\Api\Member\MemberWorkflowToolRegistry;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\FeatureModule;
use App\Platform\Mcp\McpSurfaceRegistry;
use App\Platform\Telemetry\SignalSchemaRegistry;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Contributions owns contribution requests from C3 onward.
 *
 * The request lifecycle is five named actions plus the bulk delete:
 * SubmitContributionCreateRequestAction, SubmitContributionUpdateRequestAction,
 * ApproveContributionRequestAction, RejectContributionRequestAction,
 * CancelContributionRequestAction and DeleteContributionRequestsAction.
 * ContributionRequestLifecycle is the scalar public boundary for root
 * transport, admin/MCP services and the Filament resource; callers keep their
 * Resolve, Find and Ensure request checks before invoking it.
 *
 * ApproveContributionRequestAction keeps the existing approval orchestration
 * untouched: the outer/row transaction, request-row lock, Maps identity lock,
 * duplicate re-check, staged-entity verification, mutate-then-status order,
 * post-transaction notification and staged-media ordering all stay identical.
 * Directory and Events row mutations run through their public Api contracts;
 * ContributionEntityStateService is a neutral dispatcher over owner-published
 * contribution projections and holds no entity schema, model or write.
 *
 * ContributionsAccountLifecycleContributor delegates proposer/reviewer link
 * writes to the owning ManageContributionAccountAttributionAction. It is tagged here with the unchanged
 * `contributions` key and priority 60 so existing deleted-account snapshots
 * stay readable.
 *
 * Reads stay conventional: the request reader Api, the Resolve, Find and
 * Ensure query actions, and the notification service. Per-entity form
 * contracts, current state and update rules are published by each owner and
 * composed through each owner's ContributionProjection Api contract.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(SubmissionEntityAccess::class, EntitySubmissionAccess::class);
        $this->app->bind(SubmissionContext::class, SubmissionContextResolver::class);
        $this->app->bind(ContributionEntityState::class, ContributionEntityStateService::class);
        $this->app->bind(ContributionRequestNotifier::class, ContributionRequestNotificationService::class);
        $this->app->bind(PersonContributionSchema::class, PersonContributionFormSchema::class);

        $this->app->bind(SubmissionCatalog::class, EloquentSubmissionCatalog::class);
        $this->app->bind(ContributionRequestReader::class, EloquentContributionRequestReader::class);
        $this->app->bind(ContributionUpdateContextReader::class, ResolveContributionUpdateContextAction::class);
        $this->app->bind(ContributionDirectUpdates::class, ApplyDirectContributionUpdateAction::class);
        $this->app->bind(ContributionRequestLifecycle::class, ContributionRequestLifecycleService::class);
        $this->app->bind(ContributionReviewAccess::class, ContributionReviewAccessService::class);
        $this->app->bind(ContributionSubmissionPreparation::class, ContributionSubmissionPreparationService::class);
        $this->app->bind(StagedContributionCreates::class, SubmitStagedContributionCreateAction::class);
        $this->app->bind(InstitutionClaimGuard::class, EnsureUniqueContributionCreateAction::class);
        $this->app->bind(ContributionRequestGuard::class, EnsureNoPendingContributionRequestAction::class);
        $this->app->bind(VerifiedInstitutionMapsSelections::class, CacheVerifiedInstitutionMapsSelections::class);

        $this->app->tag([
            ContributionsAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);

        $this->app->make(FilamentResourceRegistry::class)->register(ContributionRequestResource::class);
    }

    public function boot(): void
    {
        $this->app->make(ResourceDefinitionRegistry::class)->register($this->app->make(ContributionRequestResourceDefinition::class));
        parent::boot();

        $contracts = $this->app->make(FrontendFormContractRegistry::class);
        $contracts->register(SubmitInstitutionFormContractContributor::class);
        $contracts->register(SubmitPersonFormContractContributor::class);

        $this->app->make(SignalSchemaRegistry::class)->register(new ContributionsSignalSchemaContributor);

        $this->app->make(RecordActionContributorRegistry::class)->register($this->app->make(ContributionRequestRecordActionContributor::class));

        $this->app->make(MemberWorkflowToolRegistry::class)->register($this->app->make(ContributionRequestMemberWorkflowToolContributor::class));

        $surfaces = $this->app->make(McpSurfaceRegistry::class);

        $surfaces->registerAdminTool(AdminGetContributionRequestReviewSchemaTool::class);
        $surfaces->registerAdminTool(AdminReviewContributionRequestTool::class);

        $surfaces->registerMemberTool(MemberApproveContributionRequestTool::class);
        $surfaces->registerMemberTool(MemberCancelContributionRequestTool::class);
        $surfaces->registerMemberTool(MemberListContributionRequestsTool::class);
        $surfaces->registerMemberTool(MemberRejectContributionRequestTool::class);
    }
}
