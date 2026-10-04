<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use App\Contracts\PrayerTimesProvider;
use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Support\Prayer\PrayerClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Tertiary fallback via the Aladhan timings API (daily only).
 *
 * The calculation method comes from the per-country table and the timeout
 * uses the shared short budget. Live HTTP only: queued jobs and preview
 * endpoint.
 */
final class AladhanPrayerProvider implements PrayerTimesProvider
{
    public function __construct(
        private HttpFactory $http,
        private PrayerCircuitBreaker $breaker,
    ) {}

    public function key(): string
    {
        return 'aladhan';
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
        return "aladhan:{$this->methodFor($query)}:{$this->madhabFor($query)}";
    }

    public function isSourceCurrent(string $source, string $countryCode): bool
    {
        if (! str_starts_with($source, 'aladhan:')) {
            return true;
        }

        $probe = new PrayerQuery(strtoupper(trim($countryCode)), '2000-01-01', 'UTC');

        return $source === "aladhan:{$this->methodFor($probe)}/{$this->madhabFor($probe)}";
    }

    public function calcFingerprint(PrayerQuery $query): string
    {
        return implode('|', [
            $query->latitude === null ? 'null' : sprintf('%.6F', $query->latitude),
            $query->longitude === null ? 'null' : sprintf('%.6F', $query->longitude),
            $query->timezone,
            $this->methodFor($query),
            $this->madhabFor($query),
        ]);
    }

    public function dailyPrayers(PrayerQuery $query): PrayerTimesDTO
    {
        if ($query->latitude === null || $query->longitude === null) {
            throw new ProviderUnavailable('Aladhan provider requires latitude/longitude.');
        }

        $method = $this->methodFor($query);
        $madhab = $this->madhabFor($query);
        $day = CarbonImmutable::parse($query->date, $query->timezone);

        $payload = $this->json(
            $this->get("/timings/{$day->format('d-m-Y')}", [
                'latitude' => $query->latitude,
                'longitude' => $query->longitude,
                'method' => $method,
                'school' => strcasecmp($madhab, 'Hanafi') === 0 ? 1 : 0,
            ]),
            "Aladhan day {$query->date}"
        );

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new ProviderUnavailable("Aladhan day {$query->date} has no data object.");
        }

        $echo = $data['date']['gregorian']['date'] ?? null;

        if ($echo !== $day->format('d-m-Y')) {
            throw new ProviderUnavailable("Aladhan day payload date mismatch for {$query->date}.");
        }

        $timings = $data['timings'] ?? null;

        if (! is_array($timings)) {
            throw new ProviderUnavailable("Aladhan day {$query->date} has no timings object.");
        }

        return $this->dtoFromTimings($query, $timings, $method, $madhab, $data['meta']['timezone'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $timings
     */
    private function dtoFromTimings(PrayerQuery $query, array $timings, int $method, string $madhab, mixed $responseTimezone): PrayerTimesDTO
    {
        // Aladhan returns clocks in the location's timezone; the query
        // timezone may be a caller default (UTC). The response wins when
        // it names a valid zone, so the instant is never misread.
        $tz = is_string($responseTimezone) && in_array($responseTimezone, timezone_identifiers_list(), true)
            ? $responseTimezone
            : $query->timezone;

        $map = ['Fajr' => 'fajr', 'Sunrise' => 'sunrise', 'Dhuhr' => 'dhuhr', 'Asr' => 'asr', 'Maghrib' => 'maghrib', 'Isha' => 'isha', 'Imsak' => 'imsak'];
        $times = [];

        foreach ($map as $field => $key) {
            $raw = $timings[$field] ?? null;

            if (! is_string($raw)) {
                if ($key === 'imsak' || $key === 'sunrise') {
                    continue;
                }

                throw new ProviderUnavailable("Aladhan day {$query->date} is missing [{$field}].");
            }

            // Timings may carry a suffix like "05:40 (MYT)".
            $clock = trim((string) preg_replace('/\s*\([^)]+\)/', '', $raw));
            $time = PrayerClock::parseWallClock($clock, $query->date, $tz);

            if (! $time instanceof CarbonImmutable) {
                throw new ProviderUnavailable("Aladhan day {$query->date} has an unparseable [{$field}].");
            }

            $times[$key] = $time;
        }

        // All Aladhan timings are wall clocks on the row date; an isha
        // before maghrib belongs to the next local day (overnight rows).
        $times['isha'] = PrayerClock::rollOvernightIsha($times['isha'], $times['maghrib'], $tz);

        // A rolled isha can land on the next evening (malformed clocks):
        // reject exactly as the cache readers would, so live preview
        // falls through instead of serving an uncacheable row.
        if (! PrayerClock::isRowOnPrayerDay($times, $query->date, $tz)) {
            throw new ProviderUnavailable("Aladhan day {$query->date} has anchors outside the prayer day.");
        }

        return new PrayerTimesDTO(
            timesUtc: $times,
            source: "aladhan:{$method}/{$madhab}",
            fetchedAt: CarbonImmutable::now('UTC'),
            timezoneUsed: $tz,
            date: $query->date,
            zoneOrCell: $query->zone ?? "{$query->latitude},{$query->longitude}",
            calcFingerprint: $this->calcFingerprint($query),
        );
    }

    private function methodFor(PrayerQuery $query): int
    {
        $row = config("prayer.methods.{$query->countryCode}");

        if (is_array($row) && isset($row['aladhan']) && is_numeric($row['aladhan'])) {
            return (int) $row['aladhan'];
        }

        $default = config('prayer.methods.default');

        return is_array($default) && isset($default['aladhan']) && is_numeric($default['aladhan'])
            ? (int) $default['aladhan']
            : 3;
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
        $base = rtrim((string) config('prayer.aladhan.base_url', 'https://api.aladhan.com/v1'), '/');
        $timeout = (int) config('prayer.http_timeout', 3);

        if (! $this->breaker->allow($this->key())) {
            throw new ProviderUnavailable('Aladhan circuit is open.');
        }

        try {
            $response = $this->http->timeout($timeout)->acceptJson()->get($base.$path, $params);
        } catch (Throwable $exception) {
            $this->breaker->recordFailure($this->key());

            throw new ProviderUnavailable("Aladhan request failed: {$exception->getMessage()}", previous: $exception);
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
        if (! $response->successful()) {
            throw new ProviderUnavailable("{$context} failed with HTTP {$response->status()}.");
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['code'] ?? null) !== 200) {
            throw new ProviderUnavailable("{$context} returned an invalid payload.");
        }

        return $payload;
    }
}
