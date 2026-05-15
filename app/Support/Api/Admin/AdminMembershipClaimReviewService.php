<?php

declare(strict_types=1);

namespace App\Support\Api\Admin;

use App\Actions\Membership\ApproveMembershipClaimAction;
use App\Actions\Membership\RejectMembershipClaimAction;
use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use App\Models\MembershipClaim;
use App\Models\User;
use App\Support\Membership\MembershipClaimPresenter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class AdminMembershipClaimReviewService
{
    public function __construct(
        private AdminResourceRegistry $registry,
        private ApproveMembershipClaimAction $approveMembershipClaimAction,
        private RejectMembershipClaimAction $rejectMembershipClaimAction,
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

        $claim = $this->resolveClaim($recordKey);

        return [
            'data' => [
                'resource' => $this->registry->metadata(MembershipClaimResource::class),
                'record' => $this->registry->serializeRecordDetail(MembershipClaimResource::class, $claim),
                'schema' => [
                    'action' => 'review_membership_claim',
                    'method' => 'POST',
                    'endpoint' => route('api.admin.membership-claims.review', ['recordKey' => $claim->getRouteKey()], false),
                    'defaults' => [
                        'action' => 'approve',
                        'granted_role_slug' => null,
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
                            'name' => 'granted_role_slug',
                            'type' => 'string',
                            'required' => false,
                            'allowed_values' => array_keys(MembershipClaimPresenter::approvalRoleOptions($claim)),
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
                            'field' => 'granted_role_slug',
                            'required_when' => ['action' => ['approve']],
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

        $claim = $this->resolveClaim($recordKey);

        $validated = Validator::make($payload, [
            'action' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'granted_role_slug' => [
                'nullable',
                'string',
                Rule::in(array_keys(MembershipClaimPresenter::approvalRoleOptions($claim))),
                Rule::requiredIf(static fn (): bool => ($payload['action'] ?? null) === 'approve'),
            ],
            'reviewer_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $action = (string) $validated['action'];
        $reviewerNote = filled($validated['reviewer_note'] ?? null) ? (string) $validated['reviewer_note'] : null;

        $claim = match ($action) {
            'approve' => $this->approveMembershipClaimAction->handle(
                $claim,
                $actor,
                (string) $validated['granted_role_slug'],
                $reviewerNote,
            ),
            'reject' => $this->rejectMembershipClaimAction->handle(
                $claim,
                $actor,
                $reviewerNote,
            ),
            default => throw new \InvalidArgumentException('Unsupported membership-claim review action.'),
        };

        return [
            'data' => [
                'resource' => $this->registry->metadata(MembershipClaimResource::class),
                'record' => $this->registry->serializeRecordDetail(MembershipClaimResource::class, $claim),
            ],
        ];
    }

    private function resolveClaim(string $recordKey): MembershipClaim
    {
        /** @var MembershipClaim $claim */
        $claim = $this->registry->resolveRecord(MembershipClaimResource::class, $recordKey);

        return $claim->loadMissing(['claimant', 'reviewer', 'media']);
    }
}
