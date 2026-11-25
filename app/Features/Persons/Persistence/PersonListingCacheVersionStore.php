<?php

declare(strict_types=1);

namespace App\Features\Persons\Persistence;

use AIArmada\CommerceSupport\Persistence\CacheVersionStore;
use App\Features\Events\Api\EventInvolvementFingerprint;
use App\Features\Persons\Api\PersonListingCacheVersion;

final readonly class PersonListingCacheVersionStore implements PersonListingCacheVersion
{
    private const string VERSION_KEY = 'public_directory:persons:version:v1';

    public function __construct(
        private CacheVersionStore $cacheVersions,
        private EventInvolvementFingerprint $eventInvolvementFingerprint,
    ) {}

    public function current(): array
    {
        $fingerprint = $this->eventInvolvementFingerprint->current();

        return ['version' => implode('|', [$this->cacheVersions->version(self::VERSION_KEY), $fingerprint])];
    }

    public function bump(): void
    {
        $this->cacheVersions->bump(self::VERSION_KEY);
    }
}
