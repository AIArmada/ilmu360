<?php

namespace App\Features\Contributions\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Exceptions\ContributionRequestNotPendingException;
use App\Features\Contributions\Support\ContributionModel;
use App\Features\Contributions\Support\ContributionRequestNotifier;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\References\Api\ReferenceWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class RejectContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ContributionRequestNotifier $contributionRequestNotifications,
        private readonly PersonWorkflow $personWorkflow,
        private readonly ReferenceWorkflow $referenceWorkflow,
        private readonly InstitutionWorkflow $institutionWorkflow,
    ) {}

    /**
     * @param  \App\Features\Contributions\Models\ContributionRequest  $request
     * @return \App\Features\Contributions\Models\ContributionRequest
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

        OwnerContext::withOwner(null, function () use ($request, $reviewer, $reasonCode, $reviewerNote, $now): void {
            DB::transaction(function () use ($request, $reviewer, $reasonCode, $reviewerNote, $now): void {
                ContributionModel::query('contribution_request')->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
                $request->refresh();

                if (! $request->isPending()) {
                    throw new ContributionRequestNotPendingException('Only pending contribution requests can be rejected.');
                }

                if ($request->type === ContributionRequestType::Update
                    && (string) $request->proposer_id === (string) $reviewer->getKey()) {
                    throw new AuthorizationException(__('A different reviewer must review this update.'));
                }

                $request->forceFill([
                    'reviewer_id' => $reviewer->getKey(),
                    'reason_code' => $reasonCode,
                    'reviewer_note' => $reviewerNote,
                    'status' => ContributionRequestStatus::Rejected,
                    'reviewed_at' => $now,
                    'rejected_at' => $now,
                    'last_state_change_at' => $now,
                ])->save();

                if ($request->type === ContributionRequestType::Create) {
                    $entity = $request->entity;

                    if ($entity instanceof Model && ContributionModel::isInstitution($entity)) {
                        $this->institutionWorkflow->changeStatus((string) $entity->getKey(), 'rejected');
                    } elseif ($entity instanceof Model && ContributionModel::isPerson($entity)) {
                        $this->personWorkflow->changeStatus((string) $entity->getKey(), 'rejected');
                    } elseif ($entity instanceof Model && ContributionModel::isReference($entity)) {
                        $this->referenceWorkflow->changeStatus((string) $entity->getKey(), 'rejected');
                    }
                }
            });
        });

        // Unapproved staged uploads must not linger on the rejected request.
        // This runs after the state transition commits: media file deletion is
        // immediate and cannot be rolled back with the rows.
        $request->clearMediaCollection('pending_media');

        $freshRequest = $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;

        try {
            $this->contributionRequestNotifications->notifyRequestRejected($freshRequest);
        } catch (Throwable $exception) {
            Log::warning('Contribution rejection notification failed after commit.', [
                'contribution_request_id' => (string) $freshRequest->getKey(),
                'exception' => $exception,
            ]);
        }

        return $freshRequest;
    }
}
