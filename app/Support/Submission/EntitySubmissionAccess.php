<?php

namespace App\Support\Submission;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressLocationScope;
use App\Enums\EventFormat;
use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

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
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues())
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
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues())
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
            ->whereIn('venues.status', self::ALLOWED_ENTITY_STATUSES);

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
            ->whereIn('persons.status', self::ALLOWED_ENTITY_STATUSES)
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

    /**
     * @param  list<string>  $personIds
     * @return list<string> The subset of IDs the submitter is not allowed to use.
     */
    public function unusablePersonIds(?User $user, array $personIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $personIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));

        if ($ids === []) {
            return [];
        }

        $usable = $this->personQueryForSubmitter($user)
            ->whereKey($ids)
            ->pluck('persons.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_diff($ids, $usable));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assertSubmissionEntitiesAreAccessible(
        array $validated,
        ?User $submitter,
        ?string $organizerKind,
        string $validationKeyPrefix = '',
    ): void {
        $primaryOrganizerId = (string) ($validated['primary_organizer_id'] ?? '');
        $locationInstitutionId = (string) ($validated['location_institution_id'] ?? '');

        if ($organizerKind === 'institution' && $primaryOrganizerId !== '' && ! $this->canUseInstitution($submitter, $primaryOrganizerId)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('primary_organizer_id', $validationKeyPrefix) => __('Anda tidak dibenarkan memilih institusi ini untuk penghantaran majlis.'),
            ]);
        }

        if ($organizerKind === 'person' && $primaryOrganizerId !== '' && ! $this->canUsePerson($submitter, $primaryOrganizerId)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('primary_organizer_id', $validationKeyPrefix) => __('Anda tidak dibenarkan memilih penceramah ini untuk penghantaran majlis.'),
            ]);
        }

        $eventFormat = SubmissionValues::enumValue($validated['event_format'] ?? null, EventFormat::Physical->value);
        $requiresLocationChoice = $organizerKind === 'person' || ! ($validated['location_same_as_institution'] ?? true);
        $usesLocationInstitution = $eventFormat !== EventFormat::Online->value
            && $requiresLocationChoice
            && (($validated['location_type'] ?? 'institution') === 'institution');

        if ($usesLocationInstitution && $locationInstitutionId !== '' && ! $this->canUseInstitution($submitter, $locationInstitutionId)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('location_institution_id', $validationKeyPrefix) => __('Anda tidak dibenarkan memilih institusi lokasi ini.'),
            ]);
        }

        $locationVenueId = (string) ($validated['location_venue_id'] ?? '');
        $usesLocationVenue = $eventFormat !== EventFormat::Online->value
            && $requiresLocationChoice
            && (($validated['location_type'] ?? null) === 'venue');

        if ($usesLocationVenue && $locationVenueId !== '' && ! $this->venueQuery()->whereKey($locationVenueId)->exists()) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('location_venue_id', $validationKeyPrefix) => __('Sila pilih lokasi untuk majlis ini.'),
            ]);
        }

        $personIds = collect(array_merge(
            (array) ($validated['persons'] ?? []),
            collect((array) ($validated['other_key_people'] ?? []))->pluck('involveable_id')->all(),
        ))
            ->map(fn (mixed $value): ?string => filled($value) ? (string) $value : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($this->unusablePersonIds($submitter, $personIds) !== []) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('persons', $validationKeyPrefix) => __('Senarai penceramah mengandungi pilihan yang tidak dibenarkan untuk penghantaran ini.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assertSubmissionEntitiesMatchCountry(
        array $validated,
        ?string $organizerKind,
        string $submissionCountryId,
        ?Institution $scopedInstitution,
        string $validationKeyPrefix = '',
    ): void {
        $primaryOrganizerId = (string) ($validated['primary_organizer_id'] ?? '');
        $eventFormat = SubmissionValues::enumValue($validated['event_format'] ?? null, EventFormat::Physical->value);
        $requiresLocationChoice = $organizerKind === 'person' || ! ($validated['location_same_as_institution'] ?? true);
        $locationType = (string) ($validated['location_type'] ?? 'institution');

        $isScopedOrganizer = $scopedInstitution instanceof Institution
            && $organizerKind === 'institution'
            && $primaryOrganizerId === (string) $scopedInstitution->getKey();

        if ($organizerKind === 'institution' && $primaryOrganizerId !== '' && ! $isScopedOrganizer
            && ! $this->institutionBelongsToCountry($primaryOrganizerId, $submissionCountryId)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('primary_organizer_id', $validationKeyPrefix) => __('Institusi penganjur tidak berada di negara yang dipilih.'),
            ]);
        }

        $locationInstitutionId = (string) ($validated['location_institution_id'] ?? '');
        $usesLocationInstitution = $eventFormat !== EventFormat::Online->value
            && $requiresLocationChoice
            && $locationType === 'institution';
        $isScopedLocation = $scopedInstitution instanceof Institution
            && $locationInstitutionId === (string) $scopedInstitution->getKey();

        if ($usesLocationInstitution && $locationInstitutionId !== '' && ! $isScopedLocation
            && ! $this->institutionBelongsToCountry($locationInstitutionId, $submissionCountryId)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('location_institution_id', $validationKeyPrefix) => __('Institusi lokasi tidak berada di negara yang dipilih.'),
            ]);
        }

        $locationVenueId = (string) ($validated['location_venue_id'] ?? '');
        $usesLocationVenue = $eventFormat !== EventFormat::Online->value
            && $requiresLocationChoice
            && $locationType === 'venue';

        if ($usesLocationVenue && $locationVenueId !== ''
            && ! $this->venueBelongsToCountry($locationVenueId, $submissionCountryId)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('location_venue_id', $validationKeyPrefix) => __('Lokasi tempat tidak berada di negara yang dipilih.'),
            ]);
        }
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
