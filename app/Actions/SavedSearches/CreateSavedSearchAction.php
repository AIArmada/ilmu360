<?php

namespace App\Actions\SavedSearches;

use App\Enums\DawahShareOutcomeType;
use App\Exceptions\SavedSearchLimitReachedException;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\SavedSearches\SavedSearchFilterNormalizer;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class CreateSavedSearchAction
{
    use AsAction;

    public const int MAX_SAVED_SEARCHES_PER_USER = 10;

    public function __construct(
        private ShareTrackingService $shareTrackingService,
        private SavedSearchFilterNormalizer $filterNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $user, array $attributes, ?Request $request = null): SavedSearch
    {
        if ($user->savedSearches()->count() >= self::MAX_SAVED_SEARCHES_PER_USER) {
            throw new SavedSearchLimitReachedException(self::MAX_SAVED_SEARCHES_PER_USER);
        }

        $savedSearch = $user->savedSearches()->create($this->normalizeAttributes($attributes));

        $this->shareTrackingService->recordOutcome(
            type: DawahShareOutcomeType::SavedSearchCreated,
            outcomeKey: 'saved_search_created:saved_search:'.$savedSearch->id,
            subject: null,
            actor: $user,
            request: $request ?? request(),
            metadata: [
                'saved_search_id' => $savedSearch->id,
            ],
        );

        return $savedSearch;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     name: string,
     *     query: string|null,
     *     filters: array<string, mixed>|null,
     *     radius_km: int|null,
     *     lat: float|null,
     *     lng: float|null,
     *     notify: string
     * }
     */
    private function normalizeAttributes(array $attributes): array
    {
        return [
            'name' => (string) $attributes['name'],
            'query' => array_key_exists('query', $attributes) && $attributes['query'] !== null
                ? (string) $attributes['query']
                : null,
            'filters' => $this->normalizeFilters($attributes['filters'] ?? null),
            'radius_km' => array_key_exists('radius_km', $attributes) && $attributes['radius_km'] !== null
                ? (int) $attributes['radius_km']
                : null,
            'lat' => array_key_exists('lat', $attributes) && $attributes['lat'] !== null
                ? (float) $attributes['lat']
                : null,
            'lng' => array_key_exists('lng', $attributes) && $attributes['lng'] !== null
                ? (float) $attributes['lng']
                : null,
            'notify' => (string) $attributes['notify'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeFilters(mixed $filters): ?array
    {
        return $this->filterNormalizer->normalizeForStorage($filters);
    }
}
