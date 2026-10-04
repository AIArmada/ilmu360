<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use App\Contracts\PrayerTimesProvider;
use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Support\Prayer\PrayerClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fresh/stale/negative cache for provider payloads.
 *
 * Monthly payloads are immutable once published: fresh entries live until
 * month end plus grace, stale copies are retained for a year as the
 * fallback layer. Daily keys cover global coordinate lookups. Negative
 * entries stay short so unpublished months retry soon without poisoning
 * the stale layer.
 *
 * Every store interaction is exception-safe: reads degrade to misses and
 * writes become no-ops, so a down cache store collapses resolution to the
 * hardcoded floor instead of throwing onto the submit path.
 */
final class PrayerTimesCache
{
    private static int $storeDownWarnedAt = 0;

    public function __construct(
        private PrayerProviderRegistry $registry,
    ) {}

    public function monthlyKey(string $zone, string $yearMonth, string $country): string
    {
        return sprintf(
            '%s:zone:%s:%s:%s',
            $this->prefix(),
            strtoupper(trim($country)),
            strtoupper($zone),
            $yearMonth
        );
    }

    public function staleMonthlyKey(string $zone, string $yearMonth, string $country): string
    {
        return $this->monthlyKey($zone, $yearMonth, $country).':stale';
    }

    public function dailyKey(string $provider, string $cell, string $date): string
    {
        return sprintf('%s:%s:%s:%s', $this->prefix(), $provider, $cell, $date);
    }

    /**
     * Canonical daily-cell identity, shared by the anchor dispatcher and
     * the deferred job. Country and timezone ride along defensively:
     * anchors are UTC so the timezone never changes the instant, but
     * distinct identities keep cross-query reuse explicit instead of
     * assumed. The provider contributes its effective calculation
     * settings so a config edit misses the old entries instead of
     * reusing stale math — and so the job can detect a settings
     * change between dispatch and execution and discard the stale
     * refresh instead of writing new-config results into the old cell.
     */
    public function dailyCell(PrayerQuery $query, PrayerTimesProvider $provider): string
    {
        $identity = "{$query->countryCode}:{$query->timezone}:{$provider->cacheIdentitySegment($query)}";

        if ($query->latitude === null || $query->longitude === null) {
            return "{$identity}:no-coords";
        }

        return sprintf('%s:%.2F:%.2F', $identity, $query->latitude, $query->longitude);
    }

    public function staleDailyKey(string $provider, string $cell, string $date): string
    {
        return $this->dailyKey($provider, $cell, $date).':stale';
    }

    public function negativeKey(string $key): string
    {
        return $key.':negative';
    }

    /**
     * Days computed under superseded calculation inputs are pruned on
     * read: monthly keys carry no per-provider identity (mixed-source
     * months cannot key per provider), so a method/madhab config edit or
     * a representative-coordinates/timezone change must not keep serving
     * the old math. Pruned days read as gaps and heal through the normal
     * refresh path.
     *
     * @return array<string, PrayerTimesDTO>|null date Y-m-d => DTO
     */
    public function getMonthly(string $zone, string $yearMonth, string $country): ?array
    {
        return $this->pruneObsoleteDays($this->getMonthPayload($this->monthlyKey($zone, $yearMonth, $country)), $country, $zone);
    }

    /**
     * @return array<string, PrayerTimesDTO>|null date Y-m-d => DTO
     */
    public function getStaleMonthly(string $zone, string $yearMonth, string $country): ?array
    {
        return $this->pruneObsoleteDays($this->getMonthPayload($this->staleMonthlyKey($zone, $yearMonth, $country)), $country, $zone);
    }

    /**
     * @param  array<string, PrayerTimesDTO>|null  $days
     * @return array<string, PrayerTimesDTO>|null
     */
    private function pruneObsoleteDays(?array $days, string $country, string $zone): ?array
    {
        if (! is_array($days) || $days === []) {
            return $days;
        }

        $providers = $this->registry->allProviders();
        $kept = [];

        foreach ($days as $date => $dto) {
            if ($this->isSourceCurrent($dto->source, $country, $providers)
                && $this->isCalcCurrent($dto, $country, $zone, $providers)) {
                $kept[$date] = $dto;
            }
        }

        return $kept === [] ? null : $kept;
    }

    /**
     * True when a stored row's calc fingerprint matches today's canonical
     * zone inputs. Authoritative rows (null expected fingerprint) and
     * unknown sources always keep; like source pruning, validator
     * failures fail open so a resolver outage never wipes the month.
     *
     * @param  list<PrayerTimesProvider>  $providers
     */
    private function isCalcCurrent(PrayerTimesDTO $dto, string $country, string $zone, array $providers): bool
    {
        foreach ($providers as $provider) {
            if (! str_starts_with($dto->source, $provider->key().':')) {
                continue;
            }

            try {
                $expected = $provider->calcFingerprint(
                    $this->registry->canonicalZoneQuery($country, $zone, $dto->date)
                );
            } catch (Throwable) {
                return true;
            }

            if ($expected === null) {
                return true;
            }

            return $dto->calcFingerprint !== null && $dto->calcFingerprint === $expected;
        }

        return true;
    }

    /**
     * Unanimous-keep, single-veto: any provider may reject its own stale
     * rows, but pruning bugs fail open — a validator that throws keeps
     * the day rather than wiping the month.
     *
     * @param  list<PrayerTimesProvider>  $providers
     */
    private function isSourceCurrent(string $source, string $country, array $providers): bool
    {
        foreach ($providers as $provider) {
            try {
                if (! $provider->isSourceCurrent($source, $country)) {
                    return false;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return true;
    }

    /**
     * Merges new days over the stored month per layer: providers skip
     * malformed rows, so a partial fetch must fill gaps — never wipe a
     * complete stale month or unserve days the new payload lacks.
     *
     * With ordered provider keys, higher-priority stored days survive a
     * lower-priority fetch across runs (secondary results fill gaps but
     * never replace retained primary coverage); same-rank days refresh.
     *
     * @param  array<string, PrayerTimesDTO>  $days  date Y-m-d => DTO
     * @param  list<string>|null  $providerKeys  highest priority first
     */
    public function putMonthly(string $zone, string $yearMonth, array $days, string $country, ?array $providerKeys = null): void
    {
        if ($days === []) {
            return;
        }

        $payload = [];

        foreach ($days as $date => $dto) {
            $payload[$date] = $this->serialize($dto);
        }

        $freshTtl = $this->secondsUntilMonthEndGrace($yearMonth);
        $freshKey = $this->monthlyKey($zone, $yearMonth, $country);
        $staleKey = $this->staleMonthlyKey($zone, $yearMonth, $country);

        $write = function () use ($payload, $freshKey, $staleKey, $freshTtl, $providerKeys, $country, $zone): void {
            $fresh = $this->swallow(fn (): mixed => $this->store()->get($freshKey));
            $mergedFresh = $this->mergeDays(is_array($fresh) ? $fresh : [], $payload, $providerKeys, $country, $zone);

            $stale = $this->swallow(fn (): mixed => $this->store()->get($staleKey));
            $mergedStale = $this->mergeDays(is_array($stale) ? $stale : [], $payload, $providerKeys, $country, $zone);

            $this->swallow(fn (): mixed => $this->store()->put($freshKey, $mergedFresh, $freshTtl));
            $this->swallow(fn (): mixed => $this->store()->put($staleKey, $mergedStale, 366 * 86400));
        };

        // The read/merge/write sequence runs under a dedicated per-scope
        // merge lock: the refresh job's outer lock does not protect
        // preview writers, and an unlocked secondary write could clobber
        // primary coverage another worker just published. A distinct name
        // from the job lock avoids self-deadlock when the job merges. A
        // contended or dead store skips this write; the next refresh
        // retries. Never breaks resolution.
        try {
            $store = $this->store()->getStore();

            if ($store instanceof LockProvider) {
                $store->lock("prayer:lock:zone-merge:{$country}:{$zone}:{$yearMonth}", 10)->block(5, $write);
            } else {
                $write();
            }
        } catch (Throwable $exception) {
            Log::debug('Prayer month merge skipped on lock contention or store failure.', [
                'zone' => $zone,
                'month' => $yearMonth,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @param  array<string, array<string, mixed>>  $incoming
     * @param  list<string>|null  $providerKeys
     * @return array<string, array<string, mixed>>
     */
    private function mergeDays(array $stored, array $incoming, ?array $providerKeys, string $country, string $zone): array
    {
        if ($providerKeys === null) {
            return array_merge($stored, $incoming);
        }

        $rank = static function (mixed $row) use ($providerKeys): int {
            $source = is_array($row) ? ($row['source'] ?? '') : '';

            foreach ($providerKeys as $index => $key) {
                if (is_string($source) && str_starts_with($source, $key.':')) {
                    return $index;
                }
            }

            // Unknown sources always lose: any known fetch replaces them.
            return count($providerKeys);
        };

        foreach ($incoming as $date => $row) {
            // A stored row the readers would reject or prune must not
            // outrank valid incoming data for the same day: a poisoned
            // primary would otherwise block fallback repairs on both
            // layers while preview serves the incoming row directly.
            if (is_string($date) && isset($stored[$date]) && $this->isStoredRowUnservable($stored[$date], $date, $country, $zone)) {
                unset($stored[$date]);
            }

            if (! isset($stored[$date]) || $rank($row) <= $rank($stored[$date])) {
                $stored[$date] = $row;
            }
        }

        return $stored;
    }

    /**
     * True when the readers would reject the stored row (wrong-day
     * anchors) or prune it (superseded calculation inputs). Fail-open:
     * a validator outage keeps the row rather than dropping coverage.
     */
    private function isStoredRowUnservable(mixed $row, string $date, string $country, string $zone): bool
    {
        try {
            $dto = $this->deserialize($row, $date);

            if (! $dto instanceof PrayerTimesDTO) {
                return true;
            }

            $providers = $this->registry->allProviders();

            return ! $this->isSourceCurrent($dto->source, $country, $providers)
                || ! $this->isCalcCurrent($dto, $country, $zone, $providers);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * True when the month holds every expected calendar day from the given
     * source prefix (e.g. `jakim:`). Row counts alone cannot prove this:
     * invalid or outside-month rows must never stand in for a required
     * day. Partial or fallback-filled months report false so refresh and
     * prefetch keep healing them.
     *
     * @param  array<string, PrayerTimesDTO>|null  $days
     */
    public function isFullySourced(?array $days, string $sourcePrefix, string $yearMonth): bool
    {
        if (! is_array($days)) {
            return false;
        }

        foreach (self::monthDates($yearMonth) as $date) {
            $dto = $days[$date] ?? null;

            if (! $dto instanceof PrayerTimesDTO || ! str_starts_with($dto->source, $sourcePrefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string> every Y-m-d in the month
     */
    public static function monthDates(string $yearMonth): array
    {
        $start = CarbonImmutable::parse($yearMonth.'-01', 'UTC');
        $dates = [];

        for ($day = 1; $day <= $start->daysInMonth; $day++) {
            $dates[] = $start->setDay($day)->format('Y-m-d');
        }

        return $dates;
    }

    public function getDaily(string $provider, string $cell, string $date): ?PrayerTimesDTO
    {
        return $this->deserialize($this->swallow(fn (): mixed => $this->store()->get($this->dailyKey($provider, $cell, $date))), $date);
    }

    public function getStaleDaily(string $provider, string $cell, string $date): ?PrayerTimesDTO
    {
        return $this->deserialize($this->swallow(fn (): mixed => $this->store()->get($this->staleDailyKey($provider, $cell, $date))), $date);
    }

    public function putDaily(string $provider, string $cell, string $date, PrayerTimesDTO $dto): void
    {
        $payload = $this->serialize($dto);

        $this->swallow(fn (): mixed => $this->store()->put($this->dailyKey($provider, $cell, $date), $payload, $this->dailyTtl()));
        $this->swallow(fn (): mixed => $this->store()->put($this->staleDailyKey($provider, $cell, $date), $payload, 30 * 86400));
    }

    public function hasNegative(string $key): bool
    {
        return (bool) $this->swallow(fn (): mixed => $this->store()->has($this->negativeKey($key)), false);
    }

    public function putNegative(string $key): void
    {
        $this->swallow(fn (): mixed => $this->store()->put($this->negativeKey($key), true, (int) config('prayer.cache.negative_ttl', 300)));
    }

    public function forget(string $key): void
    {
        $this->swallow(fn (): mixed => $this->store()->forget($key));
    }

    /**
     * @return array<string, PrayerTimesDTO>|null
     */
    private function getMonthPayload(string $key): ?array
    {
        $payload = $this->swallow(fn (): mixed => $this->store()->get($key));

        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $days = [];

        foreach ($payload as $date => $row) {
            $dto = is_string($date) ? $this->deserialize($row, $date) : null;

            if ($dto instanceof PrayerTimesDTO) {
                $days[$date] = $dto;
            }
        }

        return $days === [] ? null : $days;
    }

    /**
     * @return array{times: array<string, string>, source: string, fetched_at: string, tz: string, date: string, zone: string|null, calc: string|null}
     */
    private function serialize(PrayerTimesDTO $dto): array
    {
        $times = [];

        foreach ($dto->timesUtc as $key => $time) {
            $times[$key] = $time->toIso8601String();
        }

        return [
            'times' => $times,
            'source' => $dto->source,
            'fetched_at' => $dto->fetchedAt->toIso8601String(),
            'tz' => $dto->timezoneUsed,
            'date' => $dto->date,
            'zone' => $dto->zoneOrCell,
            'calc' => $dto->calcFingerprint,
        ];
    }

    /**
     * Rows accepted before provider-day validation shipped may carry
     * wrong-day anchors (stale copies live a year), so every instant is
     * re-checked against the serving date: the row's own date must match
     * its key, and each instant's local date must be the prayer day
     * (isha alone may spill into the next pre-dawn). Rejected rows read
     * as gaps; refresh rewrites them and stale/floor cover the gap.
     */
    private function deserialize(mixed $row, string $expectedDate): ?PrayerTimesDTO
    {
        if (! is_array($row) || ! isset($row['times'], $row['source'], $row['fetched_at'], $row['tz'], $row['date'])) {
            return null;
        }

        try {
            if (! is_array($row['times']) || ($row['date'] ?? null) !== $expectedDate) {
                return null;
            }

            $timezone = (string) $row['tz'];
            $times = [];

            foreach ($row['times'] as $key => $iso) {
                if (! is_string($key) || ! is_string($iso)) {
                    return null;
                }

                $instant = CarbonImmutable::parse($iso, 'UTC');

                if (! PrayerClock::isOnPrayerDay($instant, $expectedDate, $timezone, $key === 'isha')) {
                    return null;
                }

                $times[$key] = $instant;
            }

            // Same-day isha preceding maghrib is the previous night's
            // anchor: reject the cached row on every layer.
            if (! PrayerClock::isIshaAfterMaghrib($times)) {
                return null;
            }

            $calc = $row['calc'] ?? null;

            return new PrayerTimesDTO(
                timesUtc: $times,
                source: (string) $row['source'],
                fetchedAt: CarbonImmutable::parse($row['fetched_at'], 'UTC'),
                timezoneUsed: $timezone,
                date: (string) $row['date'],
                zoneOrCell: isset($row['zone']) ? (string) $row['zone'] : null,
                calcFingerprint: is_string($calc) ? $calc : null,
            );
        } catch (Throwable) {
            // Corrupt entries degrade to misses; the refresh path rewrites them.
            return null;
        }
    }

    private function secondsUntilMonthEndGrace(string $yearMonth): int
    {
        $expiry = CarbonImmutable::parse($yearMonth.'-01', 'UTC')->endOfMonth()->addDays(3);

        return max(3600, $expiry->getTimestamp() - time());
    }

    private function dailyTtl(): int
    {
        return (int) config('prayer.cache.daily_ttl', 86400);
    }

    private function prefix(): string
    {
        return (string) config('prayer.cache.prefix', 'prayer:v3');
    }

    private function store(): Repository
    {
        /** @var string|null $store */
        $store = config('prayer.cache.store');

        return Cache::store($store);
    }

    private function storeName(): string
    {
        return (string) (config('prayer.cache.store') ?? config('cache.default'));
    }

    /**
     * Per-scope refresh lock on the prayer store — never the default
     * store, so prayer survives a prayer-store outage end to end.
     * Intentionally throwing: callers run inside the job's catch.
     */
    public function lock(string $name, int $seconds = 60): Lock
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException("Prayer cache store [{$this->storeName()}] does not support locks.");
        }

        return $store->lock($name, $seconds);
    }

    private function swallow(callable $operation, mixed $fallback = null): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            // Throttled so a sustained outage warns every few minutes
            // instead of once per worker lifetime or once per call.
            if (time() - self::$storeDownWarnedAt >= 300) {
                self::$storeDownWarnedAt = time();

                Log::warning('Prayer cache store is unreachable; degrading to misses.', [
                    'error' => $exception->getMessage(),
                ]);
            } else {
                Log::debug('Prayer cache store still unreachable.', [
                    'error' => $exception->getMessage(),
                ]);
            }

            return $fallback;
        }
    }
}
