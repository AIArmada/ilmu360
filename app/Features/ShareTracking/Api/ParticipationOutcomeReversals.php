<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Reverses the financial and nonmonetary participation effects of cancellation.
 * The caller owns the surrounding source transition transaction.
 */
interface ParticipationOutcomeReversals
{
    /** @param 'event'|'occurrence'|'session' $scope */
    public function reverse(string $scope, string $scopeId, ?string $actorId, string $reason): void;
}
