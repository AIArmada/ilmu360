<?php

declare(strict_types=1);

namespace App\Support\Search\Adapters;

use App\Models\Institution;
use App\Platform\Search\ResourceSearchAdapter;
use App\Support\Search\InstitutionSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class InstitutionResourceSearchAdapter implements ResourceSearchAdapter
{
    public function __construct(
        private InstitutionSearchService $searchService,
    ) {}

    public function supports(Model $model): bool
    {
        return $model instanceof Institution;
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<string>
     */
    public function matchingIds(Builder $query, string $search): array
    {
        /** @var Builder<Institution> $institutionQuery */
        $institutionQuery = $query;

        return $this->searchService->scopedSearchIds($institutionQuery, $search);
    }
}
