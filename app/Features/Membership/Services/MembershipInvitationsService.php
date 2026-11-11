<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use AIArmada\Membership\Enums\InvitationStatus;
use App\Features\Membership\Api\MembershipInvitationDetail;
use App\Features\Membership\Api\MembershipInvitations;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read adapter over membership invitations, preserving lazy expiry semantics:
 * an expired pending row is still returned by pending lookups only when its
 * expires_at has not passed.
 */
final readonly class MembershipInvitationsService implements MembershipInvitations
{
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
