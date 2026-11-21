<?php

declare(strict_types=1);

namespace App\Features\References\Api\Orm;

use App\Features\References\Api\ReferenceQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Explicit Eloquent integration port for reference visibility constraints.
 *
 * The owner supplies its published-visibility predicate to already-owned
 * relation/query callbacks. Callers may constrain by reference ids; they must
 * not add their own visibility or ownership predicates.
 *
 * @internal Framework relation-constraint adapter. Model/query transport is
 *           limited to applying the owner's visibility scope.
 *
 * Consumers: Events schedule/detail pages, event payloads/submission sync,
 * Institutions and Person pages, and the References page.
 */
interface ReferenceOrmPort extends ReferenceQueries
{
    /**
     * @template TQuery of Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function applyPublicVisibility(Builder|QueryBuilder $query, ?string $table = null): Builder|QueryBuilder;

    /**
     * Apply the reference-family visibility predicate to an Events query.
     *
     * Correlated-subquery projection used by the reference directory count;
     * the owner keeps the family/visibility SQL, callers keep only event
     * status rules.
     *
     * @param  Builder<\App\Features\Events\Models\Event>  $query
     * @return Builder<\App\Features\Events\Models\Event>
     */
    public function constrainEventReferenceSubtree(Builder $query, string $referenceIdColumn = 'references.id'): Builder;
}
