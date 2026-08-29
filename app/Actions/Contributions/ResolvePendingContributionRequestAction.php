<?php

namespace App\Actions\Contributions;

use App\Enums\ContributionRequestStatus;
use App\Models\ContributionRequest;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolvePendingContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ResolveContributionEntityMetadataAction $resolveContributionEntityMetadataAction,
    ) {}

    public function handle(Model $entity): ?ContributionRequest
    {
        $entityMetadata = $this->resolveContributionEntityMetadataAction->handle($entity);

        return ContributionRequest::query()
            ->where('entity_type', $entityMetadata['entity_type'])
            ->where('entity_id', $entityMetadata['entity_id'])
            ->where('status', ContributionRequestStatus::Pending)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
