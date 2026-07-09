<?php

namespace App\Models\Builders;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * @extends Builder<Venue>
 */
class VenueBuilder extends Builder
{
    /**
     * @var list<string>
     */
    private const array MetadataBackedColumns = [
        'description',
        'facilities',
    ];

    #[\Override]
    public function where($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        if (! is_string($column) || $this->isJsonSelector($column)) {
            parent::where($column, $operator, $value, $boolean);

            return $this;
        }

        $mappedColumn = $this->mapColumn($this->columnName($column));

        if ($mappedColumn === null) {
            parent::where($column, $operator, $value, $boolean);

            return $this;
        }

        if (func_num_args() === 2) {
            parent::where($mappedColumn, '=', $operator, $boolean);

            return $this;
        }

        parent::where($mappedColumn, $operator, $value, $boolean);

        return $this;
    }

    public function whereIn(mixed $column, mixed $values, string $boolean = 'and', bool $not = false): static
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

    public function orWhereIn(mixed $column, mixed $values): static
    {
        return $this->whereIn($column, $values, 'or');
    }

    public function orderBy(mixed $column, mixed $direction = 'asc'): static
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

    public function orderByDesc(mixed $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function whereNull(mixed $columns, string $boolean = 'and', bool $not = false): static
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

    public function whereNotNull(mixed $columns, string $boolean = 'and'): static
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

    private function mapColumn(string $column): ?string
    {
        if (in_array($column, self::MetadataBackedColumns, true)) {
            return $this->qualifiedMetadataSelector($column);
        }

        return null;
    }

    private function qualifiedMetadataSelector(string $key): string
    {
        return $this->qualifyModelColumn('metadata').'->'.$key;
    }

    private function qualifyModelColumn(string $column): string
    {
        return $this->getModel()->qualifyColumn($column);
    }

    private function columnName(string $column): string
    {
        return Str::afterLast($column, '.');
    }

    private function isJsonSelector(string $column): bool
    {
        return str_contains($column, '->');
    }
}
