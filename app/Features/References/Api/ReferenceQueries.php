<?php

declare(strict_types=1);

namespace App\Features\References\Api;

/**
 * Reference query boundary for Events, Discovery and public readers.
 *
 * Reads return immutable ids and scalar values. Callers compose their own
 * private query constraints from the owner-provided visibility statuses.
 */
interface ReferenceQueries
{
    /** @return list<string> */
    public function publiclyVisibleStatuses(): array;

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
     * Default-event reference ids for each selected directory reference.
     *
     * @param  list<string>  $referenceIds
     * @return array<string, list<string>>
     */
    public function defaultEventReferenceIdsForMany(array $referenceIds): array;

    /**
     * The stored polymorphic alias for reference links.
     */
    public function referenceMorphType(): string;

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
