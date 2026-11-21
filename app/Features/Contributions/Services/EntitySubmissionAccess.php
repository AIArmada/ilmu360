<?php

declare(strict_types=1);

namespace App\Features\Contributions\Services;

use App\Features\Contributions\Api\Orm\SubmissionEntityAccess;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\SubmissionValues;
use App\Features\Institutions\Api\Orm\InstitutionAuthoringCandidates;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Api\Orm\PersonAuthoringCandidates;
use App\Features\Places\Api\Orm\VenueAuthoringCandidates;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class EntitySubmissionAccess implements SubmissionEntityAccess
{
    public function __construct(
        private InstitutionAuthoringCandidates $institutions,
        private PersonAuthoringCandidates $persons,
        private VenueAuthoringCandidates $venues,
    ) {}

    public function institutionOptions(?User $user, ?string $countryId = null): array
    {
        return $this->institutions->options($user, $countryId);
    }

    public function institutionLabels(?User $user, array $institutionIds): array
    {
        return $this->institutions->labels($user, $institutionIds);
    }

    public function memberInstitutionOptions(User $user): array
    {
        return $this->institutions->memberOptions($user);
    }

    public function memberInstitution(User $user, string $institutionId): ?Institution
    {
        return $this->institutions->findMemberCandidate($user, $institutionId);
    }

    public function personOptions(?User $user): array
    {
        return $this->persons->options($user);
    }

    public function personLabels(?User $user, array $personIds): array
    {
        return $this->persons->labels($user, $personIds);
    }

    public function personLabel(?User $user, string $personId): ?string
    {
        return $this->persons->labels($user, [$personId])[$personId] ?? null;
    }

    public function venueOptions(?string $countryId = null): array
    {
        return $this->venues->options($countryId);
    }

    public function canUseInstitution(?User $user, string $institutionId): bool
    {
        return $this->institutions->canUse($user, $institutionId);
    }

    public function canUseMemberInstitution(User $user, string $institutionId): bool
    {
        return $this->institutions->findMemberCandidate($user, $institutionId) instanceof Institution;
    }

    public function canUsePerson(?User $user, string $personId): bool
    {
        return $this->persons->canUse($user, $personId);
    }

    public function unusablePersonIds(?User $user, array $personIds): array
    {
        return $this->persons->unusableIds($user, $personIds);
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

        if ($usesLocationVenue && $locationVenueId !== '' && ! $this->venues->canUse($locationVenueId)) {
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

    public function assertSubmissionEntitiesMatchCountryFor(
        array $validated,
        ?string $organizerKind,
        string $submissionCountryId,
        ?Institution $scopedInstitution,
        string $validationKeyPrefix = '',
    ): void {
        $this->assertSubmissionEntitiesMatchCountry(
            $validated,
            $organizerKind,
            $submissionCountryId,
            $scopedInstitution,
            $validationKeyPrefix,
        );
    }

    public function institutionBelongsToCountry(string $institutionId, string $countryId): bool
    {
        return $this->institutions->belongsToCountry($institutionId, $countryId);
    }

    public function venueBelongsToCountry(string $venueId, string $countryId): bool
    {
        return $this->venues->belongsToCountry($venueId, $countryId);
    }
}
