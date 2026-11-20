<?php

declare(strict_types=1);

namespace App\Features\References\Api;

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
    public function applyPublicVisibility(mixed $query, ?string $table = null): mixed;

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

    /** @return list<string> */
    public function publicSearchIds(string $search): array;

    /**
     * Family roots for the given references, preserving input order.
     *
     * @param  list<string>  $referenceIds
     * @return list<string>
     */
    public function familyRootIds(array $referenceIds): array;
}
