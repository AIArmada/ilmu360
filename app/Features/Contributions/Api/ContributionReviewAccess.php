<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

/** Authorized reviewer lookups; never expose mutable request models across feature boundaries. */
interface ContributionReviewAccess
{
    /** @return list<string> */
    public function pendingRequestIds(string $reviewerId): array;

    /** Returns a reviewable request ID or rejects unauthorized review. */
    public function reviewableRequestId(string $reviewerId, string $requestId): string;
}
