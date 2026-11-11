<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Transport-neutral event schedule payload.
 */
final readonly class EventScheduleInput
{
    public function __construct(
        public string $scheduleKind,
        public ?string $startsAt = null,
        public ?string $endsAt = null,
        public ?string $timezone = null,
        public ?string $timingMode = null,
        public ?string $prayerReference = null,
        public ?int $prayerOffset = null,
        public ?string $prayerDisplayText = null,
        public ?string $prayerSource = null,
        public ?string $prayerFetchedAt = null,
        public ?string $prayerZone = null,
        public ?string $prayerDate = null,
        public ?float $prayerLat = null,
        public ?float $prayerLng = null,
        public ?string $prayerVenueId = null,
        public ?string $prayerInstitutionId = null,
        public ?string $prayerCountry = null,
    ) {}
}
