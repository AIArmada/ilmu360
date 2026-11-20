<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

/**
 * Public institution-card payload boundary.
 *
 * Ordinary callers pass scalar ids and receive immutable card payloads; the
 * owner performs the hydration and follow-state lookup.
 */
interface InstitutionCards
{
    /**
     * @param  list<string>  $institutionIds
     * @param  array<string, float|int|string>  $distances
     * @return array<string, array<string, mixed>> Card payloads keyed by institution id.
     */
    public function forIds(array $institutionIds, ?string $accountId = null, array $distances = []): array;

    /**
     * @return array<string, mixed>|null
     */
    public function forId(string $institutionId, ?string $accountId = null): ?array;

    /**
     * Institution cards for a person's primary affiliations, primary first.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     display_name: string,
     *     slug: string,
     *     position: string|null,
     *     is_primary: bool,
     *     public_image_url: string,
     *     logo_url: string,
     *     cover_url: string|null
     * }>
     */
    public function personAffiliations(string $personId, int $limit = 3): array;

    /**
     * Aggregate-search institution cards with the owner's public visibility,
     * nearby radius and follow-independent card rules.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forSearch(
        string $query,
        ?float $lat = null,
        ?float $lng = null,
        int $radiusKm = 15,
        int $limit = 4,
    ): array;
}
