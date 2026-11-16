<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

/**
 * Space admin and lifecycle workflow boundary.
 *
 * Root Filament/API callers use this instead of mutating space rows,
 * institution links or slug state directly.
 */
interface SpaceWorkflow
{
    /**
     * Create or update a space from the admin form or API.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveFromAdmin(array $payload, ?string $spaceId = null): string;

    /**
     * Delete spaces through the owning feature; returns the deleted count.
     *
     * Event-location guards still reject protected spaces.
     *
     * @param  list<string>  $spaceIds
     */
    public function delete(array $spaceIds): int;
}
