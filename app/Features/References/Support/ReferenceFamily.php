<?php

declare(strict_types=1);

namespace App\Features\References\Support;

use App\Features\References\Models\Reference;
use Illuminate\Database\Eloquent\Model;

/**
 * Reference work-family resolution for public visibility and event scoping.
 *
 * Works own authorship; editions and parts inherit from the root work. Public
 * expansion includes at most two descendant levels and never includes
 * unpublished or inactive records.
 */
final class ReferenceFamily
{
    /** @var list<string> */
    private const array PUBLIC_STATUSES = ['verified', 'pending'];

    public static function rootId(Model $reference): ?string
    {
        $id = (string) $reference->getKey();

        if (ReferencePayload::isRoot($reference)) {
            return $reference->exists ? $id : null;
        }

        $parentId = $reference->getAttribute('parent_id');

        if (! is_string($parentId) || $parentId === '') {
            return null;
        }

        $parent = Reference::query()->withoutOwnerScope()->find($parentId);

        if (! $parent instanceof Model) {
            return null;
        }

        if (ReferencePayload::isRoot($parent)) {
            return $parentId;
        }

        $grandParentId = $parent->getAttribute('parent_id');

        return is_string($grandParentId) && $grandParentId !== '' ? $grandParentId : null;
    }

    /**
     * @return list<string>
     */
    public static function familyIds(Model $reference): array
    {
        $rootId = self::rootId($reference);

        return $rootId === null ? [] : self::expandIds([$rootId]);
    }

    /**
     * @param  list<string>  $referenceIds
     * @return list<string>
     */
    public static function expandIds(array $referenceIds): array
    {
        return self::expand($referenceIds, self::activeQuery());
    }

    /**
     * Expand multiple independent default-event families with at most one
     * selected-row query and one query per descendant depth.
     *
     * @param  list<string>  $referenceIds
     * @return array<string, list<string>>
     */
    public static function expandIdsForMany(array $referenceIds): array
    {
        $families = [];

        foreach (array_values(array_unique($referenceIds)) as $referenceId) {
            $families[$referenceId] = [];
        }

        if ($families === []) {
            return [];
        }

        $selected = self::activeQuery()
            ->whereKey(array_keys($families))
            ->get(['id', 'record_kind']);
        $frontierByFamily = [];

        foreach ($selected as $reference) {
            $referenceId = (string) $reference->getKey();
            $families[$referenceId] = [$referenceId];

            if (! ReferencePayload::isPart($reference)) {
                $frontierByFamily[$referenceId] = [$referenceId];
            }
        }

        for ($depth = 0; $depth < 2 && $frontierByFamily !== []; $depth++) {
            $familiesByParent = [];

            foreach ($frontierByFamily as $familyId => $parentIds) {
                foreach ($parentIds as $parentId) {
                    $familiesByParent[$parentId][] = $familyId;
                }
            }

            $children = (clone self::activeQuery())
                ->whereIn('parent_id', array_keys($familiesByParent))
                ->get(['id', 'parent_id', 'record_kind']);
            $nextFrontierByFamily = [];

            foreach ($children as $child) {
                $childId = (string) $child->getKey();
                $parentId = (string) $child->getAttribute('parent_id');

                foreach ($familiesByParent[$parentId] ?? [] as $familyId) {
                    $families[$familyId][] = $childId;

                    if (! ReferencePayload::isPart($child)) {
                        $nextFrontierByFamily[$familyId][] = $childId;
                    }
                }
            }

            foreach ($nextFrontierByFamily as $familyId => $nextIds) {
                $nextFrontierByFamily[$familyId] = array_values(array_unique($nextIds));
            }

            $frontierByFamily = $nextFrontierByFamily;
        }

        foreach ($families as $familyId => $ids) {
            $families[$familyId] = array_values(array_unique($ids));
        }

        return $families;
    }

    /**
     * Expand a family without the public-visibility filter.
     *
     * Search reindexing must still visit hidden descendants so their index
     * entries are removed when they stop being public.
     *
     * @param  list<string>  $referenceIds
     * @return list<string>
     */
    public static function expandAllIds(array $referenceIds): array
    {
        return self::expand($referenceIds, Reference::query()->withoutOwnerScope());
    }

    /**
     * @param  list<string>  $referenceIds
     * @param  \Illuminate\Database\Eloquent\Builder<Reference>  $query
     * @return list<string>
     */
    private static function expand(array $referenceIds, \Illuminate\Database\Eloquent\Builder $query): array
    {
        if ($referenceIds === []) {
            return [];
        }

        $selected = (clone $query)->whereKey($referenceIds)->get(['id', 'record_kind']);
        $ids = $selected->modelKeys();
        $parents = $selected
            ->reject(static fn (Model $reference): bool => ReferencePayload::isPart($reference))
            ->modelKeys();

        for ($depth = 0; $depth < 2 && $parents !== []; $depth++) {
            $children = (clone $query)->whereIn('parent_id', $parents)->get(['id', 'record_kind']);
            $ids = [...$ids, ...$children->modelKeys()];
            $parents = $children
                ->reject(static fn (Model $reference): bool => ReferencePayload::isPart($reference))
                ->modelKeys();
        }

        return array_values(array_unique(array_map(strval(...), $ids)));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Reference>
     */
    private static function activeQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Reference::query()->withoutOwnerScope()
            ->whereNotNull('references.published_at')
            ->whereIn('references.status', self::PUBLIC_STATUSES);
    }
}
