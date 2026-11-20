<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Api\Orm\PrayerLocationResolver;
use App\Features\Prayer\Api\ResolvePrayerStartClockAction;

/** Resolve a public submission's prayer clock from its selected location. */
final readonly class ResolveEventSubmissionPrayerStartAction
{
    public function __construct(
        private ResolvePrayerStartClockAction $resolveStartClock,
        private PrayerLocationResolver $prayerLocation,
    ) {}

    /**
     * @return array{clock: string, date: string, starts_at: string, source: string, fetched_at: string, zone: string|null, lat: float|null, lng: float|null, country: string}|null
     */
    public function handle(
        ?string $targetInstitutionId,
        ?string $targetVenueId,
        ?string $submissionCountryIso2,
        string $timezone,
        string $prayerTime,
        string $eventDate,
    ): ?array {
        $address = $this->prayerLocation->forTargets($targetVenueId, $targetInstitutionId);
        $location = $this->prayerLocation->fromAddress($address, $submissionCountryIso2);

        return $this->resolveStartClock->handle(
            countryCode: $location['countryCode'] ?? 'MY',
            date: $eventDate,
            timezone: $timezone,
            prayerTime: EventPrayerTime::from($prayerTime),
            latitude: $location['latitude'],
            longitude: $location['longitude'],
            stateCode: $location['stateCode'],
            districtCandidates: $location['districtCandidates'],
        );
    }
}
