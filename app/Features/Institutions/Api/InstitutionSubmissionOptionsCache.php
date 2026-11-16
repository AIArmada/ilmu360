<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

use Closure;

interface InstitutionSubmissionOptionsCache
{
    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberGuestOptions(?string $countryId, Closure $resolver): array;

    public function invalidate(): void;
}
