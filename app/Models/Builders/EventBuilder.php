<?php

namespace App\Models\Builders;

use App\Models\Event;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Database\Query\SortDirection;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Builder<Event>
 */
class EventBuilder extends Builder
{
    /**
     * @var list<string>
     */
    private const array OccurrenceBackedColumns = [
        'starts_at',
        'ends_at',
    ];

    /**
     * @var list<string>
     */
    private const array MetadataBackedColumns = [
        'user_id',
        'institution_id',
        'submitter_id',
        'schedule_kind',
        'schedule_state',
        'timing_mode',
        'views_count',
        'saves_count',
        'registrations_count',
        'going_count',
    ];

    /**
     * Product query field names → package columns (single store).
     *
     * @var array<string, string>
     */
    private const array PackageColumnAliases = [
        'event_format' => 'delivery_mode',
        'venue_id' => 'default_venue_id',
    ];

    #[\Override]
    public function where($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        if (! is_string($column) || $this->isJsonSelector($column) || ! $this->shouldMapColumn($column)) {
            return parent::where($column, $operator, $value, $boolean);
        }

        $columnName = $this->columnName($column);

        if (in_array($columnName, self::OccurrenceBackedColumns, true)) {
            return $this->whereOccurrenceColumn($columnName, $operator, $value, $boolean, func_num_args());
        }

        $mappedColumn = $this->mapColumn($columnName);

        if ($mappedColumn !== null) {
            if ($mappedColumn instanceof Expression) {
                $sql = $mappedColumn->getValue($this->getQuery()->getGrammar());

                if (func_num_args() === 2) {
                    if ($operator === null) {
                        $this->whereRaw($sql.' is null', [], $boolean);

                        return $this;
                    }

                    $this->whereRaw($sql.' = ?', [$operator], $boolean);

                    return $this;
                }

                if ($value === null) {
                    $this->whereRaw($sql.' is '.(in_array(strtolower((string) $operator), ['!=', '<>', 'is not'], true) ? 'not ' : '').'null', [], $boolean);

                    return $this;
                }

                $this->whereRaw($sql.' '.$operator.' ?', [$value], $boolean);

                return $this;
            }

            return parent::where($mappedColumn, $operator, $value, $boolean);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * @param  Expression|string  $column
     * @param  iterable<mixed>  $values
     * @param  string  $boolean
     * @param  bool  $not
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false): static
    {
        if (! is_string($column) || $this->isJsonSelector($column) || ! $this->shouldMapColumn($column)) {
            parent::whereIn($column, $values, $boolean, $not);

            return $this;
        }

        $columnName = $this->columnName($column);

        $mappedColumn = $this->mapColumn($columnName);

        if ($mappedColumn !== null) {
            if ($mappedColumn instanceof Expression) {
                $sql = $mappedColumn->getValue($this->getQuery()->getGrammar());

                if ($values instanceof Relation) {
                    $subquery = $values->getQuery()->toBase();
                    $this->whereRaw(
                        $sql.' '.($not ? 'not ' : '').'in ('.$subquery->toSql().')',
                        $subquery->getBindings(),
                        $boolean,
                    );

                    return $this;
                }

                $values = array_values(
                    is_array($values)
                        ? $values
                        : ($values instanceof Enumerable
                            ? $values->all()
                            : ($values instanceof \Traversable ? iterator_to_array($values) : [$values])),
                );

                if ($values === []) {
                    $this->whereRaw($not ? '1 = 1' : '0 = 1', [], $boolean);

                    return $this;
                }

                $this->whereRaw($sql.' '.($not ? 'not ' : '').'in ('.implode(', ', array_fill(0, count($values), '?')).')', $values, $boolean);

                return $this;
            }

            parent::whereIn($mappedColumn, $values, $boolean, $not);

            return $this;
        }

        parent::whereIn($column, $values, $boolean, $not);

        return $this;
    }

    /**
     * @param  Expression|string  $column
     * @param  iterable<mixed>  $values
     */
    public function orWhereIn($column, $values): static
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * @param  Expression|string  $column
     * @param  iterable<mixed>  $values
     */
    public function orWhereNotIn($column, $values): static
    {
        return $this->whereIn($column, $values, 'or', true);
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Contracts\Database\Query\Expression|string  $column
     * @param  iterable<mixed>  $values
     * @param  string  $boolean
     * @param  bool  $not
     */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false): static
    {
        if (! is_string($column) || $this->isJsonSelector($column) || ! $this->shouldMapColumn($column)) {
            parent::whereBetween($column, $values, $boolean, $not);

            return $this;
        }

        $columnName = $this->columnName($column);

        if (! in_array($columnName, self::OccurrenceBackedColumns, true)) {
            parent::whereBetween($column, $values, $boolean, $not);

            return $this;
        }

        $normalizedValues = array_values($this->normalizeValues($values));
        $start = $normalizedValues[0] ?? null;
        $end = $normalizedValues[1] ?? null;

        return $this->where(function (self $query) use ($end, $columnName, $not, $start): void {
            $sub = $query->occurrenceSubquery($columnName);
            $sql = '('.$sub->toSql().')';
            $bindings = $sub->getBindings();

            if ($not) {
                $query
                    ->whereRaw("{$sql} < ?", [...$bindings, $start])
                    ->orWhereRaw("{$sql} > ?", [...$bindings, $end]);

                return;
            }

            $query
                ->whereRaw("{$sql} >= ?", [...$bindings, $start])
                ->whereRaw("{$sql} <= ?", [...$bindings, $end]);
        }, null, null, $boolean);
    }

    public function whereJsonContains(string $column, mixed $value, string $boolean = 'and', bool $not = false): static
    {
        if ($this->columnName($column) === 'event_type' && ! $this->isJsonSelector($column)) {
            $metadataColumn = $this->qualifyModelColumn('metadata').'->event_type';

            parent::whereJsonContains($metadataColumn, $value, $boolean, $not);

            return $this;
        }

        if ($this->isJsonSelector($column) || ! $this->shouldMapColumn($column)) {
            parent::whereJsonContains($column, $value, $boolean, $not);

            return $this;
        }

        $this->columnName($column);

        parent::whereJsonContains($column, $value, $boolean, $not);

        return $this;
    }

    public function orWhereJsonContains(string $column, mixed $value): static
    {
        return $this->whereJsonContains($column, $value, 'or');
    }

    /**
     * @param  string|array|Expression  $columns
     * @param  string  $boolean
     * @param  bool  $not
     */
    /**
     * @param  string|array<mixed>|Expression  $columns
     * @param  string  $boolean
     * @param  bool  $not
     */
    public function whereNull($columns, $boolean = 'and', $not = false): static
    {
        if (! is_string($columns) || $this->isJsonSelector($columns) || ! $this->shouldMapColumn($columns)) {
            parent::whereNull($columns, $boolean, $not);

            return $this;
        }

        $columnName = $this->columnName($columns);

        if (in_array($columnName, self::OccurrenceBackedColumns, true)) {
            return $this->whereOccurrenceNull($columnName, $boolean, $not);
        }

        $mappedColumn = $this->mapColumn($columnName);

        if ($mappedColumn !== null) {
            if ($mappedColumn instanceof Expression) {
                $this->whereRaw($mappedColumn->getValue($this->getQuery()->getGrammar()).' is '.($not ? 'not ' : '').'null', [], $boolean);

                return $this;
            }

            parent::whereNull($mappedColumn, $boolean, $not);

            return $this;
        }

        parent::whereNull($columns, $boolean, $not);

        return $this;
    }

    /**
     * @param  string|array|Expression  $columns
     * @param  string  $boolean
     */
    /**
     * @param  string|array<mixed>|Expression  $columns
     * @param  string  $boolean
     */
    public function whereNotNull($columns, $boolean = 'and'): static
    {
        if (! is_string($columns) || $this->isJsonSelector($columns) || ! $this->shouldMapColumn($columns)) {
            parent::whereNotNull($columns, $boolean);

            return $this;
        }

        $columnName = $this->columnName($columns);

        if (in_array($columnName, self::OccurrenceBackedColumns, true)) {
            return $this->whereOccurrenceNull($columnName, $boolean, true);
        }

        $mappedColumn = $this->mapColumn($columnName);

        if ($mappedColumn !== null) {
            if ($mappedColumn instanceof Expression) {
                $this->whereRaw($mappedColumn->getValue($this->getQuery()->getGrammar()).' is not null', [], $boolean);

                return $this;
            }

            parent::whereNotNull($mappedColumn, $boolean);

            return $this;
        }

        parent::whereNotNull($columns, $boolean);

        return $this;
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Contracts\Database\Query\Expression|string  $column
     * @param  SortDirection|'asc'|'desc'  $direction
     */
    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Contracts\Database\Query\Expression|string  $column
     * @param  string  $direction
     */
    public function orderBy($column, $direction = 'asc'): static
    {
        if (! is_string($column) || $this->isJsonSelector($column) || ! $this->shouldMapColumn($column)) {
            parent::orderBy($column, $direction);

            return $this;
        }

        $columnName = $this->columnName($column);

        if (in_array($columnName, self::OccurrenceBackedColumns, true)) {
            parent::orderBy($this->occurrenceSubquery($columnName), $direction);

            return $this;
        }

        $mappedColumn = $this->mapColumn($columnName);

        if ($mappedColumn !== null) {
            if ($mappedColumn instanceof Expression) {
                parent::orderBy($mappedColumn, $direction);

                return $this;
            }

            parent::orderBy($mappedColumn, $direction);

            return $this;
        }

        parent::orderBy($column, $direction);

        return $this;
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Contracts\Database\Query\Expression|string  $column
     */
    public function orderByDesc($column): static
    {
        return $this->orderBy($column, 'desc');
    }

    private function whereOccurrenceColumn(
        string $column,
        mixed $operator,
        mixed $value,
        string $boolean,
        int $argumentCount,
    ): static {
        $sub = $this->occurrenceSubquery($column);
        $sql = '('.$sub->toSql().')';
        $bindings = $sub->getBindings();

        if ($argumentCount === 2) {
            $this->whereRaw("{$sql} = ?", [...$bindings, $operator], $boolean);

            return $this;
        }

        $this->whereRaw("{$sql} {$operator} ?", [...$bindings, $value], $boolean);

        return $this;
    }

    private function mapColumn(string $column): string|Expression|null
    {
        if (isset(self::PackageColumnAliases[$column])) {
            return $this->qualifyModelColumn(self::PackageColumnAliases[$column]);
        }

        if (in_array($column, self::MetadataBackedColumns, true)) {
            return $this->qualifiedMetadataSelector($column);
        }

        return null;
    }

    private function columnName(string $column): string
    {
        return Str::afterLast($column, '.');
    }

    private function isJsonSelector(string $column): bool
    {
        return str_contains($column, '->');
    }

    private function shouldMapColumn(string $column): bool
    {
        if (! str_contains($column, '.')) {
            return true;
        }

        return Str::beforeLast($column, '.') === $this->getModel()->getTable();
    }

    private function qualifiedMetadataSelector(string $key): Expression
    {
        $metadata = $this->qualifyModelColumn('metadata');

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $selector = "{$metadata}->>'{$key}'";

            return DB::raw(Str::endsWith($key, '_id') ? "({$selector})::uuid" : $selector);
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return DB::raw("json_unquote(json_extract({$metadata}, '$.\"{$key}\"'))");
        }

        return DB::raw("{$metadata}->>'{$key}'");
    }

    private function qualifyModelColumn(string $column): string
    {
        return $this->getModel()->qualifyColumn($column);
    }

    private function occurrenceSubquery(string $column): BaseQueryBuilder
    {
        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');
        $eventsTable = $this->getModel()->getTable();

        return DB::table($occurrencesTable)
            ->select($column)
            ->whereColumn("{$occurrencesTable}.event_id", "{$eventsTable}.id")
            ->orderBy("{$occurrencesTable}.starts_at")
            ->orderBy("{$occurrencesTable}.created_at")
            ->limit(1);
    }

    private function whereOccurrenceNull(string $column, string $boolean, bool $not): static
    {
        $subquery = $this->occurrenceSubquery($column);
        $sql = $subquery->toSql();
        $bindings = $subquery->getBindings();
        $operator = $not ? 'is not null' : 'is null';

        $this->whereRaw("({$sql}) {$operator}", $bindings, $boolean);

        return $this;
    }

    /**
     * @param  mixed  $operator
     * @param  mixed  $value
     */

    /**
     * @return list<mixed>
     */
    private function normalizeValues(mixed $values): array
    {
        if ($values instanceof Collection) {
            return $values->values()->all();
        }

        if (is_array($values)) {
            return array_values($values);
        }

        return [$values];
    }
}
