<?php

namespace App\Actions\Contributions;

use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Models\ContributionRequest;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class SubmitContributionUpdateRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ContributionEntityMutationService $entityMutationService,
        private readonly ResolveContributionEntityMetadataAction $resolveContributionEntityMetadataAction,
    ) {}

    /**
     * @param  array<string, mixed>  $proposedData
     */
    public function handle(
        Model $entity,
        User $proposer,
        array $proposedData,
        ?string $proposerNote = null,
    ): ContributionRequest {
        $entityMetadata = $this->resolveContributionEntityMetadataAction->handle($entity);
        $originalData = array_intersect_key(
            $this->entityMutationService->stateFor($entity),
            $proposedData,
        );

        return ContributionRequest::create([
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
    }
}
