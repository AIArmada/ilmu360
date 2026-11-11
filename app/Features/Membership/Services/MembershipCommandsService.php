<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use AIArmada\Membership\Actions\AcceptInvitationAction;
use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Actions\ChangeMemberRoleAction;
use AIArmada\Membership\Actions\InviteMemberAction;
use AIArmada\Membership\Actions\RemoveMemberAction;
use AIArmada\Membership\Actions\RevokeInvitationAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MembershipInvitationDetail;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Model;

/**
 * Interim membership command adapter over the canonical package actions.
 *
 * Application submission/review and the app invitation notification remain in
 * their current writers until M1/M4 move them behind this boundary.
 */
final readonly class MembershipCommandsService implements MembershipCommands
{
    public function __construct(
        private AddMemberAction $addMember,
        private ChangeMemberRoleAction $changeMemberRole,
        private RemoveMemberAction $removeMember,
        private InviteMemberAction $inviteMember,
        private RevokeInvitationAction $revokeInvitation,
        private AcceptInvitationAction $acceptInvitation,
    ) {}

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

    public function changeRole(
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

    public function invite(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $email,
        string $role,
        string $inviterId,
    ): MembershipInvitationDetail {
        return MembershipValueMapper::invitation($this->inviteMember->handle(
            $this->subject($subjectType, $subjectId),
            $email,
            MemberRole::from($role),
            $this->account($inviterId),
        ));
    }

    public function revokeInvitation(string $invitationId, string $actorId): void
    {
        $this->revokeInvitation->handle(
            $this->invitation($invitationId),
            $this->account($actorId),
        );
    }

    public function acceptInvitation(string $rawToken, string $accountId): void
    {
        $invitationClass = MorphModelResolver::resolve('member_invitation');
        $invitation = $invitationClass::query()
            ->where('token', $invitationClass::tokenForStorage($rawToken))
            ->firstOrFail();

        $this->acceptInvitation->handle($invitation, $this->account($accountId), $rawToken);
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

    private function invitation(string $invitationId): Model
    {
        $modelClass = MorphModelResolver::resolve('member_invitation');

        return $modelClass::query()->findOrFail($invitationId);
    }
}
