<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Writer boundary for share-outcome corrections.
 *
 * A correction is recorded once per business key when a share outcome is
 * reversed. The business key is the idempotent identity: a duplicate or
 * concurrent call replays the first committed row instead of overwriting its
 * conversion, reason, actor or occurrence time.
 */
interface OutcomeCorrections
{
    /**
     * Record the correction for a reversed share outcome.
     *
     * The occurrence time is captured when the row is created. The row is
     * written through Eloquent so the registered correction observer still
     * dispatches its referral and reward jobs.
     */
    public function ensure(
        string $businessKey,
        string $conversionId,
        string $reason,
        ?string $actorType = null,
        ?string $actorId = null,
    ): OutcomeCorrectionSnapshot;
}
