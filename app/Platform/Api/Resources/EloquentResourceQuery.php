<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use App\Platform\Api\Admin\AdminResourceHookRegistry;
use App\Platform\Api\Admin\Contracts\AdminResourceHooks;
use App\Platform\Search\ResourceSearchDispatcher;
use Carbon\CarbonInterface;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic Eloquent read mechanics for a Filament resource definition.
 */
final readonly class EloquentResourceQuery
{
    public function __construct(
        private ResourceSearchDispatcher $resourceSearchDispatcher,
        private ResourceIntrospection $introspection,
        private AdminResourceHookRegistry $hooks,
    ) {}

    /**
     * @param  class-string<resource>  $resourceClass
     * @return Builder<Model>
     */
    public function forResource(string $resourceClass): Builder
    {
        /** @var Builder<Model> $query */
        $query = $resourceClass::getEloquentQuery();

        $relations = $this->introspection->apiRelations($query->getModel());

        if ($relations !== []) {
            $query->with($relations);
        }

        return $query;
    }

    /** @param class-string<resource> $resourceClass */
    public function resolveRecord(string $resourceClass, string $recordKey): Model
    {
        $model = $this->forResource($resourceClass)->getModel();

        $routeBoundRecord = $model
            ->resolveRouteBindingQuery($this->forResource($resourceClass), $recordKey)
            ->first();

        if ($routeBoundRecord instanceof Model) {
            return $routeBoundRecord;
        }

        abort(404);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<resource>  $resourceClass
     */
    public function applySearch(Builder $query, string $resourceClass, string $search): void
    {
        if ($this->resourceSearchDispatcher->apply($query, $search)) {
            return;
        }

        $columns = $this->introspection->searchableColumns($resourceClass);

        if ($columns === []) {
            return;
        }

        $model = $query->getModel();

        $query->where(function (Builder $builder) use ($columns, $search, $model): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($model->qualifyColumn($column), 'like', "%{$search}%");
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, string $resourceClass, array $filters): void
    {
        if ($filters === []) {
            return;
        }

        $hooks = $this->hooks->for($resourceClass);

        if ($hooks instanceof AdminResourceHooks && $hooks->applyFilters($query, $resourceClass, $filters)) {
            return;
        }
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>|null  $dateSemantics
     */
    public function applyDateFilters(
        Builder $query,
        ?array $dateSemantics,
        ?string $startsAfter,
        ?string $startsBefore,
        ?string $startsOnLocalDate,
    ): void {
        if (! is_array($dateSemantics)) {
            return;
        }

        $model = $query->getModel();
        $startsAtColumn = $model->qualifyColumn('starts_at');

        if (is_string($startsAfter) && trim($startsAfter) !== '') {
            $parsedStartsAfter = UserDateTimeFormatter::parseUserDateToUtc($startsAfter, false);

            if ($parsedStartsAfter instanceof CarbonInterface) {
                $query->where($startsAtColumn, '>=', $parsedStartsAfter);
            }
        }

        if (is_string($startsBefore) && trim($startsBefore) !== '') {
            $parsedStartsBefore = UserDateTimeFormatter::parseUserDateToUtc($startsBefore, true);

            if ($parsedStartsBefore instanceof CarbonInterface) {
                $query->where($startsAtColumn, '<=', $parsedStartsBefore);
            }
        }

        if (is_string($startsOnLocalDate) && trim($startsOnLocalDate) !== '') {
            $startsOnLocalDateStart = UserDateTimeFormatter::parseUserDateToUtc($startsOnLocalDate, false);
            $startsOnLocalDateEnd = UserDateTimeFormatter::parseUserDateToUtc($startsOnLocalDate, true);

            if ($startsOnLocalDateStart instanceof CarbonInterface && $startsOnLocalDateEnd instanceof CarbonInterface) {
                $query->whereBetween($startsAtColumn, [$startsOnLocalDateStart, $startsOnLocalDateEnd]);
            }
        }
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<resource>  $resourceClass
     */
    public function applyDefaultOrdering(Builder $query, string $resourceClass): void
    {
        ResourceReadSupport::applyDefaultOrdering($query, $resourceClass);
    }

    /** @param Builder<Model> $query */
    public function applyDefaultOrderingForModel(Builder $query, Model $model): void
    {
        ResourceReadSupport::applyDefaultOrderingForModel($query, $model);
    }

    /** @param Builder<Model> $query */
    public function applyGenericSearch(Builder $query, Model $model, string $search): void
    {
        ResourceReadSupport::applyGenericSearch($query, $model, $search, [
            'title',
            'name',
            'label',
            'slug',
            'email',
            'description',
            $model->getRouteKeyName(),
        ]);
    }
}
