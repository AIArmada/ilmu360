<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

/**
 * Public event-search boundary.
 *
 * Callers receive paginated event results from the same Typesense/database
 * pipeline (including its default-search cache), so search behaviour never
 * forks between entrypoints.
 */
interface EventSearchReader
{
    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Features\Events\Models\Event>
     */
    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 20,
        string $sort = 'time'
    );

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Features\Events\Models\Event>
     */
    public function searchNearby(
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    );

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Features\Events\Models\Event>
     */
    public function searchNearbyWithQuery(
        ?string $query,
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    );
}
