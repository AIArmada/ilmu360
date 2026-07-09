<?php

namespace App\Models\Builders;

use App\Models\Reference;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * @extends Builder<Reference>
 */
class ReferenceBuilder extends Builder
{
    /**
     * @var list<string>
     */
    private const array MetadataBackedColumns = [
        'part_type',
        'part_number',
        'part_label',
        'is_canonical',
    ];

    #[\Override]
    public function where($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        if (! is_string($column) || $this->isJsonSelector($column)) {
            return parent::where($column, $operator, $value, $boolean);
        }

        $mappedColumn = $this->mapColumn($this->columnName($column));

        if ($mappedColumn === null) {
            return parent::where($column, $operator, $value, $boolean);
        }

        return parent::where($mappedColumn, $operator, $value, $boolean);
    }

    /**
     * @param  Expression|string  $column
     * @param  iterable<mixed>  $values
     * @param  string  $boolean
     * @param  bool  $not
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false): static
    {
        if (! is_string($column) || $this->isJsonSelector($column)) {
            parent::whereIn($column, $values, $boolean, $not);

            return $this;
        }

        $mappedColumn = $this->mapColumn($this->columnName($column));

        if ($mappedColumn === null) {
            parent::whereIn($column, $values, $boolean, $not);

            return $this;
        }

        parent::whereIn($mappedColumn, $values, $boolean, $not);

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
     * @param  string|array<mixed>|Expression  $columns
     * @param  string  $boolean
     * @param  bool  $not
     */
    public function whereNull($columns, $boolean = 'and', $not = false): static
    {
        if (! is_string($columns) || $this->isJsonSelector($columns)) {
            parent::whereNull($columns, $boolean, $not);

            return $this;
        }

        $mappedColumn = $this->mapColumn($this->columnName($columns));

        if ($mappedColumn === null) {
            parent::whereNull($columns, $boolean, $not);

            return $this;
        }

        parent::whereNull($mappedColumn, $boolean, $not);

        return $this;
    }

    /**
     * @param  string|array<mixed>|Expression  $columns
     * @param  string  $boolean
     */
    public function whereNotNull($columns, $boolean = 'and'): static
    {
        if (! is_string($columns) || $this->isJsonSelector($columns)) {
            parent::whereNotNull($columns, $boolean);

            return $this;
        }

        $mappedColumn = $this->mapColumn($this->columnName($columns));

        if ($mappedColumn === null) {
            parent::whereNotNull($columns, $boolean);

            return $this;
        }

        parent::whereNotNull($mappedColumn, $boolean);

        return $this;
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Contracts\Database\Query\Expression|string  $column
     * @param  string  $direction
     */
    public function orderBy($column, $direction = 'asc'): static
    {
        if (! is_string($column) || $this->isJsonSelector($column)) {
            parent::orderBy($column, $direction);

            return $this;
        }

        $mappedColumn = $this->mapColumn($this->columnName($column));

        if ($mappedColumn === null) {
            parent::orderBy($column, $direction);

            return $this;
        }

        parent::orderBy($mappedColumn, $direction);

        return $this;
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Contracts\Database\Query\Expression|string  $column
     */
    public function orderByDesc($column): static
    {
        return $this->orderBy($column, 'desc');
    }

    private function mapColumn(string $column): ?string
    {
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

    private function qualifiedMetadataSelector(string $key): string
    {
        return $this->qualifyModelColumn('metadata').'->'.$key;
    }

    private function qualifyModelColumn(string $column): string
    {
        return $this->getModel()->qualifyColumn($column);
    }
}
