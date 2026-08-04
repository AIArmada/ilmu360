<?php

declare(strict_types=1);

namespace App\Support\Events;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Models\Event;

final class PublicSchedulePolicy
{
    public static function isPublicOccurrence(EventOccurrence $occurrence): bool
    {
        return in_array((string) $occurrence->status, Event::PUBLIC_SCHEDULE_STATUSES, true)
            && in_array((string) $occurrence->visibility, Event::PUBLIC_SCHEDULE_VISIBILITIES, true)
            && $occurrence->starts_at !== null;
    }

    public static function isMeaningfulSession(EventSession $session): bool
    {
        return in_array((string) $session->status, Event::PUBLIC_SCHEDULE_STATUSES, true)
            && in_array((string) $session->visibility, Event::PUBLIC_SCHEDULE_VISIBILITIES, true)
            && filled($session->title)
            && $session->starts_at !== null;
    }
}
