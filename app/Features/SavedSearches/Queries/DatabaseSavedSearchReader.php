<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Queries;

use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Features\SavedSearches\Models\SavedSearch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DatabaseSavedSearchReader implements SavedSearchReader
{
    public function forOwner(string $ownerType, string $ownerId, ?int $limit = null): array
    {
        $query = $this->ownerQuery($ownerType, $ownerId)->latest();

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn (SavedSearch $savedSearch): SavedSearchSnapshot => $this->snapshot($savedSearch))->all();
    }

    public function notificationCandidates(): iterable
    {
        foreach (SavedSearch::query()->where('notify', '!=', 'off')->cursor() as $savedSearch) {
            yield $this->snapshot($savedSearch);
        }
    }

    public function find(string $id): ?SavedSearchSnapshot
    {
        $savedSearch = SavedSearch::query()->find($id);

        return $savedSearch instanceof SavedSearch ? $this->snapshot($savedSearch) : null;
    }

    public function exists(string $id): bool
    {
        return SavedSearch::query()->whereKey($id)->exists();
    }

    public function findForOwner(string $ownerType, string $ownerId, string $searchId): ?SavedSearchSnapshot
    {
        $savedSearch = $this->ownerQuery($ownerType, $ownerId)->find($searchId);

        return $savedSearch instanceof SavedSearch ? $this->snapshot($savedSearch) : null;
    }

    public function hasEarlierForOwner(string $ownerType, string $ownerId, string $excludingId, CarbonImmutable $before): bool
    {
        return $this->ownerQuery($ownerType, $ownerId)
            ->whereKeyNot($excludingId)
            ->where('created_at', '<', $before->setTimezone(config('app.timezone', 'UTC')))
            ->exists();
    }

    public function restorationSnapshotForOwner(string $ownerType, string $ownerId): array
    {
        return $this->ownerQuery($ownerType, $ownerId)->get()->map->attributesToArray()->all();
    }

    /** @return Builder<SavedSearch> */
    private function ownerQuery(string $ownerType, string $ownerId): Builder
    {
        return SavedSearch::query()->where('user_type', $ownerType)->where('user_id', $ownerId);
    }

    public function snapshot(SavedSearch $savedSearch): SavedSearchSnapshot
    {
        return new SavedSearchSnapshot(
            id: (string) $savedSearch->getKey(),
            userType: (string) $savedSearch->user_type,
            userId: (string) $savedSearch->user_id,
            name: (string) $savedSearch->name,
            query: $savedSearch->query,
            filters: $savedSearch->filters,
            notify: $savedSearch->notify,
            lat: $savedSearch->lat,
            lng: $savedSearch->lng,
            radiusKm: $savedSearch->radius_km,
            createdAt: $savedSearch->created_at !== null ? CarbonImmutable::instance($savedSearch->created_at) : null,
            payload: $savedSearch->toArray(),
            updatedAt: $savedSearch->updated_at !== null ? CarbonImmutable::instance($savedSearch->updated_at) : null,
        );
    }
}
