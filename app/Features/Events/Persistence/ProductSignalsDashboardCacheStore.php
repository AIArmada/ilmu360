<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\ProductSignalsDashboardCache;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class ProductSignalsDashboardCacheStore implements ProductSignalsDashboardCache
{
    private const int CACHE_TTL_SECONDS = 60;

    /**
     * @template TDashboard of array
     *
     * @param  Closure(): TDashboard  $resolver
     * @return TDashboard
     */
    public function rememberDashboard(string $timezone, string $locale, Closure $resolver): array
    {
        return Cache::remember(
            'filament.product-signals.dashboard:'.$timezone.':'.$locale,
            self::CACHE_TTL_SECONDS,
            $resolver,
        );
    }
}
