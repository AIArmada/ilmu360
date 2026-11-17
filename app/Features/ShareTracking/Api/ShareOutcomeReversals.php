<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Reverses a frozen nonmonetary outcome with durable correction evidence. */
interface ShareOutcomeReversals
{
    public function reverse(string $businessKey, string $reason, ?string $actorId = null): ?OutcomeCorrectionSnapshot;
}
