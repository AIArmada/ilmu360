<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Integration across the Events escalation workflow and Laravel's
 * globally-scoped role directory and queued mail-notifiable accounts.
 */
interface EventEscalationTransport
{
    /** @return Collection<int, Authenticatable> */
    public function forRole(string $role): Collection;

    /** @param Collection<int, Authenticatable> $recipients */
    public function notify(string $eventId, string $notificationType, Collection $recipients): void;
}
