<?php

declare(strict_types=1);

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Api\ContributionDirectUpdates;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\Contracts\ContributionRequestGuard;
use App\Features\Contributions\Support\ContributionModel;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventModeration;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\References\Api\ReferenceWorkflow;
use App\Models\User;
use App\Platform\Media\MediaCollectionSync;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\MediaLibrary\HasMedia;

/**
 * Direct edits use the relevant entity owner's public authoring contract.
 * Contributions owns the transaction; subject features own their mutations.
 */
final readonly class ApplyDirectContributionUpdateAction implements ContributionDirectUpdates
{
    public function __construct(
        private EventAuthoring $eventAuthoring,
        private EventModeration $eventModeration,
        private InstitutionWorkflow $institutions,
        private PersonWorkflow $persons,
        private ReferenceWorkflow $references,
        private ContributionRequestGuard $pendingRequests,
        private MediaCollectionSync $media,
    ) {}

    public function apply(
        string $actorId,
        string $entityType,
        string $entityId,
        array $changes,
        ?Closure $writeMedia = null,
    ): void {
        $actor = User::query()->find($actorId);
        abort_unless($actor instanceof User && $actor->canPublishDirectoryUpdates(), 403);

        if (ContributionSubjectType::tryFrom($entityType) === null) {
            throw new InvalidArgumentException("Unsupported contribution subject [{$entityType}].");
        }

        DB::transaction(function () use ($entityType, $entityId, $changes, $writeMedia): void {
            $entity = ContributionModel::query($entityType)->whereKey($entityId)->lockForUpdate()->firstOrFail();
            $this->pendingRequests->ensureNoPending($entityType, $entityId);

            if ($changes !== []) {
                $this->writeChanges($entityType, $entityId, $changes);
            }

            if ($writeMedia === null) {
                return;
            }

            if (! $entity instanceof HasMedia) {
                throw new InvalidArgumentException("Contribution subject [{$entityType}] does not support media.");
            }

            $this->media->cleanUpFailedWrites($entity, function () use ($entity, $entityType, $writeMedia): void {
                $gallery = in_array($entityType, ['event', 'institution'], true)
                    ? $entity->media()->where('collection_name', 'gallery')
                    : null;
                $before = $gallery?->count();

                $writeMedia();

                $after = $gallery?->count();

                if ($after !== null && $after > max(10, $before ?? 0)) {
                    throw ValidationException::withMessages(['gallery' => __('The gallery may contain up to 10 photos.')]);
                }
            });
        });
    }

    /** @param array<string, mixed> $changes */
    private function writeChanges(string $entityType, string $entityId, array $changes): void
    {
        $dirty = match ($entityType) {
            'event' => $this->eventAuthoring->applyContributionUpdate($entityId, $changes),
            'institution' => $this->institutions->applyContribution($entityId, $changes),
            'person' => $this->persons->applyContribution($entityId, $changes),
            'reference' => $this->references->applyContribution($entityId, $changes),
            default => throw new InvalidArgumentException("Unsupported contribution subject [{$entityType}]."),
        };

        if ($entityType === 'event' && $dirty !== []) {
            $this->eventModeration->handleSensitiveChange($entityId, $dirty);
        }
    }
}
