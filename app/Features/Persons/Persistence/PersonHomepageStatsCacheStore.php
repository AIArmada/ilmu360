<?php

declare(strict_types=1);

namespace App\Features\Persons\Persistence;

use App\Features\Persons\Api\PersonHomepageStats;
use App\Features\Persons\Models\Person;
use Illuminate\Support\Facades\Cache;

final readonly class PersonHomepageStatsCacheStore implements PersonHomepageStats
{
    private const string COUNT_KEY = 'home.stats.persons.active';

    private const int CACHE_TTL_SECONDS = 3600;

    public function activePersonCount(): int
    {
        return Cache::remember(self::COUNT_KEY, self::CACHE_TTL_SECONDS, fn (): int => Person::active()->count('id'));
    }

    public function forgetCachedCount(): void
    {
        Cache::forget(self::COUNT_KEY);
    }
}
