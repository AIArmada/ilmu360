<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Actions\ChangeMemberRoleAction;
use AIArmada\Membership\Actions\RemoveMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Membership\Actions\DetachSubjectMembersAction;
use App\Features\Membership\Actions\TransferPersonOwnershipAction;
use App\Features\Membership\Api\MembershipCommands;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Model;

/**
 * Membership command adapter over the canonical package actions.
 *
 * Package lifecycle actions remain canonical external writers for the member
 * pivots. Invitation lifecycle writes live in MembershipInvitations.
 */
final readonly class MembershipCommandsService implements MembershipCommands
{
    public function __construct(
        private AddMemberAction $addMember,
        private ChangeMemberRoleAction $changeMemberRole,
        private RemoveMemberAction $removeMember,
        private TransferPersonOwnershipAction $transferPersonOwnership,
        private DetachSubjectMembersAction $detachSubjectMembers,
    ) {}

    public function assignOwner(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $accountId,
    ): void {
        $this->addMember->handle(
            $this->subject($subjectType, $subjectId),
            $this->account($accountId),
            MemberRole::Owner,
        );
    }

    public function addMember(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $accountId,
        string $role,
    ): void {
        $this->addMember->handle(
            $this->subject($subjectType, $subjectId),
            $this->account($accountId),
            MemberRole::from($role),
        );
    }

    public function changeMemberRole(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $accountId,
        string $role,
    ): void {
        $this->changeMemberRole->handle(
            $this->subject($subjectType, $subjectId),
            $this->account($accountId),
            MemberRole::from($role),
        );
    }

    public function removeMember(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $accountId,
    ): void {
        $this->removeMember->handle(
            $this->subject($subjectType, $subjectId),
            $this->account($accountId),
        );
    }

    public function removeAllMembers(
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): void {
        $this->detachSubjectMembers->handle($subjectType, $subjectId);
    }

    public function transferPersonOwnership(
        string $personId,
        string $actorId,
        string $newOwnerId,
    ): void {
        $this->transferPersonOwnership->handle(
            $this->subject(MembershipSubjectType::Person, $personId),
            $this->account($actorId),
            $this->account($newOwnerId),
        );
    }

    private function subject(MembershipSubjectType $subjectType, string $subjectId): Model
    {
        $modelClass = MorphModelResolver::resolve($subjectType->value);

        return $modelClass::query()->findOrFail($subjectId);
    }

    private function account(string $accountId): Model
    {
        $modelClass = MorphModelResolver::resolve('user');

        return $modelClass::query()->findOrFail($accountId);
    }
}
