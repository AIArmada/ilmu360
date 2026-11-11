<?php

declare(strict_types=1);

namespace App\Features\Directory\Persistence;

use App\Shared\Submission\SubmissionOptionsCache;

/**
 * Cache invalidations owned by the institution submission workflow.
 */
final readonly class InstitutionSubmissionCache
{
    public function __construct(private SubmissionOptionsCache $optionsCache) {}

    public function forget(): void
    {
        // Bump the shared version so every country-scoped guest option entry
        // is invalidated without scanning for individual cache keys.
        $this->optionsCache->bumpInstitutionVersion();
    }
}
