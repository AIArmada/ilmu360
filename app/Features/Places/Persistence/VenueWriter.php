<?php

declare(strict_types=1);

namespace App\Features\Places\Persistence;

use App\Features\Places\Models\Venue;

/**
 * Owns the transactional venue row delete.
 *
 * The Venue model keeps its package and app hooks (facility cleanup, audits,
 * deleted-model copies, media); this writer keeps the row delete atomic with
 * the institution bridge cleanup that runs on the model's deleted hook.
 */
final readonly class VenueWriter
{
    public function delete(Venue $venue): ?bool
    {
        return $venue->getConnection()->transaction(static fn (): ?bool => $venue->delete());
    }
}
