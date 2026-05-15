<?php

namespace App\Support\Submission;

use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class EntitySubmissionAccess
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_ENTITY_STATUSES = ['verified', 'pending'];

    /**
     * @return Builder<Institution>
     */
    public function institutionQueryForSubmitter(?User $user): Builder
    {
        /** @var Builder<Institution> $query */
        $query = Institution::query();

        return $this->constrainInstitutionQueryForSubmitter($query, $user);
    }

    /**
     * @return Builder<Institution>
     */
    public function memberInstitutionQueryForSubmitter(User $user): Builder
    {
        /** @var Builder<Institution> $query */
        $query = Institution::query();

        return $query
            ->whereIn('status', self::ALLOWED_ENTITY_STATUSES)
            ->where('is_active', true)
            ->whereHas('members', fn (Builder $memberQuery): Builder => $memberQuery->whereKey($user->getKey()));
    }

    /**
     * @return Builder<Speaker>
     */
    public function speakerQueryForSubmitter(?User $user): Builder
    {
        /** @var Builder<Speaker> $query */
        $query = Speaker::query();

        return $this->constrainSpeakerQueryForSubmitter($query, $user);
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    public function constrainInstitutionQueryForSubmitter(Builder $query, ?User $user): Builder
    {
        return $query
            ->whereIn('status', self::ALLOWED_ENTITY_STATUSES)
            ->where('is_active', true)
            ->where(function (Builder $visibilityQuery) use ($user): void {
                $visibilityQuery->where('allow_public_event_submission', true);

                if ($user instanceof User) {
                    $visibilityQuery->orWhereHas('members', fn (Builder $memberQuery): Builder => $memberQuery->whereKey($user->getKey()));
                }
            });
    }

    /**
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    public function constrainSpeakerQueryForSubmitter(Builder $query, ?User $user): Builder
    {
        return $query
            ->whereIn('status', self::ALLOWED_ENTITY_STATUSES)
            ->where('is_active', true)
            ->where(function (Builder $visibilityQuery) use ($user): void {
                $visibilityQuery->where('allow_public_event_submission', true);

                if ($user instanceof User) {
                    $visibilityQuery->orWhereHas('members', fn (Builder $memberQuery): Builder => $memberQuery->whereKey($user->getKey()));
                }
            });
    }

    public function canUseInstitution(?User $user, string $institutionId): bool
    {
        return $this->institutionQueryForSubmitter($user)
            ->whereKey($institutionId)
            ->exists();
    }

    public function canUseMemberInstitution(User $user, string $institutionId): bool
    {
        return $this->memberInstitutionQueryForSubmitter($user)
            ->whereKey($institutionId)
            ->exists();
    }

    public function canUseSpeaker(?User $user, string $speakerId): bool
    {
        return $this->speakerQueryForSubmitter($user)
            ->whereKey($speakerId)
            ->exists();
    }
}
