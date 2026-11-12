<?php

declare(strict_types=1);

namespace App\Features\Contributions;

use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Contributors\ContributionsAccountLifecycleContributor;
use App\Features\Contributions\Queries\EloquentContributionRequestReader;
use App\Features\Contributions\Services\ContributionRequestLifecycleService;
use App\Platform\FeatureModule;
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
 * the root ContributionEntityMutationService stays a read/projection adapter
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
        $this->app->bind(ContributionRequestLifecycle::class, ContributionRequestLifecycleService::class);

        $this->app->tag([
            ContributionsAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);
    }
}
