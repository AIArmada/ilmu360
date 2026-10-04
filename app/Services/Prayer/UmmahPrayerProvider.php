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
 * Global prayer times via UmmahAPI (adhan engine, lat/lng-only).
 *
 * MY-secondary behind the JAKIM mirror and global primary. Ships both
 * daily and month-bulk endpoints; the month payload is the prefetch unit.
 * The API normalizes method echoes (`malaysia` → `JAKIM`), so the source
 * tag uses the REQUESTED method/madhab and stays stable for tests.
 * Live HTTP only: called from queued jobs and the preview endpoint.
 */
final class UmmahPrayerProvider implements MonthlyPrayerTimesProvider
{
    public function __construct(
        private HttpFactory $http,
        private PrayerCircuitBreaker $breaker,
    ) {}

    public function key(): string
    {
        return 'ummah';
    }

    public function supports(string $countryCode): bool
    {
        return trim($countryCode) !== '';
    }

    public function requiresZone(): bool
    {
        return false;
    }

    public function cacheIdentitySegment(PrayerQuery $query): string
    {
        return "ummah:{$this->methodFor($query)}:{$this->madhabFor($query)}";
    }

    public function isSourceCurrent(string $source, string $countryCode): bool
    {
        if (! str_starts_with($source, 'ummah:')) {
            return true;
        }

        // The probe reuses the exact effective chain (explicit query
        // methods are always null for shared monthly warming): stored
        // `ummah:{method}/{madhab}` must match today's effective pair.
        $probe = new PrayerQuery(strtoupper(trim($countryCode)), '2000-01-01', 'UTC');

        return $source === "ummah:{$this->methodFor($probe)}/{$this->madhabFor($probe)}";
    }

    public function calcFingerprint(PrayerQuery $query): string
    {
        return implode('|', [
            self::coordToken($query->latitude),
            self::coordToken($query->longitude),
            $query->timezone,
            $this->methodFor($query),
            $this->madhabFor($query),
        ]);
    }

    private static function coordToken(?float $value): string
    {
        return $value === null ? 'null' : sprintf('%.6F', $value);
    }

    public function dailyPrayers(PrayerQuery $query): PrayerTimesDTO
    {
        [$latitude, $longitude] = $this->requireCoords($query);
        $method = $this->methodFor($query);
        $madhab = $this->madhabFor($query);

        $payload = $this->json(
            $this->get('/prayer-times', [
                'lat' => $latitude,
                'lng' => $longitude,
                'date' => $query->date,
                'method' => $method,
                'madhab' => $madhab,
                'timezone' => $query->timezone,
            ]),
            "Ummah day {$query->date}"
        );

        $data = $this->data($payload, "Ummah day {$query->date}");

        if (($data['date'] ?? null) !== $query->date) {
            throw new ProviderUnavailable("Ummah day payload date mismatch for {$query->date}.");
        }

        return $this->dtoFromDay($query, $data, $method, $madhab, (string) ($data['date'] ?? $query->date));
    }

    /**
     * @return array<string, PrayerTimesDTO>
     */
    public function monthlyPrayers(PrayerQuery $query): array
    {
        [$latitude, $longitude] = $this->requireCoords($query);
        $method = $this->methodFor($query);
        $madhab = $this->madhabFor($query);
        [$year, $month] = [(int) substr($query->date, 0, 4), (int) substr($query->date, 5, 2)];

        $payload = $this->json(
            $this->get('/prayer-times/month', [
                'lat' => $latitude,
                'lng' => $longitude,
                'month' => $month,
                'year' => $year,
                'method' => $method,
                'madhab' => $madhab,
                'timezone' => $query->timezone,
            ]),
            "Ummah month {$year}-{$month}"
        );

        $data = $this->data($payload, "Ummah month {$year}-{$month}");

        if ((int) ($data['month'] ?? 0) !== $month || (int) ($data['year'] ?? 0) !== $year) {
            throw new ProviderUnavailable("Ummah month payload echo mismatch for {$year}-{$month}.");
        }

        $rows = $data['days'] ?? null;

        if (! is_array($rows) || $rows === []) {
            throw new ProviderUnavailable("Ummah month {$year}-{$month} has no day rows.");
        }

        $days = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['date']) || ! is_string($row['date'])) {
                continue;
            }

            // Day rows are untrusted: only well-formed dates inside the
            // queried month count toward coverage.
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $row['date'], $matches) !== 1
                || (int) $matches[1] !== $year
                || (int) $matches[2] !== $month
                || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
                continue;
            }

            try {
                $days[$row['date']] = $this->dtoFromDay($query, $row, $method, $madhab, $row['date']);
            } catch (ProviderUnavailable) {
                continue;
            }
        }

        if ($days === []) {
            throw new ProviderUnavailable("Ummah month {$year}-{$month} yielded no usable days.");
        }

        return $days;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function dtoFromDay(PrayerQuery $query, array $row, string $method, string $madhab, string $date): PrayerTimesDTO
    {
        $datetimes = $row['prayer_datetimes'] ?? null;
        $clocks = $row['prayer_times'] ?? null;
        $times = [];
        $ishaFromClock = false;

        foreach (['fajr', 'sunrise', 'dhuhr', 'asr', 'maghrib', 'isha', 'imsak'] as $key) {
            $iso = is_array($datetimes) ? ($datetimes[$key] ?? null) : null;

            // Strict absolute syntax on the row's prayer day; relative or
            // wrong-day text falls through to the wall-clock table below.
            // A same-day absolute isha that precedes maghrib is the
            // previous night's anchor: discard it so the clock table
            // supplies tonight's isha (rolled past midnight when needed).
            $absolute = PrayerClock::parseAbsoluteDatetime($iso, $date, $query->timezone, $key === 'isha');

            if ($absolute instanceof CarbonImmutable
                && ($key !== 'isha' || PrayerClock::isIshaAfterMaghrib($times + ['isha' => $absolute]))) {
                $times[$key] = $absolute;

                continue;
            }

            $time = PrayerClock::parseWallClock(is_array($clocks) ? ($clocks[$key] ?? null) : null, $date, $query->timezone);

            if (! $time instanceof CarbonImmutable) {
                if ($key === 'imsak') {
                    continue;
                }

                throw new ProviderUnavailable("Ummah day {$date} is missing [{$key}].");
            }

            $times[$key] = $time;

            if ($key === 'isha') {
                $ishaFromClock = true;
            }
        }

        // Overnight rows (e.g. polar summers) attach isha before maghrib;
        // chronology alone decides it belongs to the next local day. The
        // roll applies to clock-attached isha only — day-validated
        // absolute stamps keep whatever date they carry.
        if ($ishaFromClock) {
            $times['isha'] = PrayerClock::rollOvernightIsha($times['isha'], $times['maghrib'], $query->timezone);
        }

        // A rolled isha can land on the next evening (malformed clocks):
        // reject the day exactly as the cache readers would, so live
        // preview falls through instead of serving an uncacheable row.
        if (! PrayerClock::isRowOnPrayerDay($times, $date, $query->timezone)) {
            throw new ProviderUnavailable("Ummah day {$date} has anchors outside the prayer day.");
        }

        return new PrayerTimesDTO(
            timesUtc: $times,
            source: "ummah:{$method}/{$madhab}",
            fetchedAt: CarbonImmutable::now('UTC'),
            timezoneUsed: $query->timezone,
            date: $date,
            zoneOrCell: $query->zone ?? "{$query->latitude},{$query->longitude}",
            calcFingerprint: $this->calcFingerprint($query),
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function requireCoords(PrayerQuery $query): array
    {
        if ($query->latitude === null || $query->longitude === null) {
            throw new ProviderUnavailable('Ummah provider requires latitude/longitude.');
        }

        return [$query->latitude, $query->longitude];
    }

    private function methodFor(PrayerQuery $query): string
    {
        if (is_string($query->method) && trim($query->method) !== '') {
            return trim($query->method);
        }

        $row = config("prayer.methods.{$query->countryCode}");

        if (is_array($row) && isset($row['ummah']) && is_string($row['ummah'])) {
            return $row['ummah'];
        }

        $default = config('prayer.methods.default');

        return is_array($default) && isset($default['ummah']) && is_string($default['ummah'])
            ? $default['ummah']
            : 'MuslimWorldLeague';
    }

    private function madhabFor(PrayerQuery $query): string
    {
        if (is_string($query->madhab) && trim($query->madhab) !== '') {
            return trim($query->madhab);
        }

        $row = config("prayer.methods.{$query->countryCode}");

        if (is_array($row) && isset($row['madhab']) && is_string($row['madhab'])) {
            return $row['madhab'];
        }

        $default = config('prayer.methods.default');

        return is_array($default) && isset($default['madhab']) && is_string($default['madhab'])
            ? $default['madhab']
            : 'Shafi';
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function get(string $path, array $params): Response
    {
        $base = rtrim((string) config('prayer.ummah.base_url', 'https://ummahapi.com/api'), '/');
        $timeout = (int) config('prayer.http_timeout', 3);
        $key = config('services.ummah.key');

        $pending = $this->http->timeout($timeout)->acceptJson();

        if (is_string($key) && $key !== '') {
            $pending = $pending->withHeader('X-API-Key', $key);
        }

        if (! $this->breaker->allow($this->key())) {
            throw new ProviderUnavailable('Ummah circuit is open.');
        }

        try {
            $response = $pending->get($base.$path, $params);
        } catch (Throwable $exception) {
            $this->breaker->recordFailure($this->key());

            throw new ProviderUnavailable("Ummah request failed: {$exception->getMessage()}", previous: $exception);
        }

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
        if ($response->status() === 429) {
            throw new ProviderUnavailable("{$context} was rate limited (HTTP 429).");
        }

        if (! $response->successful()) {
            throw new ProviderUnavailable("{$context} failed with HTTP {$response->status()}.");
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ProviderUnavailable("{$context} returned an invalid payload.");
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function data(array $payload, string $context): array
    {
        if (($payload['success'] ?? null) !== true) {
            throw new ProviderUnavailable("{$context} reported success=false.");
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new ProviderUnavailable("{$context} has no data object.");
        }

        return $data;
    }
}
