<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Engagement eligibility predicates for event cards and action strips.
 *
 * Callers should not read Event status/visibility/window rules directly; this
 * boundary answers whether the event currently accepts going or saving.
 */
interface EventEngagementEligibility
{
    public function isGoingEligible(string $eventId): bool;

    public function isSaveEligible(string $eventId): bool;
}
