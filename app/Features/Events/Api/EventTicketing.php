<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Public admission ticket-selection policy boundary.
 */
interface EventTicketing
{
    public function eventRequiresTicketSelection(string $eventId): bool;
}
