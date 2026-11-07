<?php

declare(strict_types=1);

namespace App\Features\Prayer\Api;

use App\Shared\Prayer\EventPrayerTime;

interface ResolvePrayerStartClockAction
{
    /**
     * @param  list<string>  $districtCandidates
     * @return array{clock: string, date: string, starts_at: string, source: string, fetched_at: string, zone: string|null, lat: float|null, lng: float|null, country: string}|null
     */
    public function handle(string $countryCode, string $date, string $timezone, EventPrayerTime $prayerTime, ?float $latitude = null, ?float $longitude = null, ?string $stateCode = null, array $districtCandidates = []): ?array;
}
