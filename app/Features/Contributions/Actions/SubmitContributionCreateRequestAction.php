<?php

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Support\ContributionEntityMetadata;
use App\Features\Contributions\Support\ContributionModel;
use App\Shared\Contributions\Enums\ContributionRequestStatus;
use App\Shared\Contributions\Enums\ContributionRequestType;
use App\Shared\Contributions\Enums\ContributionSubjectType;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class SubmitContributionCreateRequestAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $proposedData
     * @return \App\Models\ContributionRequest
     */
    public function handle(
        ContributionSubjectType $subjectType,
        Model $proposer,
        array $proposedData,
        ?string $proposerNote = null,
        ?Model $entity = null,
    ): Model {
        if (! in_array($subjectType, [
            ContributionSubjectType::Institution,
            ContributionSubjectType::Person,
            ContributionSubjectType::Reference,
        ], true)) {
            throw new RuntimeException('Only institution, person, and reference creation requests are currently supported.');
        }

        $entityMetadata = $entity instanceof Model
            ? ContributionEntityMetadata::for($entity)
            : null;

        return ContributionModel::query('contribution_request')->create([
            'type' => ContributionRequestType::Create,
            'subject_type' => $entityMetadata['subject_type'] ?? $subjectType,
            'entity_type' => $entityMetadata['entity_type'] ?? null,
            'entity_id' => $entityMetadata['entity_id'] ?? null,
            'proposer_id' => $proposer->getKey(),
            'status' => ContributionRequestStatus::Pending,
            'proposed_data' => $proposedData,
            'proposer_note' => $proposerNote,
        ]);
    }
}
