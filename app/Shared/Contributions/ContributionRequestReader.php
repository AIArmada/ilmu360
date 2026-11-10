<?php

declare(strict_types=1);

namespace App\Shared\Contributions;

interface ContributionRequestReader
{
    /** @return list<string> */
    public function requestIdsForProposer(string $accountId): array;

    /** @return list<string> */
    public function requestIdsForReviewer(string $accountId): array;

    public function findRequestIdForProposer(string $accountId, string $requestId): ?string;
}
