<?php

namespace App\Actions\Events\Search;

use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\EventSearchService;
use App\Services\Signals\ProductSignalsService;
use App\Shared\Discovery\SearchFilterKeys;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ExecuteSavedSearchAction
{
    use AsAction;

    public function __construct(
        private EventSearchService $searchService,
        private ProductSignalsService $productSignalsService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    public function handle(SavedSearchSnapshot $savedSearch, ?Request $request = null): LengthAwarePaginator
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

        $resolvedRequest = $request ?? request();
        $user = $resolvedRequest->user();

        $this->productSignalsService->recordSearchExecuted(
            user: $user instanceof User ? $user : null,
            request: $resolvedRequest,
            surface: 'saved_search.execute',
            query: is_string($savedSearch->query) ? $savedSearch->query : null,
            filters: array_merge($filters, array_filter([
                'lat' => $savedSearch->lat,
                'lng' => $savedSearch->lng,
                'radius_km' => $savedSearch->radiusKm,
            ], static fn (mixed $value): bool => $value !== null)),
            resultCount: $events->total(),
            savedSearchId: $savedSearch->id,
        );

        return $events;
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
