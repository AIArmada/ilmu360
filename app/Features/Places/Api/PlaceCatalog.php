<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

interface PlaceCatalog
{
    /** @return list<array{id: string, label: string}> */
    public function venues(?string $search = null, int $limit = 50): array;

    /** @return list<array{id: string, label: string}> */
    public function spaces(?string $institutionId = null): array;

    public function resolveVenueIdentifier(string $identifier): ?string;

    public function resolveSpaceIdentifier(string $identifier): ?string;

    public function venueName(string $venueId): ?string;

    /** @return array<string, string> */
    public function venueNameOptions(string $search = '', int $limit = 50): array;

    /** @param list<string> $statuses
     * @return array<string, string>
     */
    public function venueOptionsForStatuses(array $statuses, ?int $limit = null): array;

    /** @param list<string> $venueIds
     * @return array<string, string>
     */
    public function venueNamesByIds(array $venueIds): array;

    public function resolvedPrimaryAddressId(string $venueId): ?string;

    /** @param list<string> $venueIds
     * @return array<string, string>
     */
    public function primaryAddressIdsFor(array $venueIds): array;

    public function venueTable(): string;

    public function venueMorphType(): string;
}
