<?php

declare(strict_types=1);

namespace App\Support\Search\Adapters;

use App\Models\Reference;
use App\Platform\Search\ResourceSearchAdapter;
use App\Support\Search\ReferenceSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class ReferenceResourceSearchAdapter implements ResourceSearchAdapter
{
    public function __construct(
        private ReferenceSearchService $searchService,
    ) {}

    public function supports(Model $model): bool
    {
        return $model instanceof Reference;
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<string>
     */
    public function matchingIds(Builder $query, string $search): array
    {
        /** @var Builder<Reference> $referenceQuery */
        $referenceQuery = $query;

        return $this->searchService->scopedSearchIds($referenceQuery, $search);
    }
}
