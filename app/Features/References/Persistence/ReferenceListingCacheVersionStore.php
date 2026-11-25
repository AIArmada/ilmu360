<?php

declare(strict_types=1);

namespace App\Features\References\Persistence;

use App\Features\References\Api\ReferenceListingCacheVersion;
use App\Shared\Cache\DiscoveryCache;

final readonly class ReferenceListingCacheVersionStore implements ReferenceListingCacheVersion
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
        $configured = config('discovery.cache.versions.references_listing', 'discovery:v:references:listing');

        return is_string($configured) && $configured !== '' ? $configured : 'discovery:v:references:listing';
    }
}
