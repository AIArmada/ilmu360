<?php

declare(strict_types=1);

namespace App\Support\PublicDiscovery;

use App\Contracts\PublicDiscoveryAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

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
        if ($orderedIds === []) {
            return $this->emptyPaginator($request, $perPage, $base);
        }

        $scopedIds = (clone $base)
            ->whereIn($idColumn, $orderedIds)
            ->pluck($idColumn)
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $scopedOrderedIds = collect($scopedIds)
            ->sortBy(static function (string $id) use ($orderedIds): int {
                $position = array_search($id, $orderedIds, true);

                return is_int($position) ? $position : PHP_INT_MAX;
            })
            ->values()
            ->all();

        if ($scopedOrderedIds === []) {
            return $this->emptyPaginator($request, $perPage, $base);
        }

        $orderCases = [];
        $orderBindings = [];

        foreach ($scopedOrderedIds as $position => $id) {
            $orderCases[] = 'when ? then '.$position;
            $orderBindings[] = $id;
        }

        $wrappedIdColumn = $base->getQuery()->getGrammar()->wrap($idColumn);
        $orderSql = 'case '.$wrappedIdColumn.' '.implode(' ', $orderCases).' else '.count($scopedOrderedIds).' end';

        return (clone $base)
            ->whereIn($idColumn, $scopedOrderedIds)
            ->orderByRaw($orderSql, $orderBindings)
            ->paginate($perPage, ['*'], 'page', max(1, $request->integer('page', 1)))
            ->appends($request->query());
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $base
     * @return LengthAwarePaginator<int, TModel>
     */
    private function emptyPaginator(Request $request, int $perPage, Builder $base): LengthAwarePaginator
    {
        return (clone $base)
            ->whereRaw('1 = 0')
            ->paginate($perPage, ['*'], 'page', max(1, $request->integer('page', 1)))
            ->appends($request->query());
    }
}
