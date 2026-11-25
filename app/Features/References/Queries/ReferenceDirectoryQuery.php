<?php

declare(strict_types=1);

namespace App\Features\References\Queries;

use AIArmada\Persons\Enums\AssignmentStatus;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Models\Reference;
use App\Features\References\Services\ReferenceSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class ReferenceDirectoryQuery
{
    private const int MIN_SEARCH_LENGTH = 3;

    public function __construct(
        private ReferenceSearchService $searchService,
        private ReferenceQueries $referenceQueries,
        private EventScheduleReads $scheduleReads,
    ) {}

    /**
     * @param  list<string>  $followingReferenceIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Reference>
     */
    public function paginate(
        ?string $search,
        bool $followedOnly,
        array $followingReferenceIds,
        int $page,
        array $paginationMeta,
        int $perPage = 12,
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $trimmedSearch = is_string($search) ? trim($search) : '';
        $normalizedSearch = $trimmedSearch === '' ? null : $trimmedSearch;

        if ($normalizedSearch === null) {
            $query = $this->baseReferencesQuery(
                followedOnly: $followedOnly,
                followingReferenceIds: $followingReferenceIds,
            )->orderBy('references.title');

            $paginator = $this->paginateWithWindowTotal($query, $page, $paginationMeta, $perPage);
        } elseif (mb_strlen($normalizedSearch) < self::MIN_SEARCH_LENGTH) {
            return $this->emptyPaginator($page, $paginationMeta, $perPage);
        } else {
            $directMatches = $this->directSearch(
                $normalizedSearch,
                $followedOnly,
                $followingReferenceIds,
                $page,
                $paginationMeta,
                $perPage,
            );

            $paginator = $directMatches->total() > 0
                ? $directMatches
                : $this->fuzzySearch(
                    $normalizedSearch,
                    $followedOnly,
                    $followingReferenceIds,
                    $page,
                    $paginationMeta,
                    $perPage,
                );
        }

        $this->attachNextPublicEvents($paginator->items());

        return $paginator;
    }

    /**
     * @param  list<string>  $followingReferenceIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Reference>
     */
    private function directSearch(
        string $search,
        bool $followedOnly,
        array $followingReferenceIds,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $matchingIds = $this->filterSearchIdsToCurrentScope(
            $this->searchService->publicSearchIds($search),
            $followedOnly,
            $followingReferenceIds,
        );

        if ($matchingIds === []) {
            return $this->emptyPaginator($page, $paginationMeta, $perPage);
        }

        return $this->orderedIdPaginator(
            $matchingIds,
            $followedOnly,
            $followingReferenceIds,
            $page,
            $paginationMeta,
            $perPage,
        );
    }

    /**
     * @param  list<string>  $followingReferenceIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Reference>
     */
    private function fuzzySearch(
        string $search,
        bool $followedOnly,
        array $followingReferenceIds,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $orderedIds = $this->filterSearchIdsToCurrentScope(
            $this->searchService->publicFuzzySearchIds($search),
            $followedOnly,
            $followingReferenceIds,
        );

        if ($orderedIds === []) {
            return $this->emptyPaginator($page, $paginationMeta, $perPage);
        }

        return $this->orderedIdPaginator(
            $orderedIds,
            $followedOnly,
            $followingReferenceIds,
            $page,
            $paginationMeta,
            $perPage,
        );
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  list<string>  $followingReferenceIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Reference>
     */
    private function orderedIdPaginator(
        array $orderedIds,
        bool $followedOnly,
        array $followingReferenceIds,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $paginatedIds = array_slice($orderedIds, ($page - 1) * $perPage, $perPage);

        if ($paginatedIds === []) {
            return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $page, $paginationMeta);
        }

        $positions = array_flip($paginatedIds);

        $references = $this->baseReferencesQuery(
            includeParts: true,
            followedOnly: $followedOnly,
            followingReferenceIds: $followingReferenceIds,
        )
            ->whereIn('references.id', $paginatedIds)
            ->get()
            ->sortBy(static fn (Reference $reference): int => $positions[(string) $reference->id] ?? PHP_INT_MAX)
            ->values();

        return new LengthAwarePaginator($references, count($orderedIds), $perPage, $page, $paginationMeta);
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  list<string>  $followingReferenceIds
     * @return list<string>
     */
    private function filterSearchIdsToCurrentScope(
        array $orderedIds,
        bool $followedOnly,
        array $followingReferenceIds,
    ): array {
        if ($orderedIds === []) {
            return [];
        }

        $positions = array_flip($orderedIds);

        $scopedIds = $this->applyFollowedOnlyFilter(
            Reference::query()->active(),
            $followedOnly,
            $followingReferenceIds,
        )
            ->whereIn('references.id', $orderedIds)
            ->pluck('references.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return collect($scopedIds)
            ->sortBy(static fn (string $id): int => $positions[$id] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Reference>  $query
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Reference>
     */
    private function paginateWithWindowTotal(
        Builder $query,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $items = (clone $query)
            ->selectRaw('count(*) over () as matching_total')
            ->forPage($page, $perPage)
            ->get();

        $firstItem = $items->first();
        $total = $firstItem instanceof Reference
            ? (int) $firstItem->getAttribute('matching_total')
            : 0;

        if ($items->isEmpty() && $page > 1) {
            $total = (clone $query)->reorder()->count();
        }

        $items->each(static fn (Reference $reference): Reference => $reference->makeHidden('matching_total'));

        return new LengthAwarePaginator($items, $total, $perPage, $page, $paginationMeta);
    }

    /**
     * @param  list<string>  $followingReferenceIds
     * @return Builder<Reference>
     */
    private function baseReferencesQuery(
        bool $includeParts = false,
        bool $followedOnly = false,
        array $followingReferenceIds = [],
    ): Builder {
        $query = $this->applyFollowedOnlyFilter(
            Reference::query()->active(),
            $followedOnly,
            $followingReferenceIds,
        )
            ->select('references.*')
            // Cards only read front/back covers, so skip the gallery
            // collection instead of loading every media row per page.
            ->with([
                'authors.titleAssignments' => static function (MorphMany $relation): void {
                    $relation
                        ->where('status', AssignmentStatus::Active->value)
                        ->with('title.category');
                },
                'media' => static function (MorphMany $relation): void {
                    $relation->whereIn('collection_name', ['front_cover', 'back_cover']);
                },
            ]);

        if ($includeParts) {
            $query->with([
                'parentReference.parentReference',
                'parentReference.authors.titleAssignments' => static function (MorphMany $relation): void {
                    $relation
                        ->where('status', AssignmentStatus::Active->value)
                        ->with('title.category');
                },
                'parentReference.parentReference.authors.titleAssignments' => static function (MorphMany $relation): void {
                    $relation
                        ->where('status', AssignmentStatus::Active->value)
                        ->with('title.category');
                },
            ]);
        } else {
            $query->root();
        }

        return $query;
    }

    /**
     * @param  Builder<Reference>  $query
     * @param  list<string>  $followingReferenceIds
     * @return Builder<Reference>
     */
    private function applyFollowedOnlyFilter(
        Builder $query,
        bool $followedOnly,
        array $followingReferenceIds,
    ): Builder {
        if (! $followedOnly) {
            return $query;
        }

        return $query->whereIn('references.id', $followingReferenceIds);
    }

    /**
     * @param  array<int, Reference>  $references
     */
    private function attachNextPublicEvents(array $references): void
    {
        if ($references === []) {
            return;
        }

        $referenceIds = array_values(array_map(
            static fn (Reference $reference): string => (string) $reference->getKey(),
            $references,
        ));

        $families = $this->referenceQueries->defaultEventReferenceIdsForMany($referenceIds);
        $facts = $this->scheduleReads->referenceDirectoryFacts($families);

        foreach ($references as $reference) {
            $fact = $facts[(string) $reference->getKey()] ?? null;

            $reference->setAttribute('upcoming_events_count', (int) ($fact['upcoming_events_count'] ?? 0));
            $reference->setAttribute('next_event_slug', $fact['next_event_slug'] ?? null);
            $reference->setAttribute('next_event_title', $fact['next_event_title'] ?? null);
            $reference->setAttribute('next_event_starts_at', $fact['next_event_starts_at'] ?? null);
        }
    }

    /**
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Reference>
     */
    private function emptyPaginator(int $page, array $paginationMeta, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(collect(), 0, $perPage, max(1, $page), $paginationMeta);
    }
}
