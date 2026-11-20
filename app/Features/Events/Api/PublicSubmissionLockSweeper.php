<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Cross-domain submission lock maintenance boundary for console transport. */
interface PublicSubmissionLockSweeper
{
    public function institutionEligibility(string $institutionId): SubmissionLockEligibilityResult;

    public function personEligibility(string $personId): SubmissionLockEligibilityResult;

    public function ensureInstitutionUnlockedIfIneligible(string $institutionId): void;

    public function ensurePersonUnlockedIfIneligible(string $personId): void;

    /** @return array{institutions_reopened: int, persons_reopened: int} */
    public function sweepLockedEntities(): array;
}
