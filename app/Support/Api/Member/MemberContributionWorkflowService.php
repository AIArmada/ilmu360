<?php

declare(strict_types=1);

namespace App\Support\Api\Member;

use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Contributions\CancelContributionRequestAction;
use App\Actions\Contributions\RejectContributionRequestAction;
use App\Actions\Contributions\ResolveContributionSubjectPresentationAction;
use App\Actions\Contributions\ResolveOwnContributionRequestAction;
use App\Actions\Contributions\ResolvePendingContributionApprovalsAction;
use App\Actions\Contributions\ResolveReviewableContributionRequestAction;
use App\Enums\ContributionRequestStatus;
use App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class MemberContributionWorkflowService
{
    public function __construct(
        private ResolvePendingContributionApprovalsAction $resolvePendingContributionApprovalsAction,
        private ResolveOwnContributionRequestAction $resolveOwnContributionRequestAction,
        private ResolveReviewableContributionRequestAction $resolveReviewableContributionRequestAction,
        private ResolveContributionSubjectPresentationAction $resolveContributionSubjectPresentationAction,
        private ApproveContributionRequestAction $approveContributionRequestAction,
        private RejectContributionRequestAction $rejectContributionRequestAction,
        private CancelContributionRequestAction $cancelContributionRequestAction,
    ) {}

    /**
     * @return array{data: array{my_requests: list<array<string, mixed>>, pending_approvals: list<array<string, mixed>>}}
     */
    public function list(User $actor): array
    {
        $myRequests = $actor->contributionRequests()
            ->with(['entity', 'proposer', 'reviewer'])
            ->latest('created_at')
            ->get();

        $pendingApprovals = $this->resolvePendingContributionApprovalsAction->handle($actor);

        return [
            'data' => [
                'my_requests' => $myRequests->map(fn (ContributionRequest $request): array => $this->requestData($request, $actor))->all(),
                'pending_approvals' => $pendingApprovals->map(fn (ContributionRequest $request): array => $this->requestData($request, $actor))->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array{request: array<string, mixed>}}
     */
    public function approve(string $requestId, array $payload, User $actor): array
    {
        $validated = Validator::make($payload, [
            'reviewer_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $request = $this->resolveReviewableContributionRequestAction->handle($actor, $requestId);
        $approvedRequest = $this->approveContributionRequestAction->handle(
            $request,
            $actor,
            $validated['reviewer_note'] ?? null,
        );

        return [
            'data' => [
                'request' => $this->requestData($approvedRequest, $actor),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array{request: array<string, mixed>}}
     */
    public function reject(string $requestId, array $payload, User $actor): array
    {
        $validated = Validator::make($payload, [
            'reason_code' => ['required', 'string', Rule::in(array_keys(ContributionRequestPresenter::rejectionReasonOptions()))],
            'reviewer_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $request = $this->resolveReviewableContributionRequestAction->handle($actor, $requestId);
        $rejectedRequest = $this->rejectContributionRequestAction->handle(
            $request,
            $actor,
            (string) $validated['reason_code'],
            $validated['reviewer_note'] ?? null,
        );

        return [
            'data' => [
                'request' => $this->requestData($rejectedRequest, $actor),
            ],
        ];
    }

    /**
     * @return array{data: array{request: array<string, mixed>}}
     */
    public function cancel(string $requestId, User $actor): array
    {
        $request = $this->resolveOwnContributionRequestAction->handle($actor, $requestId);
        $cancelledRequest = $this->cancelContributionRequestAction->handle($request, $actor);

        return [
            'data' => [
                'request' => $this->requestData($cancelledRequest, $actor),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestData(ContributionRequest $request, User $currentUser): array
    {
        $presentation = $request->entity instanceof Event
            || $request->entity instanceof Institution
            || $request->entity instanceof Reference
            || $request->entity instanceof Speaker
                ? $this->resolveContributionSubjectPresentationAction->handle($request->entity)
                : null;

        return [
            'id' => $request->getKey(),
            'type' => $this->enumValue($request->type),
            'type_label' => ContributionRequestPresenter::labelForType($request->type),
            'subject_type' => $this->enumValue($request->subject_type),
            'subject_label' => ContributionRequestPresenter::labelForSubject($request->subject_type),
            'entity_title' => ContributionRequestPresenter::entityTitle($request),
            'status' => $this->enumValue($request->status),
            'status_label' => ContributionRequestPresenter::labelForStatus($request->status),
            'reason_code' => $request->reason_code,
            'proposer_note' => $request->proposer_note,
            'reviewer_note' => $request->reviewer_note,
            'changed_fields' => array_keys($request->proposed_data ?? []),
            'created_at' => $this->optionalDateTimeString($request->created_at),
            'reviewed_at' => $this->optionalDateTimeString($request->reviewed_at),
            'cancelled_at' => $this->optionalDateTimeString($request->cancelled_at),
            'can_cancel' => $request->isPending() && (string) $request->proposer_id === (string) $currentUser->getKey(),
            'can_review' => $request->status === ContributionRequestStatus::Pending,
            'presentation' => $presentation,
            'proposer' => $request->proposer?->only(['id', 'name', 'email']),
            'reviewer' => $request->reviewer?->only(['id', 'name', 'email']),
        ];
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function optionalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
