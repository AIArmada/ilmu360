<?php

declare(strict_types=1);

namespace App\Features\Institutions\Persistence;

use App\Features\Institutions\Api\InstitutionSearchCache;
use App\Platform\Cache\CacheVersionStore;
use App\Platform\Cache\VersionedCache;
use Closure;

/**
 * Public institution-search cache store.
 */
final readonly class InstitutionSearchCacheStore implements InstitutionSearchCache
{
    private const string VERSION_KEY = 'institution_search_public_version_v2';

    private const int PUBLIC_SEARCH_CACHE_TTL_SECONDS = 600;

    public function __construct(
        private CacheVersionStore $versions,
        private VersionedCache $cache,
    ) {}

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    public function rememberPublicSearchIds(string $normalizedSearch, Closure $resolve): array
    {
        return $this->remember('institution_search_public', $normalizedSearch, $resolve);
    }

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    public function rememberPublicFuzzySearchIds(string $normalizedSearch, Closure $resolve): array
    {
        return $this->remember('institution_search_public_fuzzy', $normalizedSearch, $resolve);
    }

    public function bumpPublicSearchVersion(): void
    {
        $this->versions->bump(self::VERSION_KEY);
    }

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    private function remember(string $keyPrefix, string $normalizedSearch, Closure $resolve): array
    {
        $boundedSearch = mb_substr($normalizedSearch, 0, 200);

        /** @var list<string> $ids */
        $ids = $this->cache->remember(
            self::VERSION_KEY,
            sprintf('%s:%s', $keyPrefix, md5($boundedSearch)),
            self::PUBLIC_SEARCH_CACHE_TTL_SECONDS,
            $resolve,
        );

        return $ids;
    }
}
