<?php

declare(strict_types=1);

namespace App\Features\Places\Queries;

use App\Features\Places\Api\Orm\PlaceLookup;
use App\Features\Places\Models\Venue;
use Illuminate\Database\Eloquent\Model;

final readonly class EloquentPlaceLookup implements PlaceLookup
{
    /**
     * @param  iterable<Model>  $locations
     */
    public function hydrateCardVenues(iterable $locations): void
    {
        $this->hydrateVenues($locations, ['addresses.areaAssignments.area']);
    }

    /**
     * @param  iterable<Model>  $locations
     */
    public function hydrateDetailVenues(iterable $locations): void
    {
        $this->hydrateVenues($locations, [
            'addresses.country',
            'addresses.state',
            'addresses.city',
            'addresses.areaAssignments.area',
            'contactMethods',
            'media',
        ]);
    }

    /**
     * @param  iterable<Model>  $locations
     * @param  list<string>  $relations
     */
    private function hydrateVenues(iterable $locations, array $relations): void
    {
        $locationList = is_array($locations) ? array_values($locations) : iterator_to_array($locations, false);
        $venueIds = [];

        foreach ($locationList as $location) {
            $venueId = $location->getAttribute('venue_id');

            if (is_string($venueId) && $venueId !== '') {
                $venueIds[$venueId] = true;
            }
        }

        if ($venueIds === []) {
            return;
        }

        $venues = Venue::query()
            ->with($relations)
            ->whereKey(array_keys($venueIds))
            ->get()
            ->keyBy(static fn (Venue $venue): string => (string) $venue->getKey());

        foreach ($locationList as $location) {
            $venueId = $location->getAttribute('venue_id');

            $location->setRelation(
                'venue',
                is_string($venueId) && $venueId !== '' ? $venues->get($venueId) : null,
            );
        }
    }
}
