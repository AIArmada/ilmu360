<?php

declare(strict_types=1);

namespace App\Features\Contributions\Services;

use App\Features\Contributions\Api\ContributionReviewAccess;
use App\Features\Contributions\Queries\ResolvePendingContributionApprovalsAction;
use App\Features\Contributions\Queries\ResolveReviewableContributionRequestAction;
use App\Models\ContributionRequest;
use App\Models\User;

final readonly class ContributionReviewAccessService implements ContributionReviewAccess
{
    public function __construct(
        private ResolvePendingContributionApprovalsAction $pendingApprovals,
        private ResolveReviewableContributionRequestAction $reviewableRequest,
    ) {}

    public function pendingRequestIds(string $reviewerId): array
    {
        $reviewer = User::query()->findOrFail($reviewerId);

        return $this->pendingApprovals->handle($reviewer)
            ->map(static fn (ContributionRequest $request): string => (string) $request->getKey())
            ->all();
    }

    public function reviewableRequestId(string $reviewerId, string $requestId): string
    {
        $reviewer = User::query()->findOrFail($reviewerId);

        return (string) $this->reviewableRequest->handle($reviewer, $requestId)->getKey();
    }
}
