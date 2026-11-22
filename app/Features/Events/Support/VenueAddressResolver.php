<?php

declare(strict_types=1);

namespace App\Features\Events\Support;

use AIArmada\Addressing\Models\Address;
use App\Features\Places\Api\PlaceCatalog;

/**
 * Request-scoped adapter: Places supplies scalar address identities;
 * Events reads package-owned address records for local page rendering.
 * No foreign Eloquent models cross the Places API.
 */
final class VenueAddressResolver
{
    /** @var array<string, Address|null> */
    private array $addresses = [];

    public function __construct(private readonly PlaceCatalog $places) {}

    /** @param list<string> $venueIds */
    public function prime(array $venueIds): void
    {
        $missing = array_values(array_unique(array_filter($venueIds, fn (string $id): bool => $id !== '' && ! array_key_exists($id, $this->addresses))));

        if ($missing === []) {
            return;
        }

        $addressIds = $this->places->primaryAddressIdsFor($missing);
        $records = $addressIds !== []
            ? Address::query()
                ->with(['country', 'state', 'city', 'areaAssignments.area'])
                ->whereKey(array_values($addressIds))
                ->get()
                ->keyBy('id')
            : collect();

        foreach ($missing as $venueId) {
            $addressId = $addressIds[$venueId] ?? null;
            $address = $addressId !== null ? $records->get($addressId) : null;
            $this->addresses[$venueId] = $address instanceof Address ? $address : null;
        }
    }

    public function forVenueId(?string $venueId): ?Address
    {
        if ($venueId === null || $venueId === '') {
            return null;
        }

        $this->prime([$venueId]);

        return $this->addresses[$venueId];
    }
}
