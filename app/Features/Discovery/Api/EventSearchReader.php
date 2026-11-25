<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

use App\Features\Discovery\Api\Data\EventSearchHitIds;
use App\Features\Discovery\Api\Data\EventSearchPage;

/**
 * Public event-search boundary.
 *
 * Callers receive immutable result pages from the same Typesense/database
 * pipeline (including its default-search cache), so search behaviour never
 * forks between entrypoints.
 */
interface EventSearchReader
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 20,
        string $sort = 'time'
    ): EventSearchPage;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function searchIds(
        ?string $query = null,
        array $filters = [],
        int $perPage = 20,
        string $sort = 'time'
    ): EventSearchHitIds;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function searchNearby(
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): EventSearchPage;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function searchNearbyIds(
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20,
        ?string $query = null,
    ): EventSearchHitIds;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function searchNearbyWithQuery(
        ?string $query,
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): EventSearchPage;
}
