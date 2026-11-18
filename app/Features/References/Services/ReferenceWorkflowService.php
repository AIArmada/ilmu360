<?php

declare(strict_types=1);

namespace App\Features\References\Services;

use App\Features\References\Actions\ApplyReferenceContributionAction;
use App\Features\References\Actions\ChangeReferenceStatusAction;
use App\Features\References\Actions\CreateReferenceAction;
use App\Features\References\Actions\DeleteReferenceAction;
use App\Features\References\Actions\DetachReferenceVerifierAction;
use App\Features\References\Actions\GenerateReferenceSlugAction;
use App\Features\References\Actions\RestoreReferenceVerifierAction;
use App\Features\References\Actions\SaveReferenceAction;
use App\Features\References\Actions\SyncReferenceContributorsAction;
use App\Features\References\Actions\SyncReferenceHierarchyAction;
use App\Features\References\Actions\SyncReferenceMediaAction;
use App\Features\References\Actions\SyncReferenceRelationsAction;
use App\Features\References\Actions\SyncReferenceSearchIndexAction;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\References\Models\Reference;
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
        private SyncReferenceHierarchyAction $syncReferenceHierarchy,
        private SyncReferenceContributorsAction $syncReferenceContributors,
        private SyncReferenceRelationsAction $syncReferenceRelations,
        private SyncReferenceMediaAction $syncReferenceMedia,
        private SyncReferenceSearchIndexAction $syncReferenceSearchIndex,
        private DetachReferenceVerifierAction $detachReferenceVerifier,
        private RestoreReferenceVerifierAction $restoreReferenceVerifier,
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

        foreach (Reference::query()->withoutOwnerScope()->whereKey($referenceIds)->get() as $reference) {
            if (! $reference instanceof Reference) {
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

    public function verifiedIdsForAccount(string $accountId): array
    {
        return Reference::query()
            ->where('verified_by', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function detachVerifier(string $accountId): void
    {
        $this->detachReferenceVerifier->handle($accountId);
    }

    public function restoreVerifier(string $accountId, array $referenceIds): void
    {
        $this->restoreReferenceVerifier->handle($accountId, $referenceIds);
    }

    private function reference(string $referenceId): Reference
    {
        $reference = Reference::query()->withoutOwnerScope()->find($referenceId);

        if (! $reference instanceof Reference) {
            throw new RuntimeException("Reference [{$referenceId}] was not found.");
        }

        return $reference;
    }
}
