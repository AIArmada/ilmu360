<?php

declare(strict_types=1);

namespace App\Features\Directory\Persistence;

use Illuminate\Support\Facades\Cache;

/**
 * Cache invalidations owned by the institution submission workflow.
 */
final class InstitutionSubmissionCache
{
    public function forget(): void
    {
        Cache::forget('submit_institutions');
    }
}
