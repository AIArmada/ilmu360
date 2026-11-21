<?php

declare(strict_types=1);

namespace App\Features\References\Services;

use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Models\Reference;
use App\Features\References\Support\ReferenceFamily;
use Illuminate\Database\Eloquent\Model;

/**
 * Implements the reference query boundary over the current Eloquent models.
 */
final readonly class ReferenceQueryService implements ReferenceQueries
{
    public function __construct(private ReferenceSearchService $search) {}

    public function publiclyVisibleStatuses(): array
    {
        return Reference::PUBLIC_STATUSES;
    }

    public function expandReferenceIdsForFiltering(array $referenceIds): array
    {
        return ReferenceFamily::expandIds($referenceIds);
    }

    public function familyReferenceIds(string $referenceId): array
    {
        $reference = Reference::query()->withoutOwnerScope()->find($referenceId);

        return $reference instanceof Model ? ReferenceFamily::familyIds($reference) : [];
    }

    public function defaultEventReferenceIds(string $referenceId): array
    {
        return ReferenceFamily::expandIdsForMany([$referenceId])[$referenceId] ?? [];
    }

    public function defaultEventReferenceIdsForMany(array $referenceIds): array
    {
        return ReferenceFamily::expandIdsForMany($referenceIds);
    }

    public function referenceMorphType(): string
    {
        return (new Reference)->getMorphClass();
    }

    public function publicSearchIds(string $search): array
    {
        return $this->search->publicSearchIds($search);
    }

    /**
     * @param  list<string>  $referenceIds
     * @return list<string>
     */
    public function familyRootIds(array $referenceIds): array
    {
        if ($referenceIds === []) {
            return [];
        }

        $rootIds = [];

        foreach (Reference::query()->whereKey($referenceIds)->get(['id', 'parent_id', 'record_kind']) as $reference) {
            $rootIds[] = $reference->isRootReference()
                ? (string) $reference->getKey()
                : ($reference->familyRootId() ?? (string) $reference->getKey());
        }

        return array_values(array_unique($rootIds));
    }
}
