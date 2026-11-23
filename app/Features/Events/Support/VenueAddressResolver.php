<?php

declare(strict_types=1);

namespace App\Features\Events\Support;

use AIArmada\Addressing\Models\Address;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Places\Api\PlaceCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Request-scoped, owner-isolated scalar venue projections.
 *
 * Places owns venue lookups and returns immutable names/address IDs.
 * Events resolves package Address records only inside its rendering boundary.
 */
final class VenueAddressResolver
{
    /** @var array<string, array<string, Address|null>> */
    private array $addresses = [];

    /** @var array<string, array<string, string|null>> */
    private array $names = [];

    public function __construct(private readonly PlaceCatalog $places) {}

    /** @param list<string> $venueIds */
    public function prime(array $venueIds): void
    {
        $scope = $this->scopeKey();
        $missing = array_values(array_unique(array_filter(
            $venueIds,
            fn (string $id): bool => $id !== '' && ! array_key_exists($id, $this->addresses[$scope] ?? []),
        )));

        if ($missing === []) {
            return;
        }

        $validIds = array_values(array_filter($missing, static fn (string $id): bool => Str::isUuid($id)));
        $summaries = $validIds !== [] ? $this->places->venueLocationSummariesFor($validIds) : [];

        $addressIds = [];

        foreach ($summaries as $summary) {
            $addressId = $summary['address_id'];

            if (is_string($addressId) && $addressId !== '') {
                $addressIds[$addressId] = $addressId;
            }
        }

        $records = $addressIds !== []
            ? Address::query()
                ->with(['country', 'state', 'city', 'areaAssignments.area'])
                ->whereKey(array_values($addressIds))
                ->get()
                ->keyBy('id')
            : collect();

        foreach ($missing as $venueId) {
            $summary = $summaries[$venueId] ?? null;
            $addressId = $summary['address_id'] ?? null;
            $address = is_string($addressId) ? $records->get($addressId) : null;
            $this->addresses[$scope][$venueId] = $address instanceof Address ? $address : null;
            $this->names[$scope][$venueId] = $summary['name'] ?? null;
        }
    }

    public function forVenueId(?string $venueId): ?Address
    {
        if ($venueId === null || $venueId === '') {
            return null;
        }

        $this->prime([$venueId]);

        return $this->addresses[$this->scopeKey()][$venueId] ?? null;
    }

    public function nameForVenueId(?string $venueId): ?string
    {
        if ($venueId === null || $venueId === '') {
            return null;
        }

        $this->prime([$venueId]);

        return $this->names[$this->scopeKey()][$venueId] ?? null;
    }

    private function scopeKey(): string
    {
        $owner = OwnerContext::resolve();

        return $owner instanceof Model
            ? $owner->getMorphClass().':'.$owner->getKey()
            : 'global';
    }
}
