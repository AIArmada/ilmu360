<?php

declare(strict_types=1);

namespace App\Features\Contributions\Queries;

use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Support\ContributionModel;

final readonly class EloquentContributionRequestReader implements ContributionRequestReader
{
    public function requestIdsForProposer(string $accountId): array
    {
        return ContributionModel::modelClass()::query()
            ->where('proposer_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function requestIdsForReviewer(string $accountId): array
    {
        return ContributionModel::modelClass()::query()
            ->where('reviewer_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function pendingRequestIdForEntity(string $entityType, string $entityId): ?string
    {
        $id = ContributionModel::modelClass()::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('status', \App\Shared\Contributions\Enums\ContributionRequestStatus::Pending)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (string) $id : null;
    }

    public function findRequestIdForProposer(string $accountId, string $requestId): ?string
    {
        $id = ContributionModel::modelClass()::query()
            ->where('proposer_id', $accountId)
            ->whereKey($requestId)
            ->value('id');

        return $id !== null ? (string) $id : null;
    }
}
