<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\CancelMembershipApplicationAction;
use AIArmada\Membership\Models\MembershipApplication as PackageMembershipApplication;
use App\Features\Membership\Actions\ApproveMembershipApplicationAction;
use App\Features\Membership\Actions\DiscardMembershipApplicationAction;
use App\Features\Membership\Actions\RejectMembershipApplicationAction;
use App\Features\Membership\Actions\SubmitMembershipApplicationAction;
use App\Features\Membership\Api\MembershipApplicationDetail;
use App\Features\Membership\Api\MembershipApplicationFormRules;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\SubmitMembershipApplicationInput;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Media\StagedUploadFactory;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Media\StagedUpload;
use App\Shared\Membership\MembershipSubjectCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

/**
 * Membership application boundary: account-scoped reads plus submit, discard,
 * cancel and reviewer approve/reject orchestration.
 *
 * The package Apply/Cancel/Approve/Reject actions remain the canonical
 * membership_applications lifecycle writers. This service owns only the
 * app-side guards, media cleanup and owner-context handling around them.
 */
final readonly class MembershipApplicationsService implements MembershipApplications
{
    public function __construct(
        private SubmitMembershipApplicationAction $submitApplication,
        private DiscardMembershipApplicationAction $discardApplication,
        private CancelMembershipApplicationAction $cancelApplication,
        private ApproveMembershipApplicationAction $approveApplication,
        private RejectMembershipApplicationAction $rejectApplication,
        private MembershipSubjectCatalog $subjectCatalog,
        private MediaCollectionSync $mediaCollectionSync,
        private StagedUploadFactory $stagedUploads,
    ) {}

    public function forApplicant(string $accountId): array
    {
        return OwnerContext::withOwner(null, fn (): array => $this->query()
            ->where('applicant_id', $accountId)
            ->with(['reviewer', 'media'])
            ->latest('created_at')
            ->get()
            ->map(fn (Model $application): MembershipApplicationDetail => MembershipValueMapper::applicationDetail($application, $this->subjectCatalog))
            ->all());
    }

    public function reviewRoleOptions(): array
    {
        return MembershipApplicationFormRules::applicationRoleOptions();
    }

    public function find(string $applicationId): ?MembershipApplicationDetail
    {
        $application = OwnerContext::withOwner(null, fn (): ?Model => $this->query()
            ->with(['reviewer', 'media'])
            ->find($applicationId));

        return $application instanceof Model
            ? MembershipValueMapper::applicationDetail($application, $this->subjectCatalog)
            : null;
    }

    public function submit(SubmitMembershipApplicationInput $input): MembershipApplicationDetail
    {
        $application = $this->submitApplication->handle(
            $input->subjectType,
            $input->subjectId,
            $input->accountId,
            $input->justification,
            $input->meta,
        );

        try {
            OwnerContext::withOwner(null, function () use ($application, $input): void {
                if ($input->evidenceFiles === []) {
                    return;
                }

                if (! $application instanceof HasMedia) {
                    throw new RuntimeException('Membership applications must support evidence media.');
                }

                $files = array_map(
                    fn (StagedUpload $upload): UploadedFile => $this->stagedUploads->toUploadedFile($upload),
                    $input->evidenceFiles,
                );

                $this->mediaCollectionSync->syncMultiple($application, $files, 'evidence', replace: true);
            });
        } catch (Throwable $exception) {
            try {
                $this->discardApplication->handle((string) $application->getKey());
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        return $this->find((string) $application->getKey())
            ?? throw new RuntimeException('Submitted membership application could not be reloaded.');
    }

    public function discard(string $applicationId): void
    {
        $this->discardApplication->handle($applicationId);
    }

    public function cancelForApplicant(string $accountId, string $applicationId): ?MembershipApplicationDetail
    {
        $application = OwnerContext::withOwner(null, fn (): ?Model => $this->query()
            ->whereKey($applicationId)
            ->where('applicant_id', $accountId)
            ->first());

        if (! $application instanceof PackageMembershipApplication) {
            return null;
        }

        OwnerContext::withOwner(null, function () use ($application): void {
            $this->cancelApplication->handle($application);
        });

        return $this->find($applicationId);
    }

    public function approve(
        string $applicationId,
        string $reviewerAccountId,
        string $grantedRole,
        ?string $note = null,
    ): MembershipApplicationDetail {
        $application = $this->approveApplication->handle(
            $applicationId,
            $reviewerAccountId,
            $grantedRole,
            $note,
        );

        return $this->find((string) $application->getKey())
            ?? throw new RuntimeException('Reviewed membership application could not be reloaded.');
    }

    public function reject(
        string $applicationId,
        string $reviewerAccountId,
        ?string $note = null,
    ): MembershipApplicationDetail {
        $application = $this->rejectApplication->handle(
            $applicationId,
            $reviewerAccountId,
            $note,
        );

        return $this->find((string) $application->getKey())
            ?? throw new RuntimeException('Reviewed membership application could not be reloaded.');
    }

    /**
     * @return Builder<\App\Models\MembershipApplication>
     */
    private function query(): Builder
    {
        $modelClass = MorphModelResolver::resolve('membership_application');

        return $modelClass::query();
    }
}
