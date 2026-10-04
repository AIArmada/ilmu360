<?php

declare(strict_types=1);

namespace App\Actions\Prayer;

use App\Data\Prayer\PrayerQuery;
use App\Enums\EventPrayerTime;
use App\Services\Prayer\HardcodedPrayerFallback;
use App\Services\Prayer\PrayerProviderRegistry;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Builds the prayer-start preview shared by the API endpoint and the
 * submit form hints: per-label start clocks plus day-level provenance.
 *
 * Exact clocks come from provider anchors plus each label's default offset;
 * anything unresolvable degrades label-by-label to the hardcoded estimates.
 * Tarawih stays label-only, matching submit behavior.
 */
final readonly class BuildPrayerPreviewAction
{
    public function __construct(
        private ResolvePrayerAnchorAction $anchors,
        private PrayerProviderRegistry $providers,
    ) {}

    /**
     * @param  list<string>  $districtCandidates
     * @return array{date: string, country: string, timezone: string, zone: string|null, location_source: string|null, source: string, exact: bool, stale: bool, fetched_at: string|null, starts: array<string, string|null>}
     */
    public function handle(
        string $country,
        string $date,
        ?string $timezone = null,
        ?string $zone = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $stateCode = null,
        bool $allowLive = true,
        array $districtCandidates = [],
    ): array {
        $country = strtoupper(trim($country));
        $timezone ??= $this->providers->timezoneFor($country);
        $locationSource = null;

        $zones = $this->providers->zoneResolverFor($country);
        $explicitZone = $zone !== null && trim($zone) !== '' ? strtoupper(trim($zone)) : null;

        if ($explicitZone !== null && ! $zones->isKnownZone($explicitZone)) {
            throw new InvalidArgumentException("Unknown prayer zone [{$explicitZone}] for country [{$country}].");
        }

        $hadInputCoords = $latitude !== null && $longitude !== null;
        $resolved = $zones->resolve($latitude, $longitude, $stateCode, $districtCandidates);
        $zone = $explicitZone ?? $resolved['zone'];

        if ($explicitZone !== null && ! $hadInputCoords) {
            // An explicit zone without genuine coords uses only its own
            // representatives — never another zone's fallback coordinates.
            // When representatives are unavailable the coords stay null so
            // the daily fallback (which would serve wrong-zone times from
            // a warm fallback cell) is skipped entirely.
            $zoneCoords = $zones->coordsForZone($explicitZone);
            $latitude = $zoneCoords['lat'] ?? null;
            $longitude = $zoneCoords['lng'] ?? null;
        } else {
            $latitude = $resolved['lat'];
            $longitude = $resolved['lng'];
        }

        $locationSource = $resolved['source'] === 'no-zone'
            ? null
            : ($explicitZone !== null ? 'explicit-zone' : $resolved['source']);

        // Same calculation settings as submit so preview warms the exact
        // daily cells submit will read.
        $methods = $this->providers->methodsFor($country);
        $query = new PrayerQuery($country, $date, $timezone, $latitude, $longitude, $zone, $methods['ummah'], $methods['madhab']);
        $starts = [];
        $source = HardcodedPrayerFallback::SOURCE;
        $fetchedAt = null;
        $stale = false;

        foreach (EventPrayerTime::cases() as $label) {
            if ($label->isCustomTime()) {
                continue;
            }

            $fallbackClock = HardcodedPrayerFallback::clockFor($label);

            if ($label === EventPrayerTime::SelepasTarawih) {
                $starts[$label->value] = $fallbackClock;

                continue;
            }

            $reference = $label->toPrayerReference();
            $anchor = $reference !== null ? $this->anchors->handle($query, $reference, $allowLive) : null;

            if ($anchor === null) {
                $starts[$label->value] = $fallbackClock;

                continue;
            }

            $starts[$label->value] = $this->applyOffset($anchor['instant'], $label->getDefaultOffset()?->minutes() ?? 0, $timezone);

            if ($fetchedAt === null) {
                $source = $anchor['source'];
                $fetchedAt = $anchor['fetched_at'];
                $stale = $anchor['stale'];
            }
        }

        return [
            'date' => $date,
            'country' => $country,
            'timezone' => $timezone,
            'zone' => $zone,
            'location_source' => $locationSource,
            'source' => $source,
            'exact' => $source !== HardcodedPrayerFallback::SOURCE,
            'stale' => $stale,
            'fetched_at' => $fetchedAt,
            'starts' => $starts,
        ];
    }

    private function applyOffset(string $instantIso, int $offsetMinutes, string $timezone): string
    {
        return CarbonImmutable::parse($instantIso, 'UTC')->addMinutes($offsetMinutes)->setTimezone($timezone)->format('H:i');
    }
}
