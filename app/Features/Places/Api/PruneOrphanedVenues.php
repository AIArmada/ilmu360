<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

/**
 * Maintenance boundary for anonymous venue records that never completed.
 */
interface PruneOrphanedVenues
{
    /**
     * @return array{found: int, pruned: int}
     */
    public function __invoke(int $hours, bool $dryRun = false): array;
}
