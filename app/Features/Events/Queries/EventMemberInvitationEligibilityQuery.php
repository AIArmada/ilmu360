<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\Events\Api\EventMemberInvitationEligibility;
use App\Features\Events\Models\Event;
use App\Shared\Auth\AccountActor;

final readonly class EventMemberInvitationEligibilityQuery implements EventMemberInvitationEligibility
{
    public function canInvite(AccountActor $actor, string $eventId): bool
    {
        $event = Event::query()->find($eventId);

        return $event instanceof Event
            && $event->userHasScopedEventPermission($actor, 'event.manage-members');
    }
}
