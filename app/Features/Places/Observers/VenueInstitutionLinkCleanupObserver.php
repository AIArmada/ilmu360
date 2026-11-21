<?php

declare(strict_types=1);

namespace App\Features\Places\Observers;

use App\Features\Places\Actions\DeleteVenueInstitutionLinksAction;
use App\Features\Places\Models\Venue;

final readonly class VenueInstitutionLinkCleanupObserver
{
    public function __construct(private DeleteVenueInstitutionLinksAction $cleanup) {}

    public function deleted(Venue $venue): void
    {
        $this->cleanup->handle((string) $venue->getKey());
    }
}
