<?php

declare(strict_types=1);

namespace App\Support\Search\Adapters;

use App\Models\Person;
use App\Platform\Search\ResourceSearchAdapter;
use App\Support\Search\PersonSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class PersonResourceSearchAdapter implements ResourceSearchAdapter
{
    public function __construct(
        private PersonSearchService $searchService,
    ) {}

    public function supports(Model $model): bool
    {
        return $model instanceof Person;
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<string>
     */
    public function matchingIds(Builder $query, string $search): array
    {
        /** @var Builder<Person> $personQuery */
        $personQuery = $query;

        return $this->searchService->scopedSearchIds($personQuery, $search);
    }
}
