<?php

namespace App\Actions\Contributions;

use App\Enums\ContributionRequestType;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Services\Notifications\ContributionRequestNotificationService;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class RejectContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ContributionRequestNotificationService $contributionRequestNotificationService,
    ) {}

    public function handle(
        ContributionRequest $request,
        User $reviewer,
        string $reasonCode,
        ?string $reviewerNote = null,
    ): ContributionRequest {
        if (! $request->isPending()) {
            throw new RuntimeException('Only pending contribution requests can be rejected.');
        }

        $request->forceFill([
            'reviewer_id' => $reviewer->getKey(),
            'reason_code' => $reasonCode,
            'reviewer_note' => $reviewerNote,
            'status' => 'rejected',
            'reviewed_at' => now(),
        ])->save();

        if ($request->type === ContributionRequestType::Create) {
            $entity = $request->entity;

            if ($entity instanceof Institution || $entity instanceof Speaker) {
                $entity->forceFill([
                    'status' => 'rejected',
                    'is_active' => false,
                ])->save();
            }
        }

        $freshRequest = $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;

        $this->contributionRequestNotificationService->notifyCreateRequestRejected($freshRequest);

        return $freshRequest;
    }
}
