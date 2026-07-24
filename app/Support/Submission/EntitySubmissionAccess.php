<?php

namespace App\Support\Submission;

use App\Models\Institution;
use App\Models\Person;
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
            ->whereIn('status', ['verified', 'pending'])
            ->whereHas('members', fn (Builder $memberQuery): Builder => $memberQuery->whereKey($user->getKey()));
    }

    /**
     * @return Builder<Speaker>
     */
    public function personQueryForSubmitter(?User $user): Builder
    {
        /** @var Builder<Person> $query */
        $query = Person::query();

        return $this->constrainPersonQueryForSubmitter($query, $user);
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    public function constrainInstitutionQueryForSubmitter(Builder $query, ?User $user): Builder
    {
        return $query
            ->whereIn('status', self::ALLOWED_ENTITY_STATUSES)
            ->whereIn('status', ['verified', 'pending'])
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
    public function constrainPersonQueryForSubmitter(Builder $query, ?User $user): Builder
    {
        return $query
            ->whereIn('status', self::ALLOWED_ENTITY_STATUSES)
            ->whereIn('status', ['verified', 'pending'])
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

    public function canUsePerson(?User $user, string $personId): bool
    {
        return $this->personQueryForSubmitter($user)
            ->whereKey($personId)
            ->exists();
    }
}
