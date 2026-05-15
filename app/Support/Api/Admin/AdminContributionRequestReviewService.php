<?php

declare(strict_types=1);

namespace App\Support\Api\Admin;

use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Contributions\RejectContributionRequestAction;
use App\Actions\Contributions\ResolveReviewableContributionRequestAction;
use App\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Models\ContributionRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class AdminContributionRequestReviewService
{
    public function __construct(
        private AdminResourceRegistry $registry,
        private ResolveReviewableContributionRequestAction $resolveReviewableContributionRequestAction,
        private ApproveContributionRequestAction $approveContributionRequestAction,
        private RejectContributionRequestAction $rejectContributionRequestAction,
    ) {}

    public function canReview(?User $actor = null): bool
    {
        return $actor instanceof User && $actor->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>, schema: array<string, mixed>}}
     */
    public function schema(string $recordKey, ?User $actor = null): array
    {
        abort_unless($this->canReview($actor), 403);
        abort_unless($actor instanceof User, 403);

        $request = $this->resolveContributionRequest($recordKey, $actor);

        return [
            'data' => [
                'resource' => $this->registry->metadata(ContributionRequestResource::class),
                'record' => $this->registry->serializeRecordDetail(ContributionRequestResource::class, $request),
                'schema' => [
                    'action' => 'review_contribution_request',
                    'method' => 'POST',
                    'endpoint' => route('api.admin.contribution-requests.review', ['recordKey' => $request->getRouteKey()], false),
                    'defaults' => [
                        'action' => 'approve',
                        'reason_code' => null,
                        'reviewer_note' => null,
                    ],
                    'fields' => [
                        [
                            'name' => 'action',
                            'type' => 'string',
                            'required' => true,
                            'default' => 'approve',
                            'allowed_values' => ['approve', 'reject'],
                        ],
                        [
                            'name' => 'reason_code',
                            'type' => 'string',
                            'required' => false,
                            'allowed_values' => array_keys(ContributionRequestPresenter::rejectionReasonOptions()),
                        ],
                        [
                            'name' => 'reviewer_note',
                            'type' => 'string',
                            'required' => false,
                            'max_length' => 2000,
                        ],
                    ],
                    'conditional_rules' => [
                        [
                            'field' => 'reason_code',
                            'required_when' => ['action' => ['reject']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>}}
     */
    public function review(string $recordKey, array $payload, ?User $actor = null): array
    {
        abort_unless($this->canReview($actor), 403);
        abort_unless($actor instanceof User, 403);

        $request = $this->resolveContributionRequest($recordKey, $actor);

        $validated = Validator::make($payload, [
            'action' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'reason_code' => [
                'nullable',
                'string',
                Rule::in(array_keys(ContributionRequestPresenter::rejectionReasonOptions())),
                Rule::requiredIf(static fn (): bool => ($payload['action'] ?? null) === 'reject'),
            ],
            'reviewer_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $action = (string) $validated['action'];
        $reviewerNote = filled($validated['reviewer_note'] ?? null) ? (string) $validated['reviewer_note'] : null;

        $request = match ($action) {
            'approve' => $this->approveContributionRequestAction->handle($request, $actor, $reviewerNote),
            'reject' => $this->rejectContributionRequestAction->handle($request, $actor, (string) $validated['reason_code'], $reviewerNote),
            default => throw new \InvalidArgumentException('Unsupported contribution-request review action.'),
        };

        return [
            'data' => [
                'resource' => $this->registry->metadata(ContributionRequestResource::class),
                'record' => $this->registry->serializeRecordDetail(ContributionRequestResource::class, $request),
            ],
        ];
    }

    private function resolveContributionRequest(string $recordKey, User $actor): ContributionRequest
    {
        $request = $this->resolveReviewableContributionRequestAction->handle($actor, $recordKey);

        return $request->loadMissing(['entity', 'proposer', 'reviewer']);
    }
}
