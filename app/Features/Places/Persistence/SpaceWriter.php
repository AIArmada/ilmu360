<?php

declare(strict_types=1);

namespace App\Features\Places\Persistence;

use App\Features\Places\Models\Space;

/**
 * Owns the transactional space row delete.
 *
 * The Space model delegates event-location protection to GuardSpaceDeletionAction and preserves package facility cleanup;
 * this writer keeps the row delete atomic with the institution bridge cleanup
 * that runs on the model's deleted hook.
 */
final readonly class SpaceWriter
{
    public function delete(Space $space): ?bool
    {
        return $space->getConnection()->transaction(static fn (): ?bool => $space->delete());
    }
}
