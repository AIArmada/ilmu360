<?php

declare(strict_types=1);

namespace App\Shared\Submission;

use App\Platform\Cache\CacheVersionStore;

/**
 * Cache-key contract shared by the public submission options provider and the
 * Directory invalidation path.
 *
 * Institution options are versioned so one bump invalidates every
 * country-scoped entry without scanning or flushing the cache store.
 */
final readonly class SubmissionOptionsCache
{
    private const string INSTITUTION_VERSION_KEY = 'submission_options:institutions:version:v1';

    public function __construct(private CacheVersionStore $cacheVersions) {}

    public function institutionOptionsKey(?string $countryId): string
    {
        return 'submit_institutions:'.($countryId ?? 'all').':'.$this->cacheVersions->version(self::INSTITUTION_VERSION_KEY);
    }

    public function bumpInstitutionVersion(): void
    {
        $this->cacheVersions->bump(self::INSTITUTION_VERSION_KEY);
    }
}
