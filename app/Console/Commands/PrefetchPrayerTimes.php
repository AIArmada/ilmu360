<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RefreshPrayerTimes;
use App\Services\Prayer\PrayerProviderRegistry;
use App\Services\Prayer\PrayerTimesCache;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PrefetchPrayerTimes extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:prayer:prefetch
                            {--country=MY : Country code for zone warming}
                            {--zone= : Single zone code (default: all zones for the country)}
                            {--month= : Single YYYY-MM month (default: current and next month)}
                            {--dry-run : Report what would be dispatched without dispatching}';

    /**
     * @var string
     */
    protected $description = 'Warm cached prayer months so submissions resolve without live HTTP';

    public function handle(PrayerTimesCache $cache, PrayerProviderRegistry $providers): int
    {
        $country = strtoupper(trim((string) $this->option('country')));
        $zones = $providers->zoneResolverFor($country);
        $onlyZone = $this->option('zone') !== null ? strtoupper((string) $this->option('zone')) : null;

        if ($onlyZone !== null && ! $zones->isKnownZone($onlyZone)) {
            $this->error("Unknown prayer zone [{$onlyZone}] for country [{$country}].");

            return self::FAILURE;
        }

        $months = $this->targetMonths($country, $providers);

        if ($months === []) {
            $this->error('Month must look like YYYY-MM.');

            return self::FAILURE;
        }

        $zoneCodes = $onlyZone !== null ? [$onlyZone] : $zones->zoneCodes();
        $primaryKey = $providers->primaryMonthlyKey($country);
        $dispatched = 0;
        $skipped = 0;

        foreach ($zoneCodes as $zone) {
            foreach ($months as $yearMonth) {
                $warm = $cache->getMonthly($zone, $yearMonth, $country);

                // Warm months still refresh when they are not fully
                // primary-sourced: a fallback-filled or partial month must
                // heal once the primary recovers instead of serving gaps.
                $healed = $primaryKey !== null && $cache->isFullySourced($warm, $primaryKey.':', $yearMonth);

                if ($healed || $cache->hasNegative($cache->monthlyKey($zone, $yearMonth, $country))) {
                    $skipped++;

                    continue;
                }

                if (! $this->option('dry-run')) {
                    // The job derives the canonical country timezone itself.
                    RefreshPrayerTimes::dispatch('zone-month', [
                        'zone' => $zone,
                        'year_month' => $yearMonth,
                        'country' => $country,
                    ]);
                }

                $dispatched++;
            }
        }

        $this->info("Prayer prefetch: {$dispatched} refreshes dispatched, {$skipped} already warm.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function targetMonths(string $country, PrayerProviderRegistry $providers): array
    {
        $month = $this->option('month');

        if (is_string($month) && $month !== '') {
            return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? [$month] : [];
        }

        // Month boundaries in the country's own timezone.
        $now = CarbonImmutable::now($providers->timezoneFor($country));

        return [$now->format('Y-m'), $now->addMonthNoOverflow()->format('Y-m')];
    }
}
