<?php

declare(strict_types=1);

namespace App\Features\References\Persistence;

use AIArmada\CommerceSupport\Support\CacheVersionStore;
use AIArmada\CommerceSupport\Support\VersionedCache;
use App\Features\References\Api\ReferenceSearchCache;
use Closure;

/**
 * Public reference-search cache store.
 */
final readonly class ReferenceSearchCacheStore implements ReferenceSearchCache
{
    private const string VERSION_KEY = 'reference_search_public_version_v2';

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
