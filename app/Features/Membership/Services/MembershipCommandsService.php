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
use App\Features\Membership\Api\MemberSubjectType;
use App\Platform\Models\MorphModelResolver;
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
        MemberSubjectType $subjectType,
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
        MemberSubjectType $subjectType,
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
        MemberSubjectType $subjectType,
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
        MemberSubjectType $subjectType,
        string $subjectId,
        string $accountId,
    ): void {
        $this->removeMember->handle(
            $this->subject($subjectType, $subjectId),
            $this->account($accountId),
        );
    }

    public function removeAllMembers(
        MemberSubjectType $subjectType,
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
            $this->subject(MemberSubjectType::Person, $personId),
            $this->account($actorId),
            $this->account($newOwnerId),
        );
    }

    private function subject(MemberSubjectType $subjectType, string $subjectId): Model
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
