<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use App\Platform\Api\ApiPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

/**
 * Read helpers shared by the admin and member resource services.
 *
 * Surface-specific authorization, metadata composition and manifest shapes stay
 * in the owning service; only the reader mechanics live here.
 */
final class ResourceReadSupport
{
    /**
     * @param  Builder<Model>  $query
     */
    public static function applyDefaultOrderingForModel(Builder $query, Model $model): void
    {
        $table = $model->getTable();
        $candidates = [
            'family_name',
            'middle_name',
            'name',
            'title',
            'label',
            'slug',
        ];

        foreach ($candidates as $candidate) {
            if (! Schema::hasColumn($table, $candidate)) {
                continue;
            }

            $query->orderBy($model->qualifyColumn($candidate));

            return;
        }

        if (Schema::hasColumn($table, 'created_at')) {
            $query->orderBy($model->qualifyColumn('created_at'), 'desc');

            return;
        }

        $query->orderBy($model->qualifyColumn($model->getKeyName()));
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<\Filament\Resources\Resource>  $resourceClass
     */
    public static function applyDefaultOrdering(Builder $query, string $resourceClass): void
    {
        $model = $query->getModel();
        $table = $model->getTable();
        $recordTitleAttribute = $resourceClass::getRecordTitleAttribute();

        if (is_string($recordTitleAttribute) && $recordTitleAttribute !== '' && Schema::hasColumn($table, $recordTitleAttribute)) {
            $sortColumn = $recordTitleAttribute;
        } elseif (Schema::hasColumn($table, 'created_at')) {
            $sortColumn = 'created_at';
        } else {
            $sortColumn = $model->getKeyName();
        }

        $query->orderBy($model->qualifyColumn($sortColumn), $sortColumn === 'created_at' ? 'desc' : 'asc');
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $candidates
     */
    public static function applyGenericSearch(Builder $query, Model $model, string $search, array $candidates): void
    {
        $table = $model->getTable();
        $columns = array_values(array_filter(array_unique($candidates), static fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($columns === []) {
            return;
        }

        $query->where(function (Builder $builder) use ($columns, $search, $model): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($model->qualifyColumn($column), 'like', "%{$search}%");
            }
        });
    }

    /**
     * @param  array<string, mixed>  $resourceMeta
     * @param  LengthAwarePaginator<int, Model>  $records
     * @return array<string, mixed>
     */
    public static function recordsMeta(array $resourceMeta, LengthAwarePaginator $records, string $search): array
    {
        return [
            'resource' => $resourceMeta,
            'search' => $search !== '' ? $search : null,
            'pagination' => ApiPagination::paginationMeta($records),
        ];
    }

    public static function genericRecordTitle(Model $record): string
    {
        $table = $record->getTable();
        $candidates = [
            'title',
            'name',
            'middle_name',
            'family_name',
            'label',
            'slug',
            'email',
            $record->getRouteKeyName(),
        ];

        foreach (array_values(array_unique(array_filter($candidates, static fn (string $column): bool => $column !== ''))) as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $value = $record->getAttribute($column);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return (string) $record->getRouteKey();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{id: string, route_key: string, title: string, attributes: array<string, mixed>}
     */
    public static function serializeRelatedRecord(Model $record, array $attributes, ?string $title = null): array
    {
        return [
            'id' => (string) $record->getKey(),
            'route_key' => (string) $record->getRouteKey(),
            'title' => $title ?? self::genericRecordTitle($record),
            'attributes' => $attributes,
        ];
    }
}
