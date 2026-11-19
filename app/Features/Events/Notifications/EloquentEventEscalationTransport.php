<?php

declare(strict_types=1);

namespace App\Features\Events\Notifications;

use App\Features\Events\Contracts\EventEscalationTransport;
use App\Features\Events\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

final readonly class EloquentEventEscalationTransport implements EventEscalationTransport
{
    /** @return Collection<int, Authenticatable> */
    public function forRole(string $role): Collection
    {
        /** @var Collection<int, Authenticatable> $recipients */
        $recipients = User::role($role)->get();

        return $recipients;
    }

    public function notify(string $eventId, string $notificationType, Collection $recipients): void
    {
        /** @var Event $event */
        $event = Event::query()->findOrFail($eventId);

        foreach ($recipients as $recipient) {
            if ($recipient instanceof User) {
                $recipient->notify(new EventEscalationNotification($event, $notificationType));
            }
        }
    }
}
