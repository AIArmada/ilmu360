<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Reference query boundary for Events, Discovery and public readers.
 *
 * Query builders are passed opaquely so this contract keeps Laravel runtime
 * types out of the Directory public surface. Implementations preserve the
 * existing published/status visibility contract and two-level family
 * expansion.
 */
interface ReferenceQueries
{
    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder
     */
    public function applyPublicVisibility(mixed $query, ?string $table = null): mixed;

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function constrainEventReferenceSubtree(mixed $query, string $referenceIdColumn = 'references.id'): mixed;

    /**
     * @param  list<string>  $referenceIds
     * @return list<string>
     */
    public function expandReferenceIdsForFiltering(array $referenceIds): array;

    /**
     * @return list<string>
     */
    public function familyReferenceIds(string $referenceId): array;

    /**
     * @return list<string>
     */
    public function defaultEventReferenceIds(string $referenceId): array;

    /**
     * @return class-string
     */
    public function referenceModelClass(): string;
}
