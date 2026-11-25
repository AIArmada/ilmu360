<?php

declare(strict_types=1);

namespace App\Features\Contributions\EntryPoints\Member;

use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Api\ContributionReviewAccess;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Features\Contributions\EntryPoints\Ui\ContributionSubjectPresenter;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class MemberContributionWorkflowService
{
    public function __construct(
        private ContributionRequestReader $contributionRequestReader,
        private ContributionReviewAccess $contributionReviewAccess,
        private ContributionSubjectPresenter $contributionSubjectPresenter,
        private ContributionRequestLifecycle $contributionRequestLifecycle,
    ) {}

    /**
     * @return array{data: array{my_requests: list<array<string, mixed>>, pending_approvals: list<array<string, mixed>>}}
     */
    public function list(User $actor): array
    {
        $requestIds = $this->contributionRequestReader->requestIdsForProposer((string) $actor->getKey());

        $myRequests = $requestIds === []
            ? ContributionRequest::query()->whereRaw('1 = 0')->get()
            : ContributionRequest::query()
                ->whereKey($requestIds)
                ->with(['entity', 'proposer', 'reviewer'])
                ->latest('created_at')
                ->get();

        $pendingApprovals = ContributionRequest::query()
            ->whereKey($this->contributionReviewAccess->pendingRequestIds((string) $actor->getKey()))
            ->with(['entity', 'proposer'])
            ->latest('created_at')
            ->get();

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

        $request = ContributionRequest::query()->with('entity')->findOrFail(
            $this->contributionReviewAccess->reviewableRequestId((string) $actor->getKey(), $requestId)
        );
        $this->contributionRequestLifecycle->approve(
            (string) $request->getKey(),
            (string) $actor->getKey(),
            $validated['reviewer_note'] ?? null,
        );

        $request->refresh();

        return [
            'data' => [
                'request' => $this->requestData($request, $actor),
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

        $request = ContributionRequest::query()->with('entity')->findOrFail(
            $this->contributionReviewAccess->reviewableRequestId((string) $actor->getKey(), $requestId)
        );
        $this->contributionRequestLifecycle->reject(
            (string) $request->getKey(),
            (string) $actor->getKey(),
            (string) $validated['reason_code'],
            $validated['reviewer_note'] ?? null,
        );

        $request->refresh();

        return [
            'data' => [
                'request' => $this->requestData($request, $actor),
            ],
        ];
    }

    /**
     * @return array{data: array{request: array<string, mixed>}}
     */
    public function cancel(string $requestId, User $actor): array
    {
        $request = ContributionRequest::query()->with('entity')->findOrFail(
            $this->contributionRequestReader->findRequestIdForProposer((string) $actor->getKey(), $requestId)
        );
        $this->contributionRequestLifecycle->cancel((string) $request->getKey(), (string) $actor->getKey());

        $request->refresh();

        return [
            'data' => [
                'request' => $this->requestData($request, $actor),
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
            || $request->entity instanceof Person
                ? $this->contributionSubjectPresenter->present($request->entity)
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
            'can_review' => $request->isPending() && Gate::forUser($currentUser)->allows('review', $request),
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
