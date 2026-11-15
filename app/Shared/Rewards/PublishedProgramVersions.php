<?php

declare(strict_types=1);

namespace App\Shared\Rewards;

/**
 * Published reward program version data needed by external composition.
 *
 * Callers receive the version identifier and eligibility value without a
 * mutable package model.
 */
interface PublishedProgramVersions
{
    public function publishedVersion(string $programId): ?PublishedProgramVersionSnapshot;
}
