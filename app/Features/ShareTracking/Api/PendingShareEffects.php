<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Consume only projection and deferred-ticket share effect receipts. */
interface PendingShareEffects
{
    /**
     * Claim a bounded batch, settle under a live lease, and return the number
     * of receipts handled (including those settled as failed or cancelled).
     */
    public function dispatch(int $limit = 50, ?string $workerId = null): int;
}
