<?php

namespace App\Support\Submission;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressLocationScope;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
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
    public function institutionQueryForSubmitter(?User $user, ?string $countryId = null): Builder
    {
        /** @var Builder<Institution> $query */
        $query = Institution::query();

        return $this->constrainInstitutionQueryForSubmitter($query, $user, $countryId);
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
     * @return Builder<Person>
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
    public function constrainInstitutionQueryForSubmitter(Builder $query, ?User $user, ?string $countryId = null): Builder
    {
        $query
            ->whereIn('status', self::ALLOWED_ENTITY_STATUSES)
            ->whereIn('status', ['verified', 'pending'])
            ->where(function (Builder $visibilityQuery) use ($user): void {
                $visibilityQuery->where('allow_public_event_submission', true);

                if ($user instanceof User) {
                    $visibilityQuery->orWhereHas('members', fn (Builder $memberQuery): Builder => $memberQuery->whereKey($user->getKey()));
                }
            });

        if (filled($countryId)) {
            app(AddressLocationScope::class)->apply($query, new AddressLocationData(countryId: $countryId));
        }

        return $query;
    }

    /**
     * @return Builder<Venue>
     */
    public function venueQuery(?string $countryId = null): Builder
    {
        /** @var Builder<Venue> $query */
        $query = Venue::query()
            ->whereIn('status', self::ALLOWED_ENTITY_STATUSES);

        if (filled($countryId)) {
            app(AddressLocationScope::class)->apply($query, new AddressLocationData(countryId: $countryId));
        }

        return $query;
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
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

    public function institutionBelongsToCountry(string $institutionId, string $countryId): bool
    {
        return app(AddressLocationScope::class)
            ->apply(Institution::query()->whereKey($institutionId), new AddressLocationData(countryId: $countryId))
            ->exists();
    }

    public function venueBelongsToCountry(string $venueId, string $countryId): bool
    {
        return app(AddressLocationScope::class)
            ->apply(Venue::query()->whereKey($venueId), new AddressLocationData(countryId: $countryId))
            ->exists();
    }
}
