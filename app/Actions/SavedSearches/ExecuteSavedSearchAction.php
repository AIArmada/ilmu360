<?php

namespace App\Actions\SavedSearches;

use App\Models\Event;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\EventSearchService;
use App\Services\Signals\ProductSignalsService;
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
    public function handle(SavedSearch $savedSearch, ?Request $request = null): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($savedSearch->filters);

        $events = ($savedSearch->lat !== null && $savedSearch->lng !== null && $savedSearch->radius_km !== null)
            ? $this->searchService->searchNearby(
                lat: $savedSearch->lat,
                lng: $savedSearch->lng,
                radiusKm: $savedSearch->radius_km,
                filters: $filters,
                perPage: 20,
            )
            : $this->searchService->search(
                query: $savedSearch->query,
                filters: $filters,
                perPage: 20,
            );

        $resolvedRequest = $request ?? request();
        $user = $resolvedRequest->user();

        $this->productSignalsService->recordSearchExecuted(
            user: $user instanceof User ? $user : null,
            request: $resolvedRequest,
            surface: 'saved_search.execute',
            query: $savedSearch->query,
            filters: array_merge($filters, array_filter([
                'lat' => $savedSearch->lat,
                'lng' => $savedSearch->lng,
                'radius_km' => $savedSearch->radius_km,
            ], static fn (mixed $value): bool => $value !== null)),
            resultCount: $events->total(),
            savedSearchId: (string) $savedSearch->getKey(),
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

        $normalizedFilters = Arr::only($filters, $this->allowedFilterKeys());

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

    /**
     * @return list<string>
     */
    private function allowedFilterKeys(): array
    {
        return [
            'country_id',
            'state_id',
            'district_id',
            'subdistrict_id',
            'institution_id',
            'venue_id',
            'speaker_ids',
            'key_person_roles',
            'person_in_charge_ids',
            'person_in_charge_search',
            'moderator_ids',
            'imam_ids',
            'khatib_ids',
            'bilal_ids',
            'domain_tag_ids',
            'topic_ids',
            'source_tag_ids',
            'issue_tag_ids',
            'reference_ids',
            'language_codes',
            'event_type',
            'event_format',
            'gender',
            'starts_after',
            'starts_before',
            'starts_on_local_date',
            'time_scope',
            'prayer_time',
            'timing_mode',
            'starts_time_from',
            'starts_time_until',
            'children_allowed',
            'is_muslim_only',
            'has_event_url',
            'has_live_url',
            'has_end_time',
            'age_group',
        ];
    }
}
