<?php

declare(strict_types=1);

namespace App\Features\Directory\Persistence;

use App\Features\Directory\Api\PersonSearchCacheVersion;
use Illuminate\Support\Facades\Cache;

/**
 * Public person-search cache version store.
 */
final readonly class PersonSearchCache implements PersonSearchCacheVersion
{
    private const string VERSION_KEY = 'person_search_public_version_v2';

    private const int DEFAULT_VERSION = 2;

    private const int VERSION_TTL_DAYS = 30;

    public function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, self::DEFAULT_VERSION);
    }

    public function bump(): void
    {
        Cache::put(
            self::VERSION_KEY,
            $this->version() + 1,
            now()->addDays(self::VERSION_TTL_DAYS),
        );
    }
}
