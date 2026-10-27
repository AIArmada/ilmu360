<?php

namespace App\Actions\Contributions\Queries;

use App\Enums\ContributionRequestStatus;
use App\Models\ContributionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class FindUserPendingContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ResolveContributionEntityMetadataAction $resolveContributionEntityMetadataAction,
    ) {}

    public function handle(User $user, Model $entity): ?ContributionRequest
    {
        $entityMetadata = $this->resolveContributionEntityMetadataAction->handle($entity);

        return ContributionRequest::query()
            ->where('proposer_id', $user->getKey())
            ->where('entity_type', $entityMetadata['entity_type'])
            ->where('entity_id', $entityMetadata['entity_id'])
            ->where('status', ContributionRequestStatus::Pending)
            ->latest('created_at')
            ->first();
    }
}
