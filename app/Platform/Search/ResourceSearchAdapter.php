<?php

declare(strict_types=1);

namespace App\Platform\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tagged contributor that owns resource-specific search matching for a
 * platform query. Adapters are applied in registration order.
 */
interface ResourceSearchAdapter
{
    public const string TAG = 'platform.search.resource_search_adapters';

    public function supports(Model $model): bool;

    /**
     * @param  Builder<Model>  $query
     * @return list<string>
     */
    public function matchingIds(Builder $query, string $search): array;
}
