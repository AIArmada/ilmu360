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
use App\Features\Membership\Api\MembershipApplicationSummary;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\SubmitMembershipApplicationInput;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipApplicationReader;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        private MembershipApplicationReader $reader,
        private SubmitMembershipApplicationAction $submitApplication,
        private DiscardMembershipApplicationAction $discardApplication,
        private CancelMembershipApplicationAction $cancelApplication,
        private ApproveMembershipApplicationAction $approveApplication,
        private RejectMembershipApplicationAction $rejectApplication,
    ) {}

    public function forApplicant(string $accountId): array
    {
        return $this->summariesForIds($this->reader->applicationIdsForApplicant($accountId));
    }

    public function forReviewer(string $accountId): array
    {
        return $this->summariesForIds($this->reader->applicationIdsForReviewer($accountId));
    }

    public function forSubject(
        MembershipSubjectType $subjectType,
        string $subjectId,
        ?string $status = null,
    ): array {
        $query = $this->query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId);

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return $query
            ->latest('created_at')
            ->get()
            ->map(static fn (Model $application): MembershipApplicationSummary => MembershipValueMapper::applicationSummary($application))
            ->all();
    }

    public function find(string $applicationId): ?MembershipApplicationDetail
    {
        $application = $this->query()->find($applicationId);

        return $application instanceof Model
            ? MembershipValueMapper::applicationDetail($application)
            : null;
    }

    public function findForApplicant(string $accountId, string $applicationId): ?MembershipApplicationDetail
    {
        $applicationId = $this->reader->findApplicationIdForApplicant($accountId, $applicationId);

        if ($applicationId === null) {
            return null;
        }

        return $this->find($applicationId);
    }

    public function submit(SubmitMembershipApplicationInput $input): MembershipApplicationDetail
    {
        return MembershipValueMapper::applicationDetail($this->submitApplication->handle(
            $input->subjectType,
            $input->subjectId,
            $input->accountId,
            $input->justification,
            $input->meta,
        ));
    }

    public function discard(string $applicationId): void
    {
        $this->discardApplication->handle($applicationId);
    }

    public function cancelForApplicant(string $accountId, string $applicationId): ?MembershipApplicationDetail
    {
        $applicationId = $this->reader->findApplicationIdForApplicant($accountId, $applicationId);

        if ($applicationId === null) {
            return null;
        }

        $application = OwnerContext::withOwner(null, fn (): ?Model => $this->query()->find($applicationId));

        if (! $application instanceof PackageMembershipApplication) {
            return null;
        }

        OwnerContext::withOwner(null, function () use ($application): void {
            $this->cancelApplication->handle($application);
        });

        $cancelledApplication = OwnerContext::withOwner(null, fn (): Model => $this->query()->findOrFail($applicationId));

        return MembershipValueMapper::applicationDetail($cancelledApplication);
    }

    public function approve(
        string $applicationId,
        string $reviewerAccountId,
        string $grantedRole,
        ?string $note = null,
    ): MembershipApplicationDetail {
        return MembershipValueMapper::applicationDetail($this->approveApplication->handle(
            $applicationId,
            $reviewerAccountId,
            $grantedRole,
            $note,
        ));
    }

    public function reject(
        string $applicationId,
        string $reviewerAccountId,
        ?string $note = null,
    ): MembershipApplicationDetail {
        return MembershipValueMapper::applicationDetail($this->rejectApplication->handle(
            $applicationId,
            $reviewerAccountId,
            $note,
        ));
    }

    /**
     * @param  list<string>  $applicationIds
     * @return list<MembershipApplicationSummary>
     */
    private function summariesForIds(array $applicationIds): array
    {
        if ($applicationIds === []) {
            return [];
        }

        return $this->query()
            ->whereKey($applicationIds)
            ->latest('created_at')
            ->get()
            ->map(static fn (Model $application): MembershipApplicationSummary => MembershipValueMapper::applicationSummary($application))
            ->all();
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
