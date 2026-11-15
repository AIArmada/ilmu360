<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Closure;

interface ProductSignalsDashboardCache
{
    /**
     * @template TDashboard of array
     *
     * @param  Closure(): TDashboard  $resolver
     * @return TDashboard
     */
    public function rememberDashboard(string $timezone, string $locale, Closure $resolver): array;
}
