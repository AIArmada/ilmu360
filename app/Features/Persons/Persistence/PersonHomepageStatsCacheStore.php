<?php

declare(strict_types=1);

namespace App\Features\Persons\Persistence;

use App\Features\Persons\Api\PersonHomepageStats;
use App\Features\Persons\Models\Person;
use App\Shared\Cache\DiscoveryCache;

final readonly class PersonHomepageStatsCacheStore implements PersonHomepageStats
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    public function activePersonCount(): int
    {
        return $this->cache->rememberInt(
            $this->versionKey(),
            'home:stats:persons:active',
            (int) config('discovery.cache.ttls.homepage_stats', 300),
            static fn (): int => Person::active()->count('id'),
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
