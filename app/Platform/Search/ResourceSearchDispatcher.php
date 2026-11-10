<?php

declare(strict_types=1);

namespace App\Platform\Search;

use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class ResourceSearchDispatcher
{
    /** @var list<ResourceSearchAdapter> */
    private array $adapters;

    /**
     * @param  iterable<ResourceSearchAdapter>  $adapters
     */
    public function __construct(
        #[Tag(ResourceSearchAdapter::TAG)]
        iterable $adapters,
    ) {
        $this->adapters = is_array($adapters)
            ? array_values($adapters)
            : array_values(iterator_to_array($adapters, false));
    }

    /**
     * Apply the first supporting adapter's matching ids to the query.
     *
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, string $search): bool
    {
        $model = $query->getModel();

        foreach ($this->adapters as $adapter) {
            if (! $adapter->supports($model)) {
                continue;
            }

            return $this->applyMatchingIds($query, $adapter->matchingIds($query, $search));
        }

        return false;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $matchingIds
     */
    private function applyMatchingIds(Builder $query, array $matchingIds): bool
    {
        if ($matchingIds === []) {
            $query->whereRaw('1 = 0');

            return true;
        }

        $model = $query->getModel();

        $query->whereIn($model->qualifyColumn($model->getKeyName()), $matchingIds);

        return true;
    }
}
