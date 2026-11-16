<?php

declare(strict_types=1);

namespace App\Features\References\Persistence;

use App\Features\References\Api\ReferenceSearchCache;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Public reference-search cache store.
 */
final readonly class ReferenceSearchCacheStore implements ReferenceSearchCache
{
    private const string VERSION_KEY = 'reference_search_public_version_v2';

    private const int DEFAULT_VERSION = 1;

    private const int VERSION_TTL_DAYS = 30;

    private const int PUBLIC_SEARCH_CACHE_TTL_SECONDS = 600;

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    public function rememberPublicSearchIds(string $normalizedSearch, Closure $resolve): array
    {
        return $this->remember('reference_search_public', $normalizedSearch, $resolve);
    }

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    public function rememberPublicFuzzySearchIds(string $normalizedSearch, Closure $resolve): array
    {
        return $this->remember('reference_search_public_fuzzy', $normalizedSearch, $resolve);
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
    private function remember(string $keyPrefix, string $normalizedSearch, Closure $resolve): array
    {
        /** @var list<string> $ids */
        $ids = Cache::remember(
            sprintf('%s:%d:%s', $keyPrefix, $this->version(), md5($normalizedSearch)),
            self::PUBLIC_SEARCH_CACHE_TTL_SECONDS,
            $resolve,
        );

        return $ids;
    }

    private function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, self::DEFAULT_VERSION);
    }
}
