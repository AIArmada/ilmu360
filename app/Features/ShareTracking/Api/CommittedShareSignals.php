<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Durable reader of the trusted `share.committed_share` Signals log.
 *
 * Each drain step applies one retained event and advances its own durable
 * cursor in the same transaction as the final cohort projection. Late
 * arrivals behind the cursor are recovered from the retained log; poison
 * events are reported, remembered per event and skipped past. There are no
 * leases: readers serialize on the cursor row, projection writes are
 * idempotent and the cursor only ever moves forward.
 */
interface CommittedShareSignals
{
    /**
     * Drain at most the batch-clamped number of retained signal events.
     *
     * @return array{processed: int, projected: int, failed: int}
     */
    public function drain(int $batch = 25): array;

    /**
     * Operator replay: rewind the durable cursor and retry retained poison.
     */
    public function resetCursor(): void;
}
