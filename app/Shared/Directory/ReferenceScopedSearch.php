<?php

declare(strict_types=1);

namespace App\Shared\Directory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scoped reference search used by the Directory search adapter.
 *
 * Keeps the resource-specific search implementation behind a shared contract
 * so the Directory feature never imports the root search service.
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
