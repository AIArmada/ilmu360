<?php

namespace App\Http\Controllers\Api\Frontend;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\ApplicationStatus;
use App\Enums\MemberSubjectType;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipApplicationSummary;
use App\Features\Membership\Api\SubmitMembershipApplicationInput;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Platform\Media\MediaCollectionSync;
use App\Shared\Membership\MembershipSubjectType;
use App\Support\Membership\MembershipApplicationPresenter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

#[Group('MembershipApplication', 'Authenticated membership-application endpoints for listing, creating, and cancelling subject membership applications.')]
class MembershipApplicationController extends FrontendController
{
    #[Endpoint(
        title: 'List membership applications',
        description: 'Returns the current authenticated user\'s membership applications with review metadata and evidence links.',
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $applicationIds = array_map(
            static fn (MembershipApplicationSummary $summary): string => $summary->id,
            app(MembershipApplications::class)->forApplicant((string) $user->getKey()),
        );

        $applications = $applicationIds === []
            ? MembershipApplication::query()->whereRaw('1 = 0')->with(['reviewer', 'media'])->get()
            : MembershipApplication::query()
                ->whereKey($applicationIds)
                ->with(['reviewer', 'media'])
                ->latest('created_at')
                ->get();

        return response()->json([
            'data' => $applications->map(fn (MembershipApplication $application): array => $this->applicationData($application, $user))->all(),
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Submit a membership application',
        description: 'Creates a new membership application with justification text and evidence uploads for the selected subject.',
    )]
    public function store(
        string $subjectType,
        string $subject,
        Request $request,
        MembershipApplications $membershipApplications,
        MediaCollectionSync $mediaCollectionSync,
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

        $claimSubject = $resolvedSubjectType->resolveSubject($subject);
        $application = null;

        try {
            $detail = $membershipApplications->submit(new SubmitMembershipApplicationInput(
                subjectType: MembershipSubjectType::from($resolvedSubjectType->value),
                subjectId: (string) $claimSubject->getKey(),
                accountId: (string) $user->getKey(),
                justification: (string) $validated['justification'],
            ));

            $application = OwnerContext::withOwner(null, fn (): MembershipApplication => MembershipApplication::query()
                ->findOrFail($detail->application->id));

            $mediaCollectionSync->syncMultiple(
                $application,
                is_array($request->file('evidence')) ? $request->file('evidence') : null,
                'evidence',
                replace: true,
            );
        } catch (Throwable $exception) {
            if ($application instanceof MembershipApplication) {
                try {
                    $membershipApplications->discard((string) $application->getKey());
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            if (! $exception instanceof RuntimeException) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'justification' => match ($exception->getMessage()) {
                    'membership_claim_already_member' => __('You are already a member of this record.'),
                    'membership_claim_duplicate_pending' => __('You already have a pending application for this record.'),
                    'membership_claim_pending_invitation' => __('You already have a pending invitation for this record. Please accept that invitation instead.'),
                    default => __('The membership application could not be submitted.'),
                },
            ]);
        }

        return response()->json([
            'data' => [
                'application' => $this->applicationData($application->load(['reviewer', 'media']), $user),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }

    #[Endpoint(
        title: 'Cancel a membership application',
        description: 'Cancels one pending membership application owned by the current authenticated user.',
    )]
    public function cancel(string $applicationId, Request $request, MembershipApplications $membershipApplications): JsonResponse
    {
        $user = $this->requireUser($request);

        try {
            $detail = $membershipApplications->cancelForApplicant(
                (string) $user->getKey(),
                $applicationId,
            );
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'application' => __('Only pending applications can be cancelled.'),
            ]);
        }

        abort_unless($detail !== null, 404);

        $application = OwnerContext::withOwner(null, fn (): MembershipApplication => MembershipApplication::query()
            ->whereKey($detail->application->id)
            ->with(['reviewer', 'media'])
            ->firstOrFail());

        return response()->json([
            'data' => [
                'application' => $this->applicationData($application, $user),
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
    private function applicationData(MembershipApplication $application, User $currentUser, ?array $subjectPresentation = null): array
    {
        $subjectPresentation ??= MembershipApplicationPresenter::subjectPresentation($application);
        $evidenceItems = $application->relationLoaded('media')
            ? $application->media->where('collection_name', 'evidence')->values()
            : $application->getMedia('evidence');

        return [
            'id' => $application->getKey(),
            'subject_type' => $this->enumValue($application->subject_type),
            'subject_label' => MembershipApplicationPresenter::labelForSubject($application->subject_type),
            'subject_title' => $subjectPresentation['subject_title'] ?? (string) $application->subject_id,
            'subject_public_url' => $subjectPresentation['redirect_url'] ?? null,
            'status' => $this->enumValue($application->status),
            'status_label' => MembershipApplicationPresenter::labelForStatus($application->status),
            'role_label' => MembershipApplicationPresenter::roleLabel($application),
            'justification' => $application->justification,
            'granted_role' => $application->granted_role,
            'reviewer_note' => $application->reviewer_note,
            'created_at' => $this->optionalDateTimeString($application->created_at),
            'reviewed_at' => $this->optionalDateTimeString($application->reviewed_at),
            'cancelled_at' => $this->optionalDateTimeString($application->cancelled_at),
            'can_cancel' => $application->status === ApplicationStatus::Pending && (string) $application->applicant_id === (string) $currentUser->getKey(),
            'reviewer' => $application->reviewer?->only(['id', 'name', 'email']),
            'evidence' => $evidenceItems->map(fn (Media $media): array => [
                'id' => $media->getKey(),
                'name' => $media->name !== '' ? $media->name : $media->file_name,
                'url' => $media->getAvailableUrl(['thumb']) ?: $media->getUrl(),
            ])->all(),
        ];
    }
}
