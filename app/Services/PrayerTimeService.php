<?php

namespace App\Services;

use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PrayerTimeService
{
    /**
     * Cache duration for prayer times (24 hours).
     */
    protected const CACHE_TTL = 60 * 60 * 24;

    /**
     * Aladhan API base URL.
     */
    protected const ALADHAN_API_URL = 'https://api.aladhan.com/v1/timings';

    /**
     * JAKIM e-Solat API base URL.
     */
    protected const JAKIM_API_URL = 'https://www.e-solat.gov.my/index.php';

    /**
     * Get prayer times for a specific date and location.
     *
     * @return array<string, Carbon>|null
     */
    public function getPrayerTimes(
        Carbon $date,
        float $latitude,
        float $longitude,
        string $timezone = 'Asia/Kuala_Lumpur'
    ): ?array {
        $cacheKey = sprintf(
            'prayer_times:%s:%.4f:%.4f:%s',
            $date->format('Y-m-d'),
            $latitude,
            $longitude,
            strtolower($timezone),
        ).':v2';

        /** @var array<string, string>|null $cachedPrayerTimes */
        $cachedPrayerTimes = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($date, $latitude, $longitude, $timezone): ?array {
            // Try Aladhan API first (more reliable globally)
            $times = $this->fetchFromAladhan($date, $latitude, $longitude, $timezone);

            if ($times === null) {
                Log::warning('Failed to fetch prayer times from Aladhan API', [
                    'date' => $date->format('Y-m-d'),
                    'lat' => $latitude,
                    'lng' => $longitude,
                ]);
            }

            return $this->serializePrayerTimes($times);
        });

        return $this->deserializePrayerTimes($cachedPrayerTimes, $timezone);
    }

    /**
     * Fetch prayer times from Aladhan API.
     *
     * @return array<string, Carbon>|null
     */
    protected function fetchFromAladhan(
        Carbon $date,
        float $latitude,
        float $longitude,
        string $timezone
    ): ?array {
        try {
            /** @var Response $response */
            $response = Http::timeout(10)->get(self::ALADHAN_API_URL, [
                'date' => $date->format('d-m-Y'),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'method' => 4, // Muslim World League - good for Malaysia
                'tune' => '0,0,0,0,0,0,0,0,0', // No adjustments
            ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $timings = $data['data']['timings'] ?? null;

            if ($timings === null) {
                return null;
            }

            return [
                'Fajr' => $this->parseTimeString($timings['Fajr'], $date, $timezone),
                'Dhuhr' => $this->parseTimeString($timings['Dhuhr'], $date, $timezone),
                'Asr' => $this->parseTimeString($timings['Asr'], $date, $timezone),
                'Maghrib' => $this->parseTimeString($timings['Maghrib'], $date, $timezone),
                'Isha' => $this->parseTimeString($timings['Isha'], $date, $timezone),
            ];
        } catch (\Exception $e) {
            Log::error('Aladhan API request failed', [
                'error' => $e->getMessage(),
                'date' => $date->format('Y-m-d'),
            ]);

            return null;
        }
    }

    /**
     * Parse a time string (HH:MM) into a Carbon instance.
     */
    protected function parseTimeString(string $time, Carbon $date, string $timezone): Carbon
    {
        // Remove any timezone indicator (e.g., "(MYT)")
        $time = preg_replace('/\s*\([^)]+\)/', '', $time);
        $time = trim((string) $time);

        [$hours, $minutes] = explode(':', $time);

        return $date->copy()
            ->setTimezone($timezone)
            ->setTime((int) $hours, (int) $minutes, 0);
    }

    /**
     * @param  array<string, Carbon>|null  $times
     * @return array<string, string>|null
     */
    protected function serializePrayerTimes(?array $times): ?array
    {
        if ($times === null) {
            return null;
        }

        $serialized = [];

        foreach ($times as $prayer => $time) {
            $serialized[$prayer] = $time->toIso8601String();
        }

        return $serialized;
    }

    /**
     * @param  array<string, string>|null  $times
     * @return array<string, Carbon>|null
     */
    protected function deserializePrayerTimes(?array $times, string $timezone): ?array
    {
        if ($times === null) {
            return null;
        }

        $deserialized = [];

        foreach ($times as $prayer => $time) {
            $deserialized[$prayer] = Carbon::parse($time)->setTimezone($timezone);
        }

        return $deserialized;
    }

    /**
     * Calculate the actual start time for a prayer-relative event.
     */
    public function calculateStartTime(
        CarbonInterface $eventDate,
        PrayerReference $prayer,
        PrayerOffset $offset,
        float $latitude,
        float $longitude,
        string $timezone = 'Asia/Kuala_Lumpur'
    ): ?Carbon {
        // Convert to mutable Carbon for internal use
        $date = Carbon::parse($eventDate);
        $prayerTimes = $this->getPrayerTimes($date, $latitude, $longitude, $timezone);

        if ($prayerTimes === null) {
            return null;
        }

        $prayerKey = $prayer->aladhanKey();
        $prayerTime = $prayerTimes[$prayerKey] ?? null;

        if ($prayerTime === null) {
            return null;
        }

        return $prayerTime->copy()->addMinutes($offset->minutes());
    }

    /**
     * Get prayer time for a specific prayer on a date and location.
     */
    public function getPrayerTime(
        Carbon $date,
        PrayerReference $prayer,
        float $latitude,
        float $longitude,
        string $timezone = 'Asia/Kuala_Lumpur'
    ): ?Carbon {
        $prayerTimes = $this->getPrayerTimes($date, $latitude, $longitude, $timezone);

        if ($prayerTimes === null) {
            return null;
        }

        $prayerKey = $prayer->aladhanKey();

        return $prayerTimes[$prayerKey] ?? null;
    }

    /**
     * Get display text for a prayer-relative timing.
     */
    public function getDisplayText(PrayerReference $prayer, PrayerOffset $offset): string
    {
        return $offset->displayText($prayer);
    }

    /**
     * Determine the closest prayer reference for a given time.
     *
     * @return array{prayer: string, offset_minutes: int}|null
     */
    public function getClosestPrayer(
        Carbon $time,
        float $latitude,
        float $longitude,
        string $timezone = 'Asia/Kuala_Lumpur'
    ): ?array {
        $prayerTimes = $this->getPrayerTimes($time, $latitude, $longitude, $timezone);

        if ($prayerTimes === null) {
            return null;
        }

        $closestPrayer = null;
        $minDiff = PHP_INT_MAX;
        $bestOffset = null;

        foreach ($prayerTimes as $prayerName => $prayerTime) {
            $diffMinutes = $time->diffInMinutes($prayerTime, false);

            // Find the closest match within reasonable offset range (-30 to +60 min)
            if (abs($diffMinutes) < $minDiff && $diffMinutes >= -30 && $diffMinutes <= 60) {
                $minDiff = abs((int) $diffMinutes);
                $closestPrayer = $prayerName;
                $bestOffset = (int) $diffMinutes;
            }
        }

        if ($closestPrayer === null) {
            return null;
        }

        return [
            'prayer' => $closestPrayer,
            'offset_minutes' => $bestOffset,
        ];
    }

    /**
     * Get Malaysia-specific zone code for JAKIM API (future enhancement).
     */
    public function getJakimZoneCode(string $stateCode): ?string
    {
        // JAKIM zone codes by state - can be expanded
        $zones = [
            'WP' => 'WLY01', // Wilayah Persekutuan KL
            'SGR' => 'SGR01', // Selangor (multiple zones exist)
            'JHR' => 'JHR01', // Johor
            'KDH' => 'KDH01', // Kedah
            'KTN' => 'KTN01', // Kelantan
            'MLK' => 'MLK01', // Melaka
            'NSN' => 'NSN01', // Negeri Sembilan
            'PHG' => 'PHG01', // Pahang
            'PNG' => 'PNG01', // Pulau Pinang
            'PRK' => 'PRK01', // Perak
            'PLS' => 'PLS01', // Perlis
            'SBH' => 'SBH01', // Sabah
            'SWK' => 'SWK01', // Sarawak
            'TRG' => 'TRG01', // Terengganu
        ];

        return $zones[$stateCode] ?? null;
    }
}
