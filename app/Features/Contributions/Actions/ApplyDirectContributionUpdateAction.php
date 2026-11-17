<?php

declare(strict_types=1);

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Api\ContributionDirectUpdates;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventModeration;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\References\Api\ReferenceWorkflow;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
    ) {}

    public function apply(string $entityType, string $entityId, array $changes): void
    {
        DB::transaction(function () use ($entityType, $entityId, $changes): void {
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
        });
    }
}
