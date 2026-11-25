<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\EventHomepageStats;
use App\Features\Events\Models\Event;
use App\Shared\Cache\DiscoveryCache;

final readonly class EventHomepageStatsCacheStore implements EventHomepageStats
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    public function activeEventCount(): int
    {
        return $this->cache->rememberInt(
            $this->versionKey(),
            'home:stats:events:active',
            (int) config('discovery.cache.ttls.homepage_stats', 300),
            static fn (): int => Event::active()->count('id'),
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
