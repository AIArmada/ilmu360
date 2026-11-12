<?php

declare(strict_types=1);

namespace App\Features\Directory\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Reference work-family resolution for public visibility and event scoping.
 *
 * Works own authorship; editions and parts inherit from the root work. Public
 * expansion is limited to the same two descendant levels the legacy model
 * exposed and never includes unpublished or inactive records.
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

        $parent = DirectoryModel::referenceQuery()->find($parentId);

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
        return self::expand($referenceIds, DirectoryModel::referenceQuery());
    }

    /**
     * @param  list<string>  $referenceIds
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Reference>  $query
     * @return list<string>
     */
    private static function expand(array $referenceIds, \Illuminate\Database\Eloquent\Builder $query): array
    {
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
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Reference>
     */
    private static function activeQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return DirectoryModel::referenceQuery()
            ->whereNotNull('references.published_at')
            ->whereIn('references.status', self::PUBLIC_STATUSES);
    }
}
