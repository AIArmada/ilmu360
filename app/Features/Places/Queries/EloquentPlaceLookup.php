<?php

declare(strict_types=1);

namespace App\Features\Places\Queries;

use App\Features\Places\Api\Orm\PlaceLookup;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Platform\Models\SlugOrUuidResolver;
use Illuminate\Database\Eloquent\Model;

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

    public function venueTable(): string
    {
        return (new Venue)->getTable();
    }

    public function venueMorphType(): string
    {
        return (new Venue)->getMorphClass();
    }

    public function venueName(string $venueId): ?string
    {
        $name = Venue::query()->whereKey($venueId)->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * @return array<string, string>
     */
    public function venueNameOptions(string $search = '', int $limit = 50): array
    {
        $query = Venue::query();
        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            $query->where('name', 'like', '%'.$normalizedSearch.'%');
        }

        return $query
            ->orderBy('name')
            ->limit(max(1, $limit))
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, string>
     */
    public function venueOptionsForStatuses(array $statuses, ?int $limit = null): array
    {
        $query = Venue::query()
            ->whereIn('status', $statuses)
            ->orderBy('name');

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        return $query
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @param  list<string>  $venueIds
     * @return array<string, string>
     */
    public function venueNamesByIds(array $venueIds): array
    {
        if ($venueIds === []) {
            return [];
        }

        return Venue::query()
            ->whereKey($venueIds)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }

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
