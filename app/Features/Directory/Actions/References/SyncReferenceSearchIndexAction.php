<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Features\Directory\Support\DirectoryModel;
use App\Features\Directory\Support\ReferenceFamily;
use Illuminate\Database\Eloquent\Model;

/**
 * Own reference search-index refreshes.
 *
 * Contributor changes on a work affect every inherited descendant, so the
 * family is reindexed together. Visibility follows the public contract
 * (published_at present and status verified/pending).
 */
final readonly class SyncReferenceSearchIndexAction
{
    public function syncRecord(Model $reference): void
    {
        if (! method_exists($reference, 'searchable') || ! method_exists($reference, 'unsearchable')) {
            return;
        }

        $publishedAt = $reference->getAttribute('published_at');
        $status = (string) $reference->getAttribute('status');
        $visible = $publishedAt !== null && in_array($status, ['verified', 'pending'], true);

        if ($visible) {
            $reference->searchable();

            return;
        }

        $reference->unsearchable();
    }

    public function reindexFamily(string $referenceId): void
    {
        $reference = DirectoryModel::referenceQuery()->find($referenceId);

        if (! $reference instanceof Model) {
            return;
        }

        $rootId = ReferenceFamily::rootId($reference) ?? (string) $reference->getKey();
        $ids = ReferenceFamily::expandAllIds([$rootId]);

        if ($ids === []) {
            return;
        }

        $members = DirectoryModel::referenceQuery()
            ->whereKey($ids)
            ->with([
                'authors.titleAssignments.title.category',
                'parentReference.authors.titleAssignments.title.category',
                'parentReference.parentReference.authors.titleAssignments.title.category',
            ])
            ->get();

        foreach ($members as $member) {
            if ($member instanceof Model) {
                $this->syncRecord($member);
            }
        }
    }

    public function reindexAll(int $chunkSize = 100): int
    {
        $processed = 0;

        DirectoryModel::referenceQuery()
            ->chunkById(max(1, $chunkSize), function ($references) use (&$processed): void {
                foreach ($references as $reference) {
                    if (! $reference instanceof Model) {
                        continue;
                    }

                    $this->syncRecord($reference);
                    $processed++;
                }
            });

        return $processed;
    }
}
