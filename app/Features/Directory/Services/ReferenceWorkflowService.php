<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Actions\References\ApplyReferenceContributionAction;
use App\Features\Directory\Actions\References\BackfillReferenceSlugsAction;
use App\Features\Directory\Actions\References\ChangeReferenceStatusAction;
use App\Features\Directory\Actions\References\CreateReferenceAction;
use App\Features\Directory\Actions\References\DeleteReferenceAction;
use App\Features\Directory\Actions\References\GenerateReferenceSlugAction;
use App\Features\Directory\Actions\References\SaveReferenceAction;
use App\Features\Directory\Actions\References\SyncReferenceContributorsAction;
use App\Features\Directory\Actions\References\SyncReferenceHierarchyAction;
use App\Features\Directory\Actions\References\SyncReferenceMediaAction;
use App\Features\Directory\Actions\References\SyncReferenceRelationsAction;
use App\Features\Directory\Actions\References\SyncReferenceSearchIndexAction;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Implements the reference workflow through named Directory actions.
 */
final readonly class ReferenceWorkflowService implements ReferenceWorkflow
{
    public function __construct(
        private CreateReferenceAction $createReference,
        private SaveReferenceAction $saveReference,
        private ApplyReferenceContributionAction $applyReferenceContribution,
        private DeleteReferenceAction $deleteReference,
        private ChangeReferenceStatusAction $changeReferenceStatus,
        private GenerateReferenceSlugAction $generateReferenceSlug,
        private BackfillReferenceSlugsAction $backfillReferenceSlugs,
        private SyncReferenceHierarchyAction $syncReferenceHierarchy,
        private SyncReferenceContributorsAction $syncReferenceContributors,
        private SyncReferenceRelationsAction $syncReferenceRelations,
        private SyncReferenceMediaAction $syncReferenceMedia,
        private SyncReferenceSearchIndexAction $syncReferenceSearchIndex,
    ) {}

    public function createFromContribution(array $payload): string
    {
        return (string) $this->createReference->handle($payload, 'pending')->getKey();
    }

    public function createFromForm(array $payload): string
    {
        return (string) $this->saveReference->handle($payload)->getKey();
    }

    public function createVerifiedFromContribution(array $payload): string
    {
        return (string) $this->createReference->handle($payload, 'verified')->getKey();
    }

    public function saveFromAdmin(array $payload, ?string $referenceId = null): string
    {
        $reference = $referenceId !== null ? $this->reference($referenceId) : null;

        return (string) $this->saveReference->handle($payload, $reference)->getKey();
    }

    public function applyContribution(string $referenceId, array $payload): array
    {
        return $this->applyReferenceContribution->handle($this->reference($referenceId), $payload);
    }

    public function syncRelations(string $referenceId, array $payload): void
    {
        $this->syncReferenceRelations->handle($this->reference($referenceId), $payload);
    }

    public function syncMedia(string $referenceId, array $payload): void
    {
        $this->syncReferenceMedia->handle($this->reference($referenceId), $payload);
    }

    public function syncContributors(string $referenceId, mixed $authorIds): void
    {
        $this->syncReferenceContributors->sync($this->reference($referenceId), $authorIds);
    }

    public function clearContributors(string $referenceId): void
    {
        $this->syncReferenceContributors->clear($this->reference($referenceId));
    }

    public function changeStatus(string $referenceId, string $status): void
    {
        $this->changeReferenceStatus->handle($referenceId, $status);
    }

    public function delete(array $referenceIds): int
    {
        $deleted = 0;

        foreach (DirectoryModel::referenceQuery()->whereKey($referenceIds)->get() as $reference) {
            if (! $reference instanceof Model) {
                continue;
            }

            $this->deleteReference->handle($reference);
            $deleted++;
        }

        return $deleted;
    }

    public function buildSlug(string $title, ?string $ignoreReferenceId = null): string
    {
        return $this->generateReferenceSlug->handle($title, $ignoreReferenceId);
    }

    public function syncSlugsForTitle(string $title): bool
    {
        return $this->generateReferenceSlug->syncReferenceSlugsForTitle($title);
    }

    public function syncSlug(string $referenceId): bool
    {
        return $this->generateReferenceSlug->syncReferenceSlug($this->reference($referenceId));
    }

    public function syncHierarchy(string $referenceId, array $changedAttributes = []): void
    {
        $this->syncReferenceHierarchy->handle($this->reference($referenceId), $changedAttributes);
    }

    public function backfillSlugs(): array
    {
        return $this->backfillReferenceSlugs->handle();
    }

    public function syncSearchRecord(string $referenceId): void
    {
        $this->syncReferenceSearchIndex->syncRecord($this->reference($referenceId));
    }

    public function reindexSearchFamily(string $referenceId): void
    {
        $this->syncReferenceSearchIndex->reindexFamily($referenceId);
    }

    public function reindexSearch(int $chunkSize = 100): int
    {
        return $this->syncReferenceSearchIndex->reindexAll($chunkSize);
    }

    private function reference(string $referenceId): Model
    {
        $reference = DirectoryModel::referenceQuery()->find($referenceId);

        if (! $reference instanceof Model) {
            throw new RuntimeException("Reference [{$referenceId}] was not found.");
        }

        return $reference;
    }
}
