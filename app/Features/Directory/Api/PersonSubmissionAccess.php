<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Person public-submission lock and eligibility boundary.
 *
 * Membership, Identity and admin form helpers call this instead of mutating
 * person submission flags directly.
 */
interface PersonSubmissionAccess
{
    public function eligibility(string $personId): PersonSubmissionEligibility;

    public function lock(string $personId, string $actorId): void;

    public function unlock(string $personId, string $actorId): void;

    public function reopenIfIneligible(string $personId): bool;

    public function syncForAccount(string $accountId): int;

    public function sweep(): int;
}
