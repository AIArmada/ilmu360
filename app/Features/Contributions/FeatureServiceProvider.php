<?php

declare(strict_types=1);

namespace App\Features\Contributions;

use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Platform\Admin\FilamentResourceRegistry;

use App\Features\Contributions\Actions\ApplyDirectContributionUpdateAction;
use App\Features\Contributions\Actions\EnsureNoPendingContributionRequestAction;
use App\Features\Contributions\Actions\EnsureUniqueContributionCreateAction;
use App\Features\Contributions\Actions\SubmitStagedContributionCreateAction;
use App\Features\Contributions\Api\ContributionDirectUpdates;
use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Api\ContributionReviewAccess;
use App\Features\Contributions\Api\ContributionSubmissionPreparation;
use App\Features\Contributions\Api\ContributionUpdateContextReader;
use App\Features\Contributions\Api\StagedContributionCreates;
use App\Features\Contributions\Api\VerifiedInstitutionMapsSelections;
use App\Features\Contributions\Contributors\ContributionsAccountLifecycleContributor;
use App\Features\Contributions\Persistence\CacheVerifiedInstitutionMapsSelections;
use App\Features\Contributions\Queries\EloquentContributionRequestReader;
use App\Features\Contributions\Queries\ResolveContributionUpdateContextAction;
use App\Features\Contributions\Services\ContributionRequestLifecycleService;
use App\Features\Contributions\Services\ContributionReviewAccessService;
use App\Features\Contributions\Services\ContributionSubmissionPreparationService;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Contributions\ContributionRequestGuard;
use App\Shared\Contributions\InstitutionClaimGuard;

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
 * the root ContributionEntityStateService stays a read/projection adapter
 * and holds no request writes.
 *
 * ContributionsAccountLifecycleContributor rewrites proposer/reviewer links
 * during account deletion/restoration. It is tagged here with the unchanged
 * `contributions` key and priority 60 so existing deleted-account snapshots
 * stay readable.
 *
 * Reads stay conventional for now: the request reader Api, the Resolve, Find
 * and Ensure query actions, the notification service and the ContributionEntity
 * MutationService projection remain root; the feature consumes the small
 * Shared state/guard/notifier seams they implement.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
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
}
