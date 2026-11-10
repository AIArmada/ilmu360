<?php

declare(strict_types=1);

namespace App\Support\Discovery;

final class SearchSignal
{
    /**
     * Normalize a discovery interaction into the exact product-signal payload.
     *
     * @param  array<string, mixed>  $filters
     * @return array{event_name: string, event_category: string, properties: array<string, mixed>}|null
     */
    public static function payload(
        ?string $query,
        array $filters,
        string $surface,
        ?int $resultCount,
        ?string $savedSearchId = null,
    ): ?array {
        $normalizedQuery = $query !== null ? trim($query) : null;
        $normalizedFilters = array_filter($filters, function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });

        if (($normalizedQuery === null || $normalizedQuery === '') && $normalizedFilters === []) {
            return null;
        }

        $hasQuery = $normalizedQuery !== null && $normalizedQuery !== '';

        return [
            'event_name' => $hasQuery ? 'search.executed' : 'listing.filtered',
            'event_category' => $hasQuery ? 'search' : 'discovery',
            'properties' => [
                'interaction_type' => $hasQuery ? 'search' : 'filter',
                'surface' => $surface,
                'query' => $normalizedQuery,
                'filter_keys' => array_values(array_map(static fn (string $key): string => $key, array_keys($normalizedFilters))),
                'filters' => $normalizedFilters,
                'result_count' => $resultCount,
                'saved_search_id' => $savedSearchId,
            ],
        ];
    }
}
