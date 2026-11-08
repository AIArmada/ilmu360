<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Actions;

use App\Features\SavedSearches\Models\SavedSearch;
use App\Shared\Discovery\SearchFilterNormalizer;
use App\Shared\Integrations\SavedSearchLimitReachedException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class CreateSavedSearchAction
{
    use AsAction;

    public const int MAX_SAVED_SEARCHES_PER_USER = 10;

    public function __construct(private SearchFilterNormalizer $filterNormalizer) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(string $ownerType, string $ownerId, array $attributes): SavedSearch
    {
        return DB::transaction(function () use ($ownerType, $ownerId, $attributes): SavedSearch {
            if (SavedSearch::query()->where('user_type', $ownerType)->where('user_id', $ownerId)->count() >= self::MAX_SAVED_SEARCHES_PER_USER) {
                throw new SavedSearchLimitReachedException(self::MAX_SAVED_SEARCHES_PER_USER);
            }

            return SavedSearch::query()->create(array_merge($this->normalizeAttributes($attributes), [
                'user_type' => $ownerType,
                'user_id' => $ownerId,
            ]));
        });
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
            'filters' => $this->filterNormalizer->normalizeForStorage($attributes['filters'] ?? null),
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
}
