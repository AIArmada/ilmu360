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

    public function findRequestIdForProposer(string $accountId, string $requestId): ?string
    {
        $id = ContributionModel::modelClass()::query()
            ->where('proposer_id', $accountId)
            ->whereKey($requestId)
            ->value('id');

        return $id !== null ? (string) $id : null;
    }
}
