<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Events\Api\PublicScheduleEligibility;
use App\Features\Events\Policies\PublicSchedulePolicy;

/**
 * Boundary adapter over the public schedule visibility policy.
 */
final readonly class PublicScheduleEligibilityService implements PublicScheduleEligibility
{
    public function isPublicOccurrence(EventOccurrence $occurrence): bool
    {
        return PublicSchedulePolicy::isPublicOccurrence($occurrence);
    }

    public function isMeaningfulSession(EventSession $session): bool
    {
        return PublicSchedulePolicy::isMeaningfulSession($session);
    }
}
