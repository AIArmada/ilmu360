<?php

declare(strict_types=1);

namespace App\Features\Institutions\Persistence;

use AIArmada\CommerceSupport\Support\CacheVersionStore;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;

final readonly class InstitutionListingCacheVersionStore implements InstitutionListingCacheVersion
{
    private const string VERSION_KEY = 'public_directory:institutions:version:v1';

    public function __construct(
        private CacheVersionStore $cacheVersions,
    ) {}

    public function current(): array
    {
        return ['version' => $this->cacheVersions->version(self::VERSION_KEY)];
    }

    public function bump(): void
    {
        $this->cacheVersions->bump(self::VERSION_KEY);
    }
}
