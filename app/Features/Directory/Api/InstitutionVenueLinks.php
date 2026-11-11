<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

use App\Shared\Directory\Enums\InstitutionVenueRole;

/**
 * Institution↔venue bridge boundary.
 *
 * Root callers (models, Filament relation managers, seeders) use this instead
 * of attaching, detaching or demoting institution_venue rows directly. Every
 * operation preserves the one-primary-per-role invariant under the owning
 * institution's row lock.
 */
interface InstitutionVenueLinks
{
    /**
     * Replace the institution's venue links with the given set.
     *
     * @param  list<array{venue_id: string, role: InstitutionVenueRole|string, is_primary?: bool}>  $links
     */
    public function sync(string $institutionId, array $links): void;

    public function add(
        string $institutionId,
        string $venueId,
        InstitutionVenueRole|string $role,
        bool $isPrimary = false,
    ): void;

    public function remove(string $institutionId, string $venueId): void;

    public function setPrimary(string $institutionId, string $venueId): void;

    public function demotePrimary(
        string $institutionId,
        InstitutionVenueRole|string $role,
        ?string $exceptVenueId = null,
    ): void;

    public function deleteForInstitution(string $institutionId): void;

    public function deleteForVenue(string $venueId): void;
}
