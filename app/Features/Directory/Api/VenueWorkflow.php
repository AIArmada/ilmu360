<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Venue contribution, admin, lifecycle and slug workflow boundary.
 *
 * Root Filament/API/Livewire/observer callers use this instead of mutating
 * venue rows, facilities, slugs or media state directly.
 */
interface VenueWorkflow
{
    /**
     * Create a pending venue from a shared quick-add form.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createFromForm(array $payload): string;

    /**
     * Create or update a venue from the admin form or API.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveFromAdmin(array $payload, ?string $venueId = null): string;

    /**
     * Delete venues through the owning feature; returns the deleted count.
     *
     * @param  list<string>  $venueIds
     */
    public function delete(array $venueIds): int;

    public function changeStatus(string $venueId, string $status): void;

    public function changeVisibility(string $venueId, string $visibility): void;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncRelations(string $venueId, array $payload): void;

    public function syncSlug(string $venueId): bool;

    public function syncSlugsForName(string $name): bool;

    /**
     * @param  list<string>  $venueIds
     * @return list<string>
     */
    public function backfillSlugs(array $venueIds = []): array;
}
