<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

/**
 * Maintenance boundary for anonymous person profiles that never completed.
 */
interface PruneOrphanedPersons
{
    /**
     * @return array{found: int, pruned: int}
     */
    public function __invoke(int $hours, bool $dryRun = false): array;
}
