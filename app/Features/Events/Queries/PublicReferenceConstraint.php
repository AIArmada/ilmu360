<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\References\Api\ReferenceQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class PublicReferenceConstraint
{
    public function __construct(private ReferenceQueries $references) {}

    /**
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply(Builder|QueryBuilder $query, ?string $table = null): Builder|QueryBuilder
    {
        $table ??= $query instanceof Builder ? $query->getModel()->getTable() : 'references';

        return $query
            ->whereNotNull($table.'.published_at')
            ->whereIn($table.'.status', $this->references->publiclyVisibleStatuses());
    }
}
