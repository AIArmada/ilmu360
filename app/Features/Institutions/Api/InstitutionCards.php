<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

/**
 * Public institution-card payload boundary.
 *
 * Consumers pass a hydrated owner institution model (or a person id for
 * affiliation cards) and receive the immutable card payload the public HTTP
 * and MCP surfaces serialize.
 */
interface InstitutionCards
{
    /**
     * @return array<string, mixed>
     */
    public function forInstitution(object $institution, ?string $accountId = null): array;

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
