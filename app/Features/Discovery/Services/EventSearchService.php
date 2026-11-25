<?php

declare(strict_types=1);

namespace App\Features\Discovery\Services;

use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;
use App\Features\Discovery\Api\Data\EventListData;
use App\Features\Discovery\Api\Data\EventSearchHitIds;
use App\Features\Discovery\Api\Data\EventSearchPage;
use App\Features\Discovery\Api\EventDiscoveryCriteriaBuilder;
use App\Features\Discovery\Api\EventSearchReader;
use App\Features\Events\Api\Data\EventDiscoveryHit;
use App\Features\Events\Api\Data\EventDiscoveryPage;
use App\Features\Events\Api\EventCards;
use App\Features\Events\Api\EventDiscoveryReader;
use App\Features\Events\Api\EventDiscoveryReaders;
use App\Features\Events\Api\EventSearchCache;
use App\Shared\Contracts\SearchEngineResolver;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventSearchService implements EventSearchReader
{
    public function __construct(
        private readonly SearchEngineResolver $engineResolver,
        private readonly EventSearchCache $eventSearchCache,
        private readonly EventDiscoveryReaders $readers,
        private readonly EventCards $eventCards,
        private readonly EventDiscoveryCriteriaBuilder $criteriaFactory,
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
            return $this->cachedDefaultSearch($criteria);
        }

        return $this->toPage($this->performSearch($criteria));
    }

    /**
     * Search event IDs without hydrating event cards.
     *
     * @param  array<string, mixed>  $filters
     */
    public function searchIds(
        ?string $query = null,
        array $filters = [],
        int $perPage = 20,
        string $sort = 'time'
    ): EventSearchHitIds {
        $criteria = $this->criteriaFactory->fromSearch($query, $filters, $perPage, $sort);

        return $this->dispatchDiscoveryReader(
            $criteria,
            static fn (EventDiscoveryReader $reader): EventSearchHitIds => $reader->searchIds($criteria),
            'Typesense ID search failed, falling back to database',
        );
    }

    protected function performSearch(EventDiscoveryCriteria $criteria): EventDiscoveryPage
    {
        return $this->dispatchDiscoveryReader(
            $criteria,
            static fn (EventDiscoveryReader $reader): EventDiscoveryPage => $reader->search($criteria),
            'Typesense search failed, falling back to database',
        );
    }

    private function usesDefaultSearchCache(EventDiscoveryCriteria $criteria): bool
    {
        if (
            $criteria->text !== null
            || $criteria->latitude !== null
            || $criteria->longitude !== null
            || $criteria->perPage < 1
            || $criteria->sort !== 'time'
            || $criteria->page !== 1
        ) {
            return false;
        }

        $filters = $criteria->filters;
        $timeScope = $filters['time_scope'] ?? null;

        if ($timeScope !== null && $timeScope !== 'upcoming') {
            return false;
        }

        unset($filters['country_id'], $filters['time_scope']);

        return $filters === [];
    }

    private function cachedDefaultSearch(EventDiscoveryCriteria $criteria): EventSearchPage
    {
        $scopeKey = sprintf(
            'country:%s:per:%d',
            $criteria->countryId ?? 'global',
            $criteria->perPage,
        );

        try {
            $payload = $this->eventSearchCache->rememberDefaultSearch($scopeKey, function () use ($criteria): array {
                $paginator = $this->performSearch($criteria);

                return [
                    'ids' => array_map(static fn (EventDiscoveryHit $hit): string => $hit->id, $paginator->items),
                    'total' => $paginator->total,
                ];
            });
        } catch (Throwable $exception) {
            Log::warning('Default event search cache failed, using uncached results', [
                'error' => $exception->getMessage(),
            ]);

            return $this->toPage($this->performSearch($criteria));
        }

        return $this->cachedPage($payload->ids, $payload->total, $criteria->perPage);
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

    private function toPage(EventDiscoveryPage $page): EventSearchPage
    {
        $items = array_map(
            EventListData::fromArray(...),
            $this->eventCards->forIds(array_map(
                static fn (EventDiscoveryHit $hit): string => $hit->id,
                $page->items,
            )),
        );
        $distances = [];

        foreach ($page->items as $hit) {
            if ($hit->distanceKm !== null) {
                $distances[$hit->id] = round($hit->distanceKm, 3);
            }
        }

        return new EventSearchPage(
            items: $items,
            distances: $distances,
            total: $page->total,
            perPage: $page->perPage,
            currentPage: $page->currentPage,
            lastPage: $page->lastPage,
            from: $page->from,
            to: $page->to,
            hasMorePages: $page->hasMorePages,
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

        return $this->toPage($this->dispatchDiscoveryReader(
            $criteria,
            static fn (EventDiscoveryReader $reader): EventDiscoveryPage => $reader->nearby($criteria),
            'Typesense geo search failed',
        ));
    }

    /**
     * Geo search returning IDs and distances without hydrating event cards.
     *
     * @param  array<string, mixed>  $filters
     */
    public function searchNearbyIds(
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20,
        ?string $query = null,
    ): EventSearchHitIds {
        $criteria = $this->criteriaFactory->fromSearch($query, $filters, $perPage, 'distance', $lat, $lng, $radiusKm);
        $normalizedQuery = $this->normalizeSearchQuery($criteria->text);

        if ($normalizedQuery === null) {
            return $this->dispatchDiscoveryReader(
                $criteria,
                static fn (EventDiscoveryReader $reader): EventSearchHitIds => $reader->nearbyIds($criteria),
                'Typesense geo ID search failed',
            );
        }

        return $this->dispatchDiscoveryReader(
            $criteria,
            static fn (EventDiscoveryReader $reader): EventSearchHitIds => $reader->nearbyWithQueryIds($criteria),
            'Typesense geo query ID search failed',
        );
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

        return $this->toPage($this->dispatchDiscoveryReader(
            $criteria,
            static fn (EventDiscoveryReader $reader): EventDiscoveryPage => $reader->nearbyWithQuery($criteria),
            'Typesense geo query search failed',
        ));
    }

    /**
     * @template TResult
     *
     * @param  Closure(EventDiscoveryReader): TResult  $callback
     * @return TResult
     */
    private function dispatchDiscoveryReader(EventDiscoveryCriteria $criteria, Closure $callback, string $failureMessage): mixed
    {
        if ($criteria->requiresDatabaseFiltering) {
            return $callback($this->readers->database());
        }

        if ($this->engineResolver->usesTypesense() && $this->engineResolver->isRemoteEngineAvailable()) {
            try {
                return $callback($this->readers->typesense());
            } catch (\Exception $e) {
                Log::warning($failureMessage, ['error' => $e->getMessage()]);
            }
        }

        return $callback($this->readers->database());
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
