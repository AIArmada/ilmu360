<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions;

use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Api\PersonMemberRoleChanges;
use App\Features\Persons\Models\Person;
use Illuminate\Support\Facades\DB;

final readonly class ChangePersonMemberRoleAction implements PersonMemberRoleChanges
{
    public function __construct(private MembershipCommands $membershipCommands) {}

    public function change(string $personId, string $accountId, string $role): void
    {
        DB::transaction(function () use ($personId, $accountId, $role): void {
            $person = Person::query()->findOrFail($personId);
            $person->lockForMembershipMutation();

            $this->membershipCommands->changeMemberRole(
                MemberSubjectType::Person,
                $personId,
                $accountId,
                $role,
            );
        });
    }
}
