<?php

declare(strict_types=1);

namespace App\Data\Prayer;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One day's resolved prayer anchors, normalized to UTC.
 *
 * Keys are normalized at the provider edge: fajr, sunrise, dhuhr, asr,
 * maghrib, isha (plus optional imsak). `syuruk` arrives here as `sunrise`.
 * `date` and `zoneOrCell` echo the query so cached payloads stay testable.
 * Calculated providers stamp `calcFingerprint` with the inputs the row was
 * computed from (coords, timezone, method, madhab) so the monthly reader
 * can prune rows warmed under superseded inputs; authoritative rows
 * (JAKIM) leave it null and never prune on calc inputs.
 */
final readonly class PrayerTimesDTO
{
    /**
     * @var list<string>
     */
    public const array REQUIRED_KEYS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];

    /**
     * @param  array<string, CarbonImmutable>  $timesUtc
     */
    public function __construct(
        public array $timesUtc,
        public string $source,
        public CarbonImmutable $fetchedAt,
        public string $timezoneUsed,
        public string $date,
        public ?string $zoneOrCell = null,
        public ?string $calcFingerprint = null,
    ) {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! ($this->timesUtc[$key] ?? null) instanceof CarbonImmutable) {
                throw new InvalidArgumentException("Prayer times are missing required key [{$key}].");
            }
        }
    }

    public function timeFor(string $key): ?CarbonImmutable
    {
        return $this->timesUtc[$key] ?? null;
    }
}
