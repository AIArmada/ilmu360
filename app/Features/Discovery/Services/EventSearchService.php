<?php

declare(strict_types=1);

namespace App\Features\Discovery\Services;

use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;
use App\Features\Discovery\Api\Data\EventListData;
use App\Features\Discovery\Api\Data\EventSearchPage;
use App\Features\Discovery\Api\EventSearchReader;
use App\Features\Discovery\Api\Orm\EventListPayloads;
use App\Features\Events\Api\EventCards;
use App\Features\Events\Api\EventSearchCache;
use App\Features\Events\Models\Event;
use App\Platform\Search\EventDiscoveryReaders;
use App\Platform\Search\TypesenseHealthCheckService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventSearchService implements EventSearchReader
{
    public function __construct(
        private readonly TypesenseHealthCheckService $healthCheck,
        private readonly EventSearchCache $eventSearchCache,
        private readonly EventDiscoveryReaders $readers,
        private readonly EventCards $eventCards,
        private readonly EventListPayloads $eventListPayloads,
        private readonly EventDiscoveryCriteriaFactory $criteriaFactory = new EventDiscoveryCriteriaFactory,
    ) {}

    /**
     * Search events using Typesense if available, otherwise fallback to database.
     *
     * @param  array<string, mixed>  $filters
     */
    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 20,
        string $sort = 'time'
    ): EventSearchPage {
        $criteria = $this->criteriaFactory->fromSearch($query, $filters, $perPage, $sort);

        if ($this->usesDefaultSearchCache($criteria)) {
            return $this->cachedDefaultSearch($perPage);
        }

        return $this->toPage($this->performSearch($criteria));
    }

    /** @return LengthAwarePaginator<int, Event> */
    protected function performSearch(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        if ($criteria->requiresDatabaseFiltering) {
            return $this->readers->database()->search($criteria);
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->readers->typesense()->search($criteria);
            } catch (\Exception $e) {
                Log::warning('Typesense search failed, falling back to database', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->readers->database()->search($criteria);
    }

    private function usesDefaultSearchCache(EventDiscoveryCriteria $criteria): bool
    {
        return $criteria->text === null
            && $criteria->filters === []
            && $criteria->perPage === 12
            && $criteria->sort === 'time'
            && $criteria->page === 1;
    }

    private function cachedDefaultSearch(int $perPage): EventSearchPage
    {
        $criteria = $this->criteriaFactory->fromSearch(null, [], $perPage, 'time');

        try {
            $payload = $this->eventSearchCache->rememberDefaultSearch(function () use ($criteria): array {
                $paginator = $this->performSearch($criteria);

                return [
                    'ids' => array_values(array_map(static fn (Event $event): string => (string) $event->getKey(), $paginator->items())),
                    'total' => $paginator->total(),
                ];
            });
        } catch (Throwable $exception) {
            Log::warning('Default event search cache failed, using uncached results', [
                'error' => $exception->getMessage(),
            ]);

            return $this->toPage($this->performSearch($criteria));
        }

        return $this->cachedPage($payload->ids, $payload->total, $perPage);
    }

    /**
     * @param  list<string>  $eventIds
     */
    private function cachedPage(array $eventIds, int $total, int $perPage): EventSearchPage
    {
        $items = array_values(array_map(
            EventListData::fromArray(...),
            $this->eventCards->forIds($eventIds),
        ));
        $lastPage = max(1, (int) ceil($total / max($perPage, 1)));
        $hasItems = $items !== [];

        return new EventSearchPage(
            items: $items,
            distances: [],
            total: $total,
            perPage: $perPage,
            currentPage: 1,
            lastPage: $lastPage,
            from: $hasItems ? 1 : null,
            to: $hasItems ? count($items) : null,
            hasMorePages: $lastPage > 1,
        );
    }

    /**
     * @param  LengthAwarePaginator<int, Event>  $paginator
     */
    private function toPage(LengthAwarePaginator $paginator): EventSearchPage
    {
        $events = array_values(array_filter(
            $paginator->items(),
            static fn (mixed $event): bool => $event instanceof Event,
        ));

        new EloquentCollection($events)->loadMissing('displayOccurrence.media');

        $items = [];
        $distances = [];

        foreach ($events as $event) {
            $item = $this->eventListPayloads->forEvent($event);
            $items[] = $item;

            $distanceKm = $event->getAttributes()['distance_km'] ?? null;

            if (is_numeric($distanceKm)) {
                $distances[$item->id] = round((float) $distanceKm, 3);
            }
        }

        return new EventSearchPage(
            items: $items,
            distances: $distances,
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            from: $paginator->firstItem(),
            to: $paginator->lastItem(),
            hasMorePages: $paginator->hasMorePages(),
        );
    }

    /**
     * Geo search for "near me" functionality.
     *
     * @param  array<string, mixed>  $filters
     */
    public function searchNearby(
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): EventSearchPage {
        $criteria = $this->criteriaFactory->fromSearch(null, $filters, $perPage, 'distance', $lat, $lng, $radiusKm);

        if ($criteria->requiresDatabaseFiltering) {
            return $this->toPage($this->readers->database()->nearby($criteria));
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->toPage($this->readers->typesense()->nearby($criteria));
            } catch (\Exception $e) {
                Log::warning('Typesense geo search failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->toPage($this->readers->database()->nearby($criteria));
    }

    /**
     * Geo search constrained by a text query.
     *
     * @param  array<string, mixed>  $filters
     */
    public function searchNearbyWithQuery(
        ?string $query,
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): EventSearchPage {
        $criteria = $this->criteriaFactory->fromSearch($query, $filters, $perPage, 'distance', $lat, $lng, $radiusKm);
        $normalizedQuery = $this->normalizeSearchQuery($criteria->text);

        if ($normalizedQuery === null) {
            return $this->searchNearby($criteria->latitude ?? $lat, $criteria->longitude ?? $lng, (int) ($criteria->radiusKm ?? $radiusKm), $criteria->filters, $criteria->perPage);
        }

        if ($criteria->requiresDatabaseFiltering) {
            return $this->toPage($this->readers->database()->nearbyWithQuery($criteria));
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->toPage($this->readers->typesense()->nearbyWithQuery($criteria));
            } catch (\Exception $e) {
                Log::warning('Typesense geo query search failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->toPage($this->readers->database()->nearbyWithQuery($criteria));
    }

    private function normalizeSearchQuery(?string $query): ?string
    {
        if (! is_string($query)) {
            return null;
        }

        $normalizedQuery = trim($query);

        return $normalizedQuery === '' ? null : $normalizedQuery;
    }
}
