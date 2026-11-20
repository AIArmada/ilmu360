<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

interface PlaceLookup
{
    public function resolveVenueIdentifier(string $identifier): ?string;

    public function resolveSpaceIdentifier(string $identifier): ?string;

    public function venueTable(): string;

    public function venueMorphType(): string;

    public function venueName(string $venueId): ?string;

    /**
     * Documented transport: a fresh venue query for the caller's read-only
     * submitter constraints. Callers must not write through it.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Features\Places\Models\Venue>
     */
    public function newVenueQuery(): object;

    /**
     * Documented transport: venue detail record with media, addresses,
     * contacts and social profiles, resolved by slug or uuid.
     */
    public function findVenueDetail(string $identifier): ?object;

    /**
     * Primary address for a venue, falling back to its operating institution.
     */
    public function resolvedPrimaryAddressId(string $venueId): ?string;

    /** @return array<string, string> */
    public function venueNameOptions(string $search = '', int $limit = 50): array;

    /**
     * @param  list<string>  $statuses
     * @return array<string, string>
     */
    public function venueOptionsForStatuses(array $statuses, ?int $limit = null): array;

    /**
     * @param  list<string>  $venueIds
     * @return array<string, string>
     */
    public function venueNamesByIds(array $venueIds): array;

    /**
     * Attach fully loaded application venue models to already-loaded event
     * location objects using the card relation set.
     *
     * @param  iterable<object>  $locations
     */
    public function hydrateCardVenues(iterable $locations): void;

    /**
     * Attach fully loaded application venue models to already-loaded event
     * location objects using the detail-page relation set.
     *
     * @param  iterable<object>  $locations
     */
    public function hydrateDetailVenues(iterable $locations): void;
}
