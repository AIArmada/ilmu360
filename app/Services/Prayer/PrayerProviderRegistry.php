<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use App\Contracts\MonthlyPrayerTimesProvider;
use App\Contracts\PrayerTimesProvider;
use App\Contracts\PrayerZoneResolver;
use App\Data\Prayer\PrayerQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orders providers per country from configuration.
 *
 * New countries enter by adding a mapping row (and optionally a provider),
 * never by branching callers. Unmapped countries use the global default
 * chain and emit a missing-mapping warning that drives future providers.
 */
final class PrayerProviderRegistry
{
    /**
     * @return list<PrayerTimesProvider>
     */
    public function orderedFor(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $order = config("prayer.providers.{$countryCode}");

        if (! is_array($order)) {
            Log::warning('No prayer provider mapping for country; using global default.', [
                'country' => $countryCode,
            ]);

            $this->countMissingMapping($countryCode);

            $order = config('prayer.providers_default', []);
        }

        $map = config('prayer.provider_map', []);
        $providers = [];

        foreach ($order as $key) {
            $class = is_array($map) ? ($map[$key] ?? null) : null;

            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $provider = app($class);

            if ($provider instanceof PrayerTimesProvider && $provider->supports($countryCode)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Every mapped provider instance, regardless of country chain. Used
     * for source-currency vetoes: a provider dropped from a country's
     * chain must still veto its own stale rows. Emits no warnings and
     * records no missing-mapping metrics — this is local validation,
     * not resolution.
     *
     * @return list<PrayerTimesProvider>
     */
    public function allProviders(): array
    {
        $map = config('prayer.provider_map', []);
        $providers = [];

        if (! is_array($map)) {
            return [];
        }

        foreach ($map as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $provider = app($class);

            if ($provider instanceof PrayerTimesProvider) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Zone resolver for a country. Unmapped countries are coordinate-only
     * and silently receive the null adapter — most countries need no zone
     * machinery, so unlike provider mappings this emits no warning.
     */
    public function zoneResolverFor(string $countryCode): PrayerZoneResolver
    {
        $countryCode = strtoupper(trim($countryCode));
        $map = config('prayer.zone_resolvers', []);
        $class = is_array($map) ? ($map[$countryCode] ?? null) : null;

        if (is_string($class) && class_exists($class)) {
            $resolver = app($class);

            if ($resolver instanceof PrayerZoneResolver) {
                return $resolver;
            }
        }

        $default = config('prayer.zone_resolver_default', NullZoneResolver::class);

        if (is_string($default) && class_exists($default)) {
            $resolver = app($default);

            if ($resolver instanceof PrayerZoneResolver) {
                return $resolver;
            }
        }

        return new NullZoneResolver;
    }

    /**
     * Resolved calculation settings for coordinate providers. Both submit
     * and preview build their queries through here so daily cache cells
     * share one identity per country.
     *
     * @return array{ummah: string|null, madhab: string|null}
     */
    public function methodsFor(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $methods = config("prayer.methods.{$countryCode}", config('prayer.methods.default', []));

        if (! is_array($methods)) {
            return ['ummah' => null, 'madhab' => null];
        }

        $ummah = $methods['ummah'] ?? null;
        $madhab = $methods['madhab'] ?? null;

        return [
            'ummah' => is_string($ummah) ? $ummah : null,
            'madhab' => is_string($madhab) ? $madhab : null,
        ];
    }

    /**
     * Display timezone for a country (preview endpoint, prefetch month
     * boundaries, job payload defaults). Unmapped countries use UTC.
     */
    public function timezoneFor(string $countryCode): string
    {
        return (string) config(
            'prayer.country_timezones.'.strtoupper(trim($countryCode)),
            config('prayer.country_timezone_default', 'UTC'),
        );
    }

    /**
     * Canonical inputs for a shared zone month: representative coords,
     * country timezone, config-default methods. The refresh job, live
     * preview, and the cache's fingerprint validator all build through
     * here so warming and validation can never drift apart.
     */
    public function canonicalZoneQuery(string $countryCode, string $zone, string $date): PrayerQuery
    {
        $countryCode = strtoupper(trim($countryCode));
        $zone = strtoupper(trim($zone));
        $coords = $this->zoneResolverFor($countryCode)->coordsForZone($zone);

        return new PrayerQuery(
            $countryCode,
            $date,
            $this->timezoneFor($countryCode),
            $coords['lat'] ?? null,
            $coords['lng'] ?? null,
            $zone,
        );
    }

    /**
     * Key of the first monthly-capable provider for a country, or null
     * when the country warms through daily cells only.
     */
    public function primaryMonthlyKey(string $countryCode): ?string
    {
        foreach ($this->orderedFor($countryCode) as $provider) {
            if ($provider instanceof MonthlyPrayerTimesProvider) {
                return $provider->key();
            }
        }

        return null;
    }

    /**
     * Best-effort dated counters driving future per-country providers;
     * surfaced by the `app:prayer:stats` command. Never throws onto
     * resolution. Each day gets its own counter with bounded retention so
     * the report reflects the preceding seven days, not lifetime totals.
     */
    private function countMissingMapping(string $countryCode): void
    {
        try {
            /** @var string|null $store */
            $store = config('prayer.cache.store');
            $repository = Cache::store($store);
            $prefix = (string) config('prayer.cache.prefix', 'prayer:v3');
            $today = CarbonImmutable::now('UTC')->format('Y-m-d');

            $counter = "{$prefix}:metric:missing-mapping:{$countryCode}:{$today}";
            $key = "{$prefix}:metric:missing-mapping:countries";
            $append = function () use ($repository, $key, $counter, $countryCode): void {
                // Database-store increment returns false on missing keys;
                // seed first so every store counts from one. The count
                // mutates under the same lock as the index: FileStore's
                // increment is read-modify-write, so an unlocked count
                // loses simultaneous records.
                $repository->add($counter, 0, 8 * 86400);
                $repository->increment($counter);

                $countries = (array) $repository->get($key, []);

                if (! in_array($countryCode, $countries, true)) {
                    $countries[] = $countryCode;
                }

                // Renewed on every record so continuously active countries never
                // drop out of the report.
                $repository->put($key, $countries, 8 * 86400);
            };

            // Read-modify-write under a short lock: two workers registering
            // at once must not clobber each other's index entry or lose a
            // count. A contended update throws into the best-effort catch
            // below and drops this record; the next record retries.
            $storeInstance = $repository->getStore();

            if ($storeInstance instanceof LockProvider) {
                $storeInstance->lock("{$key}:lock", 10)->block(3, $append);
            } else {
                $append();
            }
        } catch (Throwable) {
            // Metrics must never break resolution.
        }
    }
}
