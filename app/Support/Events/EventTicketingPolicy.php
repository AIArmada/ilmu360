<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Event;

/**
 * Resolves whether an event admission must identify a concrete ticket.
 *
 * Ticket types may belong to the event, an occurrence, or a session. The
 * public registration paths must use the same answer so a ticketless request
 * cannot bypass a scoped ticket catalog or an explicit ticket-required policy.
 */
final readonly class EventTicketingPolicy
{
    public function requiresTicketSelection(Event $event): bool
    {
        if ($event->accessPolicy?->ticket_required === true) {
            return true;
        }

        if ($event->ticketTypes()
            ->where('status', 'active')
            ->where('visibility', 'public')
            ->exists()) {
            return true;
        }

        if ($event->occurrences()->whereHas('ticketTypes', function ($query): void {
            $query
                ->where('status', 'active')
                ->where('visibility', 'public');
        })->exists()) {
            return true;
        }

        return $event->sessions()->whereHas('ticketTypes', function ($query): void {
            $query
                ->where('status', 'active')
                ->where('visibility', 'public');
        })->exists();
    }
}
