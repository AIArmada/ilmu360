<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Services\Prayer\ProviderUnavailable;

/**
 * Providers that can fetch a whole month in one call (bulk prefetch).
 *
 * Branch on `instanceof MonthlyPrayerTimesProvider`; there is no boolean flag.
 */
interface MonthlyPrayerTimesProvider extends PrayerTimesProvider
{
    /**
     * @return array<string, PrayerTimesDTO> date Y-m-d => DTO
     *
     * @throws ProviderUnavailable
     */
    public function monthlyPrayers(PrayerQuery $query): array;
}
