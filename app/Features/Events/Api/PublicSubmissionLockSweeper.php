<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Cross-domain submission lock maintenance boundary for console transport. */
interface PublicSubmissionLockSweeper
{
    /** @return array{institutions_reopened: int, persons_reopened: int} */
    public function sweepLockedEntities(): array;
}
