<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

interface PlaceLookup
{
    public function resolveVenueIdentifier(string $identifier): ?string;

    public function resolveSpaceIdentifier(string $identifier): ?string;

    public function venueTable(): string;

    public function venueMorphType(): string;
}
