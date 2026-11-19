<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use App\Features\Prayer\Api\ResolvePrayerStartClockAction;
use App\Features\Prayer\Support\PrayerLocation;
use App\Shared\Prayer\EventPrayerTime;

/** Resolve a public submission's prayer clock from its selected location. */
final readonly class ResolveEventSubmissionPrayerStartAction
{
    public function __construct(private ResolvePrayerStartClockAction $resolveStartClock) {}

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
        $address = PrayerLocation::forTargets($targetVenueId, $targetInstitutionId);
        $location = PrayerLocation::fromAddress($address, $submissionCountryIso2);

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
