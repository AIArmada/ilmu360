<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Api\ReferenceQueries;
use App\Features\Directory\Support\DirectoryModel;
use App\Features\Directory\Support\ReferenceFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * Implements the reference query boundary over the current Eloquent models.
 */
final readonly class ReferenceQueryService implements ReferenceQueries
{
    public function applyPublicVisibility(mixed $query, ?string $table = null): mixed
    {
        if (! $query instanceof Builder && ! $query instanceof QueryBuilder) {
            throw new InvalidArgumentException('Reference visibility requires an Eloquent or query builder.');
        }

        $referenceClass = DirectoryModel::reference();

        return $referenceClass::applyPublicVisibility($query, $table);
    }

    public function constrainEventReferenceSubtree(mixed $query, string $referenceIdColumn = 'references.id'): mixed
    {
        if (! $query instanceof Builder) {
            throw new InvalidArgumentException('Reference subtree constraints require an Eloquent builder.');
        }

        $referenceClass = DirectoryModel::reference();

        return $referenceClass::constrainEventReferenceSubtree($query, $referenceIdColumn);
    }

    public function expandReferenceIdsForFiltering(array $referenceIds): array
    {
        return ReferenceFamily::expandIds($referenceIds);
    }

    public function familyReferenceIds(string $referenceId): array
    {
        $reference = DirectoryModel::referenceQuery()->find($referenceId);

        return $reference instanceof Model ? ReferenceFamily::familyIds($reference) : [];
    }

    public function defaultEventReferenceIds(string $referenceId): array
    {
        $reference = DirectoryModel::referenceQuery()->find($referenceId);

        return $reference instanceof Model ? ReferenceFamily::expandIds([(string) $reference->getKey()]) : [];
    }

    public function referenceModelClass(): string
    {
        return DirectoryModel::reference();
    }
}
