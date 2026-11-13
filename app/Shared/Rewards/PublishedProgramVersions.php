<?php

declare(strict_types=1);

namespace App\Shared\Rewards;

/**
 * Published reward program version resolution.
 *
 * Feature code asks for the currently effective published version by scalar
 * program id; the root adapter performs the timestamp/version timeline
 * resolution without exporting a mutable package model.
 */
interface PublishedProgramVersions
{
    public function publishedVersionId(string $programId): ?string;
}
