<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Institution;
use App\Models\Speaker;
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
        $organizerType = self::normalizeOrganizerType($state['organizer_type'] ?? null);
        $organizerId = self::normalizeOptionalString($state['organizer_id'] ?? null);
        $institutionId = self::normalizeOptionalString($state['institution_id'] ?? null);
        $venueId = self::normalizeOptionalString($state['venue_id'] ?? null);

        $state['organizer_type'] = $organizerType;
        $state['organizer_institution_id'] = $organizerType === 'institution' ? $organizerId : null;
        $state['organizer_speaker_id'] = $organizerType === 'speaker' ? $organizerId : null;

        if ($organizerType === 'institution') {
            $sameAsInstitution = $venueId === null && $institutionId !== null && $institutionId === $organizerId;

            $state['location_same_as_institution'] = $sameAsInstitution;
            $state['location_type'] = $venueId !== null ? 'venue' : 'institution';
            $state['location_institution_id'] = $sameAsInstitution ? $organizerId : $institutionId;
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
        $organizerType = self::normalizeOrganizerType($state['organizer_type'] ?? null);
        $organizerInstitutionId = self::normalizeOptionalString($state['organizer_institution_id'] ?? null);
        $organizerSpeakerId = self::normalizeOptionalString($state['organizer_speaker_id'] ?? null);
        $locationInstitutionId = self::normalizeOptionalString($state['location_institution_id'] ?? null);
        $locationVenueId = self::normalizeOptionalString($state['location_venue_id'] ?? null);
        $spaceId = self::normalizeOptionalString($state['space_id'] ?? null);
        $sameAsInstitution = (bool) ($state['location_same_as_institution'] ?? true);
        $locationType = in_array($state['location_type'] ?? null, ['institution', 'venue'], true)
            ? $state['location_type']
            : 'institution';

        $state['organizer_type'] = $organizerType;
        $state['organizer_id'] = null;
        $state['institution_id'] = null;
        $state['venue_id'] = null;

        if ($organizerType === 'institution') {
            $state['organizer_id'] = $organizerInstitutionId;

            if ($sameAsInstitution) {
                $state['institution_id'] = $organizerInstitutionId;
            } elseif ($locationType === 'institution') {
                $state['institution_id'] = $locationInstitutionId;
            } elseif ($locationType === 'venue') {
                $state['venue_id'] = $locationVenueId;
            }
        } elseif ($organizerType === 'speaker') {
            $state['organizer_id'] = $organizerSpeakerId;

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
            $state['organizer_institution_id'],
            $state['organizer_speaker_id'],
            $state['location_same_as_institution'],
            $state['location_type'],
            $state['location_institution_id'],
            $state['location_venue_id'],
        );

        return $state;
    }

    private static function normalizeOrganizerType(mixed $value): ?string
    {
        return match ($value) {
            'institution', Institution::class => 'institution',
            'speaker', Speaker::class => 'speaker',
            default => null,
        };
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
