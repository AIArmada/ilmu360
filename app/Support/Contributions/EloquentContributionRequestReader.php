<?php

declare(strict_types=1);

namespace App\Support\Contributions;

use App\Models\ContributionRequest;
use App\Shared\Contributions\ContributionRequestReader;

final readonly class EloquentContributionRequestReader implements ContributionRequestReader
{
    public function requestIdsForProposer(string $accountId): array
    {
        return ContributionRequest::query()
            ->where('proposer_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function requestIdsForReviewer(string $accountId): array
    {
        return ContributionRequest::query()
            ->where('reviewer_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function findRequestIdForProposer(string $accountId, string $requestId): ?string
    {
        $id = ContributionRequest::query()
            ->where('proposer_id', $accountId)
            ->whereKey($requestId)
            ->value('id');

        return $id !== null ? (string) $id : null;
    }
}
