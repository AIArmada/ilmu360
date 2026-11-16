<?php

declare(strict_types=1);

namespace App\Features\Persons\Persistence;

use App\Features\Persons\Api\PersonSubmissionOptionsCache;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Guest person submission-options cache store.
 */
final readonly class PersonSubmissionOptionsCacheStore implements PersonSubmissionOptionsCache
{
    private const string OPTIONS_KEY = 'submit_persons';

    private const int OPTIONS_TTL_SECONDS = 60;

    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberGuestPersonOptions(Closure $resolver): array
    {
        return Cache::remember(self::OPTIONS_KEY, self::OPTIONS_TTL_SECONDS, $resolver);
    }

    public function forgetGuestPersonOptions(): void
    {
        Cache::forget(self::OPTIONS_KEY);
    }
}
