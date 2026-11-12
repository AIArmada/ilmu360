<?php

namespace App\Actions\Contributions\Review;

use App\Shared\Contributions\Enums\ContributionRequestType;
use App\Shared\Contributions\Exceptions\ContributionRequestNotPendingException;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Services\Notifications\ContributionRequestNotificationService;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class RejectContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ContributionRequestNotificationService $contributionRequestNotificationService,
        private readonly PersonWorkflow $personWorkflow,
        private readonly ReferenceWorkflow $referenceWorkflow,
    ) {}

    public function handle(
        ContributionRequest $request,
        User $reviewer,
        string $reasonCode,
        ?string $reviewerNote = null,
    ): ContributionRequest {
        if (! $request->isPending()) {
            throw new ContributionRequestNotPendingException('Only pending contribution requests can be rejected.');
        }

        $now = now();

        DB::transaction(function () use ($request, $reviewer, $reasonCode, $reviewerNote, $now): void {
            ContributionRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
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

                if ($entity instanceof Institution) {
                    app(InstitutionWorkflow::class)->changeStatus((string) $entity->getKey(), 'rejected');
                } elseif ($entity instanceof Person) {
                    $this->personWorkflow->changeStatus((string) $entity->getKey(), 'rejected');
                } elseif ($entity instanceof Reference) {
                    $this->referenceWorkflow->changeStatus((string) $entity->getKey(), 'rejected');
                }
            }
        });

        // Unapproved staged uploads must not linger on the rejected request.
        // This runs after the state transition commits: media file deletion is
        // immediate and cannot be rolled back with the rows.
        $request->clearMediaCollection('pending_media');

        $freshRequest = $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;

        $this->contributionRequestNotificationService->notifyCreateRequestRejected($freshRequest);

        return $freshRequest;
    }
}
