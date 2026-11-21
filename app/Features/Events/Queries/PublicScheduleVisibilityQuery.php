<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use AIArmada\Events\Models\EventOccurrence;
use App\Features\Events\Api\PublicScheduleVisibility;
use App\Features\Events\Policies\PublicSchedulePolicy;

final readonly class PublicScheduleVisibilityQuery implements PublicScheduleVisibility
{
    public function isPublicOccurrence(string $occurrenceId): bool
    {
        $occurrence = EventOccurrence::query()->find($occurrenceId);

        return $occurrence instanceof EventOccurrence
            && PublicSchedulePolicy::isPublicOccurrence($occurrence);
    }
}
