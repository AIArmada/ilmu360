<?php

declare(strict_types=1);

namespace App\Features\Directory\Persistence;

use App\Features\Directory\Api\PublicDirectoryStats;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use Illuminate\Support\Facades\Cache;

final readonly class PublicDirectoryStatsCacheStore implements PublicDirectoryStats
{
    private const string ACTIVE_PERSON_COUNT_KEY = 'home.stats.persons.active';

    private const string ACTIVE_INSTITUTION_COUNT_KEY = 'home.stats.institutions.active';

    private const string ROOT_REFERENCE_COUNT_KEY = 'home.stats.references.active';

    private const int CACHE_TTL_SECONDS = 3600;

    public function activePersonCount(): int
    {
        return Cache::remember(
            self::ACTIVE_PERSON_COUNT_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): int => Person::active()->count('id'),
        );
    }

    public function activeInstitutionCount(): int
    {
        return Cache::remember(
            self::ACTIVE_INSTITUTION_COUNT_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): int => Institution::active()->count('id'),
        );
    }

    public function rootReferenceCount(): int
    {
        return Cache::remember(
            self::ROOT_REFERENCE_COUNT_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): int => Reference::active()->root()->count('id'),
        );
    }

    public function forgetCachedCounts(): void
    {
        Cache::forget(self::ACTIVE_PERSON_COUNT_KEY);
        Cache::forget(self::ACTIVE_INSTITUTION_COUNT_KEY);
        Cache::forget(self::ROOT_REFERENCE_COUNT_KEY);
    }
}
