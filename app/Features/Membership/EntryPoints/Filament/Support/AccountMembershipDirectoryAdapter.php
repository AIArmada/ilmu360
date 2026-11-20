<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Filament\Support;

use App\Features\Membership\Api\Filament\AccountMembershipDirectory;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\Queries\MemberDirectorySummary;
use App\Shared\Auth\AccountActor;

final readonly class AccountMembershipDirectoryAdapter implements AccountMembershipDirectory
{
    public function __construct(
        private MemberDirectorySummary $summary,
    ) {}

    /**
     * @return iterable<int, \App\Features\Institutions\Models\Institution>
     */
    public function institutions(AccountActor $account): iterable
    {
        return $this->summary->institutions($account);
    }

    /**
     * @return iterable<int, \App\Features\Persons\Models\Person>
     */
    public function persons(AccountActor $account): iterable
    {
        return $this->summary->persons($account);
    }

    /**
     * @return iterable<int, \App\Features\Events\Models\Event>
     */
    public function events(
        AccountActor $account,
        string $orderColumn = 'created_at',
        string $direction = 'desc',
    ): iterable {
        return $this->summary->events($account, $orderColumn, $direction);
    }

    /**
     * @return iterable<int, \App\Features\References\Models\Reference>
     */
    public function references(AccountActor $account): iterable
    {
        return $this->summary->references($account);
    }

    /**
     * @return iterable<int, \AIArmada\Organizations\Models\Organization>
     */
    public function organizations(AccountActor $account): iterable
    {
        return $this->summary->organizations($account);
    }

    /**
     * @return list<\App\Features\Membership\Api\MembershipRecord>
     */
    public function memberships(AccountActor $account, MemberSubjectType $subjectType): array
    {
        return $this->summary->memberships($account, $subjectType);
    }
}
