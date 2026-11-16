<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Immutable scalar input for event submission persistence. */
final readonly class EventSubmissionInput
{
    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $personSlugSegments
     */
    public function __construct(
        public array $state,
        public string $startsAt,
        public ?string $endsAt,
        public string $timezone,
        public EventSubmissionOrganizerKind $primaryOrganizerKind,
        public string $primaryOrganizerId,
        public ?string $targetInstitutionId,
        public ?string $targetVenueId,
        public ?string $prayerTime,
        public ?string $prayerReference,
        public ?int $prayerOffset,
        public ?string $prayerDisplayText,
        public bool $autoApproved,
        public bool $sessionSubmission,
        public ?string $submitterId,
        public ?string $eventContainerId,
        public array $personSlugSegments,
        public ?string $occurrenceId = null,
        public string $validationKeyPrefix = '',
        public ?string $prayerSource = null,
        public ?string $prayerFetchedAt = null,
        public ?string $prayerZone = null,
        public ?float $prayerLat = null,
        public ?float $prayerLng = null,
        public ?string $prayerCountry = null,
    ) {}
}
