<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use App\Features\Membership\Api\MembershipApplicationDetail;
use App\Features\Membership\Api\MembershipApplicationSummary;
use App\Features\Membership\Api\MembershipApplications;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipApplicationReader;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Model;

/**
 * Read adapter over membership applications using the existing applicant
 * reader for account visibility.
 */
final readonly class MembershipApplicationsService implements MembershipApplications
{
    public function __construct(
        private MembershipApplicationReader $reader,
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
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        $modelClass = MorphModelResolver::resolve('membership_application');

        return $modelClass::query();
    }
}
