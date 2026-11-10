<?php

declare(strict_types=1);

namespace App\Support\Membership;

use App\Models\MembershipApplication;
use App\Shared\Membership\MembershipApplicationReader;

final readonly class EloquentMembershipApplicationReader implements MembershipApplicationReader
{
    public function applicationIdsForApplicant(string $accountId): array
    {
        return MembershipApplication::query()
            ->where('applicant_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function applicationIdsForReviewer(string $accountId): array
    {
        return MembershipApplication::query()
            ->where('reviewer_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function findApplicationIdForApplicant(string $accountId, string $applicationId): ?string
    {
        $id = MembershipApplication::query()
            ->where('applicant_id', $accountId)
            ->whereKey($applicationId)
            ->value('id');

        return $id !== null ? (string) $id : null;
    }
}
