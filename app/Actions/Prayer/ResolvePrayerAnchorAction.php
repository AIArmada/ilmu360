<?php

declare(strict_types=1);

namespace App\Actions\Prayer;

use App\Contracts\MonthlyPrayerTimesProvider;
use App\Contracts\PrayerTimesProvider;
use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\PrayerReference;
use App\Jobs\RefreshPrayerTimes;
use App\Services\Prayer\PrayerProviderRegistry;
use App\Services\Prayer\PrayerTimesCache;
use App\Services\Prayer\ProviderUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves one prayer anchor clock for a date and location.
 *
 * Cache reads only unless $allowLive: on a miss it dispatches an
 * after-response refresh and degrades to stale cache, then null. Null means
 * no provider data — callers fall back to the hardcoded estimates.
 * Only the preview endpoint passes $allowLive=true.
 *
 * Layer order per provider: fresh → live (preview only) → stale, with
 * negative markers suppressing live attempts and refreshes but never
 * stale reads. Zoned queries additionally fall through to coordinate
 * daily cells when monthly coverage fails, so a mirror outage still
 * serves Aladhan/Ummah days instead of hardcoded estimates.
 */
final class ResolvePrayerAnchorAction
{
    /**
     * @var array<string, PrayerTimesDTO|null> live daily results per instance
     */
    private array $liveDailyMemo = [];

    /**
     * @var array<string, array<string, PrayerTimesDTO>> live monthly results per instance
     */
    private array $liveMonthlyMemo = [];

    public function __construct(
        private readonly PrayerProviderRegistry $registry,
        private readonly PrayerTimesCache $cache,
    ) {}

    /**
     * @return array{clock: string, instant: string, source: string, fetched_at: string, stale: bool}|null
     */
    public function handle(PrayerQuery $query, PrayerReference $reference, bool $allowLive = false): ?array
    {
        if ($query->zone !== null) {
            return $this->resolveZoned($query, $reference, $allowLive);
        }

        return $this->resolveGlobal($query, $reference, $allowLive);
    }

    /**
     * Zone-month resolution for zone-based countries. The zone resolver
     * decides which countries carry zones; this path only knows zones.
     *
     * @return array{clock: string, instant: string, source: string, fetched_at: string, stale: bool}|null
     */
    private function resolveZoned(PrayerQuery $query, PrayerReference $reference, bool $allowLive): ?array
    {
        $zone = strtoupper((string) $query->zone);
        $yearMonth = substr($query->date, 0, 7);

        $fresh = $this->cache->getMonthly($zone, $yearMonth, $query->countryCode);

        if (isset($fresh[$query->date])) {
            return $this->clock($fresh[$query->date], $reference, $query->timezone, false);
        }

        // A failed live attempt still queues one refresh per negative window:
        // the preview runs first with live allowed, and must not suppress
        // the submit path's refresh by writing the negative marker alone.
        if (! $this->cache->hasNegative($this->cache->monthlyKey($zone, $yearMonth, $query->countryCode))) {
            if ($allowLive) {
                $served = $this->fetchLiveZoned($query, $zone, $reference);

                if ($served !== null) {
                    return $served;
                }
            }

            // No requester timezone: the job derives the canonical country
            // timezone so deferred warming matches preview/prefetch inputs.
            $this->dispatchRefresh('zone-month', [
                'zone' => $zone,
                'year_month' => $yearMonth,
                'country' => $query->countryCode,
            ]);
        }

        $stale = $this->cache->getStaleMonthly($zone, $yearMonth, $query->countryCode);

        if (isset($stale[$query->date])) {
            return $this->clock($stale[$query->date], $reference, $query->timezone, true);
        }

        // Monthly coverage failed: fall through to coordinate daily cells so
        // zoned queries still reach daily-capable providers (Aladhan).
        foreach ($this->registry->orderedFor($query->countryCode) as $provider) {
            if ($provider->requiresZone()) {
                continue;
            }

            $served = $this->resolveProviderDay($provider, $query, $reference, $allowLive);

            if ($served !== null) {
                return $served;
            }
        }

        return null;
    }

    /**
     * @return array{clock: string, instant: string, source: string, fetched_at: string, stale: bool}|null
     */
    private function resolveGlobal(PrayerQuery $query, PrayerReference $reference, bool $allowLive): ?array
    {
        foreach ($this->registry->orderedFor($query->countryCode) as $provider) {
            if ($provider->requiresZone()) {
                continue;
            }

            $served = $this->resolveProviderDay($provider, $query, $reference, $allowLive);

            if ($served !== null) {
                return $served;
            }
        }

        return null;
    }

    /**
     * One provider's day: fresh → live (preview only) → queued refresh →
     * stale. Negatives suppress live attempts and refreshes, never stale.
     *
     * @return array{clock: string, instant: string, source: string, fetched_at: string, stale: bool}|null
     */
    private function resolveProviderDay(PrayerTimesProvider $provider, PrayerQuery $query, PrayerReference $reference, bool $allowLive): ?array
    {
        $cell = $this->cell($query, $provider);

        $fresh = $this->cache->getDaily($provider->key(), $cell, $query->date);

        if ($fresh instanceof PrayerTimesDTO) {
            return $this->clock($fresh, $reference, $query->timezone, false);
        }

        $negative = $this->cache->hasNegative($this->cache->dailyKey($provider->key(), $cell, $query->date));

        if ($allowLive && ! $negative) {
            $dto = $this->liveDaily($provider, $query, $cell);

            if ($dto instanceof PrayerTimesDTO) {
                $this->cache->putDaily($provider->key(), $cell, $query->date, $dto);

                return $this->clock($dto, $reference, $query->timezone, false);
            }
        }

        if (! $negative) {
            $this->queueDailyRefresh($query, $provider->key(), $cell);
        }

        $stale = $this->cache->getStaleDaily($provider->key(), $cell, $query->date);

        if ($stale instanceof PrayerTimesDTO) {
            return $this->clock($stale, $reference, $query->timezone, true);
        }

        return null;
    }

    private function queueDailyRefresh(PrayerQuery $query, string $providerKey, string $cell): void
    {
        $this->dispatchRefresh('daily', [
            'provider' => $providerKey,
            'cell' => $cell,
            'date' => $query->date,
            'country' => $query->countryCode,
            'lat' => $query->latitude,
            'lng' => $query->longitude,
            'timezone' => $query->timezone,
            'method' => $query->method,
            'madhab' => $query->madhab,
        ]);
    }

    /**
     * Deferred refresh that can never break resolution. Unique-job locks
     * and queue drivers touch the default cache synchronously in some
     * contexts (console, sync driver); a cache outage there must degrade
     * to hardcoded timing, not abort the submit.
     *
     * @param  array<string, mixed>  $scope
     */
    private function dispatchRefresh(string $kind, array $scope): void
    {
        try {
            Bus::dispatchAfterResponse(new RefreshPrayerTimes($kind, $scope));
        } catch (Throwable $exception) {
            Log::debug('Prayer refresh dispatch failed; resolution degrades.', [
                'kind' => $kind,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{clock: string, instant: string, source: string, fetched_at: string, stale: bool}|null
     */
    private function fetchLiveZoned(PrayerQuery $query, string $zone, PrayerReference $reference): ?array
    {
        $yearMonth = substr($query->date, 0, 7);
        $monthlyKeys = [];

        foreach ($this->registry->orderedFor($query->countryCode) as $provider) {
            if ($provider instanceof MonthlyPrayerTimesProvider) {
                $monthlyKeys[] = $provider->key();
            }
        }

        foreach ($this->registry->orderedFor($query->countryCode) as $provider) {
            if (! $provider instanceof MonthlyPrayerTimesProvider) {
                continue;
            }

            // Coordinate-driven monthly providers compute the SHARED zone
            // month from canonical zone inputs — never the requester's
            // coords/timezone/settings — or one location pollutes the month
            // for the whole zone. Mirrors prefetch/refresh exactly.
            $fetchQuery = $provider->requiresZone() ? $query : $this->canonicalZoneQuery($query, $zone);

            // Priority-aware merge (mirrors the refresh job); a partial
            // month must not stop the search for the requested day.
            $days = $this->liveMonthly($provider, $fetchQuery);

            if ($days !== []) {
                $this->cache->putMonthly($zone, $yearMonth, $days, $query->countryCode, $monthlyKeys);
            }

            if (isset($days[$query->date])) {
                return $this->clock($days[$query->date], $reference, $query->timezone, false);
            }
        }

        // Monthly coverage failed: route through the standard per-provider
        // day resolution so warm daily cells serve before any live attempt
        // and preview matches what cache-only submission would read.
        if ($query->latitude !== null && $query->longitude !== null) {
            foreach ($this->registry->orderedFor($query->countryCode) as $provider) {
                if ($provider->requiresZone()) {
                    continue;
                }

                $served = $this->resolveProviderDay($provider, $query, $reference, true);

                if ($served !== null) {
                    return $served;
                }
            }
        }

        $this->cache->putNegative($this->cache->monthlyKey($zone, $yearMonth, $query->countryCode));

        return null;
    }

    private function liveDaily(PrayerTimesProvider $provider, PrayerQuery $query, string $cell): ?PrayerTimesDTO
    {
        $memoKey = $provider->key().'|'.$cell.'|'.$query->date;

        if (array_key_exists($memoKey, $this->liveDailyMemo)) {
            return $this->liveDailyMemo[$memoKey];
        }

        try {
            $dto = $provider->dailyPrayers($query);
        } catch (ProviderUnavailable) {
            return $this->liveDailyMemo[$memoKey] = null;
        }

        return $this->liveDailyMemo[$memoKey] = $dto;
    }

    /**
     * Canonical inputs for a shared zone month — identical to what the
     * refresh job warms and the cache validator re-checks, so preview,
     * prefetch, and pruning agree.
     */
    private function canonicalZoneQuery(PrayerQuery $query, string $zone): PrayerQuery
    {
        return $this->registry->canonicalZoneQuery($query->countryCode, $zone, $query->date);
    }

    /**
     * @return array<string, PrayerTimesDTO>
     */
    private function liveMonthly(MonthlyPrayerTimesProvider $provider, PrayerQuery $query): array
    {
        $memoKey = $provider->key().'|'.$query->countryCode.'|'.($query->zone ?? '').'|'.substr($query->date, 0, 7);

        if (array_key_exists($memoKey, $this->liveMonthlyMemo)) {
            return $this->liveMonthlyMemo[$memoKey];
        }

        try {
            $days = $provider->monthlyPrayers($query);
        } catch (ProviderUnavailable) {
            return $this->liveMonthlyMemo[$memoKey] = [];
        }

        return $this->liveMonthlyMemo[$memoKey] = $days;
    }

    private function cell(PrayerQuery $query, PrayerTimesProvider $provider): string
    {
        // Canonical identity lives on the cache so the deferred job
        // recomputes the identical cell for its drift guard.
        return $this->cache->dailyCell($query, $provider);
    }

    /**
     * @return array{clock: string, instant: string, source: string, fetched_at: string, stale: bool}|null
     */
    private function clock(PrayerTimesDTO $dto, PrayerReference $reference, string $timezone, bool $stale): ?array
    {
        $time = $dto->timeFor($reference->dtoKey());

        if (! $time instanceof CarbonImmutable) {
            return null;
        }

        // The UTC instant travels with the display clock: offset math must
        // run on the instant, since wall-clock reconstruction loses the
        // anchor's calendar date across DST folds and timezone boundaries.
        return [
            'clock' => $time->setTimezone($timezone)->format('H:i'),
            'instant' => $time->toIso8601String(),
            'source' => $dto->source,
            'fetched_at' => $dto->fetchedAt->toIso8601String(),
            'stale' => $stale,
        ];
    }
}
