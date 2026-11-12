<?php

declare(strict_types=1);

namespace App\Features\Directory\Queries;

use App\Features\Directory\Support\DirectoryModel;
use App\Platform\Search\ResourceSearchAdapter;
use App\Shared\Directory\ReferenceScopedSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class ReferenceResourceSearchAdapter implements ResourceSearchAdapter
{
    /**
     * @param  ReferenceScopedSearch<Model>  $searchService
     */
    public function __construct(
        private ReferenceScopedSearch $searchService,
    ) {}

    public function supports(Model $model): bool
    {
        $referenceClass = DirectoryModel::reference();

        return $model instanceof $referenceClass;
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<string>
     */
    public function matchingIds(Builder $query, string $search): array
    {
        return $this->searchService->scopedSearchIds($query, $search);
    }
}
