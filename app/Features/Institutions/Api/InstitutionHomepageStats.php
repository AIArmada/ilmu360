<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

interface InstitutionHomepageStats
{
    public function activeInstitutionCount(): int;

    public function forgetCachedCount(): void;
}
