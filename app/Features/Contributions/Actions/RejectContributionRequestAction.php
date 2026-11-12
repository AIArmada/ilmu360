<?php

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Support\ContributionModel;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Shared\Contributions\ContributionRequestNotifier;
use App\Shared\Contributions\Enums\ContributionRequestType;
use App\Shared\Contributions\Exceptions\ContributionRequestNotPendingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class RejectContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ContributionRequestNotifier $contributionRequestNotifications,
        private readonly PersonWorkflow $personWorkflow,
        private readonly ReferenceWorkflow $referenceWorkflow,
    ) {}

    /**
     * @param  \App\Models\ContributionRequest  $request
     * @return \App\Models\ContributionRequest
     */
    public function handle(
        Model $request,
        Model $reviewer,
        string $reasonCode,
        ?string $reviewerNote = null,
    ): Model {
        if (! $request->isPending()) {
            throw new ContributionRequestNotPendingException('Only pending contribution requests can be rejected.');
        }

        $now = now();

        DB::transaction(function () use ($request, $reviewer, $reasonCode, $reviewerNote, $now): void {
            ContributionModel::query('contribution_request')->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $request->refresh();

            if (! $request->isPending()) {
                throw new ContributionRequestNotPendingException('Only pending contribution requests can be rejected.');
            }

            $request->forceFill([
                'reviewer_id' => $reviewer->getKey(),
                'reason_code' => $reasonCode,
                'reviewer_note' => $reviewerNote,
                'status' => 'rejected',
                'reviewed_at' => $now,
                'rejected_at' => $now,
                'last_state_change_at' => $now,
            ])->save();

            if ($request->type === ContributionRequestType::Create) {
                $entity = $request->entity;

                if ($entity instanceof Model && ContributionModel::isInstitution($entity)) {
                    app(InstitutionWorkflow::class)->changeStatus((string) $entity->getKey(), 'rejected');
                } elseif ($entity instanceof Model && ContributionModel::isPerson($entity)) {
                    $this->personWorkflow->changeStatus((string) $entity->getKey(), 'rejected');
                } elseif ($entity instanceof Model && ContributionModel::isReference($entity)) {
                    $this->referenceWorkflow->changeStatus((string) $entity->getKey(), 'rejected');
                }
            }
        });

        // Unapproved staged uploads must not linger on the rejected request.
        // This runs after the state transition commits: media file deletion is
        // immediate and cannot be rolled back with the rows.
        $request->clearMediaCollection('pending_media');

        $freshRequest = $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;

        $this->contributionRequestNotifications->notifyCreateRequestRejected($freshRequest);

        return $freshRequest;
    }
}
