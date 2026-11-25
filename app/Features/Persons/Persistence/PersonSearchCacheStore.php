<?php

declare(strict_types=1);

namespace App\Features\Persons\Persistence;

use App\Features\Persons\Api\PersonSearchCache;
use App\Shared\Cache\DiscoveryCache;
use Closure;

/**
 * Public person-search cache store.
 */
final readonly class PersonSearchCacheStore implements PersonSearchCache
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    public function bumpPublicSearchVersion(): void
    {
        $this->cache->bump($this->versionKey());
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
        $ids = $this->cache->remember(
            $this->versionKey(),
            sprintf('%s:%s', $keyPrefix, md5($boundedSearch)),
            (int) config('discovery.cache.ttls.search_public', 300),
            $resolve,
        );

        return $ids;
    }

    private function versionKey(): string
    {
        $configured = config('discovery.cache.versions.persons_search', 'discovery:v:persons:search');

        return is_string($configured) && $configured !== '' ? $configured : 'discovery:v:persons:search';
    }
}
