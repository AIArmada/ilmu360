<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Institution↔space bridge boundary.
 *
 * Root callers (models, Filament relation managers, seeders) use this instead
 * of attaching, detaching or deleting institution_space rows directly.
 */
interface InstitutionSpaceLinks
{
    /**
     * Attach or update an institution's capacity override for a space.
     */
    public function attach(string $institutionId, string $spaceId, ?int $capacity = null): void;

    public function detach(string $institutionId, string $spaceId): void;

    public function deleteForSpace(string $spaceId): void;

    public function deleteForInstitution(string $institutionId): void;
}
