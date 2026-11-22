<?php

declare(strict_types=1);

namespace App\Features\Places\Services;

use App\Features\Places\Api\PlaceCatalog;
use App\Features\Places\Api\SpaceEligibility;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Platform\Models\SlugOrUuidResolver;

final readonly class EloquentPlaceCatalog implements PlaceCatalog
{
    public function __construct(private SpaceEligibility $spaceEligibilityResolver, private SlugOrUuidResolver $resolver) {}

    public function venues(?string $search = null, int $limit = 50): array
    {
        $query = Venue::query()
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name');

        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $query->whereLike('name', '%'.$normalizedSearch.'%');
        }

        return $query
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (Venue $venue): array => [
                'id' => (string) $venue->id,
                'label' => (string) $venue->name,
            ])
            ->all();
    }

    public function spaces(?string $institutionId = null): array
    {
        $options = is_string($institutionId) && $institutionId !== ''
            ? $this->spaceEligibilityResolver->institutionOptions($institutionId, activeOnly: true)
            : $this->spaceEligibilityResolver->catalogOptions(activeOnly: true);

        return collect($options)
            ->map(static fn (string $label, string $id): array => ['id' => $id, 'label' => $label])
            ->values()
            ->all();
    }

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

    public function venueTable(): string
    {
        return (new Venue)->getTable();
    }

    public function venueMorphType(): string
    {
        return (new Venue)->getMorphClass();
    }

    public function resolvedPrimaryAddressId(string $venueId): ?string
    {
        return $this->primaryAddressIdsFor([$venueId])[$venueId] ?? null;
    }

    /**
     * @param list<string> $venueIds
     * @return array<string, string>
     */
    public function primaryAddressIdsFor(array $venueIds): array
    {
        $ids = array_values(array_unique(array_filter($venueIds, static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return [];
        }

        $addresses = [];

        foreach (Venue::query()->whereKey($ids)->with('addresses')->get() as $venue) {
            $address = $venue->primaryAddress();

            if (! $address instanceof \AIArmada\Addressing\Models\Address) {
                $owner = $venue->institutions()
                    ->reorder()
                    ->orderByDesc('institution_venue.is_primary')
                    ->oldest('institutions.id')
                    ->with('addresses')
                    ->first();
                $address = $owner?->primaryAddress();
            }

            if ($address instanceof \AIArmada\Addressing\Models\Address) {
                $addresses[(string) $venue->getKey()] = (string) $address->getKey();
            }
        }

        return $addresses;
    }

}
