<?php

declare(strict_types=1);

namespace App\Features\Directory\Persistence;

use Illuminate\Support\Facades\Cache;

/**
 * Public person-search cache version bump.
 */
final readonly class PersonSearchCache
{
    private const string VERSION_KEY = 'person_search_public_version_v2';

    public function bump(): void
    {
        Cache::put(
            self::VERSION_KEY,
            (int) Cache::get(self::VERSION_KEY, 2) + 1,
            now()->addDays(30),
        );
    }
}
