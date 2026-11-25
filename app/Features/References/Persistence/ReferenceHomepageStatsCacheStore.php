<?php

declare(strict_types=1);

namespace App\Features\References\Persistence;

use App\Features\References\Api\ReferenceHomepageStats;
use App\Features\References\Models\Reference;
use App\Shared\Cache\DiscoveryCache;

final readonly class ReferenceHomepageStatsCacheStore implements ReferenceHomepageStats
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    public function rootReferenceCount(): int
    {
        return $this->cache->rememberInt(
            $this->versionKey(),
            'home:stats:references:active',
            (int) config('discovery.cache.ttls.homepage_stats', 300),
            static fn (): int => Reference::active()->root()->count('id'),
        );
    }

    public function forgetCachedCount(): void
    {
        $this->cache->bump($this->versionKey());
    }

    private function versionKey(): string
    {
        $configured = config('discovery.cache.versions.homepage_stats', 'discovery:v:homepage:stats');

        return is_string($configured) && $configured !== '' ? $configured : 'discovery:v:homepage:stats';
    }
}
