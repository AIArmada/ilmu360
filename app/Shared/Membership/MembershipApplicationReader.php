<?php

declare(strict_types=1);

namespace App\Shared\Membership;

interface MembershipApplicationReader
{
    /** @return list<string> */
    public function applicationIdsForApplicant(string $accountId): array;

    /** @return list<string> */
    public function applicationIdsForReviewer(string $accountId): array;

    public function findApplicationIdForApplicant(string $accountId, string $applicationId): ?string;
}
