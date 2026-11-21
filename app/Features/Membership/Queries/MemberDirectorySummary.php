<?php

declare(strict_types=1);

namespace App\Features\Membership\Queries;

use App\Features\Membership\Api\AccountMembershipDirectory;
use App\Features\Membership\Api\MembershipDirectory;
use App\Features\Membership\Api\MemberSubjectReader;
use App\Features\Membership\Api\MemberSubjectType;
use App\Platform\ExtensionRegistry;
use RuntimeException;

final readonly class MemberDirectorySummary implements AccountMembershipDirectory
{
    public function __construct(
        private MembershipDirectory $memberships,
        private ExtensionRegistry $extensions,
    ) {}

    public function subjects(
        string $accountId,
        MemberSubjectType $subjectType,
        ?string $orderColumn = null,
        ?string $direction = null,
    ): array {
        $ids = $this->memberships->subjectIdsFor($accountId, $subjectType);

        if ($ids === []) {
            return [];
        }

        foreach ($this->extensions->resolve('membership.subject-records', MemberSubjectReader::class) as $reader) {
            /** @var MemberSubjectReader $reader */
            if ($reader->subjectType() !== $subjectType) {
                continue;
            }

            $column = $orderColumn ?? match ($subjectType) {
                MemberSubjectType::Event => 'created_at',
                MemberSubjectType::Reference => 'title',
                default => 'name',
            };
            $sort = $direction ?? ($subjectType === MemberSubjectType::Event ? 'desc' : 'asc');

            return $reader->forIds($ids, $column, $sort);
        }

        throw new RuntimeException("No subject reader registered for {$subjectType->value}.");
    }

    public function memberships(string $accountId, MemberSubjectType $subjectType): array
    {
        return $this->memberships->membershipsFor($accountId, $subjectType);
    }
}
