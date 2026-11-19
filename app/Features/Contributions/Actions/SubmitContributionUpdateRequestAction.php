<?php

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Api\ContributionEntityState;
use App\Features\Contributions\Contracts\ContributionRequestGuard;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Support\ContributionEntityMetadata;
use App\Features\Contributions\Support\ContributionModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class SubmitContributionUpdateRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ContributionEntityState $entityState,
        private readonly ContributionRequestGuard $pendingRequests,
    ) {}

    /**
     * @param  array<string, mixed>  $proposedData
     * @return \App\Features\Contributions\Models\ContributionRequest
     */
    public function handle(
        Model $entity,
        Model $proposer,
        array $proposedData,
        ?string $proposerNote = null,
    ): Model {
        $entityMetadata = ContributionEntityMetadata::for($entity);

        return DB::transaction(function () use ($entity, $entityMetadata, $proposer, $proposedData, $proposerNote): Model {
            $lockedEntity = $entity->newQuery()
                ->whereKey($entity->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedEntityMetadata = ContributionEntityMetadata::for($lockedEntity);
            $entityType = $lockedEntityMetadata['entity_type'];
            $entityId = $lockedEntityMetadata['entity_id'];
            $this->pendingRequests->ensureNoPending($entityType, $entityId);

            $originalData = array_intersect_key(
                $this->entityState->stateFor($entityType, $entityId),
                $proposedData,
            );

            return ContributionModel::query('contribution_request')->create([
                'type' => ContributionRequestType::Update,
                'subject_type' => $entityMetadata['subject_type'],
                'entity_type' => $entityMetadata['entity_type'],
                'entity_id' => $entityMetadata['entity_id'],
                'proposer_id' => $proposer->getKey(),
                'status' => ContributionRequestStatus::Pending,
                'proposed_data' => $proposedData,
                'original_data' => $originalData,
                'proposer_note' => $proposerNote,
            ]);
        });
    }
}
