<?php

declare(strict_types=1);

namespace App\Platform\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache access for the public institution search index.
 */
final class InstitutionSearchCache
{
    private const int PUBLIC_SEARCH_CACHE_TTL = 600;

    private const string PUBLIC_SEARCH_CACHE_VERSION_KEY = 'institution_search_public_version_v2';

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(string $key, Closure $callback): mixed
    {
        return Cache::remember($key, self::PUBLIC_SEARCH_CACHE_TTL, $callback);
    }

    public function version(): int
    {
        return (int) Cache::get(self::PUBLIC_SEARCH_CACHE_VERSION_KEY, 1);
    }

    public function bumpVersion(): void
    {
        Cache::put(
            self::PUBLIC_SEARCH_CACHE_VERSION_KEY,
            $this->version() + 1,
            now()->addDays(30),
        );
    }
}
