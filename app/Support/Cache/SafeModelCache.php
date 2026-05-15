<?php

namespace App\Support\Cache;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SafeModelCache
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return EloquentCollection<int, TModel>
     */
    public function rememberCollection(string $key, int $ttl, Builder $query): EloquentCollection
    {
        /** @var class-string<TModel> $modelClass */
        $modelClass = $query->getModel()::class;

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember($key, $ttl, function () use ($query): array {
            /** @var list<array<string, mixed>> $resolvedRows */
            $resolvedRows = $query
                ->get()
                ->map(static fn (Model $model): array => $model->getAttributes())
                ->values()
                ->all();

            return $resolvedRows;
        });

        /** @var EloquentCollection<int, TModel> $collection */
        $collection = $modelClass::hydrate($rows);

        return $collection;
    }

    /**
     * @return list<scalar>
     */
    public function rememberScalarList(string $key, int $ttl, Closure $resolver): array
    {
        /** @var list<scalar> $values */
        $values = Cache::remember($key, $ttl, fn (): array => array_values($resolver()));

        return $values;
    }

    /**
     * @template TPayload
     *
     * @param  Closure(): TPayload  $resolver
     * @return TPayload
     */
    public function rememberPayload(string $key, int $ttl, Closure $resolver): mixed
    {
        return Cache::remember($key, $ttl, $resolver);
    }
}
