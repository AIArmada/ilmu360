<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Institution;
use App\Models\Person;
use Carbon\CarbonInterface;

class EventContributionUpdateStateMapper
{
    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function toHelperState(array $state): array
    {
        $state = AdminEventTimeMapper::injectFormTimeFields($state);

        return self::injectOrganizerLocationFields($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function toPersistenceState(array $state): array
    {
        $state = self::normalizeOrganizerLocationState($state);
        $state = AdminEventTimeMapper::normalizeForPersistence($state);

        if (($state['starts_at'] ?? null) instanceof CarbonInterface) {
            $state['starts_at'] = $state['starts_at']->toDateTimeString();
        }

        if (($state['ends_at'] ?? null) instanceof CarbonInterface) {
            $state['ends_at'] = $state['ends_at']->toDateTimeString();
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function injectOrganizerLocationFields(array $state): array
    {
        $primaryOrganizerId = self::normalizeOptionalString($state['primary_organizer_id'] ?? null);
        $organizerType = self::resolvedPrimaryOrganizerType($primaryOrganizerId);
        $institutionId = self::normalizeOptionalString($state['institution_id'] ?? null);
        $venueId = self::normalizeOptionalString($state['venue_id'] ?? null);

        $state['primary_organizer_kind'] = $organizerType;
        $state['primary_organizer_institution_id'] = $organizerType === 'institution' ? $primaryOrganizerId : null;
        $state['primary_organizer_speaker_id'] = $organizerType === 'speaker' ? $primaryOrganizerId : null;

        if ($organizerType === 'institution') {
            $sameAsInstitution = $venueId === null && $institutionId !== null && $institutionId === $primaryOrganizerId;

            $state['location_same_as_institution'] = $sameAsInstitution;
            $state['location_type'] = $venueId !== null ? 'venue' : 'institution';
            $state['location_institution_id'] = $sameAsInstitution ? $primaryOrganizerId : $institutionId;
            $state['location_venue_id'] = $venueId;

            return $state;
        }

        $state['location_same_as_institution'] = false;
        $state['location_type'] = $venueId !== null ? 'venue' : 'institution';
        $state['location_institution_id'] = $venueId === null ? $institutionId : null;
        $state['location_venue_id'] = $venueId;

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function normalizeOrganizerLocationState(array $state): array
    {
        $primaryOrganizerId = self::normalizeOptionalString($state['primary_organizer_id'] ?? null);
        $organizerType = self::resolvedPrimaryOrganizerType($primaryOrganizerId);
        $locationInstitutionId = self::normalizeOptionalString($state['location_institution_id'] ?? null);
        $locationVenueId = self::normalizeOptionalString($state['location_venue_id'] ?? null);
        $spaceId = self::normalizeOptionalString($state['space_id'] ?? null);
        $sameAsInstitution = (bool) ($state['location_same_as_institution'] ?? true);
        $locationType = in_array($state['location_type'] ?? null, ['institution', 'venue'], true)
            ? $state['location_type']
            : 'institution';

        $state['institution_id'] = null;
        $state['venue_id'] = null;

        if ($organizerType === 'institution') {
            if ($sameAsInstitution) {
                $state['institution_id'] = $primaryOrganizerId;
            } elseif ($locationType === 'institution') {
                $state['institution_id'] = $locationInstitutionId;
            } elseif ($locationType === 'venue') {
                $state['venue_id'] = $locationVenueId;
            }
        } elseif ($organizerType === 'speaker') {
            if ($locationType === 'institution') {
                $state['institution_id'] = $locationInstitutionId;
            } elseif ($locationType === 'venue') {
                $state['venue_id'] = $locationVenueId;
            }
        }

        $state['space_id'] = $state['institution_id'] !== null && $state['venue_id'] === null
            ? $spaceId
            : null;

        unset(
            $state['primary_organizer_kind'],
            $state['primary_organizer_institution_id'],
            $state['primary_organizer_speaker_id'],
            $state['location_same_as_institution'],
            $state['location_type'],
            $state['location_institution_id'],
            $state['location_venue_id'],
        );

        return $state;
    }

    private static function resolvedPrimaryOrganizerType(?string $primaryOrganizerId): ?string
    {
        if ($primaryOrganizerId === null) {
            return null;
        }

        if (Institution::query()->whereKey($primaryOrganizerId)->exists()) {
            return 'institution';
        }

        if (Person::query()->whereKey($primaryOrganizerId)->exists()) {
            return 'speaker';
        }

        return null;
    }

    private static function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
