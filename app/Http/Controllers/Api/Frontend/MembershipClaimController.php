<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Actions\Membership\CancelMembershipClaimAction;
use App\Actions\Membership\ResolveMembershipClaimSubjectAction;
use App\Actions\Membership\ResolveMembershipClaimSubjectPresentationAction;
use App\Actions\Membership\SubmitMembershipClaimAction;
use App\Enums\MemberSubjectType;
use App\Models\MembershipClaim;
use App\Models\User;
use App\Support\Api\Frontend\FrontendMediaSyncService;
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

        $claims = $user->membershipClaims()
            ->with(['reviewer', 'media'])
            ->latest('created_at')
            ->get();

        return response()->json([
            'data' => $claims->map(fn (MembershipClaim $claim): array => $this->claimData($claim, $user))->all(),
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
        ResolveMembershipClaimSubjectAction $resolveMembershipClaimSubjectAction,
        ResolveMembershipClaimSubjectPresentationAction $resolveMembershipClaimSubjectPresentationAction,
        SubmitMembershipClaimAction $submitMembershipClaimAction,
        FrontendMediaSyncService $frontendMediaSyncService,
    ): JsonResponse {
        $resolvedSubjectType = MemberSubjectType::fromRouteSegment($subjectType);
        abort_unless($resolvedSubjectType?->isClaimable(), 404);

        $user = $this->requireUser($request);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $maxUploadSizeKb = (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);

        $validated = $request->validate([
            'justification' => ['required', 'string', 'max:2000'],
            'evidence' => ['required', 'array', 'min:1', 'max:8'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', "max:{$maxUploadSizeKb}"],
        ]);

        $claimSubject = $resolveMembershipClaimSubjectAction->handle($subjectType, $subject);

        try {
            $claim = $submitMembershipClaimAction->handle(
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

        $frontendMediaSyncService->syncMultiple(
            $claim,
            is_array($request->file('evidence')) ? $request->file('evidence') : null,
            'evidence',
        );

        $presentation = $resolveMembershipClaimSubjectPresentationAction->handle($claimSubject);

        return response()->json([
            'data' => [
                'claim' => $this->claimData($claim->fresh(['reviewer', 'media']) ?? $claim, $user, $presentation),
                'subject' => $presentation,
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
    public function cancel(string $claimId, Request $request, CancelMembershipClaimAction $cancelMembershipClaimAction): JsonResponse
    {
        $user = $this->requireUser($request);

        $claim = $user->membershipClaims()
            ->with(['reviewer', 'media'])
            ->whereKey($claimId)
            ->first();

        abort_unless($claim instanceof MembershipClaim, 404);

        try {
            $claim = $cancelMembershipClaimAction->handle($claim, $user);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'claim' => __('Only pending claims can be cancelled.'),
            ]);
        }

        return response()->json([
            'data' => [
                'claim' => $this->claimData($claim, $user),
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
    private function claimData(MembershipClaim $claim, User $currentUser, ?array $subjectPresentation = null): array
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
            'granted_role_slug' => $claim->granted_role_slug,
            'reviewer_note' => $claim->reviewer_note,
            'created_at' => $this->optionalDateTimeString($claim->created_at),
            'reviewed_at' => $this->optionalDateTimeString($claim->reviewed_at),
            'cancelled_at' => $this->optionalDateTimeString($claim->cancelled_at),
            'can_cancel' => $claim->isPending() && (string) $claim->claimant_id === (string) $currentUser->getKey(),
            'reviewer' => $claim->reviewer?->only(['id', 'name', 'email']),
            'evidence' => $evidenceItems->map(fn (Media $media): array => [
                'id' => $media->getKey(),
                'name' => $media->name !== '' ? $media->name : $media->file_name,
                'url' => $media->getAvailableUrl(['thumb']) ?: $media->getUrl(),
            ])->all(),
        ];
    }
}
