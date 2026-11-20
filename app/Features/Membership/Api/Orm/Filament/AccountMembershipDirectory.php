<?php

declare(strict_types=1);

namespace App\Features\Membership\Api\Orm\Filament;

use App\Features\Membership\Api\MemberSubjectType;
use App\Shared\Auth\AccountActor;

/**
 * Account membership lists for the identity user administration surfaces.
 *
 * The implementation keeps the owner's established ordering and eager loads;
 * consumers only render the returned presentation records.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Membership Filament user administration
 */
interface AccountMembershipDirectory
{
    /**
     * @return iterable<int, \App\Features\Institutions\Models\Institution>
     */
    public function institutions(AccountActor $account): iterable;

    /**
     * @return iterable<int, \App\Features\Persons\Models\Person>
     */
    public function persons(AccountActor $account): iterable;

    /**
     * @return iterable<int, \App\Features\Events\Models\Event>
     */
    public function events(
        AccountActor $account,
        string $orderColumn = 'created_at',
        string $direction = 'desc',
    ): iterable;

    /**
     * @return iterable<int, \App\Features\References\Models\Reference>
     */
    public function references(AccountActor $account): iterable;

    /**
     * @return iterable<int, \AIArmada\Organizations\Models\Organization>
     */
    public function organizations(AccountActor $account): iterable;

    /**
     * @return list<\App\Features\Membership\Api\MembershipRecord>
     */
    public function memberships(AccountActor $account, MemberSubjectType $subjectType): array;
}
