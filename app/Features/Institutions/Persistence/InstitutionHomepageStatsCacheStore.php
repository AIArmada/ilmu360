<?php

declare(strict_types=1);

namespace App\Features\Institutions\Persistence;

use App\Features\Institutions\Api\InstitutionHomepageStats;
use App\Features\Institutions\Models\Institution;
use App\Shared\Cache\DiscoveryCache;

final readonly class InstitutionHomepageStatsCacheStore implements InstitutionHomepageStats
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    public function activeInstitutionCount(): int
    {
        return $this->cache->rememberInt(
            $this->versionKey(),
            'home:stats:institutions:active',
            (int) config('discovery.cache.ttls.homepage_stats', 300),
            static fn (): int => Institution::active()->count('id'),
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
