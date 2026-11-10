<?php

declare(strict_types=1);

namespace App\Support\Discovery;

use App\Features\SavedSearches\Api\SavedSearchExecutionResult;
use App\Features\SavedSearches\Api\SavedSearchExecutor;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\EventSearchService;
use App\Shared\Discovery\SearchFilterKeys;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final readonly class EventSavedSearchExecutor implements SavedSearchExecutor
{
    public function __construct(
        private EventSearchService $searchService,
        private SignalRecorder $signals,
        private Request $request,
    ) {}

    public function execute(SavedSearchSnapshot $savedSearch): SavedSearchExecutionResult
    {
        $filters = $this->normalizeFilters($savedSearch->filters);

        $events = ($savedSearch->lat !== null && $savedSearch->lng !== null && $savedSearch->radiusKm !== null)
            ? $this->searchService->searchNearby(
                lat: $savedSearch->lat,
                lng: $savedSearch->lng,
                radiusKm: $savedSearch->radiusKm,
                filters: $filters,
                perPage: 20,
            )
            : $this->searchService->search(
                query: is_string($savedSearch->query) ? $savedSearch->query : null,
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
            resultCount: $events->total(),
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

    /**
     * @param  LengthAwarePaginator<int, Event>  $events
     */
    private function result(LengthAwarePaginator $events): SavedSearchExecutionResult
    {
        $hasMore = $events->hasMorePages();

        return new SavedSearchExecutionResult(
            items: array_values(array_map(
                static fn (Event $event): array => $event->toArray(),
                $events->items(),
            )),
            page: $events->currentPage(),
            perPage: $events->perPage(),
            total: $events->total(),
            hasMore: $hasMore,
            nextPage: $hasMore ? $events->currentPage() + 1 : null,
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
