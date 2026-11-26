<?php

declare(strict_types=1);

namespace App\Features\Places\Services;

use AIArmada\Addressing\Features\AddressLifecycle\Models\Address;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\Places\Api\SpaceEligibility;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Platform\Models\SlugOrUuidResolver;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

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
     * @param  list<string>  $venueIds
     * @return array<string, array{name: string, address_id: ?string}>
     */
    public function venueLocationSummariesFor(array $venueIds): array
    {
        $ids = array_values(array_unique(array_filter($venueIds, static fn (string $id): bool => Str::isUuid($id))));

        if ($ids === []) {
            return [];
        }

        $summaries = [];
        $fallbackVenueIds = [];

        foreach (Venue::query()->whereKey($ids)->with('addresses')->get(['id', 'name']) as $venue) {
            $venueId = (string) $venue->getKey();
            $address = $venue->primaryAddress();

            $summaries[$venueId] = [
                'name' => (string) $venue->name,
                'address_id' => $address instanceof Address ? (string) $address->getKey() : null,
            ];

            if (! $address instanceof Address) {
                $fallbackVenueIds[] = $venueId;
            }
        }

        if ($fallbackVenueIds !== []) {
            foreach ($this->fallbackInstitutionAddresses($fallbackVenueIds) as $venueId => $addressId) {
                if (($summaries[$venueId]['address_id'] ?? null) === null) {
                    $summaries[$venueId]['address_id'] = $addressId;
                }
            }
        }

        return $summaries;
    }

    /**
     * @param  list<string>  $venueIds
     * @return array<string, string>
     */
    public function primaryAddressIdsFor(array $venueIds): array
    {
        $addresses = [];

        foreach ($this->venueLocationSummariesFor($venueIds) as $venueId => $summary) {
            $addressId = $summary['address_id'];

            if (is_string($addressId) && $addressId !== '') {
                $addresses[$venueId] = $addressId;
            }
        }

        return $addresses;
    }

    /**
     * Resolve the first institution fallback address for each venue that has
     * no primary address of its own, in one batched relation load.
     *
     * @param  list<string>  $venueIds
     * @return array<string, string>
     */
    private function fallbackInstitutionAddresses(array $venueIds): array
    {
        $addresses = [];

        foreach (Venue::query()->whereKey($venueIds)->with([
            'institutions' => static function (BelongsToMany $query): void {
                $query
                    ->orderByDesc('institution_venue.is_primary')
                    ->orderBy('institutions.id')
                    ->with('addresses');
            },
        ])->get(['id']) as $venue) {
            $address = $venue->institutions->first()?->primaryAddress();

            if ($address instanceof Address) {
                $addresses[(string) $venue->getKey()] = (string) $address->getKey();
            }
        }

        return $addresses;
    }
}
