<?php

declare(strict_types=1);

namespace App\Features\References\Persistence;

use App\Features\References\Api\ReferenceHomepageStats;
use App\Features\References\Models\Reference;
use Illuminate\Support\Facades\Cache;

final readonly class ReferenceHomepageStatsCacheStore implements ReferenceHomepageStats
{
    private const string COUNT_KEY = 'home.stats.references.active';

    private const int CACHE_TTL_SECONDS = 3600;

    public function rootReferenceCount(): int
    {
        return Cache::remember(self::COUNT_KEY, self::CACHE_TTL_SECONDS, fn (): int => Reference::active()->root()->count('id'));
    }

    public function forgetCachedCount(): void
    {
        Cache::forget(self::COUNT_KEY);
    }
}
