<?php

declare(strict_types=1);

namespace App\Actions\Prayer;

use App\Data\Prayer\PrayerQuery;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerReference;
use App\Services\Prayer\PrayerProviderRegistry;
use Carbon\CarbonImmutable;

/**
 * Resolves a provider-backed start clock for one prayer label.
 *
 * Gated by `prayer.enabled`: disabled, custom-time, and Tarawih timings
 * return null so callers fall back to the hardcoded estimates. GPS-memo
 * misses dispatch an after-response resolve for future submissions.
 * Never performs live HTTP itself.
 */
final readonly class ResolvePrayerStartClockAction
{
    public function __construct(
        private ResolvePrayerAnchorAction $anchors,
        private PrayerProviderRegistry $providers,
    ) {}

    /**
     * @param  list<string>  $districtCandidates
     * @return array{clock: string, date: string, starts_at: string, source: string, fetched_at: string, zone: string|null, lat: float|null, lng: float|null, country: string}|null
     */
    public function handle(
        string $countryCode,
        string $date,
        string $timezone,
        EventPrayerTime $prayerTime,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $stateCode = null,
        array $districtCandidates = [],
    ): ?array {
        if (! config('prayer.enabled')) {
            return null;
        }

        if ($prayerTime->isCustomTime() || $prayerTime === EventPrayerTime::SelepasTarawih) {
            return null;
        }

        $reference = $prayerTime->toPrayerReference();

        if (! $reference instanceof PrayerReference) {
            return null;
        }

        $countryCode = strtoupper(trim($countryCode));

        // Only genuine address coords qualify for the live GPS lookup;
        // representative coords (zone/state defaults) must never be
        // memoized as if they were surveyed positions.
        $hadInputCoords = $latitude !== null && $longitude !== null;

        $resolver = $this->providers->zoneResolverFor($countryCode);
        $resolved = $resolver->resolve($latitude, $longitude, $stateCode, $districtCandidates);
        $zone = $resolved['zone'];
        $latitude = $resolved['lat'];
        $longitude = $resolved['lng'];

        if ($hadInputCoords && $latitude !== null && $longitude !== null && $resolved['source'] !== 'gps-memo') {
            $resolver->queueGpsResolution($latitude, $longitude);
        }

        $methods = $this->providers->methodsFor($countryCode);

        $query = new PrayerQuery(
            $countryCode,
            $date,
            $timezone,
            $latitude,
            $longitude,
            $zone,
            $methods['ummah'],
            $methods['madhab'],
        );

        $anchor = $this->anchors->handle($query, $reference, false);

        if ($anchor === null) {
            return null;
        }

        // Offset math runs on the anchor instant — never on a reconstructed
        // wall clock — so DST folds and UTC/local calendar boundaries
        // cannot shift the resolved day. The offset can still roll past
        // midnight (late Isha + positive offset); the resolved local date
        // travels with the clock so persistence attaches it to the right day.
        // The UTC instant travels too: persistence must store it directly,
        // since rebuilding it from wall text reintroduces fold ambiguity.
        $instant = CarbonImmutable::parse($anchor['instant'], 'UTC')
            ->addMinutes($prayerTime->getDefaultOffset()?->minutes() ?? 0);
        $resolved = $instant->setTimezone($timezone);

        return [
            'clock' => $resolved->format('H:i'),
            'date' => $resolved->format('Y-m-d'),
            'starts_at' => $instant->toIso8601String(),
            'source' => $anchor['source'],
            'fetched_at' => $anchor['fetched_at'],
            'zone' => $zone,
            // Post-resolver coordinates (genuine inputs or zone/state
            // representatives): persistence pins these so expression
            // re-resolution queries the same cache cells submission read.
            'lat' => $latitude,
            'lng' => $longitude,
            // The calculation identity submission resolved under: pinned
            // alongside zone/coords so later address edits cannot mix a
            // new country with the original cache inputs.
            'country' => $countryCode,
        ];
    }
}
