<?php

declare(strict_types=1);

namespace App\Features\References\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scoped reference search contract implemented by the References search
 * service and consumed by its resource search adapter.
 *
 * @template TModel of Model
 */
interface ReferenceScopedSearch
{
    /**
     * @param  Builder<TModel>  $query
     * @return list<string>
     */
    public function scopedSearchIds(Builder $query, string $search): array;
}
