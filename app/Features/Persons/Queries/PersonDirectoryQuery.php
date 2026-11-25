<?php

declare(strict_types=1);

namespace App\Features\Persons\Queries;

use AIArmada\Persons\Enums\AssignmentStatus;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Services\PersonSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final readonly class PersonDirectoryQuery
{
    private const int MIN_SEARCH_LENGTH = 3;

    public function __construct(
        private PersonSearchService $searchService,
        private EventScheduleReads $scheduleReads,
    ) {}

    /**
     * @param  list<string>  $followingPersonIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @param  array<string, mixed>  $requestQuery
     * @return LengthAwarePaginator<int, Person>
     */
    public function paginate(
        ?string $search,
        ?string $sort,
        ?string $titleId,
        ?string $languageId,
        ?string $stateId,
        bool $upcomingOnly,
        bool $followedOnly,
        array $followingPersonIds,
        int $page,
        array $paginationMeta,
        array $requestQuery = [],
        string $requestUrl = '',
        int $perPage = 12,
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $trimmedSearch = is_string($search) ? trim($search) : '';
        $normalizedSearch = $trimmedSearch === '' ? null : $trimmedSearch;

        if ($normalizedSearch === null) {
            return $this->attachUpcomingEventCountsToPaginator(
                $this->paginateWithWindowTotal(
                    $this->applySort(
                        $this->basePersonsQuery($titleId, $languageId, $stateId, $upcomingOnly, $followedOnly, $followingPersonIds),
                        $sort,
                    ),
                    $page,
                    $paginationMeta,
                    $perPage,
                ),
            );
        }

        if (mb_strlen($normalizedSearch) < self::MIN_SEARCH_LENGTH) {
            return $this->emptyPaginator($page, $paginationMeta, $perPage);
        }

        $directMatches = $this->directSearch(
            $normalizedSearch,
            $sort,
            $titleId,
            $languageId,
            $stateId,
            $upcomingOnly,
            $followedOnly,
            $followingPersonIds,
            $page,
            $paginationMeta,
            $requestQuery,
            $requestUrl,
            $perPage,
        );

        if ($directMatches->total() > 0) {
            return $directMatches;
        }

        return $this->fuzzySearch(
            $normalizedSearch,
            $sort,
            $titleId,
            $languageId,
            $stateId,
            $upcomingOnly,
            $followedOnly,
            $followingPersonIds,
            $page,
            $paginationMeta,
            $requestQuery,
            $requestUrl,
            $perPage,
        );
    }

    /**
     * @param  list<string>  $followingPersonIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @param  array<string, mixed>  $requestQuery
     * @return LengthAwarePaginator<int, Person>
     */
    private function directSearch(
        string $search,
        ?string $sort,
        ?string $titleId,
        ?string $languageId,
        ?string $stateId,
        bool $upcomingOnly,
        bool $followedOnly,
        array $followingPersonIds,
        int $page,
        array $paginationMeta,
        array $requestQuery,
        string $requestUrl,
        int $perPage,
    ): LengthAwarePaginator {
        $matchingIds = $this->searchService->publicSearchIds($search);

        if ($matchingIds === []) {
            return $this->emptyPaginator($page, $paginationMeta, $perPage);
        }

        if ($sort === 'name') {
            return $this->attachUpcomingEventCountsToPaginator(
                $this->paginateWithWindowTotal(
                    $this->applySort(
                        $this->basePersonsQuery($titleId, $languageId, $stateId, $upcomingOnly, $followedOnly, $followingPersonIds)
                            ->whereIn('persons.id', $matchingIds),
                        $sort,
                    ),
                    $page,
                    ['path' => $requestUrl !== '' ? $requestUrl : $paginationMeta['path'], 'query' => $requestQuery],
                    $perPage,
                    appendQuery: false,
                ),
            );
        }

        return $this->orderedIdPaginator(
            $matchingIds,
            $sort,
            $titleId,
            $languageId,
            $stateId,
            $upcomingOnly,
            $followedOnly,
            $followingPersonIds,
            $page,
            $paginationMeta,
            $requestQuery,
            $requestUrl,
            $perPage,
        );
    }

    /**
     * @param  list<string>  $followingPersonIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @param  array<string, mixed>  $requestQuery
     * @return LengthAwarePaginator<int, Person>
     */
    private function fuzzySearch(
        string $search,
        ?string $sort,
        ?string $titleId,
        ?string $languageId,
        ?string $stateId,
        bool $upcomingOnly,
        bool $followedOnly,
        array $followingPersonIds,
        int $page,
        array $paginationMeta,
        array $requestQuery,
        string $requestUrl,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->orderedIdPaginator(
            $this->searchService->publicFuzzySearchIds($search),
            $sort,
            $titleId,
            $languageId,
            $stateId,
            $upcomingOnly,
            $followedOnly,
            $followingPersonIds,
            $page,
            $paginationMeta,
            $requestQuery,
            $requestUrl,
            $perPage,
        );
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  list<string>  $followingPersonIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @param  array<string, mixed>  $requestQuery
     * @return LengthAwarePaginator<int, Person>
     */
    private function orderedIdPaginator(
        array $orderedIds,
        ?string $sort,
        ?string $titleId,
        ?string $languageId,
        ?string $stateId,
        bool $upcomingOnly,
        bool $followedOnly,
        array $followingPersonIds,
        int $page,
        array $paginationMeta,
        array $requestQuery,
        string $requestUrl,
        int $perPage,
    ): LengthAwarePaginator {
        $orderedIds = $this->scopeOrderedIds(
            $orderedIds,
            $titleId,
            $languageId,
            $stateId,
            $upcomingOnly,
            $followedOnly,
            $followingPersonIds,
        );

        if ($orderedIds === []) {
            return $this->emptyPaginator($page, $paginationMeta, $perPage);
        }

        if ($sort === 'name') {
            return $this->attachUpcomingEventCountsToPaginator(
                $this->paginateWithWindowTotal(
                    $this->applySort(
                        $this->basePersonsQuery($titleId, $languageId, $stateId, $upcomingOnly, $followedOnly, $followingPersonIds)
                            ->whereIn('persons.id', $orderedIds),
                        $sort,
                    ),
                    $page,
                    ['path' => $requestUrl !== '' ? $requestUrl : $paginationMeta['path'], 'query' => $requestQuery],
                    $perPage,
                    appendQuery: false,
                ),
            );
        }

        $paginatedIds = array_slice($orderedIds, ($page - 1) * $perPage, $perPage);

        if ($paginatedIds === []) {
            return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $page, $paginationMeta);
        }

        $positions = array_flip($paginatedIds);

        $persons = $this->basePersonsQuery($titleId, $languageId, $stateId, $upcomingOnly, $followedOnly, $followingPersonIds)
            ->whereIn('persons.id', $paginatedIds)
            ->get()
            ->sortBy(static fn (Person $person): int => $positions[(string) $person->id] ?? PHP_INT_MAX)
            ->values();

        $this->attachUpcomingEventCountsToPersons($persons);

        return new LengthAwarePaginator($persons, count($orderedIds), $perPage, $page, $paginationMeta);
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  list<string>  $followingPersonIds
     * @return list<string>
     */
    private function scopeOrderedIds(
        array $orderedIds,
        ?string $titleId,
        ?string $languageId,
        ?string $stateId,
        bool $upcomingOnly,
        bool $followedOnly,
        array $followingPersonIds,
    ): array {
        if ($orderedIds === []) {
            return [];
        }

        $positions = array_flip($orderedIds);

        $scopedIds = $this->applyDirectoryFilters(
            Person::query()
                ->speakers()
                ->whereIn('status', ['verified', 'pending']),
            $titleId,
            $languageId,
            $stateId,
            $upcomingOnly,
            $followedOnly,
            $followingPersonIds,
        )
            ->whereIn('persons.id', $orderedIds)
            ->pluck('persons.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return collect($scopedIds)
            ->sortBy(static fn (string $id): int => $positions[$id] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    private function applySort(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'name' => $query->orderBy('persons.name'),
            default => $query->publicDirectoryOrder(),
        };
    }

    /**
     * @param  list<string>  $followingPersonIds
     * @return Builder<Person>
     */
    private function basePersonsQuery(
        ?string $titleId,
        ?string $languageId,
        ?string $stateId,
        bool $upcomingOnly,
        bool $followedOnly,
        array $followingPersonIds,
    ): Builder {
        return $this->applyDirectoryFilters(
            Person::query()
                ->speakers()
                ->whereIn('status', ['verified', 'pending']),
            $titleId,
            $languageId,
            $stateId,
            $upcomingOnly,
            $followedOnly,
            $followingPersonIds,
        )->with([
            'media' => static function (MorphMany $relation): void {
                $relation->where('collection_name', 'profile');
            },
            'addresses.state',
            // Matches the lazy path in formatted_name (active assignments
            // with title.category) so cards never query per person.
            'titleAssignments' => static function (MorphMany $relation): void {
                $relation
                    ->where('status', AssignmentStatus::Active)
                    ->with('title.category');
            },
        ]);
    }

    /**
     * @param  Builder<Person>  $query
     * @param  list<string>  $followingPersonIds
     * @return Builder<Person>
     */
    private function applyDirectoryFilters(
        Builder $query,
        ?string $titleId,
        ?string $languageId,
        ?string $stateId,
        bool $upcomingOnly,
        bool $followedOnly,
        array $followingPersonIds,
    ): Builder {
        return $query
            ->when($followedOnly, static function (Builder $query) use ($followingPersonIds): void {
                $query->whereIn('persons.id', $followingPersonIds);
            })
            ->when($titleId !== null, static function (Builder $query) use ($titleId): void {
                $query->whereHas('titleAssignments', static function (Builder $titleQuery) use ($titleId): void {
                    $titleQuery
                        ->where('title_id', $titleId)
                        ->where('status', AssignmentStatus::Active);
                });
            })
            ->when($languageId !== null, static function (Builder $query) use ($languageId): void {
                $query->whereHas('languages', static function (Builder $languageQuery) use ($languageId): void {
                    $languageQuery->where('languages.id', $languageId);
                });
            })
            ->when($stateId !== null, static function (Builder $query) use ($stateId): void {
                $query->whereHas('addresses', static function (Builder $addressQuery) use ($stateId): void {
                    $addressQuery->where('state_id', $stateId);
                });
            })
            ->when($upcomingOnly, function (Builder $query): void {
                $query->whereIn('persons.id', $this->scheduleReads->upcomingSpeakerPersonIds());
            });
    }

    /**
     * @param  Builder<Person>  $query
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Person>
     */
    private function paginateWithWindowTotal(
        Builder $query,
        int $page,
        array $paginationMeta,
        int $perPage,
        bool $appendQuery = true,
    ): LengthAwarePaginator {
        $persons = (clone $query)
            ->select('persons.*')
            ->selectRaw('count(*) over () as matching_total')
            ->forPage($page, $perPage)
            ->get();

        $firstPerson = $persons->first();
        $total = $firstPerson instanceof Person
            ? (int) $firstPerson->getAttribute('matching_total')
            : 0;

        // A page beyond the end has no row from which to read the window
        // total, so fall back to a scoped count without changing the normal
        // request path.
        if ($persons->isEmpty() && $page > 1) {
            $total = (clone $query)->reorder()->count();
        }

        $persons->each(static fn (Person $person): Person => $person->makeHidden('matching_total'));

        $paginator = new LengthAwarePaginator(
            $persons,
            $total,
            $perPage,
            $page,
            $paginationMeta,
        );

        return $appendQuery ? $paginator->appends($paginationMeta['query']) : $paginator;
    }

    /**
     * @param  LengthAwarePaginator<int, Person>  $paginator
     * @return LengthAwarePaginator<int, Person>
     */
    private function attachUpcomingEventCountsToPaginator(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $this->attachUpcomingEventCountsToPersons($paginator->getCollection());

        return $paginator;
    }

    /**
     * @param  Collection<int, Person>  $persons
     */
    private function attachUpcomingEventCountsToPersons(Collection $persons): void
    {
        $personIds = $persons
            ->map(static fn (Person $person): string => (string) $person->getKey())
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        if ($personIds === []) {
            return;
        }

        $facts = $this->scheduleReads->personDirectoryFacts($personIds);

        foreach ($persons as $person) {
            $fact = $facts[(string) $person->getKey()] ?? [
                'events_count' => 0,
                'next_event_slug' => null,
                'next_event_title' => null,
                'next_event_starts_at' => null,
            ];

            $person->setAttribute('events_count', (int) $fact['events_count']);
            $person->setAttribute('next_event_slug', $fact['next_event_slug']);
            $person->setAttribute('next_event_title', $fact['next_event_title']);
            $person->setAttribute(
                'next_event_starts_at',
                is_string($fact['next_event_starts_at']) && $fact['next_event_starts_at'] !== ''
                    ? CarbonImmutable::parse($fact['next_event_starts_at'], 'UTC')
                    : null,
            );
        }
    }

    /**
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Person>
     */
    private function emptyPaginator(int $page, array $paginationMeta, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(collect(), 0, $perPage, max(1, $page), $paginationMeta);
    }
}
