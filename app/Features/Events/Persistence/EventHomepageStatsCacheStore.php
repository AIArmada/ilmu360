<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\EventHomepageStats;
use App\Features\Events\Models\Event;
use Illuminate\Support\Facades\Cache;

final readonly class EventHomepageStatsCacheStore implements EventHomepageStats
{
    private const string ACTIVE_COUNT_KEY = 'home.stats.events.active';

    private const int CACHE_TTL_SECONDS = 3600;

    public function activeEventCount(): int
    {
        $count = Cache::remember(
            self::ACTIVE_COUNT_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): int => Event::active()->count('id'),
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function forgetCachedCount(): void
    {
        Cache::forget(self::ACTIVE_COUNT_KEY);
    }
}
