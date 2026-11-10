<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Features\Directory\Support\DirectoryModel;
use App\Features\Directory\Support\ReferencePayload;
use Illuminate\Database\Eloquent\Model;

/**
 * Keep reference hierarchy, slugs and search identities consistent after a
 * record-kind, parent or bibliographic change.
 */
final readonly class SyncReferenceHierarchyAction
{
    public function __construct(
        private GenerateReferenceSlugAction $generateReferenceSlug,
        private SyncReferenceContributorsAction $syncContributors,
        private SyncReferenceSearchIndexAction $syncSearchIndex,
    ) {}

    /**
     * @param  list<string>  $changedAttributes
     */
    public function handle(Model $reference, array $changedAttributes = []): void
    {
        $hierarchyChanged = array_intersect([
            'parent_id',
            'record_kind',
            'edition_number',
            'edition_label',
            'publisher',
            'year',
            'reference_parts',
        ], $changedAttributes) !== [];

        $structureChanged = array_intersect(['record_kind', 'parent_id'], $changedAttributes) !== [];

        if ($structureChanged && ! ReferencePayload::isRoot($reference)) {
            $this->syncContributors->clear($reference);

            if ($reference->relationLoaded('authors')) {
                $reference->unsetRelation('authors');
            }

            $this->syncSearchIndex->syncRecord($reference);
        }

        if (! $hierarchyChanged) {
            return;
        }

        $this->generateReferenceSlug->syncReferenceSlug($reference);

        $children = DirectoryModel::referenceQuery()
            ->where('parent_id', (string) $reference->getKey())
            ->orderBy('title')
            ->with('parentReference.parentReference')
            ->get();

        foreach ($children as $child) {
            if (! $child instanceof Model) {
                continue;
            }

            $this->generateReferenceSlug->syncReferenceSlug($child);
            $this->syncSearchIndex->syncRecord($child);
        }
    }
}
