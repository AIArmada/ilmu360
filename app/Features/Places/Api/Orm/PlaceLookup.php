<?php

declare(strict_types=1);

namespace App\Features\Places\Api\Orm;

/**
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Places/FeatureServiceProvider.php, Engagement/Queries, Prayer/Support, Events/Queries, Events/EntryPoints, Contributions/Services
 */
interface PlaceLookup
{
    public function resolveVenueIdentifier(string $identifier): ?string;

    public function resolveSpaceIdentifier(string $identifier): ?string;

    public function venueTable(): string;

    public function venueMorphType(): string;

    public function venueName(string $venueId): ?string;

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
