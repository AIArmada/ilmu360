<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use AIArmada\Membership\Actions\RevokeInvitationAction;
use AIArmada\Membership\Enums\InvitationStatus;
use AIArmada\Membership\Models\MembershipInvitation;
use App\Features\Membership\Actions\AcceptSubjectMemberInvitation;
use App\Features\Membership\Actions\InviteSubjectMember;
use App\Features\Membership\Api\MembershipInvitationDetail;
use App\Features\Membership\Api\MembershipInvitations;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Membership invitation boundary.
 *
 * App-side invitations delegate to the feature invite action (raw token plus
 * app-only email). Acceptance and revocation delegate to the canonical package
 * actions, preserving lazy expiry semantics: an expired pending row is still
 * returned by pending lookups only when its expires_at has not passed.
 */
final readonly class MembershipInvitationsService implements MembershipInvitations
{
    public function __construct(
        private InviteSubjectMember $inviteSubjectMember,
        private AcceptSubjectMemberInvitation $acceptSubjectMemberInvitation,
        private RevokeInvitationAction $revokeInvitation,
    ) {}

    public function find(string $invitationId): ?MembershipInvitationDetail
    {
        $invitation = $this->query()->find($invitationId);

        return $invitation instanceof Model
            ? MembershipValueMapper::invitation($invitation)
            : null;
    }

    public function findByToken(string $rawToken): ?MembershipInvitationDetail
    {
        $modelClass = MorphModelResolver::resolve('member_invitation');
        $invitation = $this->query()
            ->where('token', $modelClass::tokenForStorage($rawToken))
            ->first();

        return $invitation instanceof Model
            ? MembershipValueMapper::invitation($invitation)
            : null;
    }

    public function pendingForSubject(
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): array {
        return $this->pendingQuery($subjectType, $subjectId)
            ->latest('created_at')
            ->get()
            ->map(static fn (Model $invitation): MembershipInvitationDetail => MembershipValueMapper::invitation($invitation))
            ->all();
    }

    public function recentForSubject(
        MembershipSubjectType $subjectType,
        string $subjectId,
        int $limit = 20,
    ): array {
        return $this->query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId)
            ->latest('created_at')
            ->limit(max(0, $limit))
            ->get()
            ->map(static fn (Model $invitation): MembershipInvitationDetail => MembershipValueMapper::invitation($invitation))
            ->all();
    }

    public function pendingExistsForEmail(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $email,
    ): bool {
        return $this->pendingQuery($subjectType, $subjectId)
            ->where('email', mb_strtolower(mb_trim($email)))
            ->exists();
    }

    public function invite(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $email,
        string $role,
        string $inviterId,
    ): MembershipInvitationDetail {
        return MembershipValueMapper::invitation($this->inviteSubjectMember->handle(
            $this->subject($subjectType, $subjectId),
            $email,
            $role,
            $this->account($inviterId),
        ));
    }

    public function accept(string $invitationId, string $accountId): MembershipInvitationDetail
    {
        $invitation = $this->query()->findOrFail($invitationId);

        if (! $invitation instanceof MembershipInvitation) {
            throw new RuntimeException('The member invitation model must extend the package invitation.');
        }

        $this->acceptSubjectMemberInvitation->handle(
            $invitation,
            $this->account($accountId),
        );

        return MembershipValueMapper::invitation($this->query()->findOrFail($invitationId));
    }

    public function revoke(string $invitationId, string $actorId): void
    {
        $invitation = $this->query()->findOrFail($invitationId);

        if (! $invitation instanceof MembershipInvitation) {
            throw new RuntimeException('The member invitation model must extend the package invitation.');
        }

        $this->revokeInvitation->handle($invitation, $this->account($actorId));
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

    /**
     * @return Builder<Model>
     */
    private function pendingQuery(
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): Builder {
        return $this->query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId)
            ->where('status', InvitationStatus::Pending->value)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        $modelClass = MorphModelResolver::resolve('member_invitation');

        return $modelClass::query();
    }
}
