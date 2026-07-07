<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RemoveEventGoingAction
{
    use AsAction;

    public function handle(string $eventId, User $user): array
    {
        $event = Event::query()->find($eventId);

        if ($event !== null) {
            $user->cancelResponse($event);
        }

        return [
            'event_attendees' => [],
        ];
    }
}
