<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

use Closure;

/**
 * Cache boundary for guest person options used by event submission flows.
 */
interface PersonSubmissionOptionsCache
{
    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberGuestPersonOptions(Closure $resolver): array;

    public function forgetGuestPersonOptions(): void;
}
