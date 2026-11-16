<?php

declare(strict_types=1);

namespace App\Features\Places\Queries;

use App\Features\Places\Api\PlaceLookup;
use App\Models\Space;
use App\Models\Venue;
use App\Platform\Models\SlugOrUuidResolver;

final readonly class EloquentPlaceLookup implements PlaceLookup
{
    public function __construct(private SlugOrUuidResolver $resolver) {}

    public function resolveVenueIdentifier(string $identifier): ?string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        $venue = $this->resolver->first(Venue::query(), 'venues.slug', $identifier);

        return $venue instanceof Venue ? (string) $venue->getKey() : null;
    }

    public function resolveSpaceIdentifier(string $identifier): ?string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        $space = $this->resolver->first(Space::query(), 'venue_spaces.slug', $identifier);

        return $space instanceof Space ? (string) $space->getKey() : null;
    }
}
