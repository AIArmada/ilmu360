<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Retains the explicit guest-profile retention policy behind a feature-owned
 * pruning use case.
 */
interface GuestProfilePruner
{
    /**
     * @return array{scanned: int, pruned: int, skipped: int}
     */
    public function prune(int $retentionDays, int $limit = 1000, bool $dryRun = false): array;
}
