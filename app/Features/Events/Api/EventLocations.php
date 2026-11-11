<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Event-level primary location synchronisation used by the root model hook.
 */
interface EventLocations
{
    /**
     * @param  list<string>  $spaceIds
     */
    public function sync(string $eventId, ?string $venueId = null, array $spaceIds = []): void;

    /**
     * Returns true when the primary location was written.
     */
    public function syncPrimaryFromSelection(
        string $eventId,
        bool $venueChanged,
        bool $institutionChanged,
    ): bool;
}
