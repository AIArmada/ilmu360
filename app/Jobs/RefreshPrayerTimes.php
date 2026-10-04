<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\MonthlyPrayerTimesProvider;
use App\Contracts\PrayerTimesProvider;
use App\Data\Prayer\PrayerQuery;
use App\Services\Prayer\PrayerProviderRegistry;
use App\Services\Prayer\PrayerTimesCache;
use App\Services\Prayer\ProviderUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refresh one cached prayer-times scope off the submit path.
 *
 * Zone-months refresh through the first monthly-capable provider for the
 * scope country; global days refresh through the first daily provider for
 * the country. Failures degrade to negative/stale layers and never throw
 * onto a request.
 *
 * Deliberately not ShouldBeUnique: Laravel releases the unique lock outside
 * application catch boundaries, so a dead cache store would throw onto
 * request termination. Stampede protection lives in the per-scope locks
 * below, inside the handle() catch.
 */
class RefreshPrayerTimes implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Zone-month scopes ignore any requester timezone: the job derives the
     * canonical country timezone itself. Daily scopes carry their full
     * calculation identity (cell + coords + methods).
     *
     * @param  array{zone: string, year_month: string, country: string}|array{provider: string, cell: string, date: string, country: string, lat: float, lng: float, timezone: string, method?: string|null, madhab?: string|null}  $scope
     */
    public function __construct(
        public string $kind,
        public array $scope,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        PrayerProviderRegistry $registry,
        PrayerTimesCache $cache,
    ): void {
        // Best-effort warming: a dead cache store (or any other infra
        // failure) drops the refresh instead of throwing onto the request
        // that deferred it. The next cache miss re-dispatches.
        try {
            if ($this->kind === 'zone-month') {
                $this->refreshZoneMonth($registry, $cache);

                return;
            }

            $this->refreshDaily($registry, $cache);
        } catch (Throwable $exception) {
            Log::warning('Prayer refresh dropped on infrastructure failure.', [
                'kind' => $this->kind,
                'scope' => $this->scope,
                'exception' => $exception,
            ]);
        }
    }

    private function refreshZoneMonth(
        PrayerProviderRegistry $registry,
        PrayerTimesCache $cache,
    ): void {
        $zone = strtoupper((string) ($this->scope['zone'] ?? ''));
        $yearMonth = (string) ($this->scope['year_month'] ?? '');
        $country = strtoupper((string) ($this->scope['country'] ?? ''));

        if ($zone === '' || $yearMonth === '' || $country === '') {
            return;
        }

        $zones = $registry->zoneResolverFor($country);

        if (! $zones->isKnownZone($zone)) {
            Log::warning('Prayer prefetch skipped an unknown prayer zone; snapshot may be stale.', ['zone' => $zone, 'country' => $country]);

            return;
        }

        $providers = $this->monthlyProviders($registry, $country);

        if ($providers === []) {
            return;
        }

        // Canonical shared-month inputs (country timezone, representative
        // coords, default methods) — identical to preview and the cache's
        // fingerprint validator. A scope/requester timezone (e.g. a MY
        // submission viewed from abroad) would poison the shared month
        // through fallback providers that consume it.
        $query = $registry->canonicalZoneQuery($country, $zone, $yearMonth.'-01');

        $lock = $cache->lock("prayer:lock:zone:{$country}:{$zone}:{$yearMonth}", 60);

        $lock->block(30, function () use ($providers, $cache, $zone, $yearMonth, $query, $country): void {
            if ($cache->hasNegative($cache->monthlyKey($zone, $yearMonth, $country))) {
                return;
            }

            $stored = $cache->getMonthly($zone, $yearMonth, $country);
            $expectedDates = PrayerTimesCache::monthDates($yearMonth);

            // A fallback-filled or partial month is retried so the key
            // self-heals to complete primary coverage.
            if ($cache->isFullySourced($stored, $providers[0]->key().':', $yearMonth)) {
                return;
            }

            $reason = null;
            $filled = false;
            $have = $stored !== null ? array_keys($stored) : [];
            $providerKeys = array_map(static fn (MonthlyPrayerTimesProvider $provider): string => $provider->key(), $providers);

            foreach ($providers as $provider) {
                try {
                    $days = $provider->monthlyPrayers($query);
                } catch (ProviderUnavailable $exception) {
                    $reason = $exception->getMessage();

                    continue;
                }

                if ($days === []) {
                    $reason = 'empty month';

                    continue;
                }

                $filled = true;

                // Priority-aware merge: higher-priority stored days survive
                // across runs while gaps fill from lower providers.
                $cache->putMonthly($zone, $yearMonth, $days, $country, $providerKeys);
                $have = array_unique(array_merge($have, array_keys($days)));

                // Set-based: every expected calendar day must be covered,
                // since row counts can hide outside-month junk rows.
                if (array_diff($expectedDates, $have) === []) {
                    return;
                }
            }

            if ($filled) {
                Log::info('Prayer month refresh partially filled.', ['zone' => $zone, 'month' => $yearMonth]);

                return;
            }

            $cache->putNegative($cache->monthlyKey($zone, $yearMonth, $country));

            Log::info('Prayer month refresh deferred.', ['zone' => $zone, 'month' => $yearMonth, 'reason' => $reason]);
        });
    }

    private function refreshDaily(PrayerProviderRegistry $registry, PrayerTimesCache $cache): void
    {
        $providerKey = (string) ($this->scope['provider'] ?? '');
        $country = strtoupper((string) ($this->scope['country'] ?? ''));
        $cell = (string) ($this->scope['cell'] ?? '');
        $date = (string) ($this->scope['date'] ?? '');
        $timezone = $this->scope['timezone'] ?? null;
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : null;

        if ($providerKey === '' || $country === '' || $cell === '' || $date === '' || $timezone === null) {
            return;
        }

        $provider = $this->firstProvider($registry, $country, $providerKey);

        if (! $provider instanceof PrayerTimesProvider) {
            return;
        }

        $query = new PrayerQuery(
            $country,
            $date,
            $timezone,
            isset($this->scope['lat']) ? (float) $this->scope['lat'] : null,
            isset($this->scope['lng']) ? (float) $this->scope['lng'] : null,
            method: $this->scope['method'] ?? null,
            madhab: $this->scope['madhab'] ?? null,
        );

        // Config-drift guard: the dispatched cell encodes dispatch-time
        // effective settings, but providers like Aladhan read their
        // numeric method from execution-time config. A settings change
        // between dispatch and execution would otherwise write
        // new-config results into the old-config cell, which a rollback
        // or an older worker could then serve. Discard the stale job
        // before any fetch; current-config reads dispatch their own
        // correctly-scoped refresh.
        if ($cache->dailyCell($query, $provider) !== $cell) {
            Log::info('Prayer day refresh discarded on calculation drift.', [
                'provider' => $providerKey,
                'cell' => $cell,
                'date' => $date,
            ]);

            return;
        }

        if ($this->dayIsCovered($cache, $providerKey, $cell, $date)) {
            return;
        }

        // Non-blocking: a duplicate warming job simply drops instead of
        // queueing provider HTTP behind the in-flight refresh.
        $lock = $cache->lock("prayer:lock:daily:{$country}:{$providerKey}:{$cell}:{$date}", 60);

        if (! $lock->get()) {
            return;
        }

        try {
            // Re-checked after the lock: a sibling may have warmed the day
            // while this job waited on acquisition. PHPStan assumes pure
            // calls, but cache state mutates across processes.
            // @phpstan-ignore if.alwaysFalse
            if ($this->dayIsCovered($cache, $providerKey, $cell, $date)) {
                return;
            }

            try {
                $dto = $provider->dailyPrayers($query);
            } catch (ProviderUnavailable $exception) {
                $cache->putNegative($cache->dailyKey($providerKey, $cell, $date));

                Log::info('Prayer day refresh deferred.', ['provider' => $providerKey, 'cell' => $cell, 'date' => $date, 'reason' => $exception->getMessage()]);

                return;
            }

            $cache->putDaily($providerKey, $cell, $date, $dto);
        } finally {
            $lock->release();
        }
    }

    private function dayIsCovered(PrayerTimesCache $cache, string $providerKey, string $cell, string $date): bool
    {
        return $cache->getDaily($providerKey, $cell, $date) !== null
            || $cache->hasNegative($cache->dailyKey($providerKey, $cell, $date));
    }

    /**
     * @return list<MonthlyPrayerTimesProvider>
     */
    private function monthlyProviders(PrayerProviderRegistry $registry, string $country): array
    {
        $providers = [];

        foreach ($registry->orderedFor($country) as $provider) {
            if ($provider instanceof MonthlyPrayerTimesProvider) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    private function firstProvider(PrayerProviderRegistry $registry, string $country, string $key): ?PrayerTimesProvider
    {
        foreach ($registry->orderedFor($country) as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        return null;
    }
}
