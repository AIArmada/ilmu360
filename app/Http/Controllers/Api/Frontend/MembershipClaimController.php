<?php

namespace App\Http\Controllers\Api\Frontend;

use AIArmada\Membership\Actions\ApplyForMembershipAction;
use AIArmada\Membership\Actions\CancelMembershipApplicationAction;
use AIArmada\Membership\Enums\ApplicationStatus;
use App\Enums\MemberSubjectType;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Membership\MembershipClaimPresenter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Group('MembershipClaim', 'Authenticated membership-claim endpoints for listing, creating, and cancelling subject membership claims.')]
class MembershipClaimController extends FrontendController
{
    #[Endpoint(
        title: 'List membership claims',
        description: 'Returns the current authenticated user\'s membership claims with review metadata and evidence links.',
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $claims = $user->membershipApplications()
            ->with(['reviewer', 'media'])
            ->latest('created_at')
            ->get();

        return response()->json([
            'data' => $claims->map(fn (MembershipApplication $claim): array => $this->claimData($claim, $user))->all(),
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Submit a membership claim',
        description: 'Creates a new membership claim with justification text and evidence uploads for the selected subject.',
    )]
    public function store(
        string $subjectType,
        string $subject,
        Request $request,
        ApplyForMembershipAction $applyForMembershipAction,
    ): JsonResponse {
        $resolvedSubjectType = MemberSubjectType::fromRouteSegment($subjectType);
        abort_unless($resolvedSubjectType?->isClaimable(), 404);

        $user = $this->requireUser($request);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $validated = $request->validate([
            'justification' => ['required', 'string', 'max:2000'],
        ]);

        $claimSubject = $resolvedSubjectType->resolveSubject($subject);

        try {
            /** @var MembershipApplication $claim */
            $claim = $applyForMembershipAction->handle(
                $claimSubject,
                $user,
                (string) $validated['justification'],
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'justification' => match ($exception->getMessage()) {
                    'membership_claim_already_member' => __('You are already a member of this record.'),
                    'membership_claim_duplicate_pending' => __('You already have a pending claim for this record.'),
                    'membership_claim_pending_invitation' => __('You already have a pending invitation for this record. Please accept that invitation instead.'),
                    default => __('The membership claim could not be submitted.'),
                },
            ]);
        }

        return response()->json([
            'data' => [
                'claim' => $this->claimData($claim->fresh(['reviewer', 'media']) ?? $claim, $user),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }

    #[Endpoint(
        title: 'Cancel a membership claim',
        description: 'Cancels one pending membership claim owned by the current authenticated user.',
    )]
    public function cancel(string $claimId, Request $request, CancelMembershipApplicationAction $cancelMembershipApplicationAction): JsonResponse
    {
        $user = $this->requireUser($request);

        $claim = $user->membershipApplications()
            ->with(['reviewer', 'media'])
            ->whereKey($claimId)
            ->first();

        abort_unless($claim instanceof MembershipApplication, 404);

        try {
            $cancelMembershipApplicationAction->handle($claim);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'claim' => __('Only pending claims can be cancelled.'),
            ]);
        }

        return response()->json([
            'data' => [
                'claim' => $this->claimData($claim->fresh(['reviewer', 'media']) ?? $claim, $user),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $subjectPresentation
     * @return array<string, mixed>
     */
    private function claimData(MembershipApplication $claim, User $currentUser, ?array $subjectPresentation = null): array
    {
        $subjectPresentation ??= MembershipClaimPresenter::subjectPresentation($claim);
        $evidenceItems = $claim->relationLoaded('media')
            ? $claim->media->where('collection_name', 'evidence')->values()
            : $claim->getMedia('evidence');

        return [
            'id' => $claim->getKey(),
            'subject_type' => $this->enumValue($claim->subject_type),
            'subject_label' => MembershipClaimPresenter::labelForSubject($claim->subject_type),
            'subject_title' => $subjectPresentation['subject_title'] ?? (string) $claim->subject_id,
            'subject_public_url' => $subjectPresentation['redirect_url'] ?? null,
            'status' => $this->enumValue($claim->status),
            'status_label' => MembershipClaimPresenter::labelForStatus($claim->status),
            'role_label' => MembershipClaimPresenter::roleLabel($claim),
            'justification' => $claim->justification,
            'granted_role' => $claim->granted_role,
            'reviewer_note' => $claim->reviewer_note,
            'created_at' => $this->optionalDateTimeString($claim->created_at),
            'reviewed_at' => $this->optionalDateTimeString($claim->reviewed_at),
            'cancelled_at' => $this->optionalDateTimeString($claim->cancelled_at),
            'can_cancel' => $claim->status === ApplicationStatus::Pending && (string) $claim->applicant_id === (string) $currentUser->getKey(),
            'reviewer' => $claim->reviewer?->only(['id', 'name', 'email']),
            'evidence' => $evidenceItems->map(fn (Media $media): array => [
                'id' => $media->getKey(),
                'name' => $media->name !== '' ? $media->name : $media->file_name,
                'url' => $media->getAvailableUrl(['thumb']) ?: $media->getUrl(),
            ])->all(),
        ];
    }
}
