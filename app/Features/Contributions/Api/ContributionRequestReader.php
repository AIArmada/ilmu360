<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

interface ContributionRequestReader
{
    /** @return list<string> */
    public function requestIdsForProposer(string $accountId): array;

    /** @return list<string> */
    public function requestIdsForReviewer(string $accountId): array;

    public function findRequestIdForProposer(string $accountId, string $requestId): ?string;
}
