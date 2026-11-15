<?php

namespace App\Actions\Contributions\Queries;

use App\Models\ContributionRequest;
use App\Shared\Contributions\Enums\ContributionRequestStatus;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class FindEntityPendingContributionRequestAction
{
    use AsAction;

    public function handle(string $entityType, string $entityId): ?ContributionRequest
    {
        return ContributionRequest::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('status', ContributionRequestStatus::Pending)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
