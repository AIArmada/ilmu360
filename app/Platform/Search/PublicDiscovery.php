<?php

declare(strict_types=1);

namespace App\Platform\Search;

use App\Shared\Discovery\PublicDiscoveryAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

final class PublicDiscovery
{
    /**
     * Resolve public directory search with the same direct-to-fuzzy escalation
     * and URL-preserving pagination for every entity adapter.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $base
     * @param  Builder<TModel>  $directBase
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(
        Request $request,
        string $search,
        int $perPage,
        Builder $base,
        Builder $directBase,
        PublicDiscoveryAdapter $adapter,
        string $idColumn,
        bool $preserveDirectEngineOrder = false,
    ): LengthAwarePaginator {
        $directIds = $adapter->publicSearchIds($search);

        if ($directIds !== []) {
            $directQuery = (clone $directBase)->whereIn($idColumn, $directIds);

            $directMatches = $preserveDirectEngineOrder
                ? $this->orderedPaginator($request, $perPage, $directQuery, $directIds, $idColumn)
                : $directQuery->paginate($perPage)->appends($request->query());

            if ($directMatches->total() > 0 || mb_strlen($search) < 3) {
                return $directMatches;
            }
        } elseif (mb_strlen($search) < 3) {
            return $this->emptyPaginator($request, $perPage, $base);
        }

        return $this->orderedPaginator(
            $request,
            $perPage,
            $base,
            $adapter->publicFuzzySearchIds($search),
            $idColumn,
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $base
     * @param  list<string>  $orderedIds
     * @return LengthAwarePaginator<int, TModel>
     */
    private function orderedPaginator(
        Request $request,
        int $perPage,
        Builder $base,
        array $orderedIds,
        string $idColumn,
    ): LengthAwarePaginator {
        $orderedIds = array_values(array_unique($orderedIds));

        if ($orderedIds === []) {
            return $this->emptyPaginator($request, $perPage, $base);
        }

        $positions = array_flip($orderedIds);

        $scopedIds = (clone $base)
            ->reorder()
            ->whereIn($idColumn, $orderedIds)
            ->pluck($idColumn)
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($scopedIds === []) {
            return $this->emptyPaginator($request, $perPage, $base);
        }

        usort(
            $scopedIds,
            static fn (string $left, string $right): int => ($positions[$left] ?? PHP_INT_MAX) <=> ($positions[$right] ?? PHP_INT_MAX),
        );

        $page = max(1, $request->integer('page', 1));
        $total = count($scopedIds);
        $pageIds = array_slice($scopedIds, ($page - 1) * $perPage, $perPage);

        if ($pageIds === []) {
            return $this->buildPaginator(
                $request,
                $base->getModel()->newCollection(),
                $total,
                $perPage,
                $page,
            );
        }

        $pagePositions = array_flip($pageIds);

        $items = (clone $base)
            ->reorder()
            ->whereIn($idColumn, $pageIds)
            ->get()
            ->sortBy(static fn (Model $model): int => $pagePositions[(string) $model->getKey()] ?? PHP_INT_MAX)
            ->values();

        return $this->buildPaginator($request, $items, $total, $perPage, $page);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $base
     * @return LengthAwarePaginator<int, TModel>
     */
    private function emptyPaginator(Request $request, int $perPage, Builder $base): LengthAwarePaginator
    {
        return $this->buildPaginator(
            $request,
            $base->getModel()->newCollection(),
            0,
            $perPage,
            max(1, $request->integer('page', 1)),
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  EloquentCollection<int, TModel>  $items
     * @return LengthAwarePaginator<int, TModel>
     */
    private function buildPaginator(
        Request $request,
        EloquentCollection $items,
        int $total,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        return (new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        ))->appends($request->query());
    }
}
