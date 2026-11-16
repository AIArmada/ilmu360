<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Http;

use App\Shared\Membership\MembershipSubjectCatalog;
use App\Features\Membership\Api\MembershipApplicationDetail;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipApplicationSubjectType;
use App\Features\Membership\Api\SubmitMembershipApplicationInput;
use App\Platform\Http\FrontendController;
use App\Platform\Media\StagedUploadFactory;
use App\Shared\Feedback\FeedbackActor;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

#[Group('MembershipApplication', 'Authenticated membership-application endpoints for listing, creating, and cancelling subject membership applications.')]
class MembershipApplicationController extends FrontendController
{
    #[Endpoint(
        title: 'List membership applications',
        description: 'Returns the current authenticated user\'s membership applications with review metadata and evidence links.',
    )]
    public function index(Request $request, MembershipApplications $membershipApplications): JsonResponse
    {
        $account = $this->requireAccountActor($request);
        $accountId = $account->actorId();

        return response()->json([
            'data' => array_map(
                static fn (MembershipApplicationDetail $application): array => $application->applicantPayload($accountId),
                $membershipApplications->forApplicant($accountId),
            ),
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
        MembershipSubjectCatalog $subjectCatalog,
        StagedUploadFactory $stagedUploadFactory,
    ): JsonResponse {
        $resolvedSubjectType = MembershipApplicationSubjectType::fromPublicRouteSegment($subjectType);
        abort_unless($resolvedSubjectType instanceof MembershipApplicationSubjectType, 404);

        $account = $this->requireAccountActor($request);
        abort_unless($account instanceof FeedbackActor, 403);

        if (! $account->canSubmitDirectoryFeedback()) {
            abort(403, $account->directoryFeedbackBanMessage());
        }

        $maxUploadSizeKb = (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);

        $validated = $request->validate([
            'justification' => ['required', 'string', 'max:2000'],
            'evidence' => ['required', 'array', 'min:1', 'max:8'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', "max:{$maxUploadSizeKb}"],
        ]);

        $subjectId = $subjectCatalog->resolveIdentifier($resolvedSubjectType->membershipSubjectType(), $subject);
        abort_unless(is_string($subjectId), 404);

        try {
            $detail = $membershipApplications->submit(new SubmitMembershipApplicationInput(
                subjectType: $resolvedSubjectType->membershipSubjectType(),
                subjectId: $subjectId,
                accountId: $account->actorId(),
                justification: (string) $validated['justification'],
                evidenceFiles: $this->stageEvidenceFiles($validated['evidence'], $stagedUploadFactory),
            ));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'justification' => match ($exception->getMessage()) {
                    'membership_application_already_member' => __('You are already a member of this record.'),
                    'membership_application_duplicate_pending' => __('You already have a pending claim for this record.'),
                    'membership_application_pending_invitation' => __('You already have a pending invitation for this record. Please accept that invitation instead.'),
                    default => __('The membership application could not be submitted.'),
                },
            ]);
        }

        return response()->json([
            'data' => [
                'application' => $detail->applicantPayload($account->actorId()),
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
        $account = $this->requireAccountActor($request);

        try {
            $detail = $membershipApplications->cancelForApplicant(
                $account->actorId(),
                $applicationId,
            );
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'application' => __('Only pending applications can be cancelled.'),
            ]);
        }

        abort_unless($detail instanceof MembershipApplicationDetail, 404);

        return response()->json([
            'data' => [
                'application' => $detail->applicantPayload($account->actorId()),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    /**
     * @return list<\App\Shared\Media\StagedUpload>
     */
    private function stageEvidenceFiles(mixed $files, StagedUploadFactory $stagedUploadFactory): array
    {
        if (! is_array($files)) {
            throw ValidationException::withMessages([
                'evidence' => [__('Please upload valid evidence files.')],
            ]);
        }

        $uploads = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'evidence' => [__('Please upload valid evidence files.')],
                ]);
            }

            try {
                $uploads[] = $stagedUploadFactory->fromUploadedFile($file);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'evidence' => [__('Please upload valid evidence files.')],
                ]);
            }
        }

        return $uploads;
    }
}
