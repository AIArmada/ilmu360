<?php

declare(strict_types=1);

namespace App\Features\Institutions\Persistence;

use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Shared\Cache\DiscoveryCache;

final readonly class InstitutionListingCacheVersionStore implements InstitutionListingCacheVersion
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    public function current(): array
    {
        return ['version' => $this->cache->version($this->versionKey())];
    }

    public function bump(): void
    {
        $this->cache->bump($this->versionKey());
    }

    private function versionKey(): string
    {
        $configured = config('discovery.cache.versions.institutions_listing', 'discovery:v:institutions:listing');

        return is_string($configured) && $configured !== '' ? $configured : 'discovery:v:institutions:listing';
    }
}
