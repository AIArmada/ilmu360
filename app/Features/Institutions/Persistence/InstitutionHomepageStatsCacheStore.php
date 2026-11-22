<?php

declare(strict_types=1);

namespace App\Features\Institutions\Persistence;

use App\Features\Institutions\Api\InstitutionHomepageStats;
use App\Features\Institutions\Models\Institution;
use Illuminate\Support\Facades\Cache;

final readonly class InstitutionHomepageStatsCacheStore implements InstitutionHomepageStats
{
    private const string COUNT_KEY = 'home.stats.institutions.active';

    private const int CACHE_TTL_SECONDS = 3600;

    public function activeInstitutionCount(): int
    {
        $count = Cache::remember(self::COUNT_KEY, self::CACHE_TTL_SECONDS, fn (): int => Institution::active()->count('id'));

        return is_numeric($count) ? (int) $count : 0;
    }

    public function forgetCachedCount(): void
    {
        Cache::forget(self::COUNT_KEY);
    }
}
