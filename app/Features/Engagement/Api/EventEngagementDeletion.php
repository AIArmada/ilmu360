<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

/**
 * Event-scoped cleanup boundary for Engagement records.
 */
interface EventEngagementDeletion
{
    public function deleteForEvent(string $eventId, string $eventMorphType): void;
}
