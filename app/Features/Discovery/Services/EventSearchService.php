<?php

namespace App\Features\Discovery\Services;

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;
use App\Features\Discovery\Api\EventSearchReader;
use App\Features\Events\Api\EventSearchCache;
use App\Features\Events\Models\Event;
use App\Platform\Search\EventDiscoveryReaders;
use App\Platform\Search\TypesenseHealthCheckService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventSearchService implements EventSearchReader
{
    public function __construct(
        private readonly TypesenseHealthCheckService $healthCheck,
        private readonly EventSearchRelationProvider $relationProvider,
        private readonly EventSearchCache $eventSearchCache,
        private readonly EventDiscoveryReaders $readers,
        private readonly EventDiscoveryCriteriaFactory $criteriaFactory = new EventDiscoveryCriteriaFactory,
    ) {}

    /**
     * Search events using Typesense if available, otherwise fallback to database.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 20,
        string $sort = 'time'
    ): LengthAwarePaginator {
        $criteria = $this->criteriaFactory->fromSearch($query, $filters, $perPage, $sort);

        if ($this->usesDefaultSearchCache($criteria)) {
            return $this->cachedDefaultSearch($perPage);
        }

        return $this->performSearch($criteria);
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

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    private function cachedDefaultSearch(int $perPage): LengthAwarePaginator
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

            return $this->performSearch($criteria);
        }

        if ($payload->ids === []) {
            return new Paginator(
                items: collect(),
                total: $payload->total,
                perPage: $perPage,
                currentPage: 1,
                options: [
                    'path' => Paginator::resolveCurrentPath(),
                    'pageName' => 'page',
                    'query' => request()->query(),
                ],
            );
        }

        $events = Event::query()
            ->with($this->relationProvider->relations())
            ->whereHas('occurrences')
            ->whereKey($payload->ids)
            ->get()
            ->keyBy('id');

        $orderedEvents = collect($payload->ids)
            ->map(static function (string $eventId) use ($events): ?Event {
                $event = $events->get($eventId);

                return $event instanceof Event ? $event : null;
            })
            ->filter()
            ->values();

        return new Paginator(
            items: $orderedEvents,
            total: $payload->total,
            perPage: $perPage,
            currentPage: 1,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
                'query' => request()->query(),
            ],
        );
    }

    /**
     * Geo search for "near me" functionality.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    public function searchNearby(
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $criteria = $this->criteriaFactory->fromSearch(null, $filters, $perPage, 'distance', $lat, $lng, $radiusKm);

        if ($criteria->requiresDatabaseFiltering) {
            return $this->readers->database()->nearby($criteria);
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->readers->typesense()->nearby($criteria);
            } catch (\Exception $e) {
                Log::warning('Typesense geo search failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->readers->database()->nearby($criteria);
    }

    /**
     * Geo search constrained by a text query.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    public function searchNearbyWithQuery(
        ?string $query,
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $criteria = $this->criteriaFactory->fromSearch($query, $filters, $perPage, 'distance', $lat, $lng, $radiusKm);
        $normalizedQuery = $this->normalizeSearchQuery($criteria->text);

        if ($normalizedQuery === null) {
            return $this->searchNearby($criteria->latitude ?? $lat, $criteria->longitude ?? $lng, $criteria->radiusKm ?? $radiusKm, $criteria->filters, $criteria->perPage);
        }

        if ($criteria->requiresDatabaseFiltering) {
            return $this->readers->database()->nearbyWithQuery($criteria);
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->readers->typesense()->nearbyWithQuery($criteria);
            } catch (\Exception $e) {
                Log::warning('Typesense geo query search failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->readers->database()->nearbyWithQuery($criteria);
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
