<?php

declare(strict_types=1);

namespace App\Features\Prayer\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PrayerMappingMetrics
{
    /**
     * Best-effort dated counters driving future per-country providers;
     * surfaced by the `app:prayer:stats` command. Never throws onto
     * resolution. Each day gets its own counter with bounded retention so
     * the report reflects the preceding seven days, not lifetime totals.
     */
    public function record(string $countryCode): void
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
