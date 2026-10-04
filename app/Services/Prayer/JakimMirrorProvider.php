<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use App\Contracts\MonthlyPrayerTimesProvider;
use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Support\Prayer\PrayerClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * JAKIM zone tables via the api.waktusolat.app community mirror.
 *
 * Live HTTP only: called from queued jobs and the preview endpoint, never
 * from the submit path. V2 monthly epochs are absolute instants; V1 day
 * strings are MYT wall clocks parsed explicitly in Asia/Kuala_Lumpur.
 */
final class JakimMirrorProvider implements MonthlyPrayerTimesProvider
{
    public const string TIMEZONE = 'Asia/Kuala_Lumpur';

    /**
     * V1 `prayerTime.date` month abbreviations (`15-Oct-2026`).
     *
     * @var array<string, int>
     */
    private const array V1_MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    public function __construct(
        private HttpFactory $http,
        private PrayerCircuitBreaker $breaker,
    ) {}

    public function key(): string
    {
        return 'jakim';
    }

    public function supports(string $countryCode): bool
    {
        return strtoupper(trim($countryCode)) === 'MY';
    }

    public function requiresZone(): bool
    {
        return true;
    }

    public function cacheIdentitySegment(PrayerQuery $query): string
    {
        // Zone-bound: daily cells never fill from this provider.
        return 'jakim';
    }

    public function isSourceCurrent(string $source, string $countryCode): bool
    {
        // JAKIM zone tables are authority data, not calculated: no
        // method/madhab setting can obsolete them.
        return true;
    }

    public function calcFingerprint(PrayerQuery $query): ?string
    {
        // Authority data: coordinate and setting edits never obsolete it.
        return null;
    }

    public function dailyPrayers(PrayerQuery $query): PrayerTimesDTO
    {
        try {
            $month = $this->monthlyPrayers($query);
        } catch (ProviderUnavailable) {
            $month = [];
        }

        if (isset($month[$query->date])) {
            return $month[$query->date];
        }

        return $this->fetchV1Day($query);
    }

    /**
     * @return array<string, PrayerTimesDTO>
     */
    public function monthlyPrayers(PrayerQuery $query): array
    {
        $zone = $this->requireZone($query);
        [$year, $month] = $this->splitYearMonth($query->date);

        $response = $this->get("/v2/solat/{$zone}", ['year' => $year, 'month' => $month]);

        if ($response->status() === 404) {
            throw new ProviderUnavailable("JAKIM month {$year}-{$month} is not published for zone {$zone}.");
        }

        $payload = $this->json($response, "JAKIM month {$year}-{$month} for zone {$zone}");

        $this->assertEcho($payload, $zone, $year, $month);

        $prayers = $payload['prayers'] ?? null;

        if (! is_array($prayers) || $prayers === []) {
            throw new ProviderUnavailable("JAKIM month {$year}-{$month} for zone {$zone} has no prayer rows.");
        }

        $days = [];

        foreach ($prayers as $row) {
            if (! is_array($row) || ! isset($row['day'])) {
                continue;
            }

            $day = (int) $row['day'];

            // Provider day numbers are untrusted: an invalid day must not
            // mint an outside-month key that inflates coverage counts.
            if ($day < 1 || ! checkdate($month, $day, $year)) {
                continue;
            }

            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            try {
                $days[$date] = $this->dtoFromV2Row($zone, $date, $row);
            } catch (ProviderUnavailable) {
                continue;
            }
        }

        if ($days === []) {
            throw new ProviderUnavailable("JAKIM month {$year}-{$month} for zone {$zone} yielded no usable days.");
        }

        return $days;
    }

    private function fetchV1Day(PrayerQuery $query): PrayerTimesDTO
    {
        $zone = $this->requireZone($query);
        [$year, $month] = $this->splitYearMonth($query->date);
        $day = (int) substr($query->date, 8, 2);

        $response = $this->get("/solat/{$zone}/{$day}", ['year' => $year, 'month' => $month]);

        if ($response->status() === 404) {
            throw new ProviderUnavailable("JAKIM day {$query->date} is not published for zone {$zone}.");
        }

        $payload = $this->json($response, "JAKIM day {$query->date} for zone {$zone}");

        $echoZone = $payload['zone'] ?? null;

        if (! is_string($echoZone) || strtoupper($echoZone) !== $zone) {
            throw new ProviderUnavailable("JAKIM day payload zone mismatch for {$query->date}.");
        }

        $row = $payload['prayerTime'] ?? null;

        if (! is_array($row)) {
            throw new ProviderUnavailable("JAKIM day {$query->date} for zone {$zone} has no prayerTime object.");
        }

        $this->validateV1DateEcho($query->date, $row, $zone);

        return $this->dtoFromV1Row($zone, $query->date, $row);
    }

    /**
     * The V1 day endpoint echoes its Gregorian date (`15-Oct-2026`); a
     * response for another day must never be attached to the requested
     * date. Parsed with an explicit month table — never locale-sensitive
     * date parsing — and failed closed like the zone echo above.
     *
     * @param  array<string, mixed>  $row
     */
    private function validateV1DateEcho(string $expected, array $row, string $zone): void
    {
        $rawEcho = $row['date'] ?? null;
        $echo = is_string($rawEcho) ? trim($rawEcho) : '';

        $actual = null;

        if (preg_match('/^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/', $echo, $matches) === 1) {
            $month = self::V1_MONTHS[strtolower($matches[2])] ?? null;
            $day = (int) $matches[1];
            $year = (int) $matches[3];

            if ($month !== null && checkdate($month, $day, $year)) {
                $actual = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        if ($actual !== $expected) {
            throw new ProviderUnavailable("JAKIM day payload date mismatch for {$expected} (echo [{$echo}]).");
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function dtoFromV2Row(string $zone, string $date, array $row): PrayerTimesDTO
    {
        $required = ['fajr', 'syuruk', 'dhuhr', 'asr', 'maghrib', 'isha'];

        foreach ($required as $field) {
            if (! isset($row[$field]) || ! is_numeric($row[$field])) {
                throw new ProviderUnavailable("JAKIM V2 row for {$date} is missing [{$field}].");
            }
        }

        $times = [
            'fajr' => CarbonImmutable::createFromTimestampUTC((int) $row['fajr']),
            'sunrise' => CarbonImmutable::createFromTimestampUTC((int) $row['syuruk']),
            'dhuhr' => CarbonImmutable::createFromTimestampUTC((int) $row['dhuhr']),
            'asr' => CarbonImmutable::createFromTimestampUTC((int) $row['asr']),
            'maghrib' => CarbonImmutable::createFromTimestampUTC((int) $row['maghrib']),
            'isha' => CarbonImmutable::createFromTimestampUTC((int) $row['isha']),
        ];

        if (isset($row['imsak']) && is_numeric($row['imsak'])) {
            $times['imsak'] = CarbonImmutable::createFromTimestampUTC((int) $row['imsak']);
        }

        // Absolute stamps are only trustworthy on the row's own prayer
        // day: a correct zone/month echo must not smuggle in a
        // previous-day anchor. Local-date comparison keeps legitimate
        // UTC crossover; post-midnight isha is allowed.
        foreach ($times as $key => $instant) {
            if (! PrayerClock::isOnPrayerDay($instant, $date, self::TIMEZONE, $key === 'isha')) {
                throw new ProviderUnavailable("JAKIM V2 row for {$date} has a [{$key}] stamp outside the prayer day.");
            }
        }

        // Same-day isha that precedes maghrib is the previous night's
        // anchor wearing today's date: reject the row, not just the stamp.
        if (! PrayerClock::isIshaAfterMaghrib($times)) {
            throw new ProviderUnavailable("JAKIM V2 row for {$date} has an isha stamp preceding maghrib.");
        }

        return new PrayerTimesDTO(
            timesUtc: $times,
            source: "jakim:v2/{$zone}",
            fetchedAt: CarbonImmutable::now('UTC'),
            timezoneUsed: self::TIMEZONE,
            date: $date,
            zoneOrCell: $zone,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function dtoFromV1Row(string $zone, string $date, array $row): PrayerTimesDTO
    {
        $map = ['fajr' => 'fajr', 'syuruk' => 'sunrise', 'dhuhr' => 'dhuhr', 'asr' => 'asr', 'maghrib' => 'maghrib', 'isha' => 'isha', 'imsak' => 'imsak'];
        $times = [];

        foreach ($map as $field => $key) {
            $time = PrayerClock::parseWallClock($row[$field] ?? null, $date, self::TIMEZONE);

            if (! $time instanceof CarbonImmutable) {
                if (in_array($key, ['fajr', 'sunrise', 'dhuhr', 'asr', 'maghrib', 'isha'], true)) {
                    throw new ProviderUnavailable("JAKIM V1 row for {$date} is missing [{$field}].");
                }

                continue;
            }

            $times[$key] = $time;
        }

        // Same-day isha preceding maghrib is the previous night's anchor
        // wearing today's date: reject exactly as the cache readers
        // would, so live preview falls through instead of serving an
        // uncacheable row.
        if (! PrayerClock::isRowOnPrayerDay($times, $date, self::TIMEZONE)) {
            throw new ProviderUnavailable("JAKIM V1 row for {$date} has anchors outside the prayer day.");
        }

        return new PrayerTimesDTO(
            timesUtc: $times,
            source: "jakim:v1/{$zone}",
            fetchedAt: CarbonImmutable::now('UTC'),
            timezoneUsed: self::TIMEZONE,
            date: $date,
            zoneOrCell: $zone,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertEcho(array $payload, string $zone, int $year, int $month): void
    {
        $rawZone = $payload['zone'] ?? null;
        $echoZone = is_string($rawZone) ? strtoupper($rawZone) : '';
        $echoYear = (int) ($payload['year'] ?? 0);
        $echoMonth = (int) ($payload['month_number'] ?? 0);

        if ($echoZone !== $zone || $echoYear !== $year || $echoMonth !== $month) {
            throw new ProviderUnavailable("JAKIM month payload echo mismatch for {$zone} {$year}-{$month}.");
        }
    }

    private function requireZone(PrayerQuery $query): string
    {
        if ($query->zone === null || trim($query->zone) === '') {
            throw new ProviderUnavailable('JAKIM provider requires a zone code.');
        }

        return strtoupper(trim($query->zone));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function splitYearMonth(string $date): array
    {
        return [(int) substr($date, 0, 4), (int) substr($date, 5, 2)];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function get(string $path, array $params): Response
    {
        if (! $this->breaker->allow($this->key())) {
            throw new ProviderUnavailable('JAKIM mirror circuit is open.');
        }

        $base = rtrim((string) config('prayer.jakim.base_url', 'https://api.waktusolat.app'), '/');
        $timeout = (int) config('prayer.http_timeout', 3);

        try {
            $response = $this->http->timeout($timeout)->acceptJson()->get($base.$path, $params);
        } catch (Throwable $exception) {
            $this->breaker->recordFailure($this->key());

            throw new ProviderUnavailable("JAKIM mirror request failed: {$exception->getMessage()}", previous: $exception);
        }

        // Unpublished-month 404s are routine negative-cache input, not
        // provider failures; rate limits, auth failures, and server errors
        // trip the breaker.
        if (in_array($response->status(), [401, 403, 429], true) || $response->serverError()) {
            $this->breaker->recordFailure($this->key());
        } else {
            $this->breaker->recordSuccess($this->key());
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response, string $context): array
    {
        if (! $response->successful()) {
            throw new ProviderUnavailable("{$context} failed with HTTP {$response->status()}.");
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ProviderUnavailable("{$context} returned an invalid payload.");
        }

        return $payload;
    }
}
