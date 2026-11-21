<?php

declare(strict_types=1);

namespace App\Features\Places\Actions;

use App\Features\Institutions\Api\InstitutionVenueLinks;

final readonly class DeleteVenueInstitutionLinksAction
{
    public function __construct(private InstitutionVenueLinks $links) {}

    public function handle(string $venueId): void
    {
        $this->links->deleteForVenue($venueId);
    }
}
