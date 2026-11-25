<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Services;

use App\Features\Discovery\Api\Data\EventListData;
use App\Features\Discovery\Api\Data\EventSearchPage;
use App\Features\Discovery\Api\EventSearchReader;
use App\Features\Discovery\Api\SearchFilterKeys;
use App\Features\Discovery\Api\SearchSignal;
use App\Features\Events\Api\EventCards;
use App\Features\SavedSearches\Api\SavedSearchExecutionResult;
use App\Features\SavedSearches\Api\SavedSearchExecutor;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final readonly class EventSavedSearchExecutor implements SavedSearchExecutor
{
    public function __construct(
        private EventSearchReader $searchService,
        private EventCards $eventCards,
        private SignalRecorder $signals,
        private Request $request,
    ) {}

    public function execute(SavedSearchSnapshot $savedSearch): SavedSearchExecutionResult
    {
        $filters = $this->normalizeFilters($savedSearch->filters);
        $rawQuery = is_string($savedSearch->query) ? trim($savedSearch->query) : '';
        $normalizedQuery = $rawQuery !== '' ? $rawQuery : null;

        $events = ($savedSearch->lat !== null && $savedSearch->lng !== null && $savedSearch->radiusKm !== null)
            ? ($normalizedQuery !== null
                ? $this->searchService->searchNearbyWithQuery(
                    query: $normalizedQuery,
                    lat: $savedSearch->lat,
                    lng: $savedSearch->lng,
                    radiusKm: $savedSearch->radiusKm,
                    filters: $filters,
                    perPage: 20,
                )
                : $this->searchService->searchNearby(
                    lat: $savedSearch->lat,
                    lng: $savedSearch->lng,
                    radiusKm: $savedSearch->radiusKm,
                    filters: $filters,
                    perPage: 20,
                ))
            : $this->searchService->search(
                query: $normalizedQuery,
                filters: $filters,
                perPage: 20,
            );

        $user = $this->request->user();

        $signal = SearchSignal::payload(
            query: is_string($savedSearch->query) ? $savedSearch->query : null,
            filters: array_merge($filters, array_filter([
                'lat' => $savedSearch->lat,
                'lng' => $savedSearch->lng,
                'radius_km' => $savedSearch->radiusKm,
            ], static fn (mixed $value): bool => $value !== null)),
            surface: 'saved_search.execute',
            resultCount: $events->total,
            savedSearchId: $savedSearch->id,
        );

        if ($signal !== null) {
            $this->signals->record(
                eventName: $signal['event_name'],
                eventCategory: $signal['event_category'],
                actorId: $user instanceof User ? (string) $user->getKey() : null,
                properties: $signal['properties'],
                request: $this->request,
            );
        }

        return $this->result($events);
    }

    private function result(EventSearchPage $events): SavedSearchExecutionResult
    {
        $items = array_map(
            function (array $item) use ($events): array {
                $distanceKm = $events->distances[(string) ($item['id'] ?? '')] ?? null;

                if ($distanceKm !== null) {
                    $item['distance_km'] = round($distanceKm, 3);
                }

                return $item;
            },
            $this->eventCards->fullArraysForIds($this->eventIds($events)),
        );

        return new SavedSearchExecutionResult(
            items: array_values($items),
            page: $events->currentPage,
            perPage: $events->perPage,
            total: $events->total,
            hasMore: $events->hasMorePages,
            nextPage: $events->hasMorePages ? $events->currentPage + 1 : null,
        );
    }

    /**
     * @return list<string>
     */
    private function eventIds(EventSearchPage $page): array
    {
        return array_map(
            static fn (EventListData $item): string => $item->id,
            $page->items,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFilters(mixed $filters): array
    {
        if (! is_array($filters) || $filters === []) {
            return [];
        }

        $normalizedFilters = Arr::only($filters, SearchFilterKeys::all());

        if (array_key_exists('language_codes', $normalizedFilters)) {
            $normalizedFilters['language_codes'] = array_values(array_filter(
                array_map(static function (mixed $languageCode): ?string {
                    $normalizedLanguageCode = trim((string) $languageCode);

                    return $normalizedLanguageCode !== '' ? $normalizedLanguageCode : null;
                }, (array) $normalizedFilters['language_codes']),
                static fn (?string $languageCode): bool => $languageCode !== null,
            ));

            if ($normalizedFilters['language_codes'] === []) {
                unset($normalizedFilters['language_codes']);
            }
        }

        /** @var array<string, mixed> $normalizedFilters */
        return $normalizedFilters;
    }
}
