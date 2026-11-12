<?php

namespace App\Actions\Contributions\Queries;

use App\Shared\Contributions\Enums\ContributionRequestStatus;
use App\Shared\Contributions\Support\ContributionEntityMetadata;
use App\Models\ContributionRequest;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class FindEntityPendingContributionRequestAction
{
    use AsAction;

    public function handle(Model $entity): ?ContributionRequest
    {
        $entityMetadata = ContributionEntityMetadata::for($entity);

        return ContributionRequest::query()
            ->where('entity_type', $entityMetadata['entity_type'])
            ->where('entity_id', $entityMetadata['entity_id'])
            ->where('status', ContributionRequestStatus::Pending)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
