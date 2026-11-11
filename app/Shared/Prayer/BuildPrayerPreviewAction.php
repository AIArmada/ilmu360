<?php

declare(strict_types=1);

namespace App\Shared\Prayer;

interface BuildPrayerPreviewAction
{
    /**
     * @param  list<string>  $districtCandidates
     * @return array{date: string, country: string, timezone: string, zone: string|null, location_source: string|null, source: string, exact: bool, stale: bool, fetched_at: string|null, starts: array<string, string|null>}
     */
    public function handle(string $country, string $date, ?string $timezone = null, ?string $zone = null, ?float $latitude = null, ?float $longitude = null, ?string $stateCode = null, bool $allowLive = true, array $districtCandidates = []): array;
}
