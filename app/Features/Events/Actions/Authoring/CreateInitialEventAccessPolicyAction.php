<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use App\Features\Events\Support\EventModel;
use App\Features\Events\Models\Event;

/**
 * Creates the event-level admission policy for a newly created Event.
 */
final readonly class CreateInitialEventAccessPolicyAction
{
    public function handle(string $eventId, bool $registrationRequired): void
    {
        /** @var Event $event */
        $event = EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);

        $event->accessPolicy()->create([
            'registration_required' => $registrationRequired,
            'walk_in_allowed' => ! $registrationRequired,
        ]);
    }
}
