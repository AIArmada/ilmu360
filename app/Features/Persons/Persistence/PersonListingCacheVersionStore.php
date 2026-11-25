<?php

declare(strict_types=1);

namespace App\Features\Persons\Persistence;

use App\Features\Events\Api\EventInvolvementFingerprint;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Shared\Cache\DiscoveryCache;

final readonly class PersonListingCacheVersionStore implements PersonListingCacheVersion
{
    public function __construct(
        private DiscoveryCache $cache,
        private EventInvolvementFingerprint $eventInvolvementFingerprint,
    ) {}

    public function current(): array
    {
        $fingerprint = $this->eventInvolvementFingerprint->current();

        return ['version' => implode('|', [$this->cache->version($this->versionKey()), $fingerprint])];
    }

    public function bump(): void
    {
        $this->cache->bump($this->versionKey());
    }

    private function versionKey(): string
    {
        $configured = config('discovery.cache.versions.persons_listing', 'discovery:v:persons:listing');

        return is_string($configured) && $configured !== '' ? $configured : 'discovery:v:persons:listing';
    }
}
