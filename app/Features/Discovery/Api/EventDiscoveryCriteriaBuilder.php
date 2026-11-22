<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;

/** Normalize public search input into immutable discovery criteria. */
interface EventDiscoveryCriteriaBuilder
{
    /** @param array<string, mixed> $filters */
    public function fromSearch(
        ?string $text,
        array $filters,
        int $perPage,
        string $sort,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $radiusKm = null,
    ): EventDiscoveryCriteria;
}
