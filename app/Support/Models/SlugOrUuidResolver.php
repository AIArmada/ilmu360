<?php

declare(strict_types=1);

namespace App\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SlugOrUuidResolver
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $slugColumn, string $identifier): Builder
    {
        return $query->where(function (Builder $identifierQuery) use ($query, $slugColumn, $identifier): void {
            $identifierQuery->where($slugColumn, $identifier);

            if (Str::isUuid($identifier)) {
                $identifierQuery->orWhere($query->getModel()->getQualifiedKeyName(), $identifier);
            }
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    public function first(Builder $query, string $slugColumn, string $identifier): ?Model
    {
        return $this->apply($query, $slugColumn, $identifier)->first();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    public function firstOrFail(Builder $query, string $slugColumn, string $identifier): Model
    {
        return $this->apply($query, $slugColumn, $identifier)->firstOrFail();
    }
}
