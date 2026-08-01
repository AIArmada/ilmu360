<?php

namespace App\Services;

use App\Contracts\EventCategoryCatalog;
use App\Data\EventDiscoveryCriteria;
use App\Data\EventDiscoveryCriteriaFactory;
use App\Models\Event;
use App\Support\EventDiscovery\EventDiscoveryFilterSet;
use App\Support\EventDiscovery\FuzzyEventMatcher;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\PersonSearchService;
use App\Support\Search\ReferenceSearchService;
use App\Support\Search\TypesenseHealthCheckService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventSearchService
{
    public function __construct(
        private readonly TypesenseHealthCheckService $healthCheck,
        private readonly PersonSearchService $personSearch,
        private readonly InstitutionSearchService $institutionSearch,
        private readonly ReferenceSearchService $referenceSearch,
        private readonly EventCategoryCatalog $categoryCatalog,
        private readonly FuzzyEventMatcher $fuzzyMatcher = new FuzzyEventMatcher,
        private readonly EventDiscoveryCriteriaFactory $criteriaFactory = new EventDiscoveryCriteriaFactory,
        private readonly EventDiscoveryFilterSet $filterSet = new EventDiscoveryFilterSet,
    ) {}

    /**
     * @return array<int|string, mixed>
     */
    protected function cardRelationships(): array
    {
        return [
            'media' => fn ($query) => $query
                ->whereIn('collection_name', ['cover', 'poster'])
                ->ordered(),
            'references',
            'classifications.term',
            'persons.media' => fn ($query) => $query
                ->where('collection_name', 'avatar')
                ->ordered(),
            'persons.titleAssignments.title.category',
            'languageRecords',
            'institution.media' => fn ($query) => $query
                ->where('collection_name', 'logo')
                ->ordered(),
            'institution.addresses.country',
            'institution.addresses.state',
            'institution.addresses.city',
            'institution.addresses.areaAssignments.area',
            'venue.addresses.country',
            'venue.addresses.state',
            'venue.addresses.city',
            'venue.addresses.areaAssignments.area',
            'latestPublishedChangeAnnouncement',
            'primaryOccurrence',
            'timeExpressions',
        ];
    }

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
            return $this->postgresDiscovery()->search($criteria);
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->typesenseDiscovery()->search($criteria);
            } catch (\Exception $e) {
                Log::warning('Typesense search failed, falling back to database', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->postgresDiscovery()->search($criteria);
    }

    private function postgresDiscovery(): PostgresEventDiscovery
    {
        return new PostgresEventDiscovery(
            fuzzyMatcher: $this->fuzzyMatcher,
            filterSet: $this->filterSet,
            personSearch: $this->personSearch,
            institutionSearch: $this->institutionSearch,
            referenceSearch: $this->referenceSearch,
            categoryCatalog: $this->categoryCatalog,
        );
    }

    private function typesenseDiscovery(): TypesenseEventDiscovery
    {
        return new TypesenseEventDiscovery(
            filterSet: $this->filterSet,
            categoryCatalog: $this->categoryCatalog,
        );
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
            /** @var array{ids: list<string>, total: int} $payload */
            $payload = Cache::remember(
                'default_events_search_v2',
                60,
                function () use ($criteria): array {
                    $paginator = $this->performSearch($criteria);

                    return [
                        'ids' => array_values(array_map(static fn (Event $event): string => (string) $event->getKey(), $paginator->items())),
                        'total' => $paginator->total(),
                    ];
                },
            );
        } catch (Throwable $exception) {
            Log::warning('Default event search cache failed, using uncached results', [
                'error' => $exception->getMessage(),
            ]);

            return $this->performSearch($criteria);
        }

        if ($payload['ids'] === []) {
            return new Paginator(
                items: collect(),
                total: $payload['total'],
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
            ->with($this->cardRelationships())
            ->whereKey($payload['ids'])
            ->get()
            ->keyBy('id');

        $orderedEvents = collect($payload['ids'])
            ->map(static function (string $eventId) use ($events): ?Event {
                $event = $events->get($eventId);

                return $event instanceof Event ? $event : null;
            })
            ->filter()
            ->values();

        return new Paginator(
            items: $orderedEvents,
            total: $payload['total'],
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
            return $this->postgresDiscovery()->nearby($criteria);
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->typesenseDiscovery()->nearby($criteria);
            } catch (\Exception $e) {
                Log::warning('Typesense geo search failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->postgresDiscovery()->nearby($criteria);
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
            return $this->postgresDiscovery()->nearbyWithQuery($criteria);
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->typesenseDiscovery()->nearbyWithQuery($criteria);
            } catch (\Exception $e) {
                Log::warning('Typesense geo query search failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->postgresDiscovery()->nearbyWithQuery($criteria);
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
