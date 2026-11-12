<?php

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Support\ContributionModel;
use App\Shared\Contributions\ContributionEntityState;
use App\Shared\Contributions\ContributionRequestGuard;
use App\Shared\Contributions\Enums\ContributionRequestStatus;
use App\Shared\Contributions\Enums\ContributionRequestType;
use App\Shared\Contributions\Support\ContributionEntityMetadata;
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
     * @return \App\Models\ContributionRequest
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

            $this->pendingRequests->ensureNoPending($lockedEntity);

            $originalData = array_intersect_key(
                $this->entityState->stateFor($lockedEntity),
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
