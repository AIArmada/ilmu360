<?php

declare(strict_types=1);

namespace App\Features\Persons\Persistence;

use App\Features\Persons\Api\PersonSearchCache;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Public person-search cache store.
 */
final readonly class PersonSearchCacheStore implements PersonSearchCache
{
    private const string VERSION_KEY = 'person_search_public_version_v2';

    private const int DEFAULT_VERSION = 2;

    private const int VERSION_TTL_DAYS = 30;

    private const int PUBLIC_SEARCH_CACHE_TTL_SECONDS = 600;

    private function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, self::DEFAULT_VERSION);
    }

    public function bumpPublicSearchVersion(): void
    {
        Cache::put(
            self::VERSION_KEY,
            $this->version() + 1,
            now()->addDays(self::VERSION_TTL_DAYS),
        );
    }

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    public function rememberPublicSearchIds(string $normalizedSearch, Closure $resolve): array
    {
        return $this->remember('person_search_public', $normalizedSearch, $resolve);
    }

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    public function rememberPublicFuzzySearchIds(string $normalizedSearch, Closure $resolve): array
    {
        return $this->remember('person_search_public_fuzzy', $normalizedSearch, $resolve);
    }

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    private function remember(string $keyPrefix, string $normalizedSearch, Closure $resolve): array
    {
        $boundedSearch = mb_substr($normalizedSearch, 0, 200);

        /** @var list<string> $ids */
        $ids = Cache::remember(
            sprintf('%s:%d:%s', $keyPrefix, $this->version(), md5($boundedSearch)),
            self::PUBLIC_SEARCH_CACHE_TTL_SECONDS,
            $resolve,
        );

        return $ids;
    }
}
