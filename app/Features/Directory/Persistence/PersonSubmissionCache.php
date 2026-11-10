<?php

declare(strict_types=1);

namespace App\Features\Directory\Persistence;

use Illuminate\Support\Facades\Cache;

/**
 * Person submission cache invalidation.
 */
final readonly class PersonSubmissionCache
{
    public function forget(): void
    {
        Cache::forget('submit_persons');
    }
}
